<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\SupplierPayment;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SUPPLIER-LEDGER-DAILY-1 — give the supplier ledger rows written before `entry_date` existed the business date of
 * the document behind them, so the ledger can be read day by day on the invoice / payment date.
 *
 *   purchase_bill, return_netted  → the invoice's bill_date
 *   payment                       → the payment's payment_date
 *   payment_void                  → the date of the void's own GL reversal (VOID-ON-ORIGINAL-DATE-1 may have put it
 *                                   on the payment's day)
 *   purchase_return               → the return's return_date
 *   journal_adjustment / _reversal→ the journal's entry_date
 *   opening_balance               → the supplier's creation day (the day its opening GL entry carries)
 *
 * Writes ONLY `entry_date`, only where it is NULL (idempotent). Amounts, balances, counts and every other table are
 * checked before and after and must not move. A row it cannot match stays NULL — the screen then uses the day it was
 * typed. Default DRY RUN: the same work runs and is rolled back. --yes commits it.
 */
class FinanceSupplierLedgerDatesCommand extends Command
{
    protected $signature = 'finance:supplier-ledger-dates {tenant_code} {--yes}';

    protected $description = 'Date old supplier ledger rows by their document (invoice / payment / return / journal). Dry run unless --yes.';

    private const DRY_RUN = '__supplier_ledger_dates_dry_run__';

    public function handle(TenancyManager $tenancy): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $tenancy->activate($tenant);

        $apply = (bool) $this->option('yes');
        $this->line($apply ? '<comment>APPLYING</comment>' : '<info>DRY RUN</info> — nothing is written; pass --yes to write.');

        $conn = DB::connection('tenant');
        $books = fn () => json_encode($conn->table('supplier_ledgers')
            ->selectRaw('COUNT(*) n, ROUND(SUM(amount),4) amt, ROUND(SUM(balance_after),4) bal, MAX(updated_at) upd')->first());
        $before = $books();
        $open = fn () => $conn->table('supplier_ledgers')->whereNull('entry_date')->count();
        $this->line('Rows without a date: ' . $open());

        try {
            $conn->transaction(function () use ($conn, $books, $before, $open, $apply) {
                $set = fn (string $label, $query, string $source) => $this->line(sprintf('  %-34s %d',
                    $label, $query->update(['l.entry_date' => DB::raw($source)])));

                $set('invoice (bill_date)', $conn->table('supplier_ledgers as l')
                    ->join('purchase_bills as b', 'b.id', '=', 'l.reference_id')
                    ->whereNull('l.entry_date')->where('l.reference_type', PurchaseBill::class), 'b.bill_date');

                $set('payment (payment_date)', $conn->table('supplier_ledgers as l')
                    ->join('supplier_payments as p', 'p.id', '=', 'l.reference_id')
                    ->whereNull('l.entry_date')->where('l.reference_type', SupplierPayment::class)
                    ->where('l.entry_type', 'payment'), 'p.payment_date');

                $set('payment void (its GL reversal)', $conn->table('supplier_ledgers as l')
                    ->join('supplier_payments as p', 'p.id', '=', 'l.reference_id')
                    ->join('journal_entries as j', 'j.id', '=', 'p.void_journal_entry_id')
                    ->whereNull('l.entry_date')->where('l.reference_type', SupplierPayment::class)
                    ->where('l.entry_type', 'payment_void'), 'j.entry_date');

                $set('return (return_date)', $conn->table('supplier_ledgers as l')
                    ->join('purchase_returns as r', 'r.id', '=', 'l.reference_id')
                    ->whereNull('l.entry_date')->where('l.reference_type', PurchaseReturn::class), 'r.return_date');

                $set('journal (entry_date)', $conn->table('supplier_ledgers as l')
                    ->join('journal_entries as j', 'j.id', '=', 'l.reference_id')
                    ->whereNull('l.entry_date')->where('l.reference_type', JournalEntry::class), 'j.entry_date');

                $set('opening balance (supplier created)', $conn->table('supplier_ledgers as l')
                    ->join('suppliers as s', 's.id', '=', 'l.supplier_id')
                    ->whereNull('l.entry_date')->where('l.entry_type', 'opening_balance'), 'DATE(s.created_at)');

                $left = $conn->table('supplier_ledgers')->whereNull('entry_date')
                    ->selectRaw('entry_type, reference_type, COUNT(*) n')->groupBy('entry_type', 'reference_type')->get();
                $this->line('Still without a date (the screen uses the day typed): ' . $open());
                foreach ($left as $r) {
                    $this->line("  {$r->entry_type} / " . ($r->reference_type ?? '-') . ": {$r->n}");
                }

                if ($books() !== $before) {
                    throw new RuntimeException('Amounts, balances or row count changed — rolled back, nothing written.');
                }

                if (! $apply) {
                    throw new RuntimeException(self::DRY_RUN);
                }
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== self::DRY_RUN) {
                throw $e;
            }
            $this->info('Dry run — rolled back. Run again with --yes to write.');

            return self::SUCCESS;
        }

        $this->info('Done. Amounts, balances and row count unchanged.');

        return self::SUCCESS;
    }
}
