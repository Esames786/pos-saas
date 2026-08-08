<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\SalesOrderController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-POS-1 (#12) — the LOCKED Cloud cash-semantics reference, proven through the REAL Cloud POS
 * business path (APP_ROLE=cloud, real SalesOrderController::store, real ShiftService close assertion):
 *
 *   grand_total = 100, physical cash tendered = 500
 *   → payment.amount        = 100  (amount APPLIED to the invoice)
 *   → payment.tendered      = 500  (physical cash handed over)
 *   → payment.change_amount = 400  (change returned from the drawer)
 *   → sale.paid_amount      = 100, sale.change_amount = 0
 *   → shift total_sales/expected_cash/total_cash += 100 (the APPLIED amount — never the tendered 500)
 *   → the real close calculation uses expected_cash = 100 (counted 100 ⇒ variance 0).
 *
 * The Edge settlement reuses exactly these semantics via the shared SaleOperationalSettlementService.
 */
class SaleCashSemanticsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private ?string $originalDefaultConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = config('database.default');
        DB::setDefaultConnection('tenant'); // Cloud tenant runtime resolves validation against the tenant DB
    }

    protected function tearDown(): void
    {
        if ($this->originalDefaultConnection) {
            DB::setDefaultConnection($this->originalDefaultConnection);
        }
        parent::tearDown();
    }

    public function test_cloud_100_total_500_tendered_reference_case_and_close_uses_expected_cash(): void
    {
        $this->assertFalse(\App\Support\EdgeRuntime::isBranchServer(), 'this is the CLOUD reference path');
        $this->cleanTenant(['sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $userId = $this->makeUser();
        $user = User::on('tenant')->find($userId);
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi', 'allow_negative_stock' => 1]);
        $terminalId = $this->makeTerminal($branchId);
        $productId = $this->makeProduct($this->makeCategory(), ['is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $pmId = $this->makePaymentMethod(['method_type' => 'cash']);
        $shift = app(ShiftService::class)->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $userId, 0.0);

        // REAL Cloud Direct Pay: 1 × 100, customer hands over 500 cash.
        $req = Request::create('/', 'POST', [
            'branch_id' => $branchId, 'terminal_id' => $terminalId, 'order_type' => 'quick_sale',
            'order_source' => 'pos', 'discount_type' => 'none',
            'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 100]],
            'payments' => [['payment_method_id' => $pmId, 'amount' => 100, 'tendered_amount' => 500]],
        ]);
        $req->headers->set('Accept', 'application/json');
        $resp = app()->call([app(SalesOrderController::class), 'store'], ['request' => $req]);
        $data = json_decode($resp->getContent(), true);
        $this->assertArrayHasKey('sale_id', $data ?? [], 'Cloud Direct Pay must succeed: ' . $resp->getContent());

        $sale = SalesOrder::on('tenant')->find($data['sale_id']);
        $payment = $sale->payments()->first();

        // The locked reference values — from the REAL business path, not inserted rows.
        $this->assertSame(100.0, (float) $payment->amount, 'payment.amount = amount APPLIED to the invoice');
        $this->assertSame(500.0, (float) $payment->tendered_amount, 'tendered_amount = physical cash handed over');
        $this->assertSame(400.0, (float) $payment->change_amount, 'payment change = tendered − applied');
        $this->assertSame(100.0, (float) $sale->paid_amount, 'sale.paid_amount = Σ applied amounts');
        $this->assertSame(0.0, (float) $sale->change_amount, 'sale-level change 0 (applied == grand_total)');
        $this->assertSame(100.0, (float) $sale->grand_total);

        $shift->refresh();
        $this->assertSame(100.0, (float) $shift->total_sales, 'shift total_sales += 100');
        $this->assertSame(100.0, (float) $shift->expected_cash, 'expected_cash += APPLIED 100 — never tendered 500');
        $this->assertSame(100.0, (float) $shift->total_cash, 'total_cash += APPLIED 100');

        // REAL close: the SHARED ShiftService::closeShift (used by BOTH Cloud ShiftController and the
        // Edge shift/close endpoint) — counted 100 against expected_cash.
        app(ShiftService::class)->closeShift($shift, $userId, 100.0);
        $shift->refresh();
        $this->assertSame('closed', $shift->status);
        $this->assertSame($userId, (int) $shift->closed_by_user_id);
        $this->assertSame(100.0, (float) $shift->counted_cash);
        $this->assertSame(0.0, (float) $shift->cash_variance, 'close reconciles against expected_cash=100 (variance 0) — NOT tendered 500');
    }
}
