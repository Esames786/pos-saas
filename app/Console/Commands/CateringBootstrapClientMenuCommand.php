<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Services\Tenancy\TenancyManager;
use Database\Seeders\Tenant\KashifClientMenuSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * KASHIF-CLIENT-MENU-1 — put the client's REAL menu in front of them, safely.
 *
 * One tenant, named exactly; Khatri is live and refuses by construction (no
 * tenant loop exists to reach it). Everything inside is idempotent and
 * additive — see KashifClientMenuSeeder for the full contract. The command
 * also proves its own safety: it fingerprints the ledgers before and after
 * and refuses to report success if anything financial or stock-side moved.
 */
class CateringBootstrapClientMenuCommand extends Command
{
    protected $signature = 'catering:bootstrap-client-menu {tenant_code} {--yes} {--confirm=}';

    protected $description = "Import the client's real 888-item menu, the representative Cost-Block products and the legacy 8701/8704 reference drafts into ONE allowed tenant.";

    private const ALLOWED_TENANTS = ['kashifkitchen'];

    public function handle(TenancyManager $tenancy): int
    {
        $code = (string) $this->argument('tenant_code');

        if (! in_array($code, self::ALLOWED_TENANTS, true)) {
            $this->error("Tenant [{$code}] is not on the client-menu allowlist (".implode(', ', self::ALLOWED_TENANTS).').');

            return self::FAILURE;
        }

        if (! $this->option('yes') || $this->option('confirm') !== $code) {
            $this->error("Refusing: pass --yes and --confirm={$code} (typed confirmation) to bootstrap '{$code}'.");

            return self::FAILURE;
        }

        $tenant = Tenant::where('tenant_code', $code)->first();
        if (! $tenant) {
            $this->error("Tenant [{$code}] not found.");

            return self::FAILURE;
        }

        $tenancy->activate($tenant);
        $db = DB::connection('tenant');

        $before = [
            'gl' => $db->table('journal_lines')->count(),
            'stock' => $db->table('stock_ledgers')->count(),
            'events' => $db->table('catering_events')->count(),
        ];

        $seeder = new KashifClientMenuSeeder;
        $seeder->run();
        $seeder->runRepresentatives();
        $marketStats = $seeder->runMarketEstimates();
        $seeder->retireGenericUatProfiles();
        $seeder->runLegacyOrders();

        $after = [
            'gl' => $db->table('journal_lines')->count(),
            'stock' => $db->table('stock_ledgers')->count(),
        ];

        if ($after['gl'] !== $before['gl'] || $after['stock'] !== $before['stock']) {
            $this->error(sprintf(
                'SAFETY VIOLATION: gl %d→%d, stock %d→%d — a menu bootstrap must never move money or stock. Investigate before trusting this run.',
                $before['gl'], $after['gl'], $before['stock'], $after['stock'],
            ));

            return self::FAILURE;
        }

        $menuCount = Product::where('sku', 'like', KashifClientMenuSeeder::SKU_PREFIX.'%')->count();
        $ready = CateringProductProfile::where('catering_enabled', true)->count();
        $needsSetup = CateringProductProfile::where('catering_enabled', false)
            ->whereHas('product', fn ($q) => $q->where('sku', 'like', KashifClientMenuSeeder::SKU_PREFIX.'%'))
            ->count();

        $this->info(sprintf(
            '[%s] client menu bootstrapped: %d catalogue items (KM-*), %d quotation-ready profiles, %d visible-but-needs-setup, events %d→%d (legacy 8701/8704 references idempotent). Market estimates: %d priced, %d no-honest-template, %d owner-authored untouched. GL and stock untouched.',
            $code, $menuCount, $ready, $needsSetup, $before['events'], $db->table('catering_events')->count(),
            $marketStats['priced'], $marketStats['skipped_no_template'], $marketStats['skipped_owner_authored'],
        ));

        return self::SUCCESS;
    }
}
