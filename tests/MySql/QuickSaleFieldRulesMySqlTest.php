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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\TenantFixtures;

/**
 * PHASE 2b — Quick Sale field rules. A quick sale (drive-through) must carry BOTH a vehicle number
 * and a waiter; takeaway no longer captures a vehicle at all; and the held-list payload exposes the
 * waiter + vehicle so the POS can display and re-hydrate them.
 */
class QuickSaleFieldRulesMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $terminalId;

    private int $productId;

    private int $userId;

    private int $waiterId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_order_lines', 'sales_orders', 'restaurant_waiters', 'shifts', 'terminals',
            'products', 'categories', 'branches', 'users',
        ]);
        $this->userId = $this->makeUser(['employee_code' => 'QS' . Str::random(4)]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->branchId = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['default_selling_price' => 100]);
        $this->waiterId = $this->makeWaiter($this->branchId);
        app(ShiftService::class)->open(
            Branch::on('tenant')->find($this->branchId),
            Terminal::on('tenant')->find($this->terminalId),
            $this->userId,
            0.0,
        );
    }

    private function holdRequest(array $body): Request
    {
        $request = Request::create('/held-sales', 'POST', array_merge([
            'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId, 'discount_type' => 'none',
            'lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 100]],
        ], $body));
        $request->headers->set('Accept', 'application/json');

        return $request;
    }

    private function hold(array $body): SalesOrder
    {
        $response = app()->call([app(HeldSaleController::class), 'store'], ['request' => $this->holdRequest($body)]);
        $this->assertContains($response->getStatusCode(), [200, 201], 'hold should succeed: ' . $response->getContent());

        return SalesOrder::on('tenant')->where('status', 'held')->latest('id')->firstOrFail();
    }

    public function test_quick_sale_hold_requires_both_vehicle_and_waiter(): void
    {
        try {
            app()->call([app(HeldSaleController::class), 'store'], [
                'request' => $this->holdRequest(['order_type' => 'quick_sale']),   // no vehicle, no waiter
            ]);
            $this->fail('a quick sale without a vehicle + waiter must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('vehicle_number', $e->errors());
            $this->assertArrayHasKey('restaurant_waiter_id', $e->errors());
        }
    }

    public function test_quick_sale_hold_persists_vehicle_and_waiter(): void
    {
        $sale = $this->hold([
            'order_type' => 'quick_sale', 'vehicle_number' => 'LEA-9', 'restaurant_waiter_id' => $this->waiterId,
        ]);

        $this->assertSame('LEA-9', $sale->vehicle_number);
        $this->assertSame($this->waiterId, (int) $sale->restaurant_waiter_id, 'the posted quick-sale waiter is stored');
    }

    public function test_takeaway_hold_never_stores_a_vehicle(): void
    {
        $sale = $this->hold(['order_type' => 'takeaway', 'vehicle_number' => 'LEA-9']);

        $this->assertNull($sale->vehicle_number, 'takeaway no longer captures a vehicle');
        $this->assertNull($sale->restaurant_waiter_id, 'takeaway carries no waiter');
    }

    public function test_held_list_payload_exposes_waiter_and_vehicle(): void
    {
        $this->hold([
            'order_type' => 'quick_sale', 'vehicle_number' => 'LEA-9', 'restaurant_waiter_id' => $this->waiterId,
        ]);

        $response = app()->call([app(HeldSaleController::class), 'ajaxList'], [
            'request' => Request::create('/api/pos/held-sales', 'GET', ['branch_id' => $this->branchId]),
        ]);
        $row = $response->getData(true)['sales'][0];

        $this->assertSame('LEA-9', $row['vehicle_number']);
        $this->assertSame($this->waiterId, (int) $row['restaurant_waiter_id']);
        $this->assertArrayHasKey('waiter', $row, 'the waiter name is exposed for the list + recall');
    }
}
