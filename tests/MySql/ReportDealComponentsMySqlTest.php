<?php

namespace Tests\MySql;

use App\Services\Reports\SalesReportEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * REPORT-DEAL-COMPONENTS-1 — a deal's parts are not separate sales.
 *
 * Selling a deal writes a `combo_header` line carrying the whole price plus one `component` line
 * per linked item carrying 0.00. Counting those components made every quantity lie: on 30 Aug the
 * live report printed 1,620 units against 1,322 actually sold, Bar-B-Que read 148 when 87 were
 * sold, and rows like "Regular Drink 39 @ 0.00" read as 39 free drinks.
 *
 * The whole safety of the fix rests on one fact, so it is the first thing asserted here: a
 * component's line_total is 0.00, therefore removing it cannot move a single rupee.
 */
class ReportDealComponentsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $comboId;
    private int $dealProductId;      // the deal header's product
    private int $sharedProductId;    // used BOTH as the header's product and as a component
    private int $sideProductId;      // a component that is also sold on its own
    private array $filters;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_order_lines', 'sales_orders', 'combo_components', 'combos',
            'products', 'categories', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $cat = $this->makeCategory(['name' => 'Bar-B-Que', 'slug' => 'bbq-' . Str::random(4)]);

        $this->sharedProductId = $this->makeProduct($cat, ['name' => 'Rice of Khaas']);
        $this->sideProductId   = $this->makeProduct($cat, ['name' => 'Chicken Baluchi Boti']);
        $this->dealProductId   = $this->sharedProductId;   // the live Khaas case: header == component

        $this->comboId = DB::connection('tenant')->table('combos')->insertGetId([
            'name' => 'Singaporean Rice Khass (2-3 Persons)', 'code' => 'KHASS-' . Str::random(4),
            'price' => 2900, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $today = app(\App\Support\TenantClock::class)->currentBusinessDate();
        $this->filters = app(SalesReportEngine::class)
            ->normalizeFilters(['date_from' => $today, 'date_to' => $today]);
    }

    /** One deal sold: a header carrying the money, two components carrying nothing. */
    private function sellDeal(float $price = 2900): int
    {
        $saleId = $this->makeSale($this->branchId, ['status' => 'paid', 'order_type' => 'takeaway', 'grand_total' => $price]);

        $this->makeSaleLine($saleId, $this->dealProductId, [
            'product_name' => 'Singaporean Rice Khass (2-3 Persons)', 'quantity' => 1,
            'unit_price' => $price, 'line_total' => $price, 'line_kind' => 'combo_header', 'combo_id' => $this->comboId,
        ]);
        foreach ([[$this->sharedProductId, 'Rice of Khaas'], [$this->sideProductId, 'Chicken Baluchi Boti']] as [$pid, $name]) {
            $this->makeSaleLine($saleId, $pid, [
                'product_name' => $name, 'quantity' => 1,
                'unit_price' => 0, 'line_total' => 0, 'line_kind' => 'component', 'combo_id' => $this->comboId,
            ]);
        }

        return $saleId;
    }

    /** An ordinary sale of the same product, on its own. */
    private function sellOnItsOwn(int $productId, string $name, float $price): int
    {
        $saleId = $this->makeSale($this->branchId, ['status' => 'paid', 'order_type' => 'takeaway', 'grand_total' => $price]);
        $this->makeSaleLine($saleId, $productId, [
            'product_name' => $name, 'quantity' => 1,
            'unit_price' => $price, 'line_total' => $price, 'line_kind' => 'standard',
        ]);

        return $saleId;
    }

    private function itemRow(SalesReportEngine $engine, string $needle): ?object
    {
        foreach ($engine->byItem($this->filters) as $row) {
            if (str_contains((string) $row->item, $needle)) { return $row; }
        }

        return null;
    }

    /** THE assertion the fix rests on: components carry no money, so nothing financial moves. */
    public function test_components_carry_no_money_at_all(): void
    {
        $this->sellDeal(2900);
        $this->sellOnItsOwn($this->sideProductId, 'Chicken Baluchi Boti', 1250);

        $componentMoney = DB::connection('tenant')->table('sales_order_lines')
            ->where('line_kind', 'component')->sum('line_total');

        $this->assertSame(0.0, (float) $componentMoney,
            'if a component ever carried money, excluding it from the report would change the takings');

        $engine = app(SalesReportEngine::class);
        $overview = $engine->overview($this->filters);
        $this->assertEqualsWithDelta(4150.0, (float) $overview['net_sales'], 0.01,
            'the money is the deal (2900) plus the standalone side (1250) — nothing else');
    }

    /** The live bug: the deal sold once, and the report used to say two. */
    public function test_a_deal_whose_header_shares_a_product_with_its_component_is_not_doubled(): void
    {
        $this->sellDeal(2900);

        $row = $this->itemRow(app(SalesReportEngine::class), 'Singaporean Rice Khass');

        $this->assertNotNull($row, 'the deal itself must still appear, with its money');
        $this->assertSame(1.0, (float) $row->net_qty,
            'sold once — the header and its own rice component must not be added together');
        $this->assertEqualsWithDelta(2900.0, (float) $row->net, 0.01);
    }

    /** A component that was never sold on its own has no business in the items list. */
    public function test_a_pure_component_does_not_appear_as_a_zero_rupee_item(): void
    {
        $this->sellDeal(2900);

        $this->assertNull($this->itemRow(app(SalesReportEngine::class), 'Rice of Khaas'),
            'a part of a deal is not a sale — it must not read as a free item');
    }

    /** Sold on its own AND used in a deal: the row shows only what was actually sold. */
    public function test_an_item_sold_on_its_own_shows_only_the_real_sale(): void
    {
        $this->sellDeal(2900);                                                   // 1 inside the deal
        $this->sellOnItsOwn($this->sideProductId, 'Chicken Baluchi Boti', 1250); // 1 sold properly

        $row = $this->itemRow(app(SalesReportEngine::class), 'Chicken Baluchi Boti');

        $this->assertNotNull($row);
        $this->assertSame(1.0, (float) $row->net_qty, 'one was sold; the other went out inside a deal');
        $this->assertEqualsWithDelta(1250.0, (float) $row->net, 0.01, 'the money was always right');
    }

    /** Categories were the worst hit — Bar-B-Que read 148 when 87 had been sold. */
    public function test_category_quantities_exclude_deal_components(): void
    {
        $this->sellDeal(2900);
        $this->sellOnItsOwn($this->sideProductId, 'Chicken Baluchi Boti', 1250);

        $bbq = collect(app(SalesReportEngine::class)->byCategory($this->filters))
            ->firstWhere('name', 'Bar-B-Que');

        $this->assertNotNull($bbq);
        $this->assertSame(2.0, (float) $bbq['net_qty'],
            'the deal (1) and the standalone side (1) — not the two components riding inside the deal');
        $this->assertEqualsWithDelta(4150.0, (float) $bbq['net_value'], 0.01, 'money unchanged');
    }

    /** The line-by-line record still shows everything that was rung up. */
    public function test_the_detailed_section_still_lists_the_components(): void
    {
        $this->sellDeal(2900);

        // the detailed section aliases the line's product name to `item`
        $names = app(SalesReportEngine::class)->detailedQuery($this->filters)
            ->get()->pluck('item')->all();

        $this->assertContains('Rice of Khaas', $names,
            'detailed is the raw record — a deal\'s parts belong in it');
        $this->assertContains('Chicken Baluchi Boti', $names);
        $this->assertContains('Singaporean Rice Khass (2-3 Persons)', $names);
    }

    /** Quantity totals stop being inflated, while the takings stay put. */
    public function test_the_overview_quantity_drops_but_the_takings_do_not(): void
    {
        $this->sellDeal(2900);
        $this->sellOnItsOwn($this->sideProductId, 'Chicken Baluchi Boti', 1250);

        $overview = app(SalesReportEngine::class)->overview($this->filters);

        $this->assertSame(2.0, (float) $overview['sold_qty'],
            '4 lines were written, but only 2 things were sold');
        $this->assertEqualsWithDelta(4150.0, (float) $overview['net_sales'], 0.01);
    }
}
