<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\SalesReturnController;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\Shift;
use App\Models\Tenant\User;
use App\Services\Reports\SalesReportEngine;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
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
 * REFUND-SHIFT-H1 (owner decision H1, 22 Oct 2026) — "THE SHIFT THAT ACTUALLY PAYS THE REFUND OWNS THE REFUND
 * CASH MOVEMENT": the paying shift is the counter's OPEN shift (by the cashier's selected terminal), its STABLE
 * identity (shifts.shift_uuid) is written on the return row, the original sale's shift is provenance of the sale
 * only (never read for money, never mutated), and a CASH refund with no open processing shift is REFUSED with ONE
 * shared message (SalesReturnService::CASH_REFUND_NEEDS_SHIFT — the same text the Edge till shows). A non-cash
 * refund may still post without a shift. What H1 changed in the canonical cases of this file:
 *   - "no open shift anywhere → today's day, no shift" now holds for a BANK refund only; a CASH refund is refused;
 *   - "a return inside the sale's own open shift" is now paid by the COUNTER'S shift (the same row here) and the
 *     Online form never falls back to the sale's own shift (saleShiftFallback false) — the service keeps that
 *     fallback only for callers that say nothing.
 *
 * Proven here through SalesReturnService (the money path) and the controller's choice of counter:
 *   - yesterday's bill returned today → today's open shift (refunds, expected cash), today's day, today's
 *     report; yesterday's shift and report untouched;
 *   - after midnight on an evening shift still open → the evening's day (REPORT-BUSINESS-DATE-1 kept);
 *   - the eight H1 cases (Online side): same shift / later shift / another terminal's shift / no shift (cash
 *     refused, bank posts) / the paying shift's close totals.
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

    /** H1 (2): the sale's shift is closed; a later open shift pays; the sale's shift is a snapshot before = after. */
    public function test_yesterdays_bill_returned_today_goes_into_todays_shift_day_and_report(): void
    {
        $closed = $this->shift($this->yesterday, 'closed', ['total_refunds' => 0, 'expected_cash' => 300]);
        $open = $this->shift($this->today, 'open', ['expected_cash' => 1000]);
        [$sale, $line] = $this->sale($this->yesterday, $closed);
        $closedBefore = $this->snapshot($closed);

        $return = $this->returnIt($sale, $line, 1, $open);

        $this->assertSame($this->today, $return->business_date->toDateString(), 'booked on the day the cash left the drawer');
        $this->assertSame($open, (int) $return->shift_id);
        $this->assertSame($this->terminalId, (int) $return->terminal_id);
        $this->assertSame($this->uuidOf($open), $return->processing_shift_uuid, 'H1: the paying shift by STABLE identity');

        $s = Shift::on('tenant')->find($open);
        $this->assertEquals(100, (float) $s->total_refunds, "today's shift shows the refund");
        $this->assertEquals(100, (float) $s->total_cash_refunds);
        $this->assertEquals(900, (float) $s->expected_cash, 'the drawer is expected to hold 100 less — no false shortage');
        $this->assertSame($closedBefore, $this->snapshot($closed), "yesterday's closed shift is not touched (H1: never reopened, never mutated)");

        $this->assertEquals(100, $this->report($this->today)['refunds_recorded'], "today's report has it");
        $this->assertEquals(0, $this->report($this->yesterday)['refunds_recorded'], "yesterday's closed day does not move");
        $this->assertEquals(100, $this->report($this->today, ['shift_id' => $open])['refunds_recorded'], 'the shift report agrees with the shift');
        $this->assertEquals(0, $this->report($this->yesterday, ['shift_id' => $closed])['refunds_recorded']);
    }

    /** H1 (1): sale and cash refund in the same open shift — that shift's buckets move once; the row carries the identity. */
    public function test_sale_and_cash_refund_in_the_same_open_shift_move_that_shifts_buckets_once(): void
    {
        $open = $this->shift($this->today, 'open', ['expected_cash' => 500]);
        [$sale, $line] = $this->sale($this->today, $open);

        $return = $this->returnIt($sale, $line, 2, $open, saleShiftFallback: false);   // the counter's open shift (= the sale's)

        $this->assertSame($open, (int) $return->shift_id);
        $this->assertSame($this->terminalId, (int) $return->terminal_id);
        $this->assertSame($this->uuidOf($open), $return->processing_shift_uuid);
        $this->assertSame($this->today, $return->business_date->toDateString());
        $s = Shift::on('tenant')->find($open);
        $this->assertEquals(200, (float) $s->total_refunds, 'exactly once');
        $this->assertEquals(200, (float) $s->total_cash_refunds);
        $this->assertEquals(300, (float) $s->expected_cash);
    }

    /** The service keeps the sale's-own-shift fallback for callers that say nothing; the Online form says NO. */
    public function test_a_caller_naming_nothing_still_gets_the_sales_own_open_shift_but_the_online_form_never_does(): void
    {
        $other = $this->makeTerminal($this->branchId);
        $someoneElse = $this->makeUser();
        $salesShift = $this->shift($this->today, 'open', ['opened_by_user_id' => $someoneElse, 'expected_cash' => 500]);
        [$sale, $line] = $this->sale($this->today, $salesShift);

        // Backward compatible: a caller passing neither a counter nor saleShiftFallback gets today's rule.
        $return = $this->returnIt($sale, $line, 1, null);
        $this->assertSame($salesShift, (int) $return->shift_id);

        // The Online form: a second open shift (another counter, another user) and a cashier on no counter → the
        // controller resolves nothing (two open shifts, none his) and REFUSES the cash refund — it never reaches for
        // the sale's own open shift.
        $this->shift($this->today, 'open', ['terminal_id' => $other, 'opened_by_user_id' => $someoneElse]);
        $before = $this->snapshot($salesShift);
        $response = $this->postReturnForm($sale, $line, 'cash', null);
        $this->assertSame(SalesReturnService::CASH_REFUND_NEEDS_SHIFT, $this->formError($response));
        $this->assertSame($before, $this->snapshot($salesShift), "the sale's own shift paid nothing");
        $this->assertSame(1, SalesReturn::on('tenant')->count(), 'no second return posted');
    }

    public function test_after_midnight_on_an_evening_shift_the_refund_stays_on_the_evening_day(): void
    {
        $evening = $this->shift($this->yesterday, 'open');   // opened last evening, still open after midnight
        [$sale, $line] = $this->sale($this->yesterday, $evening);

        $return = $this->returnIt($sale, $line, 1, $evening);

        $this->assertSame($this->yesterday, $return->business_date->toDateString(), 'REPORT-BUSINESS-DATE-1 still holds');
        $this->assertEquals(100, (float) Shift::on('tenant')->find($evening)->total_refunds);
    }

    /** H1 (4) Online: no open shift → cash is refused with the shared message (service AND form); a bank refund still posts. */
    public function test_with_no_open_shift_a_cash_refund_is_refused_and_a_bank_refund_books_today_never_a_closed_day(): void
    {
        $closed = $this->shift($this->yesterday, 'closed');
        [$sale, $line] = $this->sale($this->yesterday, $closed);
        $closedBefore = $this->snapshot($closed);

        // the money path
        try {
            $this->returnIt($sale, $line, 1, null, saleShiftFallback: false);
            $this->fail('a cash refund with no paying shift must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame(SalesReturnService::CASH_REFUND_NEEDS_SHIFT, $e->getMessage());
        }
        // the form (the same text, as a validation error; the sale's own CLOSED shift is never reopened)
        $response = $this->postReturnForm($sale, $line, 'cash', null);
        $this->assertSame(SalesReturnService::CASH_REFUND_NEEDS_SHIFT, $this->formError($response));
        $this->assertSame(0, SalesReturn::on('tenant')->count(), 'nothing posted');
        $this->assertSame($closedBefore, $this->snapshot($closed));

        // a bank refund leaves no drawer: it posts, with no shift, on today's day — never a closed past day
        $return = $this->returnIt($sale, $line, 1, null, saleShiftFallback: false, refundMethod: 'bank_transfer');
        $this->assertNull($return->shift_id);
        $this->assertNull($return->processing_shift_uuid);
        $this->assertSame($this->today, $return->business_date->toDateString());
        $this->assertSame($closedBefore, $this->snapshot($closed));
    }

    /** H1 (3): the cashier's SELECTED counter's open shift pays — not the sale's (open) shift on another counter. */
    public function test_a_refund_on_another_terminals_open_shift_is_paid_by_the_cashiers_selected_counter(): void
    {
        $other = $this->makeTerminal($this->branchId);
        $salesShift = $this->shift($this->today, 'open', ['expected_cash' => 1000]);                               // counter A, still open
        $theirs = $this->shift($this->today, 'open', ['terminal_id' => $other, 'opened_by_user_id' => $this->makeUser(), 'expected_cash' => 200]);
        [$sale, $line] = $this->sale($this->today, $salesShift);
        $salesShiftBefore = $this->snapshot($salesShift);

        $paying = $this->pick(SalesOrder::on('tenant')->find($sale), $other);   // the POS passes the counter it is on
        $this->assertSame($theirs, $paying);
        $return = $this->returnIt($sale, $line, 1, $paying, saleShiftFallback: false);

        $this->assertSame($theirs, (int) $return->shift_id);
        $this->assertSame($other, (int) $return->terminal_id);
        $this->assertSame($this->uuidOf($theirs), $return->processing_shift_uuid);
        $t = Shift::on('tenant')->find($theirs);
        $this->assertEquals(100, (float) $t->total_cash_refunds);
        $this->assertEquals(100, (float) $t->expected_cash);
        $this->assertSame($salesShiftBefore, $this->snapshot($salesShift), "the sale's own open shift on counter A is untouched");

        // and the form, end to end: terminal_id = the other counter
        $response = $this->postReturnForm($sale, $line, 'cash', $other);
        $this->assertNull($this->formError($response), 'posted');
        $this->assertSame($theirs, (int) SalesReturn::on('tenant')->latest('id')->first()->shift_id);
        $this->assertEquals(200, (float) Shift::on('tenant')->find($theirs)->total_cash_refunds);
        $this->assertSame($salesShiftBefore, $this->snapshot($salesShift));
    }

    /** H1 (8) Online: the PAYING shift's close totals carry the refund; the sale's shift closes with none. */
    public function test_the_paying_shifts_close_totals_carry_the_refund_not_the_sales_shift(): void
    {
        $other = $this->makeTerminal($this->branchId);
        $salesShift = $this->shift($this->today, 'open', ['expected_cash' => 300, 'total_sales' => 300, 'total_cash' => 300]);
        $paying = $this->shift($this->today, 'open', ['terminal_id' => $other, 'expected_cash' => 1000]);
        [$sale, $line] = $this->sale($this->today, $salesShift);

        $this->returnIt($sale, $line, 3, $paying, saleShiftFallback: false);

        $shifts = app(ShiftService::class);
        $closedPaying = $shifts->closeShift(Shift::on('tenant')->find($paying), $this->userId, 700.0);
        $closedSales = $shifts->closeShift(Shift::on('tenant')->find($salesShift), $this->userId, 300.0);
        $this->assertSame('closed', $closedPaying->status);
        $this->assertEquals(300, (float) $closedPaying->total_refunds);
        $this->assertEquals(300, (float) $closedPaying->total_cash_refunds);
        $this->assertEquals(700, (float) $closedPaying->expected_cash);
        $this->assertEquals(0, (float) $closedPaying->cash_variance, 'the drawer counted 700 is exactly right');
        $this->assertEquals(0, (float) $closedSales->total_refunds, "the sale's shift closes with no refund on it");
        $this->assertEquals(300, (float) $closedSales->expected_cash);
        $this->assertEquals(0, (float) $closedSales->cash_variance);
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

    private function uuidOf(int $shiftId): string
    {
        return (string) DB::connection('tenant')->table('shifts')->where('id', $shiftId)->value('shift_uuid');
    }

    /** The whole shift row as it stands (money, status, stamps) — before/after proof that a shift was never touched. */
    private function snapshot(int $shiftId): array
    {
        return (array) DB::connection('tenant')->table('shifts')->where('id', $shiftId)->first();
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

    private function returnIt(int $saleId, int $lineId, float $qty, ?int $shiftId, bool $saleShiftFallback = true, string $refundMethod = 'cash'): SalesReturn
    {
        return app(SalesReturnService::class)->processReturn(
            salesOrder: SalesOrder::on('tenant')->findOrFail($saleId),
            lines: [['sales_order_line_id' => $lineId, 'quantity' => $qty]],
            reason: 'test',
            refundMethod: $refundMethod,
            refundAmount: $qty * 100,
            userId: $this->userId,
            refundShiftId: $shiftId,
            saleShiftFallback: $saleShiftFallback,
        )->fresh();
    }

    /** The Online return form, posted as the signed-in cashier (the controller, outside the HTTP kernel). */
    private function postReturnForm(int $saleId, int $lineId, string $method, ?int $terminalId): \Symfony\Component\HttpFoundation\Response
    {
        app('session.store')->forget('errors');
        $request = Request::create('/sales-returns', 'POST', array_filter([
            'sales_order_id' => $saleId, 'refund_method' => $method, 'refund_amount' => 100,
            'lines' => [['sales_order_line_id' => $lineId, 'quantity' => 1]],
            'terminal_id' => $terminalId,
        ], fn ($v) => $v !== null));
        $request->setLaravelSession(app('session.store'));

        return app(SalesReturnController::class)->store($request, app(SalesReturnService::class));
    }

    private function formError(\Symfony\Component\HttpFoundation\Response $response): ?string
    {
        $errors = app('session.store')->get('errors');

        return $errors ? ($errors->first('return') ?: null) : null;
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
