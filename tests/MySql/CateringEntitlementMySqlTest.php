<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Services\Catering\CateringPrinterRoutingService;
use App\Services\Saas\TenantSubscriptionAccessService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-SLICE-1/3 — entitlement + routing-isolation invariants (spec §21/§15/§25):
 * a non-entitled tenant fails CLOSED on tenant.catering.* routes, an entitled
 * plan passes, catering permissions exist for the tenant guard, and the
 * copy-from-POS printer convenience never mutates POS KOT mappings.
 */
class CateringEntitlementMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const ROUTE = 'tenant.catering.events.index';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_printer_mappings', 'category_printer_mappings', 'printers',
            'categories', 'branches',
        ]);

        // Master-side scratch rows are namespaced with CATTEST- codes and removed here.
        $master = DB::connection('master');
        $tenantIds = $master->table('tenants')->where('tenant_code', 'like', 'cattest-%')->pluck('id');
        $master->table('subscriptions')->whereIn('tenant_id', $tenantIds)->delete();
        $master->table('tenants')->whereIn('id', $tenantIds)->delete();
        $planIds = $master->table('plans')->where('code', 'like', 'cattest-%')->pluck('id');
        $master->table('plan_modules')->whereIn('plan_id', $planIds)->delete();
        $master->table('plans')->whereIn('id', $planIds)->delete();
        $master->table('route_catalogs')->where('route_name', self::ROUTE)->delete();
    }

    private function seedMasterSide(bool $cateringEnabled): Tenant
    {
        // The catering module row (registered by 2026_08_13_000001 / MasterSeeder).
        $module = Module::updateOrCreate(
            ['key' => 'catering'],
            [
                'name' => 'Catering & Events', 'category' => 'Operations',
                'description' => 'Catering vertical', 'route_module_keys' => ['tenant.catering'],
                'sort_order' => 145, 'is_core' => false, 'is_active' => true,
            ]
        );

        DB::connection('master')->table('route_catalogs')->updateOrInsert(
            ['route_name' => self::ROUTE],
            ['uri' => 'catering/events', 'method' => 'GET', 'module_key' => 'tenant.catering',
                'action_key' => 'events.index', 'is_published' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $plan = Plan::create([
            'code' => 'cattest-'.uniqid(),
            'name' => 'Catering Test Plan',
            'price' => 0,
            'is_active' => true,
        ]);

        PlanModule::create([
            'plan_id' => $plan->id,
            'module_id' => $module->id,
            'is_enabled' => $cateringEnabled,
        ]);

        $tenant = Tenant::create([
            'tenant_code' => 'cattest-'.uniqid(),
            'business_name' => 'Catering Test Tenant',
            'status' => 'active',
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_ends_at' => now()->addMonth(),
        ]);

        return $tenant->fresh();
    }

    public function test_non_entitled_tenant_fails_closed_on_catering_routes(): void
    {
        $tenant = $this->seedMasterSide(cateringEnabled: false);

        $result = app(TenantSubscriptionAccessService::class)->check($tenant, self::ROUTE);

        $this->assertFalse($result['allowed'], 'catering routes must fail CLOSED for a non-entitled plan');
        $this->assertSame('module_disabled', $result['reason']);
        $this->assertSame('catering', $result['module']->key);
    }

    public function test_entitled_tenant_passes_the_catering_route_gate(): void
    {
        $tenant = $this->seedMasterSide(cateringEnabled: true);

        $result = app(TenantSubscriptionAccessService::class)->check($tenant, self::ROUTE);

        $this->assertTrue($result['allowed']);
        $this->assertSame('module_enabled', $result['reason']);
    }

    public function test_catering_permissions_exist_for_the_tenant_guard(): void
    {
        // Seeded by tenant migration 2026_08_13_100003 during migrate:fresh.
        foreach ([
            'tenant.catering.events.index',
            'tenant.catering.estimates.send',
            'tenant.catering.material-rates.store',
            'tenant.catering.rate-impact.apply',
            'tenant.catering.production-releases.store',
            'tenant.catering.printer-mappings.copy-from-pos',
        ] as $permission) {
            $this->assertTrue(
                $this->tenant()->table('permissions')
                    ->where('name', $permission)->where('guard_name', 'tenant')->exists(),
                "permission {$permission} must exist (route-name convention)"
            );
        }
    }

    /**
     * CATERING-GO-LIVE-READINESS-1 (§3): deploying catering code must NOT broaden
     * access. The real deploy surface (MasterSeeder + module migration) may
     * REGISTER the module globally, but no plan — enterprise included — gains an
     * enabled catering PlanModule, and an explicit admin grant survives reseeds.
     */
    public function test_deployment_reseed_never_broadens_catering_access(): void
    {
        // Clean slate for the deploy simulation: other tests in this class create
        // deliberately catering-enabled scratch plans — remove every catering
        // attachment so the assertion sees only what the SEEDER produces.
        if ($moduleId = DB::connection('master')->table('modules')->where('key', 'catering')->value('id')) {
            DB::connection('master')->table('plan_modules')->where('module_id', $moduleId)->delete();
        }

        // The real deploy path: deploy.sh runs MasterSeeder on every deploy —
        // with master as the default connection (Spatie models follow it).
        $runSeeder = function (): void {
            DB::setDefaultConnection('master');
            try {
                (new \Database\Seeders\MasterSeeder)->run();
            } finally {
                DB::setDefaultConnection('tenant');
            }
        };
        $runSeeder();

        $cateringModuleId = Module::where('key', 'catering')->value('id');
        $this->assertNotNull($cateringModuleId, 'module is globally REGISTERED');

        $enabled = DB::connection('master')->table('plan_modules')
            ->where('module_id', $cateringModuleId)->where('is_enabled', true)->count();
        $this->assertSame(0, $enabled,
            'no plan — enterprise included — may gain catering merely because code deployed');

        $enterprise = Plan::where('code', 'enterprise')->firstOrFail();
        $this->assertFalse($enterprise->hasEnabledModuleKey('catering'),
            'the all-modules enterprise plan excludes rollout-gated verticals');

        // The registration migration is also access-neutral.
        (require base_path('database/migrations/2026_08_13_000001_register_catering_module.php'))->up();
        $this->assertSame(0, DB::connection('master')->table('plan_modules')
            ->where('module_id', $cateringModuleId)->where('is_enabled', true)->count());

        // Explicit rollout for one client: an admin grant on a plan…
        PlanModule::updateOrCreate(
            ['plan_id' => $enterprise->id, 'module_id' => $cateringModuleId],
            ['is_enabled' => true]
        );

        // …SURVIVES the next deploy's reseed (not disabled, not duplicated)…
        $runSeeder();
        $this->assertTrue($enterprise->fresh()->hasEnabledModuleKey('catering'),
            'an explicit rollout grant must survive redeploys untouched');

        // …and every OTHER plan is still catering-disabled.
        $this->assertSame(1, DB::connection('master')->table('plan_modules')
            ->where('module_id', $cateringModuleId)->where('is_enabled', true)->count());
    }

    /** CATERING-V1-CLOSURE-1 (§9): the Permission Center shows friendly groups, not 36 raw routes. */
    public function test_permission_center_groups_catering_into_business_actions(): void
    {
        $this->seedMasterSide(cateringEnabled: true);

        $permissions = \Spatie\Permission\Models\Permission::query()
            ->where('guard_name', 'tenant')->where('name', 'like', 'tenant.catering.%')->get();
        $this->assertGreaterThanOrEqual(30, $permissions->count(), 'all catering route permissions seeded');

        $catalog = app(\App\Services\Permissions\PermissionCatalogService::class)
            ->build($permissions, ['catering']);

        $cateringNode = collect($catalog['modules'])->firstWhere('key', 'catering');
        $this->assertNotNull($cateringNode, 'catering module appears for an entitled plan');

        $featureNames = array_keys($cateringNode['features']);
        sort($featureNames);

        // The friendly vocabulary. Every group an operator can be shown must be
        // one of these — a raw route name reaching the screen is the failure
        // this test exists to catch.
        //
        // Asserted as a SUBSET rather than an exact snapshot, deliberately. Which
        // catering permissions exist in the database depends on what has been
        // route-synced, so an exact list quietly made this test depend on which
        // OTHER test ran first: the same code produced twelve names alone and
        // thirteen inside the suite. Membership is the property actually being
        // protected; the count was an accident of ordering.
        $friendly = [
            'Apply Commercial Rates',
            'Catering Products',
            'Catering Settings',
            'Commercial Rates',
            'Confirm Booking',
            'Create / Edit Estimate',
            'Finalise Event',
            'Issue Materials',
            'Manage Material Rates',
            'Print / Reprint',
            'Record Advance',
            'Refund Customer',
            'Release Production',
            'Send / Revise Quote',
            'Store Issue',
            'View Catering',
        ];

        foreach ($featureNames as $name) {
            $this->assertContains($name, $friendly,
                "'{$name}' is not a business action — the Permission Center must never show a raw route group");
        }

        // …and the core lifecycle is genuinely grouped, not merely absent.
        foreach ([
            'View Catering', 'Create / Edit Estimate', 'Send / Revise Quote', 'Confirm Booking',
            'Record Advance', 'Release Production', 'Issue Materials', 'Finalise Event',
            'Manage Material Rates', 'Catering Products', 'Print / Reprint', 'Catering Settings',
        ] as $expected) {
            $this->assertContains($expected, $featureNames,
                "the Permission Center must still group '{$expected}'");
        }

        // Sensitive lifecycle actions stay individually visible inside their group…
        $allNames = collect($cateringNode['features'])
            ->flatMap(fn ($feature) => collect($feature['actions'] ?? [])->pluck('name')
                ->merge(collect($feature['groups'] ?? [])->flatten(1)->pluck('name')));
        $this->assertTrue($allNames->contains('tenant.catering.estimates.send'));
        $this->assertTrue($allNames->contains('tenant.catering.events.close'));

        // …and enforcement is untouched: every managed key is still the raw route name.
        foreach ($catalog['managed'] as $managedName) {
            $this->assertStringStartsWith('tenant.', $managedName);
        }

        // Non-entitled plan: whole module hidden AND unmanaged.
        $hidden = app(\App\Services\Permissions\PermissionCatalogService::class)->build($permissions, []);
        $this->assertNull(collect($hidden['modules'])->firstWhere('key', 'catering'));
        $this->assertSame([], $hidden['managed']);
    }

    public function test_copy_from_pos_is_one_way_and_catering_mappings_stay_independent(): void
    {
        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $printerId = $this->makePrinter();
        $otherPrinterId = $this->makePrinter();

        // POS KOT routing: one branch-scoped mapping, one wildcard, one inactive (skipped),
        // one receipt-only (skipped).
        $this->tenant()->table('category_printer_mappings')->insert([
            ['branch_id' => $branchId, 'category_id' => $categoryId, 'printer_id' => $printerId, 'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => null, 'category_id' => null, 'printer_id' => $otherPrinterId, 'print_role' => 'both', 'order_type' => 'all', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $branchId, 'category_id' => null, 'printer_id' => $otherPrinterId, 'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => null, 'category_id' => $categoryId, 'printer_id' => $printerId, 'print_role' => 'receipt', 'order_type' => 'all', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $posBefore = $this->tenant()->table('category_printer_mappings')->orderBy('id')->get();

        $routing = app(CateringPrinterRoutingService::class);
        $copied = $routing->copyFromPosKotMappings();
        $this->assertSame(2, $copied, 'only active kot/both mappings copy');

        // Idempotent re-copy.
        $this->assertSame(0, $routing->copyFromPosKotMappings(), 'second copy adds nothing');

        // POS table byte-identical after the copy.
        $posAfter = $this->tenant()->table('category_printer_mappings')->orderBy('id')->get();
        $this->assertEquals($posBefore, $posAfter, 'POS KOT mappings must never be mutated by catering');

        // Catering mappings are independently manageable: deleting one leaves POS intact.
        $this->tenant()->table('catering_printer_mappings')->limit(1)->delete();
        $this->assertSame(1, (int) $this->tenant()->table('catering_printer_mappings')->count());
        $this->assertSame(4, (int) $this->tenant()->table('category_printer_mappings')->count());
    }
}
