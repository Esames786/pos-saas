<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Database\Seeders\Tenant\KashifLegacyImportSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * KASHIF-LEGACY-IMPORT-2 — the go-live pipeline, one command.
 *
 * Feed a NEWER old_software.xlsx through the extraction script, re-run this,
 * and the tenant catches up: products enriched from the client's own item
 * master, customers from the order book, and (by explicit scope) the legacy
 * orders as events. Idempotent throughout; Khatri unreachable by construction;
 * GL/stock fingerprinted before and after — money and stock must not move.
 */
class CateringImportLegacyCommand extends Command
{
    protected $signature = 'catering:import-legacy {tenant_code} {--orders=none : none|future|all} {--yes} {--confirm=}';

    protected $description = "Import the client's legacy database (products enrichment, customers, orders) from docs/data/legacy-*.csv into ONE allowed tenant.";

    private const ALLOWED_TENANTS = ['kashifkitchen'];

    public function handle(TenancyManager $tenancy): int
    {
        $code = (string) $this->argument('tenant_code');
        $ordersScope = (string) $this->option('orders');

        if (! in_array($code, self::ALLOWED_TENANTS, true)) {
            $this->error("Tenant [{$code}] is not on the legacy-import allowlist.");

            return self::FAILURE;
        }
        if (! in_array($ordersScope, ['none', 'future', 'all'], true)) {
            $this->error('--orders must be none|future|all.');

            return self::FAILURE;
        }
        if (! $this->option('yes') || $this->option('confirm') !== $code) {
            $this->error("Refusing: pass --yes and --confirm={$code} (typed confirmation).");

            return self::FAILURE;
        }

        $tenant = Tenant::where('tenant_code', $code)->first();
        if (! $tenant) {
            $this->error("Tenant [{$code}] not found.");

            return self::FAILURE;
        }

        $tenancy->activate($tenant);
        $db = DB::connection('tenant');
        $before = [$db->table('journal_lines')->count(), $db->table('stock_ledgers')->count()];

        $seeder = new KashifLegacyImportSeeder;

        $p = $seeder->importProducts();
        $this->info(sprintf(
            'products: matched=%d missed=%d rerated=%d charge-only=%d owner-kept=%d party-off=%d',
            $p['matched'], $p['missed'], $p['rerated'], $p['charged'], $p['owner_kept'], $p['party_off'],
        ));

        $c = $seeder->importCustomers();
        $this->info(sprintf('customers: imported=%d already-there=%d', $c['imported'], $c['existing']));

        $o = $seeder->importOrders($ordersScope);
        $this->info(sprintf('orders(%s): events=%d lines=%d skipped-existing=%d', $ordersScope, $o['events'], $o['lines'], $o['skipped_existing']));

        $after = [$db->table('journal_lines')->count(), $db->table('stock_ledgers')->count()];
        if ($after !== $before) {
            $this->error('SAFETY VIOLATION: GL or stock moved during a data import. Investigate before trusting this run.');

            return self::FAILURE;
        }

        $this->info('GL and stock untouched. Done.');

        return self::SUCCESS;
    }
}
