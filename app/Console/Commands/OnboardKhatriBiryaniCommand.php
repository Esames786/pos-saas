<?php

namespace App\Console\Commands;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDomain;
use App\Services\Tenancy\TenancyManager;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * KHATRI BIRYANI ONBOARDING (docs/onboarding/khatri-biryani-2026-08.md — decisions D1–D4).
 *
 * Idempotent end-to-end onboarding of the Khatri Biryani tenant:
 *  - master: `erp_extensions` module row (closes the quotations/purchase-requisitions unmapped
 *    fail-open; enabled ONLY for the plan codes whose sidebar already shows the group);
 *  - master: custom non-public plan `khatri_restaurant` — restaurant_pro's module set (= everything
 *    except manufacturing + offline_edge + erp_extensions) with branch_limit=1, terminal_limit=4,
 *    user_limit=10, product_limit unlimited;
 *  - master: tenant `khatribiryani` + domain + ACTIVE subscription;
 *  - provision (existing TenantProvisioner — DB, migrations, base seed, Owner);
 *  - tenant: terminals T1..T4 (T1/T2 active, T3/T4 inactive per contract), menu categories +
 *    products (ALL SERVICE-BASED: is_stock_tracked=0, consumption 'none' — no inventory deduction),
 *    Manager role (enabled-module permissions minus manufacturing/ERP/roles/permissions/billing).
 *
 * NOTHING here hardcodes Khatri into permission/entitlement CODE — this is data provisioning only.
 */
class OnboardKhatriBiryaniCommand extends Command
{
    protected $signature = 'onboard:khatri-biryani
        {--owner-password= : Owner password (required on first provision)}
        {--owner-email= : Tenant owner/default report email (can be set later)}
        {--yes : Confirm execution}';

    protected $description = 'Onboard the Khatri Biryani tenant (idempotent; see docs/onboarding/khatri-biryani-2026-08.md).';

    private const PLAN_CODE = 'khatri_restaurant';
    private const TENANT_CODE = 'khatribiryani';

    /** restaurant_pro's module set = all standard modules minus manufacturing/offline_edge/erp_extensions. */
    private const PLAN_MODULES = [
        'pos', 'catalog', 'restaurant', 'kitchen_display', 'kitchen_inventory', 'inventory',
        'purchasing', 'stock_count', 'printing', 'reports', 'sales_controls', 'multi_branch',
        'users_roles', 'finance',
    ];

    private const PLAN_FEATURES = ['branch_limit' => 1, 'terminal_limit' => 4, 'user_limit' => 10, 'product_limit' => null];

    /** Menu (Z-report prices authoritative — see the onboarding doc for ⚠ client-verification flags). */
    private const MENU = [
        'Beef Khatri Biryani' => [
            ['Beef Khatri Biryani (1/2 kg)', 450], ['Beef Khatri Biryani (1 kg)', 900],
            ['Beef Khatri Biryani Special (1/2 kg)', 600], ['Beef Khatri Biryani Special (1 kg)', 1200],
            ['Saadi Khatri Biryani (1/2 kg)', 250], ['Saadi Khatri Biryani (1 kg)', 500],
            ['Saadi Biryani (1/2 kg)', 200], ['Matka Biryani Beef', 4000],
        ],
        'Beef Changezi Pulao' => [
            ['Beef Changezi Pulao (1/2 kg)', 450], ['Beef Changezi Pulao (1 kg)', 900],
            ['Beef Changezi Pulao Special (1/2 kg)', 600], ['Saada Beef Changezi Pulao (1/2 kg)', 250],
        ],
        'Chicken Biryani' => [
            ['Chicken Biryani (1/2 kg)', 330], ['Chicken Biryani (1 kg)', 650],
            ['Chicken Biryani Family Pack', 1600], ['Chicken Extra Piece', 150],
        ],
        'Singaporean Rice' => [
            ['Singaporean Rice (Small)', 550], ['Singaporean Rice (Large)', 1000],
            ['Singaporean Rice Family Pack (Small)', 2500], ['Singaporean Rice Family Pack (Large)', 3500],
            ['Extra Sauce', 130],
        ],
        'Haleem' => [
            ['Haleem (Plate)', 300], ['Haleem (1/2 kg)', 400], ['Haleem (1 kg)', 800],
        ],
        'Desserts' => [
            ['Cream Cocktail (Cup)', 120], ['Cream Cocktail (Half Pack)', 600], ['Cream Cocktail (Full Pack)', 1000],
            ['Mango Delight (Cup)', 130], ['Mango Delight (Half)', 650], ['Mango Delight (Full)', 1300],
        ],
        'Beverages' => [
            ['Mineral Water (Small)', 60], ['Mineral Water (Large)', 120],
            ['Cola Next 300 ml', 90], ['Cola Next 500 ml', 110], ['Cola Next 1.5 Ltr', 180],
            ['Coldrink Jumbo', 240], ['1 Ltr Coldrink', 160], ['Pakola 300 ml', 90],
        ],
        'Extras' => [
            ['Raita', 70],
        ],
    ];

    public function handle(TenantProvisioner $provisioner, TenancyManager $tenancy): int
    {
        if (! $this->option('yes')) {
            $this->error('Refusing without --yes (creates/updates master plan + tenant + tenant data).');

            return self::FAILURE;
        }

        // ── 1. master: erp_extensions module (D2) ──
        $erp = Module::updateOrCreate(['key' => 'erp_extensions'], [
            'name' => 'ERP Extensions', 'category' => 'Operations',
            'description' => 'Bank reconciliation, quotations, purchase requisitions (coming soon).',
            'route_module_keys' => ['tenant.quotations', 'tenant.purchase-requisitions'],
            'sort_order' => 90, 'is_core' => false, 'is_active' => true,
        ]);
        // preserve today's effective access: only the plan codes whose sidebar shows the ERP group.
        foreach (Plan::whereIn('code', ['enterprise', 'standard', 'finance_erp'])->get() as $erpPlan) {
            PlanModule::updateOrCreate(['plan_id' => $erpPlan->id, 'module_id' => $erp->id], ['is_enabled' => true]);
        }
        $this->info('erp_extensions module mapped (quotations/purchase-requisitions fail-open closed).');

        // ── 2. master: custom plan (D1) ──
        $plan = Plan::updateOrCreate(['code' => self::PLAN_CODE], [
            'name' => 'Khatri Restaurant (Custom)', 'price' => 0, 'currency_code' => 'PKR',
            'billing_period' => 'monthly', 'is_public' => false, 'is_custom' => true,
        ]);
        $moduleIds = Module::whereIn('key', self::PLAN_MODULES)->pluck('id', 'key');
        foreach ($moduleIds as $key => $id) {
            PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_id' => $id], ['is_enabled' => true]);
        }
        // everything NOT in the set is explicitly disabled (manufacturing, offline_edge, erp_extensions).
        foreach (Module::whereNotIn('key', self::PLAN_MODULES)->get() as $off) {
            PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_id' => $off->id], ['is_enabled' => false]);
        }
        foreach (self::PLAN_FEATURES as $key => $value) {
            PlanFeature::updateOrCreate(['plan_id' => $plan->id, 'feature_key' => $key], ['feature_value' => $value]);
        }
        $this->info('plan khatri_restaurant ready (branch 1 / terminal 4 / user 10; no MFG/ERP/edge).');

        // ── 3. master: tenant + domain + subscription ──
        $tenant = Tenant::updateOrCreate(['tenant_code' => self::TENANT_CODE], [
            'business_name' => 'Khatri Biryani', 'owner_name' => 'Khatri Biryani',
            'owner_email' => $this->option('owner-email') ?: Tenant::where('tenant_code', self::TENANT_CODE)->value('owner_email'),
            'currency_code' => 'PKR', 'status' => 'pending',
        ]);
        TenantDomain::updateOrCreate(
            ['domain' => self::TENANT_CODE . '.' . config('tenancy.tenant_base_domain')],
            ['tenant_id' => $tenant->id, 'is_primary' => true, 'status' => 'active']
        );
        Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $plan->id, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(),
        ]);

        // ── 4. provision (existing idempotent pipeline: DB + migrations + base seed + Owner) ──
        $alreadyProvisioned = DB::connection('master')->table('tenant_databases')
            ->where('tenant_id', $tenant->id)->where('migration_status', 'completed')->exists();
        $password = $this->option('owner-password');
        if (! $alreadyProvisioned && ! $password) {
            $this->error('First provision requires --owner-password.');

            return self::FAILURE;
        }
        $provisioner->provisionTenant($tenant->fresh(), $password ?: Str::random(24));
        $this->info('tenant provisioned: ' . self::TENANT_CODE . '.' . config('tenancy.tenant_base_domain'));

        // ── 5. tenant data: terminals + menu + Manager role ──
        $tenancy->activate($tenant->fresh());
        $branchId = (int) DB::connection('tenant')->table('branches')->orderBy('id')->value('id');

        foreach ([['T1', 'Counter 1', 'active'], ['T2', 'Counter 2', 'active'], ['T3', 'Terminal 3', 'inactive'], ['T4', 'Terminal 4', 'inactive']] as [$code, $name, $status]) {
            DB::connection('tenant')->table('terminals')->updateOrInsert(
                ['code' => $code],
                ['branch_id' => $branchId, 'name' => $name, 'requires_shift' => 1, 'status' => $status,
                 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $this->info('terminals T1..T4 seeded (T1/T2 active — contract: 4 allowed, 2 active).');

        $unitId = DB::connection('tenant')->table('units')->where('code', 'EA')->value('id')
            ?: DB::connection('tenant')->table('units')->insertGetId([
                'code' => 'EA', 'name' => 'Each', 'unit_type' => 'quantity', 'base_factor' => 1,
                'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

        $sort = 0;
        $productCount = 0;
        foreach (self::MENU as $categoryName => $products) {
            $slug = Str::slug($categoryName);
            $categoryId = DB::connection('tenant')->table('categories')->where('slug', $slug)->value('id')
                ?: DB::connection('tenant')->table('categories')->insertGetId([
                    'name' => $categoryName, 'slug' => $slug, 'code' => strtoupper(Str::slug($categoryName, '_')),
                    'sort_order' => ++$sort, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            foreach ($products as [$name, $price]) {
                $pslug = Str::slug($name);
                DB::connection('tenant')->table('products')->updateOrInsert(
                    ['slug' => $pslug],
                    [
                        'category_id' => $categoryId, 'unit_id' => $unitId,
                        'sku' => strtoupper(Str::slug($name, '-')),
                        'name' => $name, 'product_type' => 'simple',
                        // SERVICE-BASED (D4): no inventory deduction, ever.
                        'is_stock_tracked' => 0, 'inventory_consumption_method' => 'none',
                        'is_sellable' => 1, 'is_pos_visible' => 1,
                        'default_selling_price' => $price, 'status' => 'active',
                        'created_at' => now(), 'updated_at' => now(),
                    ]
                );
                $productCount++;
            }
        }
        $this->info("menu seeded: " . count(self::MENU) . " categories, {$productCount} service-based products.");

        // Manager role: every synced permission belonging to an ENABLED plan module, minus admin/owner
        // concerns. Data-driven from the plan's module route keys — nothing Khatri-specific in code.
        $routeKeys = Module::whereIn('key', self::PLAN_MODULES)->pluck('route_module_keys')->flatten()->all();
        $exclude = ['tenant.roles', 'tenant.permissions', 'tenant.billing', 'tenant.manufacturing',
            'tenant.offline-edge', 'tenant.quotations', 'tenant.purchase-requisitions',
            'tenant.finance.bank-reconciliation'];
        $manager = \Spatie\Permission\Models\Role::findOrCreate('Manager', 'tenant');
        $names = \Spatie\Permission\Models\Permission::where('guard_name', 'tenant')->pluck('name')
            ->filter(function (string $name) use ($routeKeys, $exclude) {
                foreach ($exclude as $ex) {
                    if (str_starts_with($name, $ex)) {
                        return false;
                    }
                }
                foreach ($routeKeys as $key) {
                    if (str_starts_with($name, $key)) {
                        return true;
                    }
                }

                return false;
            })->values()->all();
        $manager->syncPermissions($names);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info('Manager role synced: ' . count($names) . ' permissions (no MFG/ERP/roles/billing).');

        $this->info('DONE. Verify prices flagged ⚠ in docs/onboarding/khatri-biryani-2026-08.md before go-live.');

        return self::SUCCESS;
    }
}
