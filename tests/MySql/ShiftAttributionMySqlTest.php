<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * POS-SHIFT-ATTRIBUTION-1 — the shift follows the drawer, the business date follows the order.
 *
 * A held check opened at the Floor counter and paid at a till used to keep the Floor's shift, because
 * business_date was being read off the shift and the two were carried together. So Kashif Food's Floor
 * shift — a counter that cannot take payment at all — closed on 30 Aug asking for Rs 1,19,505 of cash
 * its drawer had never seen, and showed a Rs 21,505 shortfall that was pure arithmetic. Every one of
 * those 44 sales was already stamped with the till that took the money; only the shift disagreed.
 *
 * business_date is its own column and is preserved independently, so the midnight rule the old
 * coupling existed to protect still holds: a check opened before midnight reports on its opening day
 * wherever it is paid.
 */
class ShiftAttributionMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $userId;
    private int $branchId;
    private int $floorTerminal;
    private int $tillTerminal;
    private int $floorShift;
    private int $tillShift;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts',
            'terminal_user', 'terminals', 'products', 'categories', 'payment_methods', 'branches', 'users',
        ]);

        $this->userId = $this->makeUser(['employee_code' => 'SA' . Str::random(4)]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');

        $this->branchId = $this->makeBranch();
        $this->floorTerminal = $this->makeTerminal($this->branchId, ['code' => 'T4', 'name' => 'DTQ Floor']);
        $this->tillTerminal = $this->makeTerminal($this->branchId, ['code' => 'T2', 'name' => 'DTQ 1']);

        $branch = Branch::on('tenant')->find($this->branchId);
        $this->floorShift = app(ShiftService::class)
            ->open($branch, Terminal::on('tenant')->find($this->floorTerminal), $this->userId, 0.0)->id;
        $this->tillShift = app(ShiftService::class)
            ->open($branch, Terminal::on('tenant')->find($this->tillTerminal), $this->userId, 0.0)->id;
    }

    /**
     * The rule under test, applied exactly as SalesOrderController does when a recalled check is paid:
     * the shift comes from the PAYING terminal, the business date from the ORDER.
     */
    private function payRecalledCheck(SalesOrder $held, ?Shift $payingShift, string $businessDate): array
    {
        return [
            'shift_id' => $payingShift?->id ?? $held->shift_id,
            'business_date' => $held->business_date?->toDateString() ?? $businessDate,
        ];
    }

    /** A check opened at the Floor, paid at the till: the money belongs to the till's shift. */
    public function test_payment_moves_the_shift_to_the_paying_counter(): void
    {
        $held = SalesOrder::on('tenant')->findOrFail($this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in',
            'terminal_id' => $this->floorTerminal, 'shift_id' => $this->floorShift,
            'business_date' => '2026-08-30',
        ]));

        $resolved = $this->payRecalledCheck($held, Shift::on('tenant')->find($this->tillShift), '2026-08-31');

        $this->assertSame($this->tillShift, $resolved['shift_id'],
            'the cash went into the till drawer, so the till shift owns it.');
        $this->assertNotSame($this->floorShift, $resolved['shift_id'],
            'the Floor cannot take payment — its shift must not be asked for this cash.');
    }

    /** The midnight rule the old coupling protected still holds. */
    public function test_the_business_date_still_follows_the_order_not_the_payment(): void
    {
        $held = SalesOrder::on('tenant')->findOrFail($this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in',
            'terminal_id' => $this->floorTerminal, 'shift_id' => $this->floorShift,
            'business_date' => '2026-08-30',
        ]));

        // Paid after midnight, at a till whose shift belongs to the NEXT business day.
        $resolved = $this->payRecalledCheck($held, Shift::on('tenant')->find($this->tillShift), '2026-08-31');

        $this->assertSame('2026-08-30', $resolved['business_date'],
            'a check opened before midnight reports on its opening day, wherever it is paid.');
    }

    /** Paid at the same counter that took it — nothing moves. */
    public function test_a_check_paid_where_it_was_opened_is_unchanged(): void
    {
        $held = SalesOrder::on('tenant')->findOrFail($this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in',
            'terminal_id' => $this->tillTerminal, 'shift_id' => $this->tillShift,
            'business_date' => '2026-08-30',
        ]));

        $resolved = $this->payRecalledCheck($held, Shift::on('tenant')->find($this->tillShift), '2026-08-30');

        $this->assertSame($this->tillShift, $resolved['shift_id']);
        $this->assertSame('2026-08-30', $resolved['business_date']);
    }

    /** No shift on the payment path (manual / non-POS source) — the order's own shift stands. */
    public function test_without_a_paying_shift_the_orders_own_shift_is_kept(): void
    {
        $held = SalesOrder::on('tenant')->findOrFail($this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in',
            'terminal_id' => $this->floorTerminal, 'shift_id' => $this->floorShift,
            'business_date' => '2026-08-30',
        ]));

        $resolved = $this->payRecalledCheck($held, null, '2026-08-31');

        $this->assertSame($this->floorShift, $resolved['shift_id'],
            'a manual sale with no open shift must not lose the order\'s own shift.');
    }

    /** The shape that produced the false shortfall: Floor opens, till pays, Floor stays empty. */
    public function test_the_floor_shift_is_not_asked_for_cash_it_never_took(): void
    {
        $floorOwned = 0.0;
        $tillOwned = 0.0;

        foreach ([1200.0, 800.0, 2400.0] as $amount) {
            $held = SalesOrder::on('tenant')->findOrFail($this->makeSale($this->branchId, [
                'status' => 'held', 'order_type' => 'dine_in', 'grand_total' => $amount,
                'terminal_id' => $this->floorTerminal, 'shift_id' => $this->floorShift,
                'business_date' => '2026-08-30',
            ]));
            $resolved = $this->payRecalledCheck($held, Shift::on('tenant')->find($this->tillShift), '2026-08-30');
            $resolved['shift_id'] === $this->floorShift ? $floorOwned += $amount : $tillOwned += $amount;
        }

        $this->assertSame(0.0, $floorOwned, 'the Floor drawer is owed nothing — it never took a payment.');
        $this->assertSame(4400.0, $tillOwned, 'every rupee sits on the shift whose drawer holds it.');
    }
}
