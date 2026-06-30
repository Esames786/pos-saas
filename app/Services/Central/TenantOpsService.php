<?php

namespace App\Services\Central;

use App\Models\Master\Tenant;
use App\Services\Saas\DemoResetService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * MASTER-TENANT-OPS-1 — non-destructive sync + guarded reset for tenants.
 *
 * sync   = migrate tenant DB + ensure permissions from the route catalog + grant Owner
 *          + reset permission cache (NEVER seeds or deletes data) — mirrors deploy.sh.
 * reset  = backup first, then drop+recreate+reseed — only for is_demo tenants that are
 *          in saas.demos.reset_tenant_codes (via DemoResetService's own hard guards).
 */
class TenantOpsService
{
    public function __construct(
        private TenancyManager $tenancy,
        private TenantBackupService $backups,
        private DemoResetService $demoReset,
    ) {}

    /** Migrate + sync permissions for ONE tenant. No data is seeded or deleted. */
    public function syncTenant(Tenant $tenant): array
    {
        $names = DB::connection('master')->table('route_catalogs')
            ->where('route_name', 'like', 'tenant.%')
            ->pluck('route_name')->all();

        $result = ['tenant_code' => $tenant->tenant_code, 'migrate' => '—', 'permissions' => '—', 'status' => 'ok', 'error' => null];

        try {
            $this->tenancy->activate($tenant);

            // The spatie permission cache may still hold the logged-in superadmin's
            // (central-guard) permissions. Forget it so findOrCreate / grant read the
            // TENANT DB on the now-active connection — otherwise a cache miss makes
            // findOrCreate INSERT a permission that already exists (duplicate-key error).
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path'     => 'database/migrations/tenant',
                '--force'    => true,
            ]);
            $result['migrate'] = 'migrated';

            foreach ($names as $n) {
                Permission::findOrCreate($n, 'tenant');
            }
            $owner = Role::where('name', 'Owner')->where('guard_name', 'tenant')->first();
            if ($owner && $names) {
                $owner->givePermissionTo($names);
            }
            $result['permissions'] = $owner ? (count($names) . ' synced') : 'no Owner role';

            Artisan::call('permission:cache-reset');
        } catch (Throwable $e) {
            $result['status'] = 'FAIL';
            $result['error']  = $e->getMessage();
            Log::error('tenant_ops.sync_failed', ['tenant' => $tenant->tenant_code, 'error' => $e->getMessage()]);
        } finally {
            $this->tenancy->deactivate();
            DB::setDefaultConnection('master');
        }

        return $result;
    }

    /** Sync every tenant; resilient (one failure does not abort the rest). */
    public function syncAll(): array
    {
        $results = [];
        foreach (Tenant::orderBy('tenant_code')->get() as $tenant) {
            $results[] = $this->syncTenant($tenant);
        }

        return $results;
    }

    /** Back up every tenant; resilient. */
    public function backupAll(?int $userId = null): array
    {
        $results = [];
        foreach (Tenant::orderBy('tenant_code')->get() as $tenant) {
            try {
                $backup = $this->backups->backup($tenant, 'bulk', $userId, 'Backup-all run');
                $results[] = ['tenant_code' => $tenant->tenant_code, 'status' => 'ok', 'file' => $backup->file_name, 'size' => $backup->humanSize()];
            } catch (Throwable $e) {
                $results[] = ['tenant_code' => $tenant->tenant_code, 'status' => 'FAIL', 'file' => null, 'size' => '—', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Reset ONE tenant: backup first, then drop+recreate+reseed.
     * Blocked for non-demo unless ALLOW_TENANT_RESET_FOR_NON_DEMO=true, and only
     * tenants in saas.demos.reset_tenant_codes are resettable from the UI.
     */
    public function resetTenant(Tenant $tenant, ?int $userId = null): array
    {
        if (! $tenant->isDemo() && ! config('saas.tenant_ops.allow_non_demo_reset', false)) {
            throw new RuntimeException(
                'Production/client tenants cannot be reset from the UI. '
                . 'Set ALLOW_TENANT_RESET_FOR_NON_DEMO=true to override (not recommended).'
            );
        }

        $resetCodes = (array) config('saas.demos.reset_tenant_codes', []);
        if (! in_array($tenant->tenant_code, $resetCodes, true)) {
            throw new RuntimeException(
                "Tenant [{$tenant->tenant_code}] is not a UI-resettable demo. "
                . 'Resettable demos: ' . implode(', ', $resetCodes) . '. '
                . 'Use `php artisan system:reset` for the legacy demo / full rebuild.'
            );
        }

        // Backup first (so a reset is always recoverable).
        $this->backups->backup($tenant, 'pre_reset', $userId, 'Auto pre-reset backup');

        $fresh = $this->demoReset->resetTenantCode($tenant->tenant_code);

        Log::warning('tenant_ops.reset', ['tenant' => $tenant->tenant_code, 'by' => $userId]);

        return ['tenant_code' => $tenant->tenant_code, 'status' => 'ok', 'plan' => $fresh->subscription?->plan?->code];
    }

    /** Reset ALL public demo tenants (is_demo + reset_tenant_codes); backup each first. */
    public function resetDemoTenants(?int $userId = null): array
    {
        $codes   = (array) config('saas.demos.reset_tenant_codes', []);
        $results = [];
        $stopped = false;

        foreach ($codes as $code) {
            if ($stopped) {
                $results[] = ['tenant_code' => $code, 'status' => 'skipped'];
                continue;
            }

            $tenant = Tenant::where('tenant_code', $code)->first();
            if (! $tenant) {
                $results[] = ['tenant_code' => $code, 'status' => 'FAIL: tenant not found'];
                $stopped = true;
                continue;
            }

            try {
                $results[] = $this->resetTenant($tenant, $userId);
            } catch (Throwable $e) {
                $results[] = ['tenant_code' => $code, 'status' => 'FAIL: ' . $e->getMessage()];
                $stopped = true;
            }
        }

        return $results;
    }
}
