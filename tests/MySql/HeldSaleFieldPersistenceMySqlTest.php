<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\HeldSaleController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * HELD-SALE-FIELD-PERSISTENCE — the Hold write-path must persist the same order-linked fields the
 * Review & Pay path does, so a recalled held order comes back WHOLE. This is a regression guard for the
 * bug where new order fields (delivery charge, vehicle number) were wired into Bill Preview + Review &
 * Pay but the Hold path was missed — the charge silently dropped to 0 and the recall showed the wrong
 * total.
 *
 *   • a delivery order's delivery charge is saved and folded into grand_total (was 0),
 *   • a LOCKED branch stores its own default charge, never the client's number,
 *   • a TAKEAWAY order keeps its vehicle number (the save gate was quick_sale-only),
 *   • a DELIVERY order never stores a vehicle number,
 *   • the recall list (ajaxList) re-exposes delivery_charge_amount so recall restores it.
 *
 * NOTE on discounts: a held order may NOT carry a manual discount by design (store() rejects it —
 * discounts need manager approval at PAYMENT, not while holding). So there is deliberately nothing to
 * persist/restore for discount at hold time; that path is asserted here to stay a hard refusal.
 */
class HeldSaleFieldPersistenceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_order_lines', 'sales_orders', 'shifts', 'terminals',
            'customer_addresses', 'customers', 'delivery_channels',
            'products', 'categories', 'branches', 'users',
        ]);
        $this->userId = $this->makeUser(['employee_code' => 'HS' . \Illuminate\Support\Str::random(4)]);
        $user = User::on('tenant')->find($this->userId);
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');
    }

    /** An active aggregator channel (no rider required) for delivery holds. */
    private function aggregatorChannel(): int
    {
        return \App\Models\Tenant\DeliveryChannel::create([
            'name' => 'Foodpanda', 'type' => 'aggregator', 'is_active' => true,
        ])->id;
    }

    /** Drive the REAL controller store() with an open shift, returning the created held sale. */
    private function hold(int $branchId, array $body): SalesOrder
    {
        $terminalId = $this->makeTerminal($branchId);
        app(ShiftService::class)->open(
            Branch::on('tenant')->find($branchId),
            Terminal::on('tenant')->find($terminalId),
            $this->userId,
            0.0
        );

        $request = Request::create('/held-sales', 'POST', array_merge([
            'branch_id'     => $branchId,
            'terminal_id'   => $terminalId,
            'discount_type' => 'none',
        ], $body));
        $request->headers->set('Accept', 'application/json');

        $response = app()->call([app(HeldSaleController::class), 'store'], ['request' => $request]);
        $this->assertContains(
            $response->getStatusCode(),
            [200, 201],
            'hold should succeed: ' . $response->getContent()
        );

        return SalesOrder::on('tenant')->where('status', 'held')->latest('id')->firstOrFail();
    }

    public function test_delivery_charge_is_saved_and_folded_into_grand_total_on_hold(): void
    {
        $branchId  = $this->makeBranch(['default_delivery_charge' => 0, 'delivery_charge_locked' => 0]);
        $productId = $this->makeProduct($this->makeCategory(), ['default_selling_price' => 450]);
        $customer  = \App\Models\Tenant\Customer::create(['name' => 'Recall Tester', 'phone' => '0300-0000001', 'status' => 'active']);

        $sale = $this->hold($branchId, [
            'order_type'             => 'delivery',
            'delivery_charge_amount' => 50,
            'customer_id'            => $customer->id,
            'delivery_channel_id'    => $this->aggregatorChannel(),
            'delivery_address'       => 'House 1, Street 2',
            'lines'                  => [['product_id' => $productId, 'quantity' => 2, 'unit_price' => 450]],
        ]);

        $this->assertSame(50.0, (float) $sale->delivery_charge_amount, 'delivery charge must be persisted, not dropped to 0');
        $this->assertSame(900.0, (float) $sale->subtotal);
        $this->assertSame(950.0, (float) $sale->grand_total, '900 merchandise + 50 delivery');

        // The recall list must re-expose the charge so recallHeldSale() can restore it to the input.
        $list = app(HeldSaleController::class)->ajaxList(Request::create('/held-sales/list', 'GET'))->getData(true);
        $row = collect($list['sales'])->firstWhere('id', $sale->id);
        $this->assertNotNull($row, 'held sale must appear in the recall list');
        $this->assertSame(50.0, (float) $row['delivery_charge_amount'], 'recall list must carry the delivery charge');
    }

    public function test_locked_branch_stores_its_own_default_charge_not_client_input(): void
    {
        $branchId  = $this->makeBranch(['default_delivery_charge' => 150, 'delivery_charge_locked' => 1]);
        $productId = $this->makeProduct($this->makeCategory(), ['default_selling_price' => 500]);
        $customer  = \App\Models\Tenant\Customer::create(['name' => 'Locked Tester', 'phone' => '0300-0000002', 'status' => 'active']);

        $sale = $this->hold($branchId, [
            'order_type'             => 'delivery',
            'delivery_charge_amount' => 5, // client tries to under-charge
            'customer_id'            => $customer->id,
            'delivery_channel_id'    => $this->aggregatorChannel(),
            'delivery_address'       => 'Locked Ave',
            'lines'                  => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 500]],
        ]);

        $this->assertSame(150.0, (float) $sale->delivery_charge_amount, 'locked branch: client value ignored, default applied');
        $this->assertSame(650.0, (float) $sale->grand_total, '500 + 150 locked default');
    }

    public function test_takeaway_keeps_vehicle_number_and_delivery_never_does(): void
    {
        $branchId  = $this->makeBranch();
        $productId = $this->makeProduct($this->makeCategory(), ['default_selling_price' => 300]);

        $takeaway = $this->hold($branchId, [
            'order_type'     => 'takeaway',
            'vehicle_number' => 'LEA-4213',
            'lines'          => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $this->assertSame('LEA-4213', $takeaway->vehicle_number, 'takeaway must keep the vehicle number');

        $customer = \App\Models\Tenant\Customer::create(['name' => 'No Vehicle', 'phone' => '0300-0000003', 'status' => 'active']);
        $delivery = $this->hold($branchId, [
            'order_type'             => 'delivery',
            'vehicle_number'         => 'SHOULD-NOT-STICK',
            'delivery_charge_amount' => 0,
            'customer_id'            => $customer->id,
            'delivery_channel_id'    => $this->aggregatorChannel(),
            'delivery_address'       => 'Somewhere',
            'lines'                  => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $this->assertNull($delivery->vehicle_number, 'a delivery order must not store a vehicle number');
    }

    public function test_a_manual_discount_is_refused_at_hold_time(): void
    {
        $branchId  = $this->makeBranch();
        $productId = $this->makeProduct($this->makeCategory(), ['default_selling_price' => 300]);
        $terminalId = $this->makeTerminal($branchId);
        app(ShiftService::class)->open(
            Branch::on('tenant')->find($branchId),
            Terminal::on('tenant')->find($terminalId),
            $this->userId,
            0.0
        );

        $request = Request::create('/held-sales', 'POST', [
            'branch_id'      => $branchId,
            'terminal_id'    => $terminalId,
            'order_type'     => 'quick_sale',
            'discount_type'  => 'fixed',
            'discount_value' => 50,
            'lines'          => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $request->headers->set('Accept', 'application/json');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app()->call([app(HeldSaleController::class), 'store'], ['request' => $request]);
    }
}
