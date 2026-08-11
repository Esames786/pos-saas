<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\HeldSaleController;
use App\Http\Controllers\Tenant\RestaurantTableSessionController;
use App\Http\Controllers\Tenant\SalesOrderController;
use App\Http\Controllers\Tenant\SplitBillController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\MySql\Support\TenantFixtures;

/**
 * SHIFT-POS-INTEGRATION-CLOSURE-1 (#6) — REAL application-path tests: they invoke the actual
 * controllers (via the container, with a real authenticated tenant user), NOT mirrored expressions.
 * Each test fails if the controller's shift/terminal/business-date wiring is removed.
 */
class ShiftPosIntegrationTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private ?string $originalDefaultConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        // In the real tenant runtime, tenancy makes 'tenant' the DEFAULT connection — so controller
        // `exists:branches,id` / `exists:products,id` validation resolves against the tenant DB.
        // Replicate that here (restored in tearDown so other test classes are unaffected).
        $this->originalDefaultConnection = config('database.default');
        \Illuminate\Support\Facades\DB::setDefaultConnection('tenant');
    }

    protected function tearDown(): void
    {
        if ($this->originalDefaultConnection) {
            \Illuminate\Support\Facades\DB::setDefaultConnection($this->originalDefaultConnection);
        }
        parent::tearDown();
    }

    private function actingAsTenant(): int
    {
        $userId = $this->makeUser();
        $user = User::on('tenant')->find($userId);
        $this->actingAs($user, 'tenant');   // also sets the default guard -> auth()->id() works
        Auth::shouldUse('tenant');

        return $userId;
    }

    private function openShift(int $branchId, int $terminalId, int $userId): Shift
    {
        return app(ShiftService::class)->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $userId, 0.0);
    }

    private function jsonRequest(string $method, array $body): Request
    {
        $req = Request::create('/', $method, $body);
        $req->headers->set('Accept', 'application/json');

        return $req;
    }

    // ── #1 / #6A: Open Table binds to the EXACT terminal shift ────────────────────────────────────

    public function test_open_table_binds_to_exact_terminal_shift_not_arbitrary_branch_shift(): void
    {
        $this->cleanTenant(['restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'shifts', 'terminals', 'branches']);
        $userId = $this->actingAsTenant();
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalA = $this->makeTerminal($branchId);
        $terminalB = $this->makeTerminal($branchId);
        $shiftA = $this->openShift($branchId, $terminalA, $userId);
        $shiftB = $this->openShift($branchId, $terminalB, $userId);
        $table = RestaurantTable::on('tenant')->find($this->makeTable($branchId));

        // Open the table FROM terminal B. It must bind to Shift B — never the arbitrary branch shift A.
        $resp = app()->call([app(RestaurantTableSessionController::class), 'open'], [
            'request' => $this->jsonRequest('POST', ['terminal_id' => $terminalB, 'guest_count' => 2]),
            'restaurantTable' => $table,
        ]);
        $this->assertSame(200, $resp->getStatusCode(), 'Open Table should succeed: ' . $resp->getContent());

        $session = \App\Models\Tenant\RestaurantTableSession::on('tenant')->where('restaurant_table_id', $table->id)->first();
        $this->assertNotNull($session);
        $this->assertSame($shiftB->id, (int) $session->opened_shift_id, 'Table must bind to the EXACT opening terminal shift (B), not branch shift A.');
        $this->assertNotSame($shiftA->id, (int) $session->opened_shift_id);
        $this->assertSame($shiftB->business_date->toDateString(), $session->business_date->toDateString());
    }

    public function test_open_table_rejects_terminal_from_another_branch(): void
    {
        $this->cleanTenant(['restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'shifts', 'terminals', 'branches']);
        $userId = $this->actingAsTenant();
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $otherBranchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $foreignTerminal = $this->makeTerminal($otherBranchId);
        $this->openShift($otherBranchId, $foreignTerminal, $userId);
        $table = RestaurantTable::on('tenant')->find($this->makeTable($branchId));

        // The guarantee is "rejected with 422, and no session created". UserDataScope::assertPosSelection
        // rejects by abort()ing (the HTTP kernel turns that into the same 422 the sibling paths return
        // directly), so accept either shape rather than pinning one rejection mechanism.
        $status = null;
        try {
            $resp = app()->call([app(RestaurantTableSessionController::class), 'open'], [
                'request' => $this->jsonRequest('POST', ['terminal_id' => $foreignTerminal, 'guest_count' => 2]),
                'restaurantTable' => $table,
            ]);
            $status = $resp->getStatusCode();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $status = $e->getStatusCode();
        }
        $this->assertSame(422, $status, 'A terminal from another branch must be rejected.');
        $this->assertSame(0, \App\Models\Tenant\RestaurantTableSession::on('tenant')->where('restaurant_table_id', $table->id)->count());
    }

    public function test_open_table_without_open_shift_is_rejected(): void
    {
        $this->cleanTenant(['restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'shifts', 'terminals', 'branches']);
        $this->actingAsTenant();
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminal = $this->makeTerminal($branchId); // no shift opened
        $table = RestaurantTable::on('tenant')->find($this->makeTable($branchId));

        $resp = app()->call([app(RestaurantTableSessionController::class), 'open'], [
            'request' => $this->jsonRequest('POST', ['terminal_id' => $terminal, 'guest_count' => 2]),
            'restaurantTable' => $table,
        ]);
        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame(0, \App\Models\Tenant\RestaurantTableSession::on('tenant')->where('restaurant_table_id', $table->id)->count());
    }

    // ── #6C: Direct Pay through the REAL SalesOrderController ─────────────────────────────────────

    public function test_direct_pay_after_midnight_keeps_shift_business_date_real_path(): void
    {
        $this->cleanTenant(['sale_payments', 'sales_order_lines', 'sales_orders', 'products', 'categories', 'payment_methods', 'shifts', 'terminals', 'branches']);
        $userId = $this->actingAsTenant();
        // allow_negative_stock so inventory posting never blocks the sale in a bare test DB.
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi', 'allow_negative_stock' => 1]);
        $terminalId = $this->makeTerminal($branchId);
        $productId = $this->makeProduct($this->makeCategory(), ['is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active']);
        $pmId = $this->makePaymentMethod();

        // Shift opened on 5 Aug (Karachi); pay after midnight (now = 6 Aug).
        Carbon::setTestNow(Carbon::parse('2026-08-05 15:00:00', 'UTC'));
        $shift = $this->openShift($branchId, $terminalId, $userId);

        try {
            Carbon::setTestNow(Carbon::parse('2026-08-05 20:30:00', 'UTC')); // 01:30 Karachi, 6 Aug
            $resp = app()->call([app(SalesOrderController::class), 'store'], [
                'request' => $this->jsonRequest('POST', [
                    'branch_id' => $branchId, 'terminal_id' => $terminalId, 'order_type' => 'quick_sale',
                    'order_source' => 'pos', 'discount_type' => 'none',
                    'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 100]],
                    'payments' => [['payment_method_id' => $pmId, 'amount' => 100]],
                ]),
            ]);
            $data = json_decode($resp->getContent(), true);
            $this->assertArrayHasKey('sale_id', $data ?? [], 'Direct Pay should return a sale: ' . $resp->getContent());

            $sale = SalesOrder::on('tenant')->find($data['sale_id']);
            $this->assertSame('paid', $sale->status, 'A committed direct-pay sale is paid (proves draft never persists).');
            $this->assertSame($shift->id, (int) $sale->shift_id);
            $this->assertSame('2026-08-05', $sale->business_date->toDateString(), 'Direct Pay after midnight keeps the open shift business date.');
        } finally {
            Carbon::setTestNow();
        }
    }

    // ── #6B: Hold / Add Round through the REAL HeldSaleController ─────────────────────────────────

    public function test_add_round_after_midnight_keeps_original_business_date_real_path(): void
    {
        $this->cleanTenant(['sales_order_lines', 'sales_orders', 'products', 'categories', 'shifts', 'terminals', 'branches']);
        $userId = $this->actingAsTenant();
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);
        $productId = $this->makeProduct($this->makeCategory());

        Carbon::setTestNow(Carbon::parse('2026-08-05 15:00:00', 'UTC'));
        $this->openShift($branchId, $terminalId, $userId);

        try {
            // First hold (business day 5 Aug).
            $resp1 = app()->call([app(HeldSaleController::class), 'store'], [
                'request' => $this->jsonRequest('POST', [
                    'branch_id' => $branchId, 'terminal_id' => $terminalId, 'order_type' => 'quick_sale',
                    'discount_type' => 'none',
                    'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 50]],
                ]),
            ]);
            $held = json_decode($resp1->getContent(), true);
            $this->assertArrayHasKey('sale_id', $held ?? [], 'Hold should return a sale: ' . $resp1->getContent());
            $heldId = $held['sale_id'];

            // Add Round AFTER midnight (now = 6 Aug) — must keep the original 5 Aug business date.
            Carbon::setTestNow(Carbon::parse('2026-08-05 20:30:00', 'UTC'));
            $resp2 = app()->call([app(HeldSaleController::class), 'store'], [
                'request' => $this->jsonRequest('POST', [
                    'held_sale_id' => $heldId, 'branch_id' => $branchId, 'terminal_id' => $terminalId,
                    'order_type' => 'quick_sale', 'discount_type' => 'none',
                    'lines' => [['product_id' => $productId, 'quantity' => 2, 'unit_price' => 50]],
                ]),
            ]);
            $this->assertSame(200, $resp2->getStatusCode(), 'Add Round should succeed: ' . $resp2->getContent());

            $sale = SalesOrder::on('tenant')->find($heldId);
            $this->assertSame('2026-08-05', $sale->business_date->toDateString(), 'Add Round after midnight keeps the original business date.');
        } finally {
            Carbon::setTestNow();
        }
    }

    // ── #6D: Split Bill through the REAL SplitBillController ──────────────────────────────────────

    public function test_split_child_inherits_parent_shift_and_business_date_real_path(): void
    {
        $this->cleanTenant(['sales_order_lines', 'sales_orders', 'products', 'categories', 'shifts', 'terminals', 'branches']);
        $userId = $this->actingAsTenant();
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);
        $productId = $this->makeProduct($this->makeCategory());
        $shift = $this->openShift($branchId, $terminalId, $userId);

        // Build a held parent (2 lines) via the real Hold path.
        $resp1 = app()->call([app(HeldSaleController::class), 'store'], [
            'request' => $this->jsonRequest('POST', [
                'branch_id' => $branchId, 'terminal_id' => $terminalId, 'order_type' => 'quick_sale',
                'discount_type' => 'none',
                'lines' => [
                    ['product_id' => $productId, 'quantity' => 1, 'unit_price' => 30, 'client_line_key' => 'a'],
                    ['product_id' => $productId, 'quantity' => 1, 'unit_price' => 70, 'client_line_key' => 'b'],
                ],
            ]),
        ]);
        $parent = SalesOrder::on('tenant')->find(json_decode($resp1->getContent(), true)['sale_id']);
        $lineToSplit = $parent->lines()->first();

        // Split AFTER midnight — the child must inherit the PARENT's shift + business date.
        Carbon::setTestNow(Carbon::parse('2026-08-05 20:30:00', 'UTC'));
        try {
            $resp2 = app()->call([app(SplitBillController::class), 'store'], [
                'request' => $this->jsonRequest('POST', [
                    'lines' => [['sales_order_line_id' => $lineToSplit->id, 'quantity' => 1]],
                ]),
                'salesOrder' => $parent,
            ]);
            $this->assertContains($resp2->getStatusCode(), [200, 302], 'Split should succeed: ' . $resp2->getContent());

            $child = SalesOrder::on('tenant')->where('id', '!=', $parent->id)->where('status', 'held')->latest('id')->first();
            $this->assertNotNull($child, 'Split must create a held child.');
            $this->assertSame($shift->id, (int) $child->shift_id, 'Child inherits the parent shift.');
            $this->assertSame($parent->business_date->toDateString(), $child->business_date->toDateString(), 'Child inherits the parent business date (never "now").');
        } finally {
            Carbon::setTestNow();
        }
    }
}
