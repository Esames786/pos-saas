<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROD-READINESS-1 — clear the Spatie permission cache EVERYWHERE.
 *
 * The database cache store resolves its connection lazily, so the spatie
 * cache key ('spatie.permission.cache') can exist in MASTER's cache table
 * AND in EACH TENANT DB's cache table. `php artisan permission:cache-reset`
 * only clears master's row — a stale per-tenant row then serves OLD
 * permissions to web requests (new routes 403 while old ones work).
 *
 * Run this after ANY permission sync / provisioning work. deploy.sh calls it.
 */
class SystemClearTenantPermissionCache extends Command
{
    protected $signature = 'system:clear-tenant-permission-cache';

    protected $description = 'Delete Spatie permission cache rows from the master AND every tenant cache table';

    public function handle(TenancyManager $tenancy): int
    {
        $masterDeleted = DB::connection('master')->table('cache')->where('key', 'like', '%spatie%')->delete();
        $this->line("master: {$masterDeleted} cache row(s) deleted");

        $ok = 0;
        $skipped = 0;

        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            try {
                $tenancy->activate($tenant);

                if (Schema::connection('tenant')->hasTable('cache')) {
                    $deleted = DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie%')->delete();
                    $this->line("  {$tenant->tenant_code}: {$deleted} cache row(s) deleted");
                }
                $ok++;
            } catch (\Throwable $e) {
                $this->warn("  SKIP {$tenant->tenant_code}: " . substr($e->getMessage(), 0, 90));
                $skipped++;
            } finally {
                $tenancy->deactivate();
                DB::setDefaultConnection('master');
            }
        }

        // In-process registrar flush too, so a long-running caller sees fresh state.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info("Done. tenants ok={$ok} skipped={$skipped}");

        return self::SUCCESS;
    }
}
