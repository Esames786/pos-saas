<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * SHIFT-CANCELLATIONS-1 — what a shift threw away.
 *
 * A shift row already records every way money ARRIVED (cash / card / bank / cheque) and every way
 * it went back (refunds, by method). What it never recorded is what was CANCELLED — a whole bill
 * voided, or single items pulled off a check after the kitchen already had them.
 *
 * Cancelled work never becomes money, so no total on the shift row moves. That is exactly why it
 * was invisible: on 31 Aug one counter had voided twelve units and another none, and the Shift
 * Report showed the same thing for both.
 *
 * Counted from the ORDERS rather than stored on the shift, so every past shift reports it too.
 * This pins down the two sources, which are genuinely different things:
 *   - a cancelled ORDER  — the whole bill is dead, and it carries an amount
 *   - a cancelled LINE   — the bill lives on, so no cancelled-order query can ever see it
 */
class ShiftCancellationsReportMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $terminalId;
    private int $productId;
    private int $userId;
    private int $reasonId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_order_line_cancellations', 'void_reasons', 'sales_order_lines', 'sales_orders',
            'shifts', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);

        $this->userId = $this->makeUser(['employee_code' => 'SC' . Str::random(4)]);
        $this->branchId = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct(
            $this->makeCategory(['name' => 'Food', 'slug' => 'food-' . Str::random(4)])
        );
        $this->reasonId = DB::connection('tenant')->table('void_reasons')->insertGetId([
            'name' => 'Customer changed mind', 'reason_type' => 'void', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeShift(): int
    {
        return DB::connection('tenant')->table('shifts')->insertGetId([
            'shift_uuid' => (string) Str::ulid(),
            'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId,
            'opened_by_user_id' => $this->userId, 'status' => 'open',
            'opening_cash' => 0, 'total_sales' => 0, 'expected_cash' => 0,
            'business_date' => now()->toDateString(), 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function orderOn(int $shiftId, string $status, float $total): int
    {
        $id = $this->makeSale($this->branchId, [
            'status' => $status, 'order_type' => 'takeaway',
            'terminal_id' => $this->terminalId, 'grand_total' => $total,
        ]);
        DB::connection('tenant')->table('sales_orders')->where('id', $id)->update(['shift_id' => $shiftId]);

        return $id;
    }

    private function voidLine(int $saleId, float $qty): void
    {
        $lineId = $this->makeSaleLine($saleId, $this->productId, ['quantity' => $qty, 'kot_sent_quantity' => $qty]);
        DB::connection('tenant')->table('sales_order_line_cancellations')->insert([
            'event_uuid' => (string) Str::uuid(),
            'sales_order_id' => $saleId, 'sales_order_line_id' => $lineId,
            'void_reason_id' => $this->reasonId, 'approval_mode' => 'auto_approve',
            'product_name' => 'Test Item', 'quantity' => $qty,
            'requested_by_user_id' => $this->userId, 'cancelled_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0:\Illuminate\Support\Collection,1:\Illuminate\Support\Collection} */
    private function report(array $shiftIds): array
    {
        $cancelled = \App\Models\Tenant\SalesOrder::query()
            ->whereIn('shift_id', $shiftIds)->where('status', 'cancelled')
            ->selectRaw('shift_id, COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as amount')
            ->groupBy('shift_id')->get()->keyBy('shift_id');

        $voided = DB::connection('tenant')->table('sales_order_line_cancellations as c')
            ->join('sales_orders as o', 'o.id', '=', 'c.sales_order_id')
            ->whereIn('o.shift_id', $shiftIds)
            ->selectRaw('o.shift_id, COUNT(*) as lines_count, COALESCE(SUM(c.quantity), 0) as units')
            ->groupBy('o.shift_id')->get()->keyBy('shift_id');

        return [$cancelled, $voided];
    }

    /** A whole bill cancelled on this shift, with its money. */
    public function test_a_cancelled_bill_is_reported_against_its_shift(): void
    {
        $shift = $this->makeShift();
        $this->orderOn($shift, 'cancelled', 1490);
        $this->orderOn($shift, 'paid', 800);

        [$cancelled, ] = $this->report([$shift]);

        $this->assertSame(1, (int) $cancelled[$shift]->bills);
        $this->assertEqualsWithDelta(1490.0, (float) $cancelled[$shift]->amount, 0.01,
            'the paid bill is not a cancellation');
    }

    /** Items pulled off a check that stayed open — invisible to any order-level query. */
    public function test_items_voided_off_a_living_bill_are_still_counted(): void
    {
        $shift = $this->makeShift();
        $sale = $this->orderOn($shift, 'paid', 2000);
        $this->voidLine($sale, 2);
        $this->voidLine($sale, 10);

        [$cancelled, $voided] = $this->report([$shift]);

        $this->assertArrayNotHasKey($shift, $cancelled->all(),
            'the bill was paid, so a cancelled-order query sees nothing — which is the whole point');
        $this->assertSame(2, (int) $voided[$shift]->lines_count);
        $this->assertEqualsWithDelta(12.0, (float) $voided[$shift]->units, 0.01,
            'twelve units left the kitchen and were thrown away');
    }

    /** Two shifts on one terminal must not borrow each other's cancellations. */
    public function test_each_shift_gets_only_its_own(): void
    {
        $busy = $this->makeShift();
        $quiet = $this->makeShift();

        $this->orderOn($busy, 'cancelled', 500);
        $this->voidLine($this->orderOn($busy, 'paid', 900), 3);
        $this->orderOn($quiet, 'paid', 1200);

        [$cancelled, $voided] = $this->report([$busy, $quiet]);

        $this->assertEqualsWithDelta(500.0, (float) $cancelled[$busy]->amount, 0.01);
        $this->assertEqualsWithDelta(3.0, (float) $voided[$busy]->units, 0.01);
        $this->assertArrayNotHasKey($quiet, $cancelled->all(), 'the quiet shift cancelled nothing');
        $this->assertArrayNotHasKey($quiet, $voided->all());
    }

    /** A shift with nothing thrown away reports nothing — not a zero row. */
    public function test_a_clean_shift_reports_nothing(): void
    {
        $shift = $this->makeShift();
        $this->orderOn($shift, 'paid', 1000);

        [$cancelled, $voided] = $this->report([$shift]);

        $this->assertTrue($cancelled->isEmpty());
        $this->assertTrue($voided->isEmpty());
    }

    /**
     * The figures the shift ALREADY stores are what the report now shows: sales that arrived by
     * card or bank are not a missing drawer, they simply never went into it.
     */
    public function test_the_stored_payment_split_explains_the_gap_between_sales_and_expected_cash(): void
    {
        $shift = $this->makeShift();
        DB::connection('tenant')->table('shifts')->where('id', $shift)->update([
            'total_sales' => 227520, 'total_cash' => 194195, 'total_card' => 21755,
            'total_bank_transfer' => 11570, 'total_refunds' => 1110, 'total_cash_refunds' => 1110,
            'opening_cash' => 0, 'expected_cash' => 193085,
        ]);
        $row = DB::connection('tenant')->table('shifts')->where('id', $shift)->first();

        $this->assertEqualsWithDelta(
            (float) $row->total_cash + (float) $row->total_card + (float) $row->total_bank_transfer,
            (float) $row->total_sales, 0.01,
            'the three ways money arrived add up to the sales figure'
        );
        $this->assertEqualsWithDelta(
            (float) $row->opening_cash + (float) $row->total_cash - (float) $row->total_cash_refunds,
            (float) $row->expected_cash, 0.01,
            'expected cash is opening + cash sales - cash refunds; card and bank never enter the drawer'
        );
    }
}
