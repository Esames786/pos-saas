<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\HeldSaleController;
use App\Http\Controllers\Tenant\SalesOrderController;
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
 * SALE-DATE-TRUTH-1 — order ka waqt order ka rehta hai, payment ka nahi banta.
 *
 * `sale_date` do jagah likha jaata tha: order bante waqt, AUR phir dobara jab held order recall ho
 * kar pay hota. Doosri likhaai order ka apna waqt mita deti thi — receipt par "Date:" wo lamha
 * dikhata jab paisa diya gaya, na ke jab khana manga gaya. Jitni der table baithi, utna bara farq:
 * Kashif ke 71% aur Khatri ke 50% orders par ye ho chuka hai.
 *
 * Hisab par asar NAHI para (waqt khisakta hai, din nahi — 9,261 me se ek order ka din badla), is
 * liye ye report ya GL ka nahi, sirf us ek line ka masla hai jo customer parhta hai.
 *
 * Ye guard ASLI controllers chalata hai — mirror kiya hua query nahi, warna wiring badalne par
 * chup rehta.
 */
class SaleDateTruthMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $userId;
    private int $branchId;
    private int $terminalId;
    private int $productId;
    private int $pmId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'kot_batch_lines', 'kot_batches', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'shifts', 'terminals', 'products', 'categories', 'branches', 'users',
        ]);

        $this->userId = $this->makeUser();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');

        $this->branchId   = $this->makeBranch(['timezone' => 'Asia/Karachi', 'allow_negative_stock' => 1]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId  = $this->makeProduct($this->makeCategory(), ['is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active']);
        $this->pmId       = $this->makePaymentMethod();

        app(ShiftService::class)->open(
            Branch::on('tenant')->find($this->branchId),
            Terminal::on('tenant')->find($this->terminalId),
            $this->userId,
            0.0,
        );
    }

    private function req(array $body): Request
    {
        $r = Request::create('/', 'POST', $body);
        $r->headers->set('Accept', 'application/json');

        return $r;
    }

    private function hold(): SalesOrder
    {
        $res = app()->call([app(HeldSaleController::class), 'store'], ['request' => $this->req([
            'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId,
            'order_type' => 'takeaway', 'discount_type' => 'none',
            'lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 500]],
        ])]);
        $this->assertContains($res->getStatusCode(), [200, 201], 'hold chalna chahiye: ' . $res->getContent());

        return SalesOrder::on('tenant')->where('status', 'held')->latest('id')->firstOrFail();
    }

    private function pay(SalesOrder $held): SalesOrder
    {
        $res = app()->call([app(SalesOrderController::class), 'store'], ['request' => $this->req([
            'held_sale_id' => $held->id,
            'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId,
            'order_type' => 'takeaway', 'discount_type' => 'none',
            'lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 500]],
            'payments' => [['payment_method_id' => $this->pmId, 'amount' => 500]],
        ])]);
        $this->assertSame(200, $res->getStatusCode(), 'payment chalni chahiye: ' . $res->getContent());

        return $held->fresh();
    }

    /** ASAL KEERA: 30 minute baad paisa dene se order ka waqt nahi hilna chahiye. */
    public function test_paying_a_held_order_does_not_move_its_order_time(): void
    {
        $held  = $this->hold();
        $punch = $held->sale_date->copy();

        $this->travel(30)->minutes();
        $paid = $this->pay($held);

        $this->assertSame(
            $punch->toDateTimeString(),
            $paid->sale_date->toDateTimeString(),
            'order ka waqt wohi rehna chahiye jab khana manga gaya tha',
        );
    }

    /** Payment ka waqt kho nahi raha — wo completed_at me apni jagah par hai. */
    public function test_the_payment_moment_is_still_recorded_separately(): void
    {
        $held  = $this->hold();
        $punch = $held->sale_date->copy();

        $this->travel(30)->minutes();
        $paid = $this->pay($held);

        $this->assertNotNull($paid->completed_at, 'payment ka waqt kahin to likha hona chahiye');
        $this->assertGreaterThanOrEqual(
            29 * 60,
            abs($paid->completed_at->diffInSeconds($punch)),
            'completed_at payment ka lamha hai, order ka nahi — dono ka farq nazar aana chahiye',
        );
    }

    /** Naye (seedhe pay hone wale) order par waqt lagta zaroor hai — hum ne use hataya nahi. */
    public function test_a_direct_pay_sale_still_gets_its_order_time(): void
    {
        $res = app()->call([app(SalesOrderController::class), 'store'], ['request' => $this->req([
            'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId,
            'order_type' => 'takeaway', 'discount_type' => 'none',
            'lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 500]],
            'payments' => [['payment_method_id' => $this->pmId, 'amount' => 500]],
        ])]);
        $this->assertSame(200, $res->getStatusCode(), 'direct pay chalni chahiye: ' . $res->getContent());

        $sale = SalesOrder::on('tenant')->latest('id')->firstOrFail();
        $this->assertNotNull($sale->sale_date, 'naye order par waqt zaroor lagna chahiye');
        $this->assertLessThan(120, abs($sale->sale_date->diffInSeconds(now())), 'aur wo abhi ka hona chahiye');
    }
}
