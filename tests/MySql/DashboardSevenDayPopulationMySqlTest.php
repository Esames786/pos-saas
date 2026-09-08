<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Reports\SalesReportEngine;
use App\Services\Reports\SalesReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * DASHBOARD-7DAY-POPULATION-1 — one day, one answer.
 *
 * The dashboard used to carry two definitions of a day's trading, six inches apart on the same
 * screen. The tile — and every report — counts `paid + partially_returned + returned` and nets the
 * posted refunds off. The "Last 7 Days" card ran its own query and counted `paid` only, netting
 * nothing, so a returned bill vanished from it entirely.
 *
 * The client caught it as a COUNT mismatch (295 against 291 for the same day) and assumed the
 * money was fine, because that day every return happened to be a FULL return: subtracting the
 * refunds removed exactly the money the card had already dropped, and the two wrong roads met at
 * the right answer. The two days before carried PARTIAL returns, where money that was genuinely
 * kept simply went missing from the history — 1,400 and 2,490.
 *
 * So the tests that matter are the partial-return ones. A test built only on full returns would
 * have passed against the old code.
 */
class DashboardSevenDayPopulationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $otherBranchId;
    private int $productId;
    private string $today;
    private string $yesterday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanTenant([
            'sales_return_lines', 'sales_returns', 'sales_order_lines', 'sales_orders',
            'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branchId      = $this->makeBranch(['name' => 'Main']);
        $this->otherBranchId = $this->makeBranch(['name' => 'Other']);

        $category        = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(5)]);
        $this->productId = $this->makeProduct($category, ['name' => 'Biryani ' . Str::random(4)]);

        $this->today     = app(\App\Support\TenantClock::class)
            ->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
        $this->yesterday = \Illuminate\Support\Carbon::parse($this->today)->subDay()->toDateString();
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function bill(string $businessDate, float $total, string $status = 'paid', ?int $branchId = null): int
    {
        return $this->makeSale($branchId ?? $this->branchId, [
            'sale_no'       => 'SO-' . Str::upper(Str::random(10)),
            'status'        => $status,
            'order_type'    => 'takeaway',
            'business_date' => $businessDate,
            'grand_total'   => $total,
            'subtotal'      => $total,
        ]);
    }

    /** A posted return of `$amount` against `$saleId`, allocated to that bill's business day. */
    private function refund(int $saleId, string $businessDate, float $amount): void
    {
        DB::connection('tenant')->table('sales_returns')->insert([
            'return_no'      => 'RT-' . Str::upper(Str::random(8)),
            'sales_order_id' => $saleId,
            'branch_id'      => $this->branchId,
            'return_date'    => now(),
            'business_date'  => $businessDate,
            'subtotal'       => $amount,
            'grand_total'    => $amount,
            'refund_amount'  => $amount,
            'refund_method'  => 'cash',
            'status'         => 'posted',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function daily(?int $branchId = null, ?User $user = null): array
    {
        return app(SalesReportService::class)->dailyStats(
            $this->yesterday, $this->today, $branchId ?? $this->branchId, $user
        );
    }

    /* ── the population ──────────────────────────────────────────────────── */

    /**
     * THE test. A partially returned bill keeps the money that was not handed back — and the old
     * card threw the whole bill away, count and money together.
     */
    public function test_a_partially_returned_bill_keeps_its_count_and_its_kept_money(): void
    {
        $this->bill($this->today, 1000);                                  // plain paid
        $partial = $this->bill($this->today, 500, 'partially_returned');
        $this->refund($partial, $this->today, 120);                       // 380 stays with the shop

        $row = $this->daily()[$this->today] ?? null;

        $this->assertNotNull($row, 'the day must appear at all');
        $this->assertSame(2, $row['orders'],
            'a partially returned bill is still a bill — the old card dropped it and reported 1');
        $this->assertEqualsWithDelta(1380.00, $row['net_sales'], 0.01,
            '1000 + 500 − 120 refunded. The old card reported 1000 and lost the 380 that was kept');
    }

    /** A fully returned bill still counts as a bill; its money nets to nothing. */
    public function test_a_fully_returned_bill_still_counts_as_an_order(): void
    {
        $this->bill($this->today, 1000);
        $full = $this->bill($this->today, 740, 'returned');
        $this->refund($full, $this->today, 740);

        $row = $this->daily()[$this->today];

        $this->assertSame(2, $row['orders'], 'the bill happened, so it is counted');
        $this->assertEqualsWithDelta(1000.00, $row['net_sales'], 0.01, 'and its money nets to zero');
    }

    /** The card and the tile must agree — that is the whole complaint. */
    public function test_the_seven_day_row_agrees_with_the_today_tile(): void
    {
        $this->bill($this->today, 1000);
        $partial = $this->bill($this->today, 500, 'partially_returned');
        $this->refund($partial, $this->today, 120);
        $full = $this->bill($this->today, 300, 'returned');
        $this->refund($full, $this->today, 300);

        $service = app(SalesReportService::class);
        $tile    = $service->todayStats($this->branchId);
        $row     = $service->dailyStats($this->yesterday, $this->today, $this->branchId)[$this->today];

        $this->assertSame($tile['order_count'], $row['orders'],
            'the two figures on one screen must be the same figure');
        $this->assertEqualsWithDelta($tile['net_sales'], $row['net_sales'], 0.01,
            'and so must the money');
    }

    /** And with the Report Center, which is the authority both are supposed to follow. */
    public function test_it_agrees_with_the_report_centre_for_the_same_day(): void
    {
        $this->bill($this->today, 1000);
        $partial = $this->bill($this->today, 500, 'partially_returned');
        $this->refund($partial, $this->today, 120);

        $engine   = app(SalesReportEngine::class);
        $overview = $engine->overview($engine->normalizeFilters([
            'date_from' => $this->today, 'date_to' => $this->today, 'branch_ids' => [$this->branchId],
        ]));
        $row = $this->daily()[$this->today];

        $this->assertEqualsWithDelta((float) $overview['net_sales'], $row['net_sales'], 0.01,
            'the dashboard card and the Report Center must not disagree about one day');
    }

    /* ── what must stay out ──────────────────────────────────────────────── */

    /** Held and cancelled bills are not trading — neither figure may pick them up. */
    public function test_held_and_cancelled_bills_are_counted_by_neither(): void
    {
        $this->bill($this->today, 1000);
        $this->bill($this->today, 5000, 'held');
        $this->bill($this->today, 7000, 'cancelled');

        $row = $this->daily()[$this->today];

        $this->assertSame(1, $row['orders']);
        $this->assertEqualsWithDelta(1000.00, $row['net_sales'], 0.01);
    }

    /** A day with no returns behaves exactly as the old card did — no silent shift. */
    public function test_a_day_with_no_returns_is_unchanged(): void
    {
        $this->bill($this->yesterday, 800);
        $this->bill($this->yesterday, 200);

        $row = $this->daily()[$this->yesterday];

        $this->assertSame(2, $row['orders']);
        $this->assertEqualsWithDelta(1000.00, $row['net_sales'], 0.01);
    }

    /** Another branch's trading stays out of this branch's row. */
    public function test_another_branch_is_not_included(): void
    {
        $this->bill($this->today, 1000);
        $this->bill($this->today, 9999, 'paid', $this->otherBranchId);

        $row = $this->daily()[$this->today];

        $this->assertSame(1, $row['orders']);
        $this->assertEqualsWithDelta(1000.00, $row['net_sales'], 0.01);
    }

    /** Days are keyed on the BUSINESS date, not on when the row happened to be written. */
    public function test_days_are_split_on_the_business_date(): void
    {
        $this->bill($this->yesterday, 400);
        $this->bill($this->today, 600);

        $stats = $this->daily();

        $this->assertEqualsWithDelta(400.00, $stats[$this->yesterday]['net_sales'], 0.01);
        $this->assertEqualsWithDelta(600.00, $stats[$this->today]['net_sales'], 0.01);
    }
}
