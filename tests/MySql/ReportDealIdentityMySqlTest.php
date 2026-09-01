<?php

namespace Tests\MySql;

use App\Services\Reports\SalesReportEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * REPORT-DEAL-IDENTITY-1 — a deal reports under its own name.
 *
 * A deal has no product of its own: its combo_header line sits on an existing product, very often
 * one of its own components. Grouping by product alone piled several different things into one row
 * and labelled it with MAX(product_name). On 31 Aug the owner read "Singaporean Rice (Regular)
 * (Midnight) — 196" when Midnight had sold 30; the rest was ordinary Regular plus four more deals.
 *
 * The one thing that must NOT change is the money. Grouping decides which rows exist, never the
 * sum — so that is the first assertion, and the one that would catch a mistake in the returns join.
 */
class ReportDealIdentityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $productId;      // one product, wearing three different hats
    private int $midnightId;
    private int $dealFiveId;
    private array $filters;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_return_lines', 'sales_returns', 'sales_order_lines', 'sales_orders',
            'combo_components', 'combos', 'products', 'categories', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->productId = $this->makeProduct(
            $this->makeCategory(['name' => 'Singaporean Rice', 'slug' => 'sr-' . Str::random(4)]),
            ['name' => 'Singaporean Rice (Regular)']
        );

        $mk = fn (string $name, float $price) => DB::connection('tenant')->table('combos')->insertGetId([
            'name' => $name, 'code' => Str::upper(Str::random(8)), 'price' => $price,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->midnightId = $mk('Singaporean Rice (Regular) (Midnight)', 550);
        $this->dealFiveId = $mk('Deal 5 (Serve 2)', 1620);

        $this->today = app(\App\Support\TenantClock::class)
            ->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
        $this->filters = app(SalesReportEngine::class)
            ->normalizeFilters(['date_from' => $this->today, 'date_to' => $this->today]);
    }

    /** @return array{0:int,1:int} sale id, line id */
    private function sell(?int $comboId, string $lineKind, float $qty, float $price, string $lineName): array
    {
        $saleId = $this->makeSale($this->branchId, [
            'status' => 'paid', 'order_type' => 'takeaway', 'grand_total' => $qty * $price,
        ]);
        $lineId = $this->makeSaleLine($saleId, $this->productId, [
            'product_name' => $lineName, 'quantity' => $qty, 'unit_price' => $price,
            'line_total' => $qty * $price, 'line_kind' => $lineKind, 'combo_id' => $comboId,
        ]);

        return [$saleId, $lineId];
    }

    private function returnLine(int $saleId, int $lineId, float $qty, float $amount): void
    {
        $returnId = DB::connection('tenant')->table('sales_returns')->insertGetId([
            'return_no' => 'SR-' . Str::upper(Str::random(8)), 'sales_order_id' => $saleId,
            'branch_id' => $this->branchId, 'status' => 'posted', 'return_date' => now(),
            'business_date' => $this->today, 'grand_total' => $amount, 'subtotal' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('sales_return_lines')->insert([
            'sales_return_id' => $returnId, 'sales_order_line_id' => $lineId,
            'product_id' => $this->productId, 'quantity' => $qty, 'unit_price' => $amount / $qty,
            'line_total' => $amount, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The live shape: one product carrying a plain sale and two different deals. */
    private function theLiveShape(): void
    {
        $this->sell(null, 'standard', 160, 600, 'Singaporean Rice (Regular)');
        $this->sell($this->midnightId, 'combo_header', 30, 550, 'Singaporean Rice (Regular) (Midnight)');
        $this->sell($this->dealFiveId, 'combo_header', 3, 1620, 'Deal 5 (Serve 2)');
    }

    private function rows(): \Illuminate\Support\Collection
    {
        return collect(app(SalesReportEngine::class)->byItem($this->filters));
    }

    /** THE assertion: rows may split, money may not move. */
    public function test_splitting_the_rows_does_not_move_a_single_rupee(): void
    {
        $this->theLiveShape();

        $expected = (float) DB::connection('tenant')->table('sales_order_lines')->sum('line_total');
        $this->assertEqualsWithDelta(
            $expected, (float) $this->rows()->sum(fn ($r) => (float) $r->net), 0.01,
            'the item rows must still add up to every line that was sold'
        );
    }

    /** What the owner actually asked: Midnight sold 30, not 196. */
    public function test_a_deal_reports_under_its_own_name_and_its_own_quantity(): void
    {
        $this->theLiveShape();
        $rows = $this->rows();

        $midnight = $rows->firstWhere('item', 'Singaporean Rice (Regular) (Midnight)');
        $plain = $rows->firstWhere('item', 'Singaporean Rice (Regular)');
        $dealFive = $rows->firstWhere('item', 'Deal 5 (Serve 2)');

        $this->assertNotNull($midnight, 'the deal must appear under its own name');
        $this->assertSame(30.0, (float) $midnight->sold_qty, 'Midnight sold 30 — not the whole product');
        $this->assertNotNull($plain);
        $this->assertSame(160.0, (float) $plain->sold_qty);
        $this->assertNotNull($dealFive);
        $this->assertSame(3.0, (float) $dealFive->sold_qty);
    }

    /** The name comes from the deal itself, so one line's wording cannot rename the row. */
    public function test_the_row_is_named_from_the_deal_not_from_a_line(): void
    {
        // the same deal rung up twice, with the line text differing
        $this->sell($this->midnightId, 'combo_header', 1, 550, 'ZZZ whatever the line said');
        $this->sell($this->midnightId, 'combo_header', 1, 550, 'Singaporean Rice (Regular) (Midnight)');

        $rows = $this->rows();
        $this->assertCount(1, $rows, 'one deal is one row');
        $this->assertSame('Singaporean Rice (Regular) (Midnight)', (string) $rows->first()->item);
        $this->assertSame(2.0, (float) $rows->first()->sold_qty);
    }

    /** A return follows its own line's deal — the part that could have moved money. */
    public function test_returns_land_on_the_row_they_came_from(): void
    {
        [$plainSale, $plainLine] = $this->sell(null, 'standard', 10, 600, 'Singaporean Rice (Regular)');
        [$dealSale, $dealLine] = $this->sell($this->midnightId, 'combo_header', 5, 550, 'Singaporean Rice (Regular) (Midnight)');

        $this->returnLine($plainSale, $plainLine, 4, 2400);
        $this->returnLine($dealSale, $dealLine, 1, 550);

        $rows = $this->rows();
        $plain = $rows->firstWhere('item', 'Singaporean Rice (Regular)');
        $midnight = $rows->firstWhere('item', 'Singaporean Rice (Regular) (Midnight)');

        $this->assertSame(4.0, (float) $plain->returned_qty, 'the ordinary return stays on the ordinary row');
        $this->assertEqualsWithDelta(2400.0, (float) $plain->returns_amount, 0.01);
        $this->assertSame(1.0, (float) $midnight->returned_qty, 'the deal return stays on the deal');
        $this->assertEqualsWithDelta(550.0, (float) $midnight->returns_amount, 0.01);

        $this->assertEqualsWithDelta(2950.0, (float) $rows->sum(fn ($r) => (float) $r->returns_amount), 0.01,
            'and the total returns are untouched — this is what a broken split would lose');
    }

    /** Components stay out, exactly as REPORT-DEAL-COMPONENTS-1 left them. */
    public function test_deal_components_are_still_excluded(): void
    {
        $this->sell($this->midnightId, 'combo_header', 5, 550, 'Singaporean Rice (Regular) (Midnight)');
        $this->sell($this->midnightId, 'component', 5, 0, 'Singaporean Rice (Regular)');

        $rows = $this->rows();

        $this->assertCount(1, $rows, 'the component adds no row of its own');
        $this->assertSame(5.0, (float) $rows->first()->sold_qty, 'and no quantity to the deal');
    }

    /** A plain product with no deals behaves exactly as it always did. */
    public function test_an_ordinary_product_is_unchanged(): void
    {
        $this->sell(null, 'standard', 7, 600, 'Singaporean Rice (Regular)');

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('Singaporean Rice (Regular)', (string) $rows->first()->item);
        $this->assertSame(7.0, (float) $rows->first()->sold_qty);
        $this->assertEqualsWithDelta(4200.0, (float) $rows->first()->net, 0.01);
    }
}
