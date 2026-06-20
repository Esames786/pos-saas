<?php

namespace App\Services\Saas;

use App\Models\Master\Plan;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDomain;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Services\Tenancy\TenancyManager;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Provisions per-industry public demo tenants by reusing the generic
 * TenantProvisioner. Each demo gets a plan, a subdomain, a never-expiring
 * subscription, the standard owner account (internal), plus a public
 * restricted "Demo" user. Resets are guarded by the demo allowlist + is_demo.
 */
class DemoProvisioner
{
    private const PLAN_MAP = [
        'retail'         => 'retail_starter',
        'inventory'      => 'inventory_store',
        'restaurant'     => 'restaurant_starter',
        'restaurant_pro' => 'restaurant_pro',
        'enterprise'     => 'enterprise',
        'finance'        => 'finance_erp',
    ];

    private const BUSINESS_NAMES = [
        'retail'         => 'Bingoo Retail Demo',
        'inventory'      => 'Bingoo Inventory Demo',
        'restaurant'     => 'Bingoo Restaurant Demo',
        'restaurant_pro' => 'Bingoo Restaurant Pro Demo',
        'enterprise'     => 'Bingoo Enterprise Demo',
        'finance'        => 'Finance & Supply Chain ERP Demo',
    ];

    public function __construct(
        private TenantProvisioner $provisioner,
        private TenancyManager $tenancyManager,
        private DemoRoleService $demoRoleService,
    ) {}

    public static function industries(): array
    {
        return array_keys(self::PLAN_MAP);
    }

    public function provision(string $industry, bool $fresh = false): Tenant
    {
        if (! isset(self::PLAN_MAP[$industry])) {
            throw new InvalidArgumentException(
                "Unsupported demo industry [{$industry}]. Allowed: " . implode(', ', self::industries())
            );
        }

        $tenantCode = config("saas.demos.tenant_codes.{$industry}");
        $allowlist  = config('saas.demos.allowlist', []);

        if (! $tenantCode || ! in_array($tenantCode, $allowlist, true)) {
            throw new RuntimeException("Demo tenant code for [{$industry}] is missing or not allowlisted.");
        }

        $plan = Plan::where('code', self::PLAN_MAP[$industry])->first();
        if (! $plan) {
            throw new RuntimeException('Plan [' . self::PLAN_MAP[$industry] . '] not found. Run MasterSeeder first.');
        }

        $existing = Tenant::where('tenant_code', $tenantCode)->first();

        if ($existing) {
            if (! $fresh) {
                throw new RuntimeException("Demo tenant [{$tenantCode}] already exists. Use --fresh to recreate it.");
            }
            $this->safelyDestroyDemoTenant($existing);
        }

        $password = config('saas.demos.default_password', 'demo1234');
        $domain   = $tenantCode . '.' . config('tenancy.tenant_base_domain');
        $farFuture = now()->addYear();

        $tenant = DB::connection('master')->transaction(function () use ($tenantCode, $industry, $plan, $domain, $farFuture) {
            $tenant = Tenant::create([
                'tenant_code'   => $tenantCode,
                'business_name' => self::BUSINESS_NAMES[$industry],
                'owner_name'    => 'Demo Owner',
                'owner_email'   => 'owner@' . $tenantCode . '.com',
                'currency_code' => 'PKR',
                'status'        => 'pending',
                'is_demo'       => true,
                'trial_ends_at' => $farFuture,
            ]);

            TenantDomain::create([
                'tenant_id'  => $tenant->id,
                'domain'     => $domain,
                'is_primary' => true,
                'status'     => 'pending',
            ]);

            Subscription::create([
                'tenant_id'              => $tenant->id,
                'plan_id'                => $plan->id,
                'status'                 => 'active',
                'trial_ends_at'          => $farFuture,
                'current_period_ends_at' => $farFuture,
            ]);

            return $tenant;
        });

        // Reuse the generic provisioner: creates DB, migrates, base data,
        // owner user + Owner role + all perms, flips tenant + domain active.
        $this->provisioner->provisionTenant($tenant->fresh(), $password);

        // Inside the tenant DB: restricted Demo role + public demo user.
        $this->seedDemoAccess($tenant, $password);

        return $tenant->fresh(['domains', 'database', 'subscription.plan']);
    }

    private function seedDemoAccess(Tenant $tenant, string $password): void
    {
        $this->tenancyManager->activate($tenant);

        try {
            $role = $this->demoRoleService->createOrUpdateDemoRole();

            $branch = Branch::query()->orderBy('id')->first();

            $user = User::updateOrCreate(
                ['email' => 'demo@' . $tenant->tenant_code . '.com'],
                [
                    'name'              => 'Demo User',
                    'password'          => Hash::make($password),
                    'status'            => 'active',
                    'locale'            => 'en',
                    'default_branch_id' => $branch?->id,
                ]
            );

            if ($branch) {
                $user->branches()->syncWithoutDetaching([$branch->id]);
            }

            $user->syncRoles([$role]);
        } finally {
            $this->tenancyManager->deactivate();
            DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        }
    }

    /**
     * Drop a demo tenant's database and master rows — only after strict
     * allowlist + is_demo + deterministic-name safety checks.
     */
    private function safelyDestroyDemoTenant(Tenant $tenant): void
    {
        $allowlist = config('saas.demos.allowlist', []);

        if (! in_array($tenant->tenant_code, $allowlist, true)) {
            throw new RuntimeException("Refusing to reset [{$tenant->tenant_code}] — not in demo allowlist.");
        }

        if (! $tenant->isDemo()) {
            throw new RuntimeException("Refusing to reset [{$tenant->tenant_code}] — tenant is not flagged is_demo.");
        }

        $this->tenancyManager->deactivate();
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));

        $dbName = $this->demoDatabaseName($tenant->tenant_code);
        $masterDb = config('database.connections.master.database');

        if ($dbName === '' || $dbName === $masterDb || ! str_starts_with($dbName, 'pos_tenant_')) {
            throw new RuntimeException("Refusing to drop unsafe database name [{$dbName}].");
        }

        DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$dbName}`");

        $tenant->database()->delete();
        $tenant->domains()->delete();
        $tenant->subscription()->delete();
        $tenant->delete();
    }

    /** Mirror of TenantProvisioner::makeDatabaseName(). */
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
