<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Services\Permissions\PermissionCatalogService;
use App\Services\Saas\TenantSubscriptionAccessService;
use Illuminate\Support\Facades\DB;

/**
 * STARTER-PLAN-COUNTER-1 — a POS plan can make its counter, open a shift and close the day.
 *
 * retail_starter, inventory_store, restaurant_starter and quick_sale have `pos` but not `multi_branch`, which owned
 * Branches, Terminals, Shifts and Day Closing — so a tenant on them could not make a counter or open a shift, and the
 * POS sells only on an open shift. They now open with multi_branch OR pos. Catering (multi_branch, no pos) keeps them;
 * a plan with neither still cannot; currencies stay multi_branch only; how many branches / counters stays the plan's limit.
 */
class StarterPlanCounterMySqlTest extends MySqlTenantTestCase
{
    private const ROUTES = ['tenant.branches.index', 'tenant.terminals.store', 'tenant.shifts.store', 'tenant.daily-closings.index'];

    protected function setUp(): void
    {
        parent::setUp();
        $master = DB::connection('master');
        $tenantIds = $master->table('tenants')->where('tenant_code', 'like', 'sptest-%')->pluck('id');
        $master->table('subscriptions')->whereIn('tenant_id', $tenantIds)->delete();
        $master->table('tenants')->whereIn('id', $tenantIds)->delete();
        $planIds = $master->table('plans')->where('code', 'like', 'sptest-%')->pluck('id');
        $master->table('plan_modules')->whereIn('plan_id', $planIds)->delete();
        $master->table('plans')->whereIn('id', $planIds)->delete();

        foreach (['pos', 'multi_branch', 'catalog'] as $key) {
            $this->assertNotNull(Module::where('key', $key)->first(), "the {$key} module is seeded");
        }
        foreach ([...self::ROUTES, 'tenant.currencies.index', 'tenant.shifts.index'] as $route) {
            $this->assertNotNull(DB::connection('master')->table('route_catalogs')->where('route_name', $route)->value('module_key'), "{$route} is in the route catalog");
        }
    }

    /** @param list<string> $moduleKeys */
    private function tenantOnPlan(array $moduleKeys): Tenant
    {
        $plan = Plan::create(['code' => 'sptest-' . uniqid(), 'name' => 'Starter Test Plan', 'price' => 0, 'is_active' => true]);
        foreach ($moduleKeys as $key) {
            PlanModule::create(['plan_id' => $plan->id, 'module_id' => Module::where('key', $key)->value('id'), 'is_enabled' => true]);
        }
        $tenant = Tenant::create(['tenant_code' => 'sptest-' . uniqid(), 'business_name' => 'Starter Test Shop', 'status' => 'active']);
        Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);

        return $tenant->fresh();
    }

    public function test_a_pos_plan_without_the_branches_module_can_make_a_counter_and_open_a_shift(): void
    {
        $shop = $this->tenantOnPlan(['pos', 'catalog']);   // like retail_starter
        foreach (self::ROUTES as $route) {
            $this->assertTrue(app(TenantSubscriptionAccessService::class)->check($shop, $route)['allowed'], "{$route} opens on a POS plan");
        }
        $currencies = app(TenantSubscriptionAccessService::class)->check($shop, 'tenant.currencies.index');
        $this->assertFalse($currencies['allowed'], 'currencies stay with the Branches module');
    }

    public function test_catering_keeps_them_through_the_branches_module_and_a_plan_with_neither_does_not_get_them(): void
    {
        $caterer = $this->tenantOnPlan(['multi_branch', 'catalog']);   // like kashif-catering: no pos
        $bare = $this->tenantOnPlan(['catalog']);
        foreach (self::ROUTES as $route) {
            $this->assertTrue(app(TenantSubscriptionAccessService::class)->check($caterer, $route)['allowed'], "{$route}: catering keeps it");
            $refused = app(TenantSubscriptionAccessService::class)->check($bare, $route);
            $this->assertFalse($refused['allowed'], "{$route}: still refused without pos or branches");
            $this->assertSame('module_disabled', $refused['reason']);
        }
    }

    public function test_the_role_editor_files_shifts_under_pos_on_a_pos_plan(): void
    {
        $perms = collect(['tenant.shifts.index', 'tenant.shifts.store', 'tenant.terminals.index', 'tenant.currencies.index'])
            ->map(fn ($n) => (object) ['name' => $n]);

        $pos = app(PermissionCatalogService::class)->build($perms, ['pos', 'catalog']);
        $this->assertContains('tenant.shifts.store', $pos['managed'], 'a role on a POS plan can be given shifts');
        $this->assertContains('tenant.terminals.index', $pos['managed']);
        $this->assertNotContains('tenant.currencies.index', $pos['managed'], 'not currencies');
        $this->assertArrayHasKey('pos', collect($pos['modules'])->keyBy('key')->all());

        $both = app(PermissionCatalogService::class)->build($perms, ['pos', 'multi_branch']);
        $this->assertContains('tenant.shifts.store', $both['managed'], 'with both modules they stay where they were');

        $none = app(PermissionCatalogService::class)->build($perms, ['catalog']);
        $this->assertNotContains('tenant.shifts.store', $none['managed']);
    }
}
