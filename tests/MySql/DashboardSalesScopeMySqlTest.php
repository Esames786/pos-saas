<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\SalesOrderController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Services\Reports\SalesReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * USER-DATA-SCOPE-1 (dashboard + sales-order filter) — a terminal/order-type restricted operator's
 * DASHBOARD numbers and Sales Orders "Type" filter are limited to what he is assigned; an
 * unrestricted user (all order types) sees everything. Plus: the POS vehicle field is Quick-Sale-only.
 */
class DashboardSalesScopeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private User $takeawayUser;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['sales_order_lines', 'sales_orders', 'terminal_user', 'branch_user', 'terminals', 'branches', 'users']);

        $this->branchId = $this->makeBranch();

        $this->takeawayUser = User::on('tenant')->findOrFail($this->makeUser([
            'email' => 'takeaway@example.test', 'employee_code' => 'TK'.Str::random(4),
            'default_branch_id' => $this->branchId, 'allowed_order_types' => json_encode(['takeaway']),
        ]));
        $this->owner = User::on('tenant')->findOrFail($this->makeUser([
            'email' => 'owner@example.test', 'employee_code' => 'OW'.Str::random(4),
            'default_branch_id' => $this->branchId,
        ]));

        // Paid sales for TODAY (business date in the branch's clock): 2 takeaway + 1 dine-in.
        $bizDate = app(\App\Support\TenantClock::class)->currentBusinessDate(Branch::on('tenant')->find($this->branchId));
        $this->makeSale($this->branchId, ['sale_no' => 'TK-1', 'order_type' => 'takeaway', 'business_date' => $bizDate, 'grand_total' => 100]);
        $this->makeSale($this->branchId, ['sale_no' => 'TK-2', 'order_type' => 'takeaway', 'business_date' => $bizDate, 'grand_total' => 100]);
        $this->makeSale($this->branchId, ['sale_no' => 'DIN-1', 'order_type' => 'dine_in', 'business_date' => $bizDate, 'grand_total' => 200]);
    }

    public function test_today_stats_are_scoped_to_the_users_order_type(): void
    {
        $svc = app(SalesReportService::class);

        $mine = $svc->todayStats($this->branchId, $this->takeawayUser);
        $this->assertSame(2, $mine['order_count'], 'takeaway user counts only takeaway orders');
        $this->assertSame(200.0, (float) $mine['net_sales'], 'net = 2 x 100 takeaway only');

        $all = $svc->todayStats($this->branchId, $this->owner);
        $this->assertSame(3, $all['order_count'], 'owner sees all three');
        $this->assertSame(400.0, (float) $all['net_sales'], 'net = 200 takeaway + 200 dine-in');

        // No user passed = unrestricted (backward compatible).
        $this->assertSame(3, $svc->todayStats($this->branchId)['order_count']);
    }

    public function test_sales_orders_index_offers_only_the_users_order_types(): void
    {
        // scoped takeaway user
        $this->actingAs($this->takeawayUser, 'tenant');
        Auth::shouldUse('tenant');
        $view = app(SalesOrderController::class)->index(Request::create('/sales-orders', 'GET'));
        $data = $view->getData();
        $this->assertSame(['takeaway'], $data['orderTypes'], 'dropdown limited to his one type');
        $this->assertSame(['TK-1', 'TK-2'], $data['orders']->pluck('sale_no')->sort()->values()->all(), 'list scoped to takeaway');

        // unrestricted owner sees all four types + all rows
        $this->actingAs($this->owner, 'tenant');
        Auth::shouldUse('tenant');
        $ownerData = app(SalesOrderController::class)->index(Request::create('/sales-orders', 'GET'))->getData();
        $this->assertSame(['dine_in', 'takeaway', 'quick_sale', 'delivery'], $ownerData['orderTypes']);
        $this->assertSame(3, $ownerData['orders']->total());
    }

    public function test_a_scoped_user_cannot_widen_the_type_filter_by_hand(): void
    {
        $this->actingAs($this->takeawayUser, 'tenant');
        Auth::shouldUse('tenant');
        // hand-edited ?order_type=dine_in must be ignored (still only his takeaway rows)
        $data = app(SalesOrderController::class)->index(Request::create('/sales-orders', 'GET', ['order_type' => 'dine_in']))->getData();
        $this->assertSame(['TK-1', 'TK-2'], $data['orders']->pluck('sale_no')->sort()->values()->all());
    }

    public function test_pos_vehicle_input_is_quick_sale_only(): void
    {
        $src = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));
        $this->assertStringContainsString("const isVehicleType = orderTypeEl.value === 'quick_sale';", $src);
        $this->assertStringNotContainsString("orderTypeEl.value === 'quick_sale' || orderTypeEl.value === 'takeaway'", $src, 'takeaway must no longer show the vehicle field');
    }
}
