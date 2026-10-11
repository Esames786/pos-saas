<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\DashboardController;
use App\Models\Tenant\User;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\SalesReportEngine;
use App\Services\Reports\SalesReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * DASHBOARD-CATEGORY-TODAY-1 — "Top products ki list k bjae tmam category ki list ajae".
 *
 * A new dashboard card beside Top Products: every category that sold today, biggest first. It asks
 * the Report Center's own engine, so the card and the report never disagree on a day. These run the
 * REAL dashboard controller and compare against that engine, not against a re-built query.
 */
class DashboardCategoryTodayMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private string $today;
    private int $bbq;
    private int $drinks;
    private int $deals;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'combo_components', 'combos', 'products', 'categories', 'terminal_user', 'branch_user',
            'terminals', 'model_has_permissions', 'branches', 'users',
        ]);
        view()->share('errors', new ViewErrorBag);

        $this->branchId = $this->makeBranch();
        $this->today = app(\App\Support\TenantClock::class)->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));

        $this->bbq    = $this->makeCategory(['name' => 'Bar-B-Q', 'slug' => 'bbq-' . Str::random(4)]);
        $this->drinks = $this->makeCategory(['name' => 'Drinks', 'slug' => 'drinks-' . Str::random(4)]);
        $this->deals  = $this->makeCategory(['name' => 'Deals', 'slug' => 'deals-' . Str::random(4)]);
        $boti  = $this->makeProduct($this->bbq, ['name' => 'Chicken Boti']);
        $drink = $this->makeProduct($this->drinks, ['name' => 'Soft Drink']);
        $combo = DB::connection('tenant')->table('combos')->insertGetId([
            'branch_id' => $this->branchId, 'code' => 'CB-' . Str::random(4), 'name' => 'Family Deal',
            'category_id' => $this->deals, 'price' => 2900, 'sort_order' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Today: 2 boti (1,600) + 3 drinks (360) on one bill, a deal on another — its header sits on
        // the Boti product but belongs to Deals, and its 0-priced parts must not be counted again.
        $a = $this->makeSale($this->branchId, ['order_type' => 'dine_in', 'grand_total' => 1960]);
        $this->makeSaleLine($a, $boti, ['product_name' => 'Chicken Boti', 'quantity' => 2, 'unit_price' => 800, 'line_total' => 1600]);
        $this->makeSaleLine($a, $drink, ['product_name' => 'Soft Drink', 'quantity' => 3, 'unit_price' => 120, 'line_total' => 360]);
        $b = $this->makeSale($this->branchId, ['order_type' => 'takeaway', 'grand_total' => 2900]);
        $this->makeSaleLine($b, $boti, ['product_name' => 'Family Deal', 'combo_id' => $combo, 'line_kind' => 'combo_header', 'quantity' => 1, 'unit_price' => 2900, 'line_total' => 2900]);
        $this->makeSaleLine($b, $drink, ['product_name' => 'Soft Drink', 'combo_id' => $combo, 'line_kind' => 'component', 'quantity' => 2, 'unit_price' => 0, 'line_total' => 0]);

        // Not today's sales: a running (held) bill, and yesterday's paid bill.
        $held = $this->makeSale($this->branchId, ['status' => 'held', 'grand_total' => 5000]);
        $this->makeSaleLine($held, $boti, ['quantity' => 5, 'unit_price' => 1000, 'line_total' => 5000]);
        $old = $this->makeSale($this->branchId, ['business_date' => date('Y-m-d', strtotime($this->today . ' -1 day')), 'grand_total' => 7000]);
        $this->makeSaleLine($old, $drink, ['quantity' => 7, 'unit_price' => 1000, 'line_total' => 7000]);

        $this->owner = User::on('tenant')->findOrFail($this->makeUser(['employee_code' => 'OW' . Str::random(4)]));
        $this->bindRestaurantTenant();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant');
        $this->dropTestTenants();
        parent::tearDown();
    }

    /** The page layout reads the bound tenant's plan, as IdentifyTenant binds it. Cleaned after. */
    private function bindRestaurantTenant(): void
    {
        $this->dropTestTenants();
        $plan = \App\Models\Master\Plan::create(['code' => 'dashcat-' . uniqid(), 'name' => 'Dashboard Category Test', 'price' => 0, 'is_active' => true]);
        foreach (['pos', 'restaurant', 'reports'] as $key) {
            $module = \App\Models\Master\Module::firstOrCreate(['key' => $key], [
                'name' => ucfirst($key), 'category' => 'Test', 'description' => $key,
                'route_module_keys' => ['tenant.' . $key], 'sort_order' => 900, 'is_core' => false, 'is_active' => true,
            ]);
            \App\Models\Master\PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);
        }
        $tenant = \App\Models\Master\Tenant::create(['tenant_code' => 'dashcat' . Str::lower(Str::random(6)), 'business_name' => 'Dashboard Category Test', 'status' => 'active']);
        \App\Models\Master\Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
        app()->instance('tenant', $tenant->fresh());
    }

    private function dropTestTenants(): void
    {
        $m = DB::connection('master');
        $ids = $m->table('tenants')->where('tenant_code', 'like', 'dashcat%')->pluck('id');
        $m->table('subscriptions')->whereIn('tenant_id', $ids)->delete();
        $m->table('tenants')->whereIn('id', $ids)->delete();
        $planIds = $m->table('plans')->where('code', 'like', 'dashcat-%')->pluck('id');
        $m->table('plan_modules')->whereIn('plan_id', $planIds)->delete();
        $m->table('plans')->whereIn('id', $planIds)->delete();
    }

    private function dashboard(?User $user = null, array $query = [])
    {
        $user ??= $this->owner;
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');
        $request = Request::create('/dashboard', 'GET', $query);
        app()->instance('request', $request);

        return app(DashboardController::class)->__invoke($request, app(SalesReportService::class), app(InventoryReportService::class));
    }

    private function grantDetails(User $user): User
    {
        Permission::findOrCreate('tenant.dashboard.details', 'tenant');
        $user->givePermissionTo('tenant.dashboard.details');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** name => [qty, sales] */
    private function rows(array $categories): array
    {
        return collect($categories)->mapWithKeys(fn ($r) => [$r['name'] => [(float) $r['net_qty'], (float) $r['net_value']]])->all();
    }

    public function test_every_category_sold_today_biggest_first_with_deals_under_their_own_head(): void
    {
        $view = $this->dashboard($this->grantDetails($this->owner));

        $this->assertSame([
            'Deals'   => [1.0, 2900.0],
            'Bar-B-Q' => [2.0, 1600.0],
            'Drinks'  => [3.0, 360.0],
        ], $this->rows($view->getData()['categoriesToday']),
            'held and yesterday\'s bills are out; the deal is under Deals; its free parts add no drinks');

        $html = $view->render();
        $this->assertStringContainsString('Sales by Category Today', $html);
        $this->assertStringContainsString('Items total', $html, 'the footer says it is items only — the Net Sales tile also carries bill-level charges');
        $this->assertMatchesRegularExpression('/id="dashboard-category-total">\s*4,860\.00/', $html);
    }

    public function test_the_card_agrees_with_the_report_centers_categories_for_today(): void
    {
        $view = $this->dashboard($this->grantDetails($this->owner));

        $engine = app(SalesReportEngine::class);
        $report = $engine->byCategory($engine->normalizeFilters(['date_from' => $this->today, 'date_to' => $this->today]));
        $fromReport = $this->rows(array_filter($report, fn ($r) => $r['net_value'] != 0 || $r['net_qty'] != 0));
        ksort($fromReport);
        $onCard = $this->rows($view->getData()['categoriesToday']);
        ksort($onCard);

        $this->assertSame($fromReport, $onCard);
    }

    public function test_a_scoped_operator_sees_only_his_order_types(): void
    {
        $takeaway = User::on('tenant')->findOrFail($this->makeUser([
            'employee_code' => 'TK' . Str::random(4), 'allowed_order_types' => json_encode(['takeaway']),
        ]));

        $view = $this->dashboard($this->grantDetails($takeaway));

        $this->assertSame(['Deals' => [1.0, 2900.0]], $this->rows($view->getData()['categoriesToday']),
            'only the takeaway bill — same limit the Top Products card and the report apply');
    }

    public function test_without_dashboard_details_nothing_is_fetched_or_shown(): void
    {
        $view = $this->dashboard();

        $this->assertSame([], $view->getData()['categoriesToday']);
        $this->assertStringNotContainsString('Sales by Category Today', $view->render());
    }
}
