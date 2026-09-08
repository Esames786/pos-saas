<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\Supplier;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * KASHIF-LEGACY-SUPPLIERS-1 — the vendor book from the client's own old software.
 *
 * The tenant went live with exactly ONE supplier (`Default Supplier`), so every
 * purchase would have started by typing a vendor in by hand. The old software
 * holds 236 of them under GL parent 201002.
 *
 * What it imports is deliberately thin, because the source is thin: the NAME is
 * on all 236, a phone on 28, and address / city / NTN / sales-tax number /
 * discount / payment terms are empty on every single row — those columns exist
 * in the old schema but were never filled. Importing blanks as blanks is honest;
 * inventing them would not be.
 *
 * WHAT IT WILL NOT DO — opening balances. Seven suppliers carry ~6.49M credit
 * and ~4.49M debit in the workbook. That is money, not master data: it belongs
 * in the ledger through a posting step (Dr 3300 / Cr 2100) once the owner
 * confirms the figures are still true today. This command writes 0.00 and says
 * so, rather than quietly creating six and a half million rupees of liability
 * that no journal entry backs.
 *
 * Idempotent on `code`: running it twice updates, never duplicates. Additive —
 * it deletes nothing, and a supplier the tenant already had by hand is left
 * alone unless it shares a legacy code.
 */
class CateringImportLegacySuppliersCommand extends Command
{
    protected $signature = 'catering:import-legacy-suppliers {tenant_code} {--yes} {--dry-run}';

    protected $description = 'Import the legacy supplier book (names + phones only, never opening balances) into a catering tenant.';

    /** This data belongs to one client; no other tenant may receive it. */
    private const ALLOWED = ['kashifkitchen'];

    public function handle(TenancyManager $tenancy): int
    {
        $code = (string) $this->argument('tenant_code');

        if (! in_array($code, self::ALLOWED, true)) {
            $this->error("Refusing: '{$code}' is not in the allowlist for this client's legacy data.");

            return self::FAILURE;
        }
        if (! $this->option('yes') && ! $this->option('dry-run')) {
            $this->error('Refusing: pass --dry-run to preview, or --yes to write.');

            return self::FAILURE;
        }

        $path = base_path('docs/data/legacy-suppliers.csv');
        if (! is_file($path)) {
            $this->error("Missing {$path} — run scripts/extract-legacy-xlsx.php first.");

            return self::FAILURE;
        }

        $tenant = Tenant::where('tenant_code', $code)->first();
        if (! $tenant) {
            $this->error("Tenant '{$code}' not found.");

            return self::FAILURE;
        }
        $tenancy->activate($tenant);

        // This command touches master data only. If a journal line or a stock
        // movement appears across it, something is very wrong and the operator
        // must hear about it rather than read a cheerful summary.
        $fingerprint = fn () => [
            'journal_entries' => DB::connection('tenant')->table('journal_entries')->count(),
            'journal_lines' => DB::connection('tenant')->table('journal_lines')->count(),
            'stock_ledgers' => DB::connection('tenant')->table('stock_ledgers')->count(),
            'supplier_ledgers' => DB::connection('tenant')->table('supplier_ledgers')->count(),
        ];
        $before = $fingerprint();

        $rows = $this->csv($path);
        $this->info(count($rows).' supplier rows in the legacy file.');

        $created = 0;
        $updated = 0;
        $withPhone = 0;
        $carryingBalance = [];

        foreach ($rows as $row) {
            $name = trim((string) $row['name']);
            $legacyCode = 'LEG-'.trim((string) $row['account_no']);
            if ($name === '') {
                continue;
            }
            if ((float) $row['opening_credit'] !== 0.0 || (float) $row['opening_debit'] !== 0.0) {
                $carryingBalance[] = $name.' (cr '.number_format((float) $row['opening_credit'], 2)
                    .' / dr '.number_format((float) $row['opening_debit'], 2).')';
            }

            $phone = trim((string) $row['phone']) ?: null;
            if ($phone) {
                $withPhone++;
            }

            $attributes = [
                'name' => mb_substr($name, 0, 190),
                'phone' => $phone,
                'address' => trim((string) $row['address']) ?: null,
                'status' => 'active',
            ];

            if ($this->option('dry-run')) {
                Supplier::where('code', $legacyCode)->exists() ? $updated++ : $created++;

                continue;
            }

            $supplier = Supplier::where('code', $legacyCode)->first();
            if ($supplier) {
                $supplier->fill($attributes)->save();
                $updated++;
            } else {
                // opening_balance / current_balance stay at their column
                // defaults: this command never creates money.
                Supplier::create($attributes + ['code' => $legacyCode]);
                $created++;
            }
        }

        $after = $fingerprint();

        $this->newLine();
        $this->info(($this->option('dry-run') ? '[DRY RUN] ' : '')
            ."created={$created} updated={$updated} with_phone={$withPhone}");
        $this->line('  suppliers now: '.Supplier::count());

        if ($carryingBalance !== []) {
            $this->newLine();
            $this->warn('NOT imported — these '.count($carryingBalance).' suppliers carry an opening balance in the');
            $this->warn('old book. They need a GL posting step and the owner\'s confirmation, not a silent insert:');
            foreach ($carryingBalance as $line) {
                $this->line('    '.$line);
            }
        }

        if ($before !== $after) {
            $this->error('INTEGRITY: accounting or stock moved during a master-data import — '
                .json_encode(['before' => $before, 'after' => $after]));

            return self::FAILURE;
        }
        $this->info('integrity: no journal entry, no stock movement, no supplier ledger row. '
            .json_encode($after));

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string>> */
    private function csv(string $path): array
    {
        $rows = [];
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        while (($r = fgetcsv($fh)) !== false) {
            $rows[] = array_combine($header, array_pad($r, count($header), null));
        }
        fclose($fh);

        return $rows;
    }
}
