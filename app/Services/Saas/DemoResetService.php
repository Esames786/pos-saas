<?php

namespace App\Services\Saas;

use App\Models\Master\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Safely resets public industry demo tenants (15D-8).
 *
 * A "reset" = DemoProvisioner::provision($industry, fresh:true) (drop + recreate
 * the tenant DB) followed by the matching industry seeder (via demo:seed), so the
 * five public demos can be restored after visitors mutate them.
 *
 * Hard guards before anything is dropped:
 *   - tenant_code must be in config('saas.demos.reset_tenant_codes') — this EXCLUDES
 *     the legacy 'demo' tenant (which lives in the broader 'allowlist').
 *   - tenant must exist in the master DB and be flagged is_demo.
 *   - tenant.status must be active.
 *   - the target database must be exactly pos_tenant_{tenant_code}, never the master
 *     DB, never empty, always prefixed pos_tenant_.
 * DemoProvisioner::safelyDestroyDemoTenant() re-checks allowlist + is_demo + DB name
 * as a second line of defense.
 */
class DemoResetService
{
    public function __construct(
        private DemoProvisioner $provisioner,
    ) {}

    /**
     * Reset a single public demo tenant by its tenant_code.
     */
    public function resetTenantCode(string $tenantCode): Tenant
    {
        $tenantCode = trim($tenantCode);

        $this->assertResettable($tenantCode);

        $industry = $this->industryForTenantCode($tenantCode);

        // Drop + recreate the tenant DB and base data (DemoProvisioner runs its own guards).
        $this->provisioner->provision($industry, true);

        // Re-seed rich industry sample data through the same path as demo:seed.
        $exit = Artisan::call('demo:seed', [
            'industry'         => $industry,
            '--no-interaction' => true,
        ]);

        if ($exit !== 0) {
            throw new RuntimeException(
                "Demo seeding failed for [{$tenantCode}] (industry {$industry}). " . trim(Artisan::output())
            );
        }

        return Tenant::with('subscription.plan', 'domains')
            ->where('tenant_code', $tenantCode)
            ->firstOrFail();
    }

    /**
     * Reset a single public demo tenant by its industry key.
     */
    public function resetIndustry(string $industry): Tenant
    {
        $tenantCode = config("saas.demos.tenant_codes.{$industry}");

        if (! $tenantCode) {
            throw new RuntimeException("Unknown demo industry [{$industry}].");
        }

        return $this->resetTenantCode($tenantCode);
    }

    /**
     * Reset all public demo tenants in configured order, stopping on first failure.
     *
     * @return array<int, array{tenant_code:string, plan:?string, status:string}>
     */
    public function resetAll(): array
    {
        $codes   = (array) config('saas.demos.reset_tenant_codes', []);
        $results = [];
        $stopped = false;

        foreach ($codes as $code) {
            if ($stopped) {
                $results[] = ['tenant_code' => $code, 'plan' => null, 'status' => 'skipped'];
                continue;
            }

            try {
                $tenant   = $this->resetTenantCode($code);
                $results[] = [
                    'tenant_code' => $code,
                    'plan'        => $tenant->subscription?->plan?->code,
                    'status'      => 'ok',
                ];
            } catch (\Throwable $e) {
                $results[] = ['tenant_code' => $code, 'plan' => null, 'status' => 'FAIL: ' . $e->getMessage()];
                $stopped   = true;
            }
        }

        return $results;
    }

    /**
     * Throw unless $tenantCode is a real, active, is_demo public demo tenant whose
     * deterministic database name is safe to drop.
     */
    public function assertResettable(string $tenantCode): Tenant
    {
        $resetCodes = (array) config('saas.demos.reset_tenant_codes', []);

        if (! in_array($tenantCode, $resetCodes, true)) {
            throw new RuntimeException(
                "Refusing to reset [{$tenantCode}] — not a public demo reset tenant. "
                . 'Allowed: ' . implode(', ', $resetCodes) . '.'
            );
        }

        $tenant = Tenant::where('tenant_code', $tenantCode)->first();

        if (! $tenant) {
            throw new RuntimeException(
                "Demo tenant [{$tenantCode}] does not exist. Provision it first: php artisan demo:provision {$this->industryForTenantCode($tenantCode)}"
            );
        }

        if (! $tenant->isDemo()) {
            throw new RuntimeException("Refusing to reset [{$tenantCode}] — tenant is not flagged is_demo.");
        }

        if ($tenant->status !== 'active') {
            throw new RuntimeException(
                "Refusing to reset [{$tenantCode}] — status is [{$tenant->status}], expected active. "
                . "Use: php artisan demo:provision {$this->industryForTenantCode($tenantCode)} --fresh"
            );
        }

        $dbName   = $this->demoDatabaseName($tenantCode);
        $masterDb = config('database.connections.master.database');

        if ($dbName === '' || $dbName === $masterDb || ! str_starts_with($dbName, 'pos_tenant_')) {
            throw new RuntimeException("Refusing to reset [{$tenantCode}] — unsafe database name [{$dbName}].");
        }

        if ($dbName !== 'pos_tenant_' . $tenantCode) {
            throw new RuntimeException(
                "Refusing to reset [{$tenantCode}] — database name [{$dbName}] does not match expected pattern pos_tenant_{$tenantCode}."
            );
        }

        return $tenant;
    }

    /** Database name that demo:reset would drop, used for confirmation prompts. */
    public function databaseNameFor(string $tenantCode): string
    {
        return $this->demoDatabaseName($tenantCode);
    }

    private function industryForTenantCode(string $tenantCode): string
    {
        $map = array_flip((array) config('saas.demos.tenant_codes', []));

        if (! isset($map[$tenantCode])) {
            throw new RuntimeException("No industry mapping for demo tenant [{$tenantCode}].");
        }

        return $map[$tenantCode];
    }

    /** Mirror of TenantProvisioner::makeDatabaseName() / DemoProvisioner::demoDatabaseName(). */
    private function demoDatabaseName(string $tenantCode): string
    {
        $safe = Str::of($tenantCode)
            ->lower()
            ->replaceMatches('/[^a-z0-9_]/', '_')
            ->trim('_')
            ->toString();

        return 'pos_tenant_' . $safe;
    }
}
