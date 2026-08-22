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
 * POS-DRAFT-1 — a held sale can be parked as a DRAFT. It is saved exactly like any held sale
 * (status 'held', recallable, editable) and only differs in that the KOT is not sent (a frontend
 * decision keyed on the same is_draft flag). The server contract this guards:
 *   • save_as_draft=1 persists status='held' + is_draft=true (NOT the pay-flow status='draft'),
 *   • the recall list (ajaxList) re-exposes is_draft so a recalled draft keeps its badge,
 *   • saving the same order again as a normal Hold clears is_draft (then the KOT prints),
 *   • a plain Hold is is_draft=false.
 * The "KOT is skipped" behaviour lives in the POS JS (submitHeldSale skips handleKotAfterSale when
 * asDraft); the KOT is never enqueued server-side on hold, so there is nothing to assert there.
 */
class PosDraftMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_order_lines', 'sales_orders', 'shifts', 'terminals',
            'products', 'categories', 'branches', 'users',
        ]);
        $this->userId = $this->makeUser(['employee_code' => 'PD'.\Illuminate\Support\Str::random(4)]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    /** Drive the REAL store() with an open shift; returns the created/updated held sale. */
    private function hold(int $branchId, int $terminalId, array $body): SalesOrder
    {
        $request = Request::create('/held-sales', 'POST', array_merge([
            'branch_id' => $branchId,
            'terminal_id' => $terminalId,
            'discount_type' => 'none',
        ], $body));
        $request->headers->set('Accept', 'application/json');

        $response = app()->call([app(HeldSaleController::class), 'store'], ['request' => $request]);
        $this->assertContains($response->getStatusCode(), [200, 201], 'hold should succeed: '.$response->getContent());

        return SalesOrder::on('tenant')->latest('id')->firstOrFail();
    }

    private function openShift(int $branchId): int
    {
        $terminalId = $this->makeTerminal($branchId);
        app(ShiftService::class)->open(
            Branch::on('tenant')->find($branchId),
            Terminal::on('tenant')->find($terminalId),
            $this->userId,
            0.0
        );

        return $terminalId;
    }

    public function test_save_as_draft_parks_a_held_sale_flagged_is_draft_and_recall_carries_it(): void
    {
        $branchId = $this->makeBranch();
        $terminalId = $this->openShift($branchId);
        $productId = $this->makeProduct($this->makeCategory(), ['default_selling_price' => 300]);

        $sale = $this->hold($branchId, $terminalId, [
            'order_type' => 'takeaway',
            'save_as_draft' => 1,
            'lines' => [['product_id' => $productId, 'quantity' => 2, 'unit_price' => 300]],
        ]);

        // A draft is a normal held sale, NOT the pay-flow 'draft' status.
        $this->assertSame('held', $sale->status, 'a draft stays a held sale so recall/pay/table logic is untouched');
        $this->assertTrue((bool) $sale->is_draft, 'save_as_draft marks the order a draft');

        // The recall list re-exposes is_draft so recallHeldSale() can restore the badge.
        $list = app(HeldSaleController::class)->ajaxList(Request::create('/held-sales/list', 'GET'))->getData(true);
        $row = collect($list['sales'])->firstWhere('id', $sale->id);
        $this->assertNotNull($row, 'a draft must appear in the recall list like any held sale');
        $this->assertTrue($row['is_draft'], 'recall list must carry is_draft');
    }

    public function test_a_plain_hold_is_not_a_draft(): void
    {
        $branchId = $this->makeBranch();
        $terminalId = $this->openShift($branchId);
        $productId = $this->makeProduct($this->makeCategory(), ['default_selling_price' => 300]);

        $sale = $this->hold($branchId, $terminalId, [
            'order_type' => 'takeaway',
            'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 300]],
        ]);

        $this->assertSame('held', $sale->status);
        $this->assertFalse((bool) $sale->is_draft, 'a normal Hold is never a draft');
    }

    public function test_re_holding_a_draft_as_a_normal_hold_clears_the_draft_flag(): void
    {
        $branchId = $this->makeBranch();
        $terminalId = $this->openShift($branchId);
        $productId = $this->makeProduct($this->makeCategory(), ['default_selling_price' => 300]);

        $draft = $this->hold($branchId, $terminalId, [
            'order_type' => 'takeaway',
            'save_as_draft' => 1,
            'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $this->assertTrue((bool) $draft->is_draft);

        // Recall the same order and save it as a normal Hold (save_as_draft omitted) — the KOT would
        // now fire (frontend) and the draft flag must clear.
        $held = $this->hold($branchId, $terminalId, [
            'held_sale_id' => $draft->id,
            'order_type' => 'takeaway',
            'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 300]],
        ]);

        $this->assertSame($draft->id, $held->id, 'the same order is updated in place');
        $this->assertFalse((bool) $held->is_draft, 'a normal Hold clears the draft flag');
    }
}
