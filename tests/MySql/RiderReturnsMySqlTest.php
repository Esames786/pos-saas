<?php

namespace Tests\MySql;

use App\Services\Reports\SalesReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * RIDER-RETURNS-1 — the delivery reports say what came back, not just what went out.
 *
 * LEGACY-REPORTS-POPULATION-1 brought returned bills back into these two screens, which was right
 * — a rider carried the order whether or not it was later refunded. But it left both pages showing
 * a single "Total Amount" that silently mixed money kept with money handed back, and both still
 * called themselves "Paid delivery orders". At Khatri that read as 28 deliveries for 61,720 where
 * the day before it would have said 25 for 57,250, with nothing on the page to explain the jump.
 *
 * So the columns are split: Deliveries / Returned / Total / Returns / Net. The assertions below
 * pin the arithmetic that ties them together, and the fan-out case that would quietly break it.
 */
class RiderReturnsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $productId;
    private int $riderId;
    private int $channelId;
    private string $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanTenant([
            'sales_return_lines', 'sales_returns', 'sales_order_lines', 'sales_orders',
            'delivery_riders', 'delivery_channels', 'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        $c = DB::connection('tenant');

        $this->branchId  = $this->makeBranch(['name' => 'Main']);
        $category        = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(5)]);
        $this->productId = $this->makeProduct($category, ['name' => 'Biryani ' . Str::random(4)]);

        $this->channelId = $c->table('delivery_channels')->insertGetId([
            'name' => 'Own Delivery', 'type' => 'own', 'commission_percent' => 10,
            'is_active' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->riderId = $c->table('delivery_riders')->insertGetId([
            'branch_id' => $this->branchId, 'name' => 'Moeen', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->day = app(\App\Support\TenantClock::class)
            ->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
    }

    /* ── fixtures ────────────────────────────────────────────────────────── */

    /** A delivery bill of `$total`, of which `$refund` was later handed back. */
    private function bill(float $total, float $refund = 0.0): int
    {
        $status = $refund <= 0 ? 'paid' : ($refund >= $total ? 'returned' : 'partially_returned');

        $saleId = $this->makeSale($this->branchId, [
            'sale_no'             => 'SO-' . Str::upper(Str::random(10)),
            'status'              => $status,
            'order_type'          => 'delivery',
            'business_date'       => $this->day,
            'grand_total'         => $total,
            'subtotal'            => $total,
            'delivery_channel_id' => $this->channelId,
            'delivery_rider_id'   => $this->riderId,
        ]);

        $this->makeSaleLine($saleId, $this->productId, [
            'quantity' => 1, 'unit_price' => $total, 'line_total' => $total,
        ]);

        if ($refund > 0) {
            $this->refund($saleId, $refund);
        }

        return $saleId;
    }

    private function refund(int $saleId, float $amount, string $status = 'posted'): void
    {
        DB::connection('tenant')->table('sales_returns')->insert([
            'return_no'      => 'RT-' . Str::upper(Str::random(8)),
            'sales_order_id' => $saleId, 'branch_id' => $this->branchId,
            'return_date'    => now(), 'business_date' => $this->day,
            'subtotal'       => $amount, 'grand_total' => $amount, 'refund_amount' => $amount,
            'refund_method'  => 'cash', 'status' => $status,
            'created_at'     => now(), 'updated_at' => now(),
        ]);
    }

    private function filters(): array
    {
        return ['date_from' => $this->day, 'date_to' => $this->day, 'branch_id' => $this->branchId];
    }

    /** One clean, one partial, one full: 1800 delivered, 420 back, 1380 kept. */
    private function seedMixedDay(): void
    {
        $this->bill(1000);
        $this->bill(500, 120);
        $this->bill(300, 300);
    }

    /* ── riders ──────────────────────────────────────────────────────────── */

    public function test_the_rider_totals_split_deliveries_from_returns(): void
    {
        $this->seedMixedDay();

        $row = collect(app(SalesReportService::class)->byRider($this->filters())['riders'])->first();

        $this->assertSame(3, (int) $row->delivery_count, 'the rider carried all three');
        $this->assertSame(2, (int) $row->returned_count, 'two of them came back, wholly or in part');
        $this->assertEqualsWithDelta(1800.00, (float) $row->total_amount, 0.01);
        $this->assertEqualsWithDelta(420.00, (float) $row->returns_amount, 0.01, '120 + 300');
        $this->assertEqualsWithDelta(1380.00, (float) $row->net_amount, 0.01, '1800 − 420');
    }

    public function test_the_per_day_breakdown_carries_the_same_split(): void
    {
        $this->seedMixedDay();

        $row = collect(app(SalesReportService::class)->byRider($this->filters())['daily'])->first();

        $this->assertSame(3, (int) $row->delivery_count);
        $this->assertSame(2, (int) $row->returned_count);
        $this->assertEqualsWithDelta(1800.00, (float) $row->total_amount, 0.01);
        $this->assertEqualsWithDelta(420.00, (float) $row->returns_amount, 0.01);
        $this->assertEqualsWithDelta(1380.00, (float) $row->net_amount, 0.01,
            'the totals table and the day rows must not tell different stories');
    }

    /* ── channels ────────────────────────────────────────────────────────── */

    public function test_the_channel_row_splits_returns_and_still_bills_commission_on_gross(): void
    {
        $this->seedMixedDay();

        $row = collect(app(SalesReportService::class)->byChannel($this->filters()))->first();

        $this->assertSame(3, (int) $row->order_count);
        $this->assertSame(2, (int) $row->returned_count);
        $this->assertEqualsWithDelta(1800.00, (float) $row->gross_amount, 0.01);
        $this->assertEqualsWithDelta(420.00, (float) $row->returns_amount, 0.01);
        $this->assertEqualsWithDelta(1380.00, (float) $row->net_amount, 0.01);
        $this->assertEqualsWithDelta(180.00, (float) $row->commission_amount, 0.01,
            'commission stays on gross — whether an aggregator refunds its cut is settled in '
            . 'their statement, not on this page. Netting it here would quietly change a figure '
            . 'the owner already reconciles against.');
    }

    /* ── the ways this arithmetic breaks ─────────────────────────────────── */

    /**
     * The refunds are joined as a per-order subquery precisely so the aggregate cannot fan out.
     * A plain join to sales_returns would duplicate the order row for a bill refunded twice and
     * inflate EVERY column on it — the count, the gross and the net together.
     */
    public function test_a_bill_refunded_twice_neither_double_counts_nor_fans_out(): void
    {
        $saleId = $this->bill(1000, 100);
        $this->refund($saleId, 50);

        $row = collect(app(SalesReportService::class)->byRider($this->filters())['riders'])->first();

        $this->assertSame(1, (int) $row->delivery_count, 'one delivery, however many refunds');
        $this->assertSame(1, (int) $row->returned_count, 'and it is one returned order, not two');
        $this->assertEqualsWithDelta(1000.00, (float) $row->total_amount, 0.01,
            'a second refund must not make the rider look like he carried 2,000');
        $this->assertEqualsWithDelta(150.00, (float) $row->returns_amount, 0.01, '100 + 50');
        $this->assertEqualsWithDelta(850.00, (float) $row->net_amount, 0.01);
    }

    /** A reversed return is not money handed back — only `posted` counts. */
    public function test_a_cancelled_return_is_ignored(): void
    {
        $saleId = $this->bill(1000);
        $this->refund($saleId, 400, 'cancelled');

        $row = collect(app(SalesReportService::class)->byRider($this->filters())['riders'])->first();

        $this->assertSame(0, (int) $row->returned_count);
        $this->assertEqualsWithDelta(0.00, (float) $row->returns_amount, 0.01);
        $this->assertEqualsWithDelta(1000.00, (float) $row->net_amount, 0.01);
    }

    /** A day with no returns must read exactly as it did before the columns existed. */
    public function test_a_day_with_no_returns_is_unchanged(): void
    {
        $this->bill(800);
        $this->bill(200);

        $row = collect(app(SalesReportService::class)->byRider($this->filters())['riders'])->first();

        $this->assertSame(2, (int) $row->delivery_count);
        $this->assertSame(0, (int) $row->returned_count);
        $this->assertEqualsWithDelta(1000.00, (float) $row->total_amount, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $row->returns_amount, 0.01);
        $this->assertEqualsWithDelta(1000.00, (float) $row->net_amount, 0.01,
            'net equals total when nothing came back');
    }
}
