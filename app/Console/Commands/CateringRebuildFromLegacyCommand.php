<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Database\Seeders\Tenant\KashifLegacyRebuildSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * KASHIF-LEGACY-REBUILD-1 — replace the catalogue with the client's own book.
 *
 * DESTRUCTIVE by design and by name: --wipe removes the products, categories,
 * customers, catering configuration and bookings the earlier imports built, so
 * what follows is a rebuild rather than another layer. Guarded three ways —
 * one allowlisted tenant, a typed confirmation, and an explicit --wipe — and
 * it still fingerprints GL and stock, refusing to report success if either
 * moved.
 */
class CateringRebuildFromLegacyCommand extends Command
{
    protected $signature = 'catering:rebuild-from-legacy {tenant_code} {--wipe} {--yes} {--confirm=}';

    protected $description = "Rebuild ONE tenant's catalogue, catering configuration and customers from docs/data/legacy-*.csv (the client's own database).";

    private const ALLOWED_TENANTS = ['kashifkitchen'];

    public function handle(TenancyManager $tenancy): int
    {
        $code = (string) $this->argument('tenant_code');

        if (! in_array($code, self::ALLOWED_TENANTS, true)) {
            $this->error("Tenant [{$code}] is not on the rebuild allowlist.");

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

        $seeder = new KashifLegacyRebuildSeeder;

        if ($this->option('wipe')) {
            $removed = $seeder->wipe();
            $this->warn('wiped: '.collect($removed)->filter()->map(fn ($n, $t) => "{$t}={$n}")->implode(' '));
        }

        $stats = $seeder->run();

        $after = [$db->table('journal_lines')->count(), $db->table('stock_ledgers')->count()];
        if ($after !== $before) {
            $this->error('SAFETY VIOLATION: GL or stock moved during a catalogue rebuild.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'categories=%d materials=%d products=%d (quotable=%d needs-setup=%d) blocks: with-material=%d charge-only=%d · party-on=%d complimentary=%d · customers=%d',
            $stats['categories'], $stats['materials'], $stats['products'], $stats['quotable'],
            $stats['needs_setup'], $stats['with_material'], $stats['charge_only'],
            $stats['party_on'], $stats['complimentary'], $stats['customers'],
        ));

        $dupes = $db->table('products')->select('sku')->groupBy('sku')->havingRaw('count(*) > 1')->count();
        $this->info($dupes === 0
            ? 'Every product carries a unique legacy id. GL and stock untouched.'
            : "WARNING: {$dupes} duplicate SKUs — investigate before trusting this run.");

        return $dupes === 0 ? self::SUCCESS : self::FAILURE;
    }
}
