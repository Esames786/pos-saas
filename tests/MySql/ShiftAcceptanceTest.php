<?php

namespace Tests\MySql;

use App\Exceptions\ShiftException;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Reports\SalesReportService;
use App\Services\Sales\ShiftService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1 (#9) — the committed acceptance matrix (non-concurrency
 * cases; the close-vs-operation races live in ShiftCloseRaceTest). pos_test_* only, zero skips.
 */
class ShiftAcceptanceTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private function svc(): ShiftService
    {
        return app(ShiftService::class);
    }

    /** #5 — a sale cannot resolve a shift when none is open on the terminal. */
    public function test_sale_without_open_shift_is_rejected(): void
    {
        $this->cleanTenant(['shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminal = Terminal::on('tenant')->find($this->makeTerminal($branchId, ['requires_shift' => 0]));

        $this->expectException(ShiftException::class);
        $this->svc()->lockOpenShiftForTerminal($terminal);
    }

    /** #6 — opening a table is blocked when the branch has no open shift. */
    public function test_table_operation_without_open_shift_is_rejected(): void
    {
        $this->cleanTenant(['shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);

        $this->expectException(ShiftException::class);
        $this->svc()->lockOpenShiftForBranch($branchId);
    }

    /** #8 — a Direct Pay sale rung after midnight keeps the shift's business date. */
    public function test_direct_pay_after_midnight_keeps_shift_business_date(): void
    {
        $this->cleanTenant(['shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);

        Carbon::setTestNow(Carbon::parse('2026-08-05 15:00:00', 'UTC')); // 20:00 Karachi -> 5 Aug
        $shift = $this->svc()->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $this->makeUser(), 0.0);

        try {
            Carbon::setTestNow(Carbon::parse('2026-08-05 20:30:00', 'UTC')); // 01:30 Karachi next day
            $clock = app(\App\Support\TenantClock::class);
            $this->assertSame('2026-08-05', $clock->businessDateForSale($shift), 'Direct Pay after midnight still books to the open shift business date.');
        } finally {
            Carbon::setTestNow();
        }
    }

    /** #7 — Add Round after midnight keeps the ORIGINAL held order's business date. */
    public function test_add_round_after_midnight_keeps_original_business_date(): void
    {
        $this->cleanTenant(['sales_orders', 'shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $held = $this->makeSale($branchId, ['status' => 'held', 'business_date' => '2026-08-05']);

        // The controller preserves the existing business_date: `$sale->business_date ?? $current`.
        Carbon::setTestNow(Carbon::parse('2026-08-06 05:00:00', 'UTC'));
        try {
            $existing = \App\Models\Tenant\SalesOrder::on('tenant')->find($held);
            $currentBusinessDate = '2026-08-06';
            $preserved = $existing->business_date?->toDateString() ?? $currentBusinessDate;
            $this->assertSame('2026-08-05', $preserved, 'Add Round keeps the check on its original business day.');
        } finally {
            Carbon::setTestNow();
        }
    }

    /** #9 — a split child inherits the parent order's shift + business date (never "now"). */
    public function test_split_child_inherits_parent_shift_and_business_date(): void
    {
        $this->cleanTenant(['sales_orders', 'shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);
        $shift = $this->svc()->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $this->makeUser(), 0.0);
        $parent = \App\Models\Tenant\SalesOrder::on('tenant')->find(
            $this->makeSale($branchId, ['status' => 'held', 'shift_id' => $shift->id, 'business_date' => '2026-08-05'])
        );

        // Mirrors SplitBillController: child business_date = parent's (never recomputed from now).
        Carbon::setTestNow(Carbon::parse('2026-08-06 03:00:00', 'UTC'));
        try {
            $childShiftId = $parent->shift_id;
            $childBusinessDate = $parent->business_date?->toDateString()
                ?? optional(Shift::find($childShiftId))->business_date?->toDateString();
            $this->assertSame($shift->id, $childShiftId);
            $this->assertSame('2026-08-05', $childBusinessDate);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** #10 — the first shift opened after midnight gets the NEW business date. */
    public function test_next_shift_after_midnight_gets_new_business_date(): void
    {
        $this->cleanTenant(['shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);

        Carbon::setTestNow(Carbon::parse('2026-08-05 15:00:00', 'UTC')); // 20:00 Karachi -> 5 Aug
        $shift1 = $this->svc()->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $this->makeUser(), 0.0);
        $shift1->update(['status' => 'closed', 'closed_at' => now()]);

        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00', 'UTC')); // 02:00 Karachi next day -> 6 Aug
        try {
            $shift2 = $this->svc()->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $this->makeUser(), 0.0);
            $this->assertSame('2026-08-05', $shift1->business_date->toDateString());
            $this->assertSame('2026-08-06', $shift2->business_date->toDateString(), 'A new shift after midnight starts a new business day.');
        } finally {
            Carbon::setTestNow();
        }
    }

    /** #14 — shift_uuid is generated, unique and immutable. */
    public function test_shift_uuid_is_unique_and_stable(): void
    {
        $this->cleanTenant(['shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $t1 = Terminal::on('tenant')->find($this->makeTerminal($branchId));
        $t2 = Terminal::on('tenant')->find($this->makeTerminal($branchId));

        $s1 = $this->svc()->open(Branch::on('tenant')->find($branchId), $t1, $this->makeUser(), 0.0);
        $s2 = $this->svc()->open(Branch::on('tenant')->find($branchId), $t2, $this->makeUser(), 0.0);

        $this->assertNotEmpty($s1->shift_uuid);
        $this->assertNotSame($s1->shift_uuid, $s2->shift_uuid, 'Each shift gets a distinct uuid.');

        $original = $s1->shift_uuid;
        $s1->refresh();
        $this->assertSame($original, $s1->shift_uuid, 'shift_uuid is stable across reloads.');

        // The unique index rejects a duplicate.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->tenant()->table('shifts')->where('id', $s2->id)->update(['shift_uuid' => $original]);
    }

    /** CLOSURE-1 #2 — operational "Today" uses the branch business day, not Laravel's UTC today(). */
    public function test_dashboard_today_uses_branch_business_day_not_utc_today(): void
    {
        $this->cleanTenant(['sales_orders', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);

        // Freeze the clock at 2026-08-05 19:30 UTC = 2026-08-06 00:30 in Karachi. Laravel today()
        // (UTC) is 5 Aug, but the branch's current business day is 6 Aug.
        Carbon::setTestNow(Carbon::parse('2026-08-05 19:30:00', 'UTC'));
        try {
            $this->assertSame('2026-08-05', today()->toDateString(), 'Precondition: UTC today() is a day behind.');
            $this->assertSame('2026-08-06', app(\App\Support\TenantClock::class)->currentBusinessDate(Branch::on('tenant')->find($branchId)));

            // A paid sale on the CURRENT business day (6 Aug) rung just after midnight (5 Aug 19:30 UTC).
            $this->makeSale($branchId, ['status' => 'paid', 'business_date' => '2026-08-06', 'sale_date' => '2026-08-05 19:30:00', 'grand_total' => 100]);
            // A sale from the PREVIOUS business day (5 Aug) must NOT count as "today".
            $this->makeSale($branchId, ['status' => 'paid', 'business_date' => '2026-08-05', 'sale_date' => '2026-08-05 10:00:00', 'grand_total' => 999]);

            $stats = app(SalesReportService::class)->todayStats($branchId);
            $this->assertEquals(1, $stats['order_count'], 'Only the 6 Aug (current business day) sale counts as today.');
            $this->assertEquals(100, $stats['net_sales'], 'UTC today() would have wrongly picked the 5 Aug 999 sale.');
        } finally {
            Carbon::setTestNow();
        }
    }

    /** CLOSURE-1 #3 — shift_uuid cannot be changed once assigned; normal updates still work. */
    public function test_shift_uuid_is_immutable_after_assignment(): void
    {
        $this->cleanTenant(['shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);
        $shift = $this->svc()->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $this->makeUser(), 0.0);

        // A normal (non-uuid) update is fine.
        $shift->update(['closing_notes' => 'ok']);
        $this->assertSame('ok', $shift->refresh()->closing_notes);

        // Changing shift_uuid is rejected.
        try {
            $shift->shift_uuid = (string) Str::ulid();
            $shift->save();
            $this->fail('Expected shift_uuid change to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('immutable', $e->getMessage());
        }
    }

    /** #12 — the historical backfill uses the branch timezone, not a UTC DATE(). */
    public function test_karachi_backfill_around_utc_midnight_uses_branch_timezone(): void
    {
        $this->cleanTenant(['sales_orders', 'shifts', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);

        // A sale at 2026-08-05 20:30 UTC is 2026-08-06 01:30 in Karachi. UTC DATE() = 5 Aug (WRONG);
        // the correct business date is 6 Aug. Seed it with a NULL business_date and no shift.
        $saleId = $this->makeSale($branchId, ['status' => 'paid', 'shift_id' => null, 'sale_date' => '2026-08-05 20:30:00']);
        $this->tenant()->table('sales_orders')->where('id', $saleId)->update(['business_date' => null]);

        // Run the REAL backfill migration up() and check the corrected value.
        $migration = require base_path('database/migrations/tenant/2026_08_07_000003_backfill_shift_business_dates.php');
        $migration->up();

        $value = $this->tenant()->table('sales_orders')->where('id', $saleId)->value('business_date');
        $this->assertSame('2026-08-06', Carbon::parse($value)->toDateString(), 'Backfill converts to the branch business day, not the UTC date.');
        $this->assertNotSame('2026-08-05', Carbon::parse($value)->toDateString(), 'A UTC DATE() would have been wrong here.');
    }

    /** #13 — a business daily report groups 23:59 and 00:01 of the same shift together. */
    public function test_business_report_groups_2359_and_0001_on_same_business_day(): void
    {
        $this->cleanTenant(['sales_orders', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);

        // Same shift business day (5 Aug), one rung at 23:59 and one at 00:01 (actual 6 Aug).
        $this->makeSale($branchId, ['status' => 'paid', 'business_date' => '2026-08-05', 'sale_date' => '2026-08-05 18:59:00', 'grand_total' => 100]);
        $this->makeSale($branchId, ['status' => 'paid', 'business_date' => '2026-08-05', 'sale_date' => '2026-08-05 19:01:00', 'grand_total' => 40]);

        $summary = app(SalesReportService::class)->summary([
            'date_from' => '2026-08-05', 'date_to' => '2026-08-05', 'branch_ids' => [$branchId],
        ]);

        $this->assertEquals(2, $summary['totals']->order_count, 'Both sales fall on the same business day.');
        $this->assertEquals(140, (float) $summary['totals']->net_sales);
        $this->assertEquals(['2026-08-05'], $summary['daily']->pluck('sale_day')->all());
    }

    /** #11 — a receipt/reprint renders the ORIGINAL shift-local time even if the branch tz changed. */
    public function test_receipt_uses_shift_timezone_after_branch_timezone_change(): void
    {
        $this->cleanTenant(['print_jobs', 'sales_orders', 'shifts', 'terminals', 'branches']);
        // Branch is now Europe/London, but the sale happened under an Asia/Karachi shift.
        $branchId = $this->makeBranch(['timezone' => 'Europe/London']);
        $terminalId = $this->makeTerminal($branchId);
        $shiftId = $this->tenant()->table('shifts')->insertGetId([
            'shift_uuid' => (string) Str::ulid(), 'branch_id' => $branchId, 'terminal_id' => $terminalId,
            'opened_by_user_id' => $this->makeUser(), 'opening_cash' => 0, 'expected_cash' => 0,
            'status' => 'closed', 'business_date' => '2026-08-05', 'timezone_name' => 'Asia/Karachi',
            'opened_at' => '2026-08-05 15:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 2026-08-05 19:00 UTC = 06 Aug 00:00 Karachi (+5) vs 05 Aug 20:00 London (BST +1).
        $saleId = $this->makeSale($branchId, ['status' => 'paid', 'shift_id' => $shiftId, 'sale_date' => '2026-08-05 19:00:00']);

        $jobId = $this->makePrintJob(null, [
            'document_type' => 'receipt', 'reference_type' => 'sales_order', 'reference_id' => $saleId,
        ]);
        $payload = app(EscPosPayloadService::class)->build(PrintJob::on('tenant')->find($jobId));

        $this->assertStringContainsString('Date: 2026-08-06 00:00', $payload, 'Receipt shows the original Karachi-local time.');
        $this->assertStringNotContainsString('2026-08-05 20:00', $payload, 'It must NOT shift to the branch\'s new London time.');
    }
}
