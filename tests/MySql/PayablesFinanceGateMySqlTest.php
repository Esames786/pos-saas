<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Tenant\User;
use App\Services\Saas\TenantSubscriptionAccessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * PAYABLES-FINANCE-GATE-1 — supplier aging is a finance report, like customer aging.
 *
 * FIN-6A moved Receivables aging onto the finance module and left Payables, its other half, on the
 * generic reports module. Kashif Kitchen's plan has finance and not reports, so it could open the
 * Supplier Ledger, General Ledger and Trial Balance — but not what it owes, by age.
 *
 * Driven through what deploy runs (`system:routes-sync` writes the catalog), the access service every
 * request goes through, and the real sidebar.
 */
class PayablesFinanceGateMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const ROUTE = 'tenant.reports.purchases.payables';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['branches', 'users']);

        $master = DB::connection('master');
        $tenantIds = $master->table('tenants')->where('tenant_code', 'like', 'paytest-%')->pluck('id');
        $master->table('subscriptions')->whereIn('tenant_id', $tenantIds)->delete();
        $master->table('tenants')->whereIn('id', $tenantIds)->delete();
        $planIds = $master->table('plans')->where('code', 'like', 'paytest-%')->pluck('id');
        $master->table('plan_modules')->whereIn('plan_id', $planIds)->delete();
        $master->table('plans')->whereIn('id', $planIds)->delete();

        // What deploy step [4] runs: the catalog is rebuilt from the route list + module-key overrides.
        Artisan::call('route:clear');
        Artisan::call('system:routes-sync');
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant');
        parent::tearDown();
    }

    /** A real module row, created only if the master test DB lacks it — never overwriting a seeded one. */
    private function module(string $key): Module
    {
        return Module::firstOrCreate(['key' => $key], [
            'name' => ucfirst($key), 'category' => 'Test', 'description' => $key,
            'route_module_keys' => ['tenant.' . $key], 'sort_order' => 900, 'is_core' => false, 'is_active' => true,
        ]);
    }

    /** A tenant on a plan with exactly these optional modules. */
    private function tenantWith(array $moduleKeys): Tenant
    {
        $plan = Plan::create(['code' => 'paytest-' . uniqid(), 'name' => 'Payables Test Plan', 'price' => 0, 'is_active' => true]);
        foreach ($moduleKeys as $key) {
            PlanModule::create(['plan_id' => $plan->id, 'module_id' => $this->module($key)->id, 'is_enabled' => true]);
        }
        $tenant = Tenant::create(['tenant_code' => 'paytest-' . uniqid(), 'business_name' => 'Payables Test', 'status' => 'active']);
        Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);

        return $tenant->fresh();
    }

    private function allowed(Tenant $tenant): bool
    {
        return app(TenantSubscriptionAccessService::class)->check($tenant, self::ROUTE)['allowed'];
    }

    /** The real sidebar, for a tenant bound the way IdentifyTenant binds it. */
    private function sidebarFor(Tenant $tenant): string
    {
        app()->instance('tenant', $tenant);
        $uid = $this->makeUser();
        foreach ([self::ROUTE, 'tenant.finance.trial-balance.index', 'tenant.reports.center.index'] as $name) {
            Permission::findOrCreate($name, 'tenant');
        }
        $user = User::on('tenant')->find($uid);
        $user->givePermissionTo([self::ROUTE, 'tenant.finance.trial-balance.index', 'tenant.reports.center.index']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($uid), 'tenant');
        Auth::shouldUse('tenant');

        return view('partials.sidebar')->render();
    }

    public function test_deploy_files_payables_under_finance_like_receivables(): void
    {
        $catalog = DB::connection('master')->table('route_catalogs');

        $this->assertSame('tenant.finance', (clone $catalog)->where('route_name', self::ROUTE)->value('module_key'));
        $this->assertSame('tenant.finance', (clone $catalog)->where('route_name', 'tenant.reports.sales.receivables')->value('module_key'),
            'its other half, unchanged');
        $this->assertSame('tenant.reports', (clone $catalog)->where('route_name', 'tenant.reports.purchases.returns')->value('module_key'),
            'only payables moved — the rest of Reports stays where it was');
    }

    public function test_a_finance_plan_without_reports_opens_payables(): void
    {
        // Kashif Kitchen's shape.
        $this->assertTrue($this->allowed($this->tenantWith(['finance'])));
    }

    public function test_a_plan_without_finance_no_longer_opens_payables(): void
    {
        // The three public demo plans with reports but no finance — same as Receivables already was for them.
        $this->assertFalse($this->allowed($this->tenantWith(['reports'])));
    }

    public function test_a_finance_tenant_without_reports_finds_the_link_under_finance(): void
    {
        $html = $this->sidebarFor($this->tenantWith(['finance']));

        $this->assertStringContainsString('id="finance-payables-link"', $html, 'the Reports menu is hidden for this plan, so Finance carries it');
        $this->assertStringContainsString('Payables Aging', $html);
    }

    public function test_a_tenant_with_both_keeps_its_old_menu(): void
    {
        $html = $this->sidebarFor($this->tenantWith(['finance', 'reports']));

        $this->assertStringNotContainsString('id="finance-payables-link"', $html, 'no second copy under Finance');
        $this->assertStringContainsString('/reports/purchases/payables', $html, 'still in the Reports menu, as before');
    }

    public function test_a_tenant_without_finance_sees_no_dead_link(): void
    {
        $html = $this->sidebarFor($this->tenantWith(['reports']));

        $this->assertStringNotContainsString('/reports/purchases/payables', $html, 'a link the gate would refuse is worse than none');
    }
}
