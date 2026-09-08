<?php

namespace Tests\MySql;

use App\Services\Reports\RestaurantReportService;
use App\Services\Reports\SalesReportEngine;
use App\Services\Reports\SalesReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * LEGACY-REPORTS-POPULATION-1 — six report queries rejoin the population everyone else counts.
 *
 * They filtered `status = 'paid'` alone, so a returned or partially returned bill vanished from
 * them: items, payments, delivery channels, riders, tables, waiters and order types each lost it,
 * count and money together.
 *
 * This bug had already been found once. `SalesReportService::baseSalesQuery` carries the note: at
 * Khatri the same filter hid 8 orders and the delivery charges the shop legitimately kept, so the
 * counter was told to expect 22,500 when the drawer said 22,850. That correction reached one
 * function; these six were missed, and by the time they were measured the delivery reports alone
 * were short 44 orders worth 63,610.
 *
 * So the assertions here are deliberately NOT hard-coded totals. Each one says "this screen agrees
 * with SalesReportEngine for the same window" — the only assertion that keeps holding when the
 * engine is corrected again. A hard number would pass while drifting.
 *
 * The PARTIAL return is the case that matters. A test built on full returns alone would pass
 * against the old code, because dropping the bill and refunding all of it come to the same figure.
 */
class LegacyReportsPopulationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $productId;
    private int $waiterId;
    private int $tableId;
    private string $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanTenant([
            'sales_return_lines', 'sales_returns', 'sales_order_lines', 'sales_orders',
            'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'delivery_riders', 'delivery_channels', 'sale_payments', 'payment_methods',
            'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        $c = DB::connection('tenant');

        $this->branchId = $this->makeBranch(['name' => 'Main']);
        $category       = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(5)]);
        $this->productId = $this->makeProduct($category, ['name' => 'Biryani ' . Str::random(4)]);
        $this->waiterId  = $this->makeWaiter($this->branchId);

        $floorId = $c->table('restaurant_floors')->insertGetId([
            'branch_id' => $this->branchId, 'name' => 'Ground', 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->tableId = $c->table('restaurant_tables')->insertGetId([
            'branch_id' => $this->branchId, 'restaurant_floor_id' => $floorId,
            'table_no' => 'T1', 'capacity' => 4, 'status' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->day = app(\App\Support\TenantClock::class)
            ->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
    }

    /* ── fixtures ────────────────────────────────────────────────────────── */

    /** A bill of `$total`, of which `$refund` was later handed back (0 = nothing returned). */
    private function bill(float $total, float $refund = 0.0, array $attrs = []): int
    {
        $status = $refund <= 0 ? 'paid' : ($refund >= $total ? 'returned' : 'partially_returned');

        $saleId = $this->makeSale($this->branchId, array_merge([
            'sale_no'       => 'SO-' . Str::upper(Str::random(10)),
            'status'        => $status,
            'order_type'    => 'dine_in',
            'business_date' => $this->day,
            'grand_total'   => $total,
            'subtotal'      => $total,
            'restaurant_waiter_id'  => $this->waiterId,
            'restaurant_table_id'   => $this->tableId,
        ], $attrs));

        $this->makeSaleLine($saleId, $this->productId, [
            'quantity' => 1, 'unit_price' => $total, 'line_total' => $total,
        ]);

        if ($refund > 0) {
            DB::connection('tenant')->table('sales_returns')->insert([
                'return_no' => 'RT-' . Str::upper(Str::random(8)),
                'sales_order_id' => $saleId, 'branch_id' => $this->branchId,
                'return_date' => now(), 'business_date' => $this->day,
                'subtotal' => $refund, 'grand_total' => $refund, 'refund_amount' => $refund,
                'refund_method' => 'cash', 'status' => 'posted',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $saleId;
    }

    private function filters(): array
    {
        return ['date_from' => $this->day, 'date_to' => $this->day, 'branch_id' => $this->branchId];
    }

    private function engineOverview(): array
    {
        $engine = app(SalesReportEngine::class);

        return $engine->overview($engine->normalizeFilters([
            'date_from' => $this->day, 'date_to' => $this->day, 'branch_ids' => [$this->branchId],
        ]));
    }

    /** The mixed day every test below is measured against: one clean, one partial, one full. */
    private function seedMixedDay(): void
    {
        $this->bill(1000);          // clean
        $this->bill(500, 120);      // partial — 380 stays with the shop
        $this->bill(300, 300);      // full   — nets to nothing, but the bill happened
    }

    /* ── restaurant reports ──────────────────────────────────────────────── */

    public function test_the_waiters_report_agrees_with_the_engine(): void
    {
        $this->seedMixedDay();

        $rows = app(RestaurantReportService::class)->waiters($this->filters());

        $this->assertSame(3, (int) $rows->sum('order_count'),
            'all three bills were served by this waiter — the old report showed 1');
        $this->assertEqualsWithDelta(
            (float) $this->engineOverview()['net_sales'],
            (float) $rows->sum('net_sales'), 0.01,
            'a waiter total must not disagree with the Report Center for the same day'
        );
    }

    public function test_the_tables_report_agrees_with_the_engine(): void
    {
        $this->seedMixedDay();

        $rows = app(RestaurantReportService::class)->tables($this->filters());

        $this->assertSame(3, (int) $rows->sum('order_count'));
        $this->assertEqualsWithDelta(
            (float) $this->engineOverview()['net_sales'],
            (float) $rows->sum('net_sales'), 0.01
        );
    }

    public function test_the_order_types_report_agrees_with_the_engine(): void
    {
        $this->seedMixedDay();

        $rows = app(RestaurantReportService::class)->orderTypes($this->filters());

        $this->assertSame(3, (int) $rows->sum('order_count'));
        $this->assertEqualsWithDelta(
            (float) $this->engineOverview()['net_sales'],
            (float) $rows->sum('net_sales'), 0.01
        );
    }

    /**
     * A bill refunded TWICE must not be double-counted. The refunds are joined as a per-order
     * subquery precisely so the aggregate cannot fan out; a plain join to sales_returns would
     * duplicate the order row and inflate every column on it.
     */
    public function test_a_bill_refunded_twice_is_not_counted_twice(): void
    {
        $saleId = $this->bill(1000, 100);
        DB::connection('tenant')->table('sales_returns')->insert([
            'return_no' => 'RT-' . Str::upper(Str::random(8)),
            'sales_order_id' => $saleId, 'branch_id' => $this->branchId,
            'return_date' => now(), 'business_date' => $this->day,
            'subtotal' => 50, 'grand_total' => 50, 'refund_amount' => 50,
            'refund_method' => 'cash', 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = app(RestaurantReportService::class)->waiters($this->filters());

        $this->assertSame(1, (int) $rows->sum('order_count'), 'one bill, however many refunds');
        $this->assertEqualsWithDelta(850.00, (float) $rows->sum('net_sales'), 0.01,
            '1000 − 100 − 50');
    }

    /* ── sales reports ───────────────────────────────────────────────────── */

    /**
     * The items screen was wrong in two directions at once, and the errors partly cancelled: short
     * on money because returned bills were dropped, long on quantity because a deal's components
     * counted as separate sales.
     */
    public function test_the_items_report_counts_returned_bills_and_ignores_combo_components(): void
    {
        $this->bill(1000);
        $partial = $this->bill(500, 120);

        // A deal: header carries the money, the component carries nothing.
        $this->makeSaleLine($partial, $this->productId, [
            'quantity' => 1, 'unit_price' => 0, 'line_total' => 0, 'line_kind' => 'component',
        ]);

        $rows = app(SalesReportService::class)->items($this->filters());

        $this->assertEqualsWithDelta(2.0, (float) $rows->sum('qty_sold'), 0.01,
            "a deal's component is not a separate sale — the old screen counted 3");
        $this->assertEqualsWithDelta(1500.00, (float) $rows->sum('net_amount'), 0.01,
            'the returned bill keeps its line value; the refund is a separate document');
    }

    /** Money taken at the counter is money taken, whatever happened to the bill afterwards. */
    public function test_the_payments_report_includes_money_taken_on_a_returned_bill(): void
    {
        $c = DB::connection('tenant');
        $methodId = $c->table('payment_methods')->insertGetId([
            'code' => 'CASH', 'name' => 'Cash', 'method_type' => 'cash', 'is_cash_drawer' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[1000, 0.0], [500, 120.0], [300, 300.0]] as [$total, $refund]) {
            $saleId = $this->bill((float) $total, $refund);
            $c->table('sale_payments')->insert([
                'sales_order_id' => $saleId, 'payment_method_id' => $methodId,
                'amount' => $total, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $rows = app(SalesReportService::class)->payments($this->filters());

        $this->assertEqualsWithDelta(1800.00, (float) $rows->sum('total_amount'), 0.01,
            'every rupee that crossed the counter: 1000 + 500 + 300. The refunds are their own '
            . 'document and must not be netted off a receipts report');
    }

    /** Channels and riders share one query, so one bill proves both. */
    public function test_the_delivery_reports_include_returned_orders(): void
    {
        $c = DB::connection('tenant');
        $channelId = $c->table('delivery_channels')->insertGetId([
            'name' => 'Own Delivery', 'type' => 'own', 'commission_percent' => 10,
            'is_active' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $riderId = $c->table('delivery_riders')->insertGetId([
            'branch_id' => $this->branchId, 'name' => 'Moeen', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $delivery = ['order_type' => 'delivery', 'delivery_channel_id' => $channelId, 'delivery_rider_id' => $riderId];
        $this->bill(1000, 0, $delivery);
        $this->bill(500, 120, $delivery);   // partial — the shop kept 380
        $this->bill(300, 300, $delivery);   // full

        $service = app(SalesReportService::class);

        $channels = collect($service->byChannel($this->filters()));
        $this->assertSame(3, (int) $channels->sum('order_count'),
            'the old query showed 1 — this is the 44-orders-at-Khatri case in miniature');
        $this->assertEqualsWithDelta(1800.00, (float) $channels->sum('gross_amount'), 0.01);
        $this->assertEqualsWithDelta(180.00, (float) $channels->sum('commission_amount'), 0.01,
            'commission follows the population, and the higher figure is the correct one');

        $riders = collect($service->byRider($this->filters())['riders'] ?? $service->byRider($this->filters()));
        $this->assertSame(3, (int) $riders->sum('delivery_count'),
            'a rider carried three orders, whatever happened to them afterwards');
    }

    /** A day with no returns must behave exactly as it did before — no silent shift. */
    public function test_a_day_with_no_returns_is_unchanged(): void
    {
        $this->bill(800);
        $this->bill(200);

        $rows = app(RestaurantReportService::class)->waiters($this->filters());

        $this->assertSame(2, (int) $rows->sum('order_count'));
        $this->assertEqualsWithDelta(1000.00, (float) $rows->sum('net_sales'), 0.01);
    }

    /** Held and cancelled bills are not trading, and none of these reports may pick them up. */
    public function test_held_and_cancelled_bills_stay_out(): void
    {
        $this->bill(1000);
        $this->makeSale($this->branchId, [
            'sale_no' => 'SO-' . Str::upper(Str::random(10)), 'status' => 'held',
            'order_type' => 'dine_in', 'business_date' => $this->day, 'grand_total' => 5000,
            'restaurant_waiter_id' => $this->waiterId, 'restaurant_table_id' => $this->tableId,
        ]);
        $this->makeSale($this->branchId, [
            'sale_no' => 'SO-' . Str::upper(Str::random(10)), 'status' => 'cancelled',
            'order_type' => 'dine_in', 'business_date' => $this->day, 'grand_total' => 7000,
            'restaurant_waiter_id' => $this->waiterId, 'restaurant_table_id' => $this->tableId,
        ]);

        $rows = app(RestaurantReportService::class)->waiters($this->filters());

        $this->assertSame(1, (int) $rows->sum('order_count'));
        $this->assertEqualsWithDelta(1000.00, (float) $rows->sum('net_sales'), 0.01);
    }
}
