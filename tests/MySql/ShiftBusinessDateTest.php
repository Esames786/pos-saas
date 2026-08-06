<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Services\Sales\ShiftService;
use App\Support\TenantClock;
use Carbon\Carbon;
use Tests\MySql\Support\TenantFixtures;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 — the business-date invariant and the shift-close guard.
 *
 * Core rule under test: "Midnight changes the actual calendar date, but NOT the business date of an
 * already-open shift", and "a shift cannot close while it still owns unresolved operational work."
 */
class ShiftBusinessDateTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private function clock(): TenantClock
    {
        return app(TenantClock::class);
    }

    public function test_business_date_at_open_is_the_branch_tz_calendar_date(): void
    {
        // 5 Aug 23:50 Karachi is still business day 5 Aug; one hour later (6 Aug 00:50) is 6 Aug.
        $before = Carbon::parse('2026-08-05 18:50:00', 'UTC'); // 23:50 Asia/Karachi (+5)
        $after  = Carbon::parse('2026-08-05 19:50:00', 'UTC'); // 00:50 Asia/Karachi next day

        $this->assertSame('2026-08-05', $this->clock()->businessDateForOpening('Asia/Karachi', $before));
        $this->assertSame('2026-08-06', $this->clock()->businessDateForOpening('Asia/Karachi', $after));
    }

    public function test_sale_business_date_is_frozen_by_the_shift_not_now(): void
    {
        // A shift opened on 5 Aug: a sale rung up after midnight (now = 6 Aug) still books to 5 Aug.
        $shift = new Shift(['business_date' => '2026-08-05']);
        Carbon::setTestNow(Carbon::parse('2026-08-06 02:00:00'));
        try {
            $this->assertSame('2026-08-05', $this->clock()->businessDateForSale($shift));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_business_timezone_rejects_numeric_offsets_and_defaults_to_karachi(): void
    {
        $this->assertNull($this->clock()->normalize('UTC+5'));
        $this->assertNull($this->clock()->normalize('+05:00'));
        $this->assertSame('Asia/Karachi', $this->clock()->businessTimezone(new Branch(['timezone' => 'UTC+5'])));
        $this->assertSame('Europe/London', $this->clock()->businessTimezone(new Branch(['timezone' => 'Europe/London'])));
    }

    public function test_shift_close_is_blocked_by_held_sales_and_open_tables(): void
    {
        $this->cleanTenant(['restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'sales_orders', 'shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);
        $tableId = $this->makeTable($branchId);
        $svc = app(ShiftService::class);

        $shift = $svc->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $this->makeUser(), 0.0);

        // A held (unpaid) sale owned by the shift, and an open table bound to the shift.
        $heldId = $this->makeSale($branchId, ['shift_id' => $shift->id, 'status' => 'held', 'business_date' => $shift->business_date->toDateString()]);
        $sessionId = $this->tenant()->table('restaurant_table_sessions')->insertGetId([
            'session_no' => 'SES-' . uniqid(), 'branch_id' => $branchId, 'restaurant_table_id' => $tableId,
            'opened_by_user_id' => $this->makeUser(), 'opened_shift_id' => $shift->id,
            'business_date' => $shift->business_date->toDateString(), 'status' => 'open',
            'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse($svc->canClose($shift), 'A shift with held sales / open tables cannot close.');
        $work = $svc->unresolvedWork($shift);
        $this->assertArrayHasKey($heldId, $work['held_sales']);
        $this->assertArrayHasKey($sessionId, $work['open_tables']);

        // Settle both -> now the shift may close.
        $this->tenant()->table('sales_orders')->where('id', $heldId)->update(['status' => 'paid']);
        $this->tenant()->table('restaurant_table_sessions')->where('id', $sessionId)->update(['status' => 'closed']);

        $this->assertTrue($svc->canClose($shift->refresh()), 'Once work is settled the shift can close.');
    }
}
