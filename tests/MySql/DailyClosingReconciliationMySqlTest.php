<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\DailyClosingController;
use App\Http\Controllers\Tenant\ShiftController;
use App\Models\Tenant\Shift;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * Daily Closing must aggregate the day the SHIFTS belong to, and no close flow may invent a count.
 *
 * Live incident 2026-08-11: a cashier submitted the shift-close form with the counted-cash field
 * blank; the controller defaulted it to 0, recorded the entire 28,400 drawer as missing, and
 * auto-raised a full-takings shortage voucher. The same silent-zero default sat in the standalone
 * Daily Closing screen and in Close Branch — and Daily Closing also grouped shifts by the UTC
 * date of closed_at instead of the frozen business_date every report uses.
 */
class DailyClosingReconciliationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private ?string $originalDefaultConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = config('database.default');
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'cash_count_lines', 'daily_closings', 'expense_voucher_lines', 'expense_vouchers',
            'expense_categories', 'shifts', 'terminals', 'accounts', 'cash_bank_accounts',
            'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
    }

    protected function tearDown(): void
    {
        if ($this->originalDefaultConnection) {
            DB::setDefaultConnection($this->originalDefaultConnection);
        }
        parent::tearDown();
    }

    private function actingAsTenant(): int
    {
        $userId = $this->makeUser();
        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');

        return $userId;
    }

    /** A closed shift with realistic counters, frozen to a business date. */
    private function makeClosedShift(int $userId, int $branchId, int $terminalId, string $businessDate, string $closedAtUtc, array $attrs = []): int
    {
        return (int) DB::connection('tenant')->table('shifts')->insertGetId(array_merge([
            'shift_uuid' => strtoupper(bin2hex(random_bytes(13))),
            'branch_id' => $branchId,
            'terminal_id' => $terminalId,
            'opened_by_user_id' => $userId,
            'closed_by_user_id' => $userId,
            'opening_cash' => 0,
            'total_sales' => 35970, 'total_cash' => 35970,
            'total_refunds' => 7570, 'total_cash_refunds' => 7570,
            'expected_cash' => 28400, 'counted_cash' => 18350, 'cash_variance' => -10050,
            'status' => 'closed',
            'business_date' => $businessDate,
            'timezone_name' => 'Asia/Karachi',
            'opened_at' => $businessDate . ' 06:32:58',
            'closed_at' => $closedAtUtc,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    public function test_daily_closing_groups_shifts_by_business_date_not_utc_close_date(): void
    {
        $userId = $this->actingAsTenant();
        $branchId = $this->makeBranch();
        $terminalId = $this->makeTerminal($branchId);

        // The Khatri overnight shape: the shift BELONGS to Aug 11 but was closed at 05:30 UTC on
        // Aug 12 (10:30 in Karachi the next morning — the cashier forgot overnight).
        $this->makeClosedShift($userId, $branchId, $terminalId, '2026-08-11', '2026-08-12 05:30:00');

        $controller = app(DailyClosingController::class);
        $response = $controller->store(Request::create('/daily-closings', 'POST', [
            'branch_id' => $branchId,
            'closing_date' => '2026-08-11',
            'counted_cash' => '18350',
        ]));
        $this->assertSame(302, $response->getStatusCode());

        $closing = DB::connection('tenant')->table('daily_closings')->first();
        $this->assertNotNull($closing);
        // The shift is on ITS day: refunds included, expected aggregated, variance vs the count.
        $this->assertSame(28400.0, (float) $closing->expected_cash, 'The overnight-closed shift must land on its business date.');
        $this->assertSame(7570.0, (float) $closing->total_refunds);
        $this->assertSame(-10050.0, (float) $closing->cash_variance);

        // And the NEXT day's closing must NOT pick that shift up again.
        $response = $controller->store(Request::create('/daily-closings', 'POST', [
            'branch_id' => $branchId,
            'closing_date' => '2026-08-12',
            'counted_cash' => '0',
        ]));
        $this->assertSame(302, $response->getStatusCode());
        $day2 = DB::connection('tenant')->table('daily_closings')->whereDate('closing_date', '2026-08-12')->first();
        $this->assertSame(0.0, (float) $day2->expected_cash, 'A shift already on Aug 11 must not be counted again on Aug 12.');
    }

    public function test_daily_closing_refuses_a_blank_count(): void
    {
        $userId = $this->actingAsTenant();
        $branchId = $this->makeBranch();
        $terminalId = $this->makeTerminal($branchId);
        $this->makeClosedShift($userId, $branchId, $terminalId, '2026-08-11', '2026-08-11 18:03:06');

        $response = app(DailyClosingController::class)->store(Request::create('/daily-closings', 'POST', [
            'branch_id' => $branchId,
            'closing_date' => '2026-08-11',
            // counted_cash absent, no denominations — the live-incident shape
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, DB::connection('tenant')->table('daily_closings')->count(),
            'A blank count must never freeze a snapshot claiming the whole drawer is missing.');
    }

    public function test_a_shift_cannot_close_without_a_count_but_a_typed_zero_still_can(): void
    {
        $userId = $this->actingAsTenant();
        $branchId = $this->makeBranch();
        $terminalId = $this->makeTerminal($branchId);
        $shiftId = app(ShiftService::class)->open(
            \App\Models\Tenant\Branch::on('tenant')->findOrFail($branchId),
            \App\Models\Tenant\Terminal::on('tenant')->findOrFail($terminalId),
            $userId,
            0.0
        )->id;

        $controller = app(ShiftController::class);

        // Blank — the exact live-incident submission (the form posts denominations as blanks).
        $response = $controller->close(
            Request::create('/shifts/' . $shiftId . '/close', 'POST', ['denominations' => ['1' => '', '2' => '']]),
            Shift::on('tenant')->findOrFail($shiftId),
            app(ShiftService::class)
        );
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('open', Shift::on('tenant')->find($shiftId)->status,
            'A blank count must not close the shift at 0.');

        // Typed zero — a deliberate claim, still accepted (empty drawer is a real state).
        $response = $controller->close(
            Request::create('/shifts/' . $shiftId . '/close', 'POST', ['counted_cash' => '0']),
            Shift::on('tenant')->findOrFail($shiftId),
            app(ShiftService::class)
        );
        $this->assertSame(302, $response->getStatusCode());
        $closed = Shift::on('tenant')->find($shiftId);
        $this->assertSame('closed', $closed->status);
        $this->assertSame(0.0, (float) $closed->counted_cash);
    }
}
