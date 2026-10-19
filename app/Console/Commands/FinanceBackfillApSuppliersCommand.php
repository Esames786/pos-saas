<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Finance\SupplierPayableService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AP-SUPPLIER-DIMENSION-1 (data) — name the supplier on Accounts Payable lines posted before
 * purchase bills, supplier payments, purchase returns and supplier openings started writing it.
 *
 * METADATA ONLY: sets `supplier_id` and `counterparty_type = 'supplier'` on AP lines whose supplier is
 * NULL, taken from the line's own source document. No amount, account, date or entry is touched, no
 * line is added or removed, and nothing is written to supplier_ledgers — that mirror runs only from
 * the manual-journal screen. A reversal (`<type>_reversal`) takes its supplier from the same source.
 *
 * Default DRY RUN. `--yes` writes, in one transaction.
 */
class FinanceBackfillApSuppliersCommand extends Command
{
    protected $signature = 'finance:backfill-ap-suppliers {tenant_code} {--yes}';

    protected $description = 'Name the supplier on old Accounts Payable journal lines from their source documents. Dry run unless --yes.';

    /** source type (without _reversal) => [table, supplier column]; null table = source_id IS the supplier. */
    private const SOURCES = [
        'purchase_bill'            => ['purchase_bills', 'supplier_id'],
        'supplier_payment'         => ['supplier_payments', 'supplier_id'],
        'purchase_return'          => ['purchase_returns', 'supplier_id'],
        'supplier_opening_balance' => [null, null],
    ];

    public function handle(TenancyManager $tenancy): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $tenancy->activate($tenant);
        $apply = (bool) $this->option('yes');
        $this->line($apply ? '<comment>APPLYING</comment>' : '<info>DRY RUN</info> — pass --yes to write.');

        $conn = DB::connection('tenant');
        $apIds = app(SupplierPayableService::class)->apAccountIds();

        $lines = $conn->table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $apIds)
            ->whereNull('l.supplier_id')
            ->get(['l.id', 'e.entry_no', 'e.source_type', 'e.source_id', 'l.debit', 'l.credit']);

        $plan = [];
        $unresolved = [];
        foreach ($lines as $line) {
            $base = preg_replace('/_reversal$/', '', (string) $line->source_type);
            if (! isset(self::SOURCES[$base]) || ! $line->source_id) {
                $unresolved[] = $line;
                continue;
            }
            [$table, $column] = self::SOURCES[$base];
            $supplierId = $table === null
                ? (int) $line->source_id
                : (int) $conn->table($table)->where('id', $line->source_id)->value($column);
            if (! $supplierId || ! $conn->table('suppliers')->where('id', $supplierId)->exists()) {
                $unresolved[] = $line;
                continue;
            }
            $plan[$line->id] = ['supplier_id' => $supplierId, 'line' => $line];
        }

        $names = $conn->table('suppliers')->whereIn('id', array_unique(array_column($plan, 'supplier_id')))->pluck('name', 'id');
        $this->table(['Entry', 'Source', 'Dr', 'Cr', 'Supplier'], array_map(fn ($p) => [
            $p['line']->entry_no, $p['line']->source_type,
            number_format((float) $p['line']->debit, 2), number_format((float) $p['line']->credit, 2),
            $names[$p['supplier_id']] ?? ('#' . $p['supplier_id']),
        ], array_values($plan)));

        $this->line(count($plan) . ' AP line(s) to name, ' . count($unresolved) . ' left as they are (no supplier on the source, or not a supplier document).');
        foreach (array_slice($unresolved, 0, 20) as $u) {
            $this->line("  left: {$u->entry_no} ({$u->source_type})");
        }

        if (! $apply || ! $plan) {
            return self::SUCCESS;
        }

        $conn->transaction(function () use ($conn, $plan) {
            foreach ($plan as $lineId => $p) {
                // Re-checked NULL inside the write, so a line named since the read is never overwritten.
                $conn->table('journal_lines')->where('id', $lineId)->whereNull('supplier_id')
                    ->update(['supplier_id' => $p['supplier_id'], 'counterparty_type' => 'supplier', 'updated_at' => now()]);
            }
        });
        $this->info('Done: ' . count($plan) . ' line(s) named.');

        return self::SUCCESS;
    }
}
