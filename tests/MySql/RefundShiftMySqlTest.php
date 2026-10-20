<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\SalesReturnController;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\Shift;
use App\Models\Tenant\User;
use App\Services\Reports\SalesReportEngine;
use App\Services\Sales\SalesReturnService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * REFUND-SHIFT-1 — a refund goes into the shift whose drawer pays it, on that shift's business day.
 *
 * Owner, 20 Oct: "return day closing mein aa raha ho". Before, a return adjusted only the ORIGINAL sale's
 * shift and only while it was open, and took the original sale's day: yesterday's bill returned today reached
 * no shift and no day closing (today's drawer looked short) and changed a closed day's report.
 *
 * Proven here through SalesReturnService (the money path) and the controller's choice of counter:
 *   - yesterday's bill returned today → today's open shift (refunds, expected cash), today's day, today's
 *     report; yesterday's shift and report untouched;
 *   - a return inside the sale's own open shift → exactly as before;
 *   - after midnight on an evening shift still open → the evening's day (REPORT-BUSINESS-DATE-1 kept);
 *   - no open shift anywhere → today's day, no shift — never a closed past day.
 */
class RefundShiftMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $terminalId;

    private int $userId;

    private string $today;

    private string $yesterday;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_return_lines', 'sales_returns', 'sales_ledgers', 'sales_order_lines', 'sales_orders',
            'shifts', 'terminals', 'products', 'categories', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->userId = $this->makeUser();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->today = app(\App\Support\TenantClock::class)->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
        $this->yesterday = date('Y-m-d', strtotime($this->today . ' -1 day'));
    }

    public function test_yesterdays_bill_returned_today_goes_into_todays_shift_day_and_report(): void
    {
        $closed = $this->shift($this->yesterday, 'closed', ['total_refunds' => 0, 'expected_cash' => 300]);
        $open = $this->shift($this->today, 'open', ['expected_cash' => 1000]);
        [$sale, $line] = $this->sale($this->yesterday, $closed);

        $return = $this->returnIt($sale, $line, 1, $open);

        $this->assertSame($this->today, $return->business_date->toDateString(), 'booked on the day the cash left the drawer');
        $this->assertSame($open, (int) $return->shift_id);
        $this->assertSame($this->terminalId, (int) $return->terminal_id);

        $s = Shift::on('tenant')->find($open);
        $this->assertEquals(100, (float) $s->total_refunds, "today's shift shows the refund");
        $this->assertEquals(100, (float) $s->total_cash_refunds);
        $this->assertEquals(900, (float) $s->expected_cash, 'the drawer is expected to hold 100 less — no false shortage');
        $y = Shift::on('tenant')->find($closed);
        $this->assertEquals(0, (float) $y->total_refunds, "yesterday's closed shift is not touched");
        $this->assertEquals(300, (float) $y->expected_cash);

        $this->assertEquals(100, $this->report($this->today)['refunds_recorded'], "today's report has it");
        $this->assertEquals(0, $this->report($this->yesterday)['refunds_recorded'], "yesterday's closed day does not move");
        $this->assertEquals(100, $this->report($this->today, ['shift_id' => $open])['refunds_recorded'], 'the shift report agrees with the shift');
        $this->assertEquals(0, $this->report($this->yesterday, ['shift_id' => $closed])['refunds_recorded']);
    }

    public function test_a_return_inside_the_sales_own_open_shift_is_as_before(): void
    {
        $open = $this->shift($this->today, 'open', ['expected_cash' => 500]);
        [$sale, $line] = $this->sale($this->today, $open);

        $return = $this->returnIt($sale, $line, 2, null);   // no counter named: the sale's own open shift

        $this->assertSame($open, (int) $return->shift_id);
        $this->assertSame($this->today, $return->business_date->toDateString());
        $this->assertEquals(200, (float) Shift::on('tenant')->find($open)->total_cash_refunds);
        $this->assertEquals(300, (float) Shift::on('tenant')->find($open)->expected_cash);
    }

    public function test_after_midnight_on_an_evening_shift_the_refund_stays_on_the_evening_day(): void
    {
        $evening = $this->shift($this->yesterday, 'open');   // opened last evening, still open after midnight
        [$sale, $line] = $this->sale($this->yesterday, $evening);

        $return = $this->returnIt($sale, $line, 1, $evening);

        $this->assertSame($this->yesterday, $return->business_date->toDateString(), 'REPORT-BUSINESS-DATE-1 still holds');
        $this->assertEquals(100, (float) Shift::on('tenant')->find($evening)->total_refunds);
    }

    public function test_with_no_open_shift_the_return_books_today_never_a_closed_day(): void
    {
        $closed = $this->shift($this->yesterday, 'closed');
        [$sale, $line] = $this->sale($this->yesterday, $closed);

        $return = $this->returnIt($sale, $line, 1, null);

        $this->assertNull($return->shift_id);
        $this->assertSame($this->today, $return->business_date->toDateString());
        $this->assertEquals(0, (float) Shift::on('tenant')->find($closed)->total_refunds);
    }

    public function test_the_counter_paying_is_found_pos_counter_first_then_own_counter_then_the_only_open_shift(): void
    {
        $other = $this->makeTerminal($this->branchId);
        $mine = $this->shift($this->today, 'open');                             // on $this->terminalId
        $theirs = $this->shift($this->today, 'open', ['terminal_id' => $other, 'opened_by_user_id' => $this->makeUser()]);
        [$sale] = $this->sale($this->yesterday, $this->shift($this->yesterday, 'closed'));
        $order = SalesOrder::on('tenant')->find($sale);

        $this->assertSame($theirs, $this->pick($order, $other), 'the POS counter the return was opened from');
        $this->assertSame($mine, $this->pick($order, null), 'two open shifts: the one this user opened');

        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['default_terminal_id' => $other]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');   // the signed-in user as saved now
        $this->assertSame($theirs, $this->pick($order, null), "the user's own counter");

        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['default_terminal_id' => null]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        DB::connection('tenant')->table('shifts')->where('id', $mine)->update(['status' => 'closed']);
        $this->assertSame($theirs, $this->pick($order, null), "the branch's only open shift");

        $otherBranch = $this->makeTerminal($this->makeBranch(['name' => 'Elsewhere']));
        $this->assertSame($theirs, $this->pick($order, $otherBranch), "another branch's counter is never used");
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function shift(string $businessDate, string $status, array $attrs = []): int
    {
        return (int) DB::connection('tenant')->table('shifts')->insertGetId(array_merge([
            'shift_uuid' => strtoupper(bin2hex(random_bytes(13))), 'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId,
            'opened_by_user_id' => $this->userId, 'opening_cash' => 0, 'total_sales' => 0, 'total_cash' => 0,
            'total_refunds' => 0, 'total_cash_refunds' => 0, 'expected_cash' => 0, 'status' => $status,
            'business_date' => $businessDate, 'timezone_name' => 'Asia/Karachi', 'opened_at' => $businessDate . ' 09:00:00',
            'closed_at' => $status === 'closed' ? $businessDate . ' 23:00:00' : null, 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** @return array{0:int,1:int} sale id, line id — 3 × 100, paid, non-stock (the money path only) */
    private function sale(string $businessDate, int $shiftId): array
    {
        $p = $this->makeProduct($this->makeCategory(), ['is_stock_tracked' => 0, 'inventory_consumption_method' => 'none']);
        $sale = $this->makeSale($this->branchId, ['business_date' => $businessDate, 'shift_id' => $shiftId, 'terminal_id' => $this->terminalId,
            'order_type' => 'takeaway', 'subtotal' => 300, 'grand_total' => 300, 'paid_amount' => 300]);
        $line = $this->makeSaleLine($sale, $p, ['quantity' => 3, 'unit_price' => 100, 'line_total' => 300]);

        return [$sale, $line];
    }

    private function returnIt(int $saleId, int $lineId, float $qty, ?int $shiftId): SalesReturn
    {
        return app(SalesReturnService::class)->processReturn(
            salesOrder: SalesOrder::on('tenant')->findOrFail($saleId),
            lines: [['sales_order_line_id' => $lineId, 'quantity' => $qty]],
            reason: 'test',
            refundMethod: 'cash',
            refundAmount: $qty * 100,
            userId: $this->userId,
            refundShiftId: $shiftId,
        )->fresh();
    }

    private function pick(SalesOrder $order, ?int $terminalId): ?int
    {
        $m = new \ReflectionMethod(SalesReturnController::class, 'refundShiftId');

        // RETURN-NO-BILL-1: the controller asks by branch (a return without a bill has no sale).
        return $m->invoke(app(SalesReturnController::class), (int) $order->branch_id, $terminalId);
    }

    private function report(string $day, array $extra = []): array
    {
        $e = app(SalesReportEngine::class);

        return $e->overview($e->normalizeFilters(array_merge(['date_from' => $day, 'date_to' => $day, 'branch_ids' => [$this->branchId]], $extra)));
    }
}
