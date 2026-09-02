<?php

namespace Tests\MySql;

use App\Services\Reports\SalesReportEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * ITEMS-BY-CATEGORY-1 — the Items rows, filed under category heads, as a section of its own.
 *
 * The client's old software prints its item report under category heads with a subtotal each. The
 * owner reads both papers side by side and asked for the same shape — beside the existing Items
 * section, not instead of it, exactly as Deals was added the day before. So the two rules this
 * section lives or dies by are:
 *
 *   1. it must never disagree with Items, because it is the same rows; and
 *   2. each head must reconcile against the Categories section, because that is the whole point
 *      of a head — the owner adds it up against a figure he already has.
 *
 * Both are asserted as RELATIONSHIPS, never as hard-coded totals: a hard number would keep passing
 * while the two sections quietly drifted apart, which is the failure this guard exists to catch.
 */
class ItemsByCategoryMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $riceRoot;      // "Biryani" — a root with children
    private int $plainLeaf;     // "Biryani → Plain"
    private int $specialLeaf;   // "Biryani → Special"
    private int $drinksFlat;    // "Drinks" — a root with no children
    private int $dealsRoot;
    private int $plainProduct;
    private int $specialProduct;
    private int $drinkProduct;
    private int $comboId;
    private string $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanTenant([
            'sales_return_lines', 'sales_returns', 'sales_order_lines', 'sales_orders',
            'combo_components', 'combos', 'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        $c = DB::connection('tenant');

        $this->branchId = $this->makeBranch(['name' => 'Main']);

        $this->riceRoot    = $this->makeCategory(['name' => 'Biryani', 'slug' => 'biryani-' . Str::random(5)]);
        $this->plainLeaf   = $this->makeCategory(['name' => 'Plain', 'slug' => 'plain-' . Str::random(5), 'parent_id' => $this->riceRoot]);
        $this->specialLeaf = $this->makeCategory(['name' => 'Special', 'slug' => 'special-' . Str::random(5), 'parent_id' => $this->riceRoot]);
        $this->drinksFlat  = $this->makeCategory(['name' => 'Drinks', 'slug' => 'drinks-' . Str::random(5)]);
        $this->dealsRoot   = $this->makeCategory(['name' => 'Deals', 'slug' => 'deals-' . Str::random(5)]);

        $this->plainProduct   = $this->makeProduct($this->plainLeaf, ['name' => 'Plain Biryani']);
        $this->specialProduct = $this->makeProduct($this->specialLeaf, ['name' => 'Special Biryani']);
        $this->drinkProduct   = $this->makeProduct($this->drinksFlat, ['name' => 'Cola']);

        $this->comboId = (int) $c->table('combos')->insertGetId([
            'branch_id' => $this->branchId, 'category_id' => $this->dealsRoot,
            'code' => 'D1-' . Str::upper(Str::random(5)), 'name' => 'Family Deal',
            'price' => 2000, 'sort_order' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('combo_components')->insert([
            'combo_id' => $this->comboId, 'product_id' => $this->plainProduct, 'quantity' => 1,
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->day = app(\App\Support\TenantClock::class)
            ->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
    }

    /* ── fixtures ────────────────────────────────────────────────────────── */

    private function sell(int $productId, float $price, float $qty = 1): int
    {
        $saleId = $this->makeSale($this->branchId, [
            'sale_no' => 'SO-' . Str::upper(Str::random(10)), 'status' => 'paid',
            'order_type' => 'takeaway', 'business_date' => $this->day,
            'grand_total' => $price * $qty, 'subtotal' => $price * $qty,
        ]);
        $this->makeSaleLine($saleId, $productId, [
            'quantity' => $qty, 'unit_price' => $price, 'line_total' => $price * $qty,
        ]);

        return $saleId;
    }

    /** A deal: the header carries the money on its first component's product. */
    private function sellDeal(float $price): int
    {
        $saleId = $this->makeSale($this->branchId, [
            'sale_no' => 'SO-' . Str::upper(Str::random(10)), 'status' => 'paid',
            'order_type' => 'takeaway', 'business_date' => $this->day,
            'grand_total' => $price, 'subtotal' => $price,
        ]);
        $this->makeSaleLine($saleId, $this->plainProduct, [
            'quantity' => 1, 'unit_price' => $price, 'line_total' => $price,
            'line_kind' => 'combo_header', 'combo_id' => $this->comboId,
        ]);
        $this->makeSaleLine($saleId, $this->plainProduct, [
            'quantity' => 1, 'unit_price' => 0, 'line_total' => 0,
            'line_kind' => 'component', 'combo_id' => $this->comboId,
        ]);

        return $saleId;
    }

    private function refund(int $saleId, float $amount, int $productId, float $qty = 1): void
    {
        $c = DB::connection('tenant');
        $lineId = $c->table('sales_order_lines')->where('sales_order_id', $saleId)
            ->where('product_id', $productId)->orderBy('id')->value('id');

        $returnId = $c->table('sales_returns')->insertGetId([
            'return_no' => 'RT-' . Str::upper(Str::random(8)),
            'sales_order_id' => $saleId, 'branch_id' => $this->branchId,
            'return_date' => now(), 'business_date' => $this->day,
            'subtotal' => $amount, 'grand_total' => $amount, 'refund_amount' => $amount,
            'refund_method' => 'cash', 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('sales_return_lines')->insert([
            'sales_return_id' => $returnId, 'sales_order_line_id' => $lineId,
            'product_id' => $productId, 'quantity' => $qty,
            'unit_price' => $amount / max($qty, 1), 'line_total' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('sales_orders')->where('id', $saleId)->update(['status' => 'partially_returned']);
    }

    private function filters(): array
    {
        return app(SalesReportEngine::class)->normalizeFilters([
            'date_from' => $this->day, 'date_to' => $this->day, 'branch_ids' => [$this->branchId],
        ]);
    }

    private function headNamed(array $heads, string $name): ?array
    {
        foreach ($heads as $h) {
            if ($h['head'] === $name) {
                return $h;
            }
        }

        return null;
    }

    /** Net of one root head in the Categories section — the figure a head must reconcile to. */
    private function categoryNet(string $name): float
    {
        foreach (app(SalesReportEngine::class)->byCategory($this->filters()) as $root) {
            if ($root['name'] === $name) {
                return round((float) $root['net'] - (float) ($root['returns_amount'] ?? 0), 2);
            }
        }

        return 0.0;
    }

    /** The mixed day every test is measured against. */
    private function seedDay(): void
    {
        $this->sell($this->plainProduct, 400, 3);      // Biryani → Plain    1,200
        $this->sell($this->specialProduct, 700, 2);    // Biryani → Special  1,400
        $this->sell($this->drinkProduct, 120, 5);      // Drinks (flat)        600
        $this->sellDeal(2000);                         // Deals              2,000
    }

    /* ── the two rules ───────────────────────────────────────────────────── */

    public function test_the_section_totals_exactly_what_the_items_section_totals(): void
    {
        $this->seedDay();
        $engine = app(SalesReportEngine::class);

        $items = collect($engine->byItem($this->filters(), 'net', true));
        $heads = collect($engine->byCategoryItems($this->filters()));

        $this->assertEqualsWithDelta(
            (float) $items->sum('net_value'),
            (float) $heads->sum('net_value'), 0.01,
            'this section IS the Items rows rearranged — if the two totals can differ, one of them is lying'
        );
        $this->assertEqualsWithDelta(
            (float) $items->sum('sold_qty'),
            (float) $heads->sum('sold_qty'), 0.01
        );
    }

    public function test_every_head_reconciles_against_the_categories_section(): void
    {
        $this->seedDay();

        foreach (app(SalesReportEngine::class)->byCategoryItems($this->filters()) as $head) {
            if ($head['head'] === 'Deals') {
                continue; // deals live in their own section; the head is checked below
            }
            $this->assertEqualsWithDelta(
                $this->categoryNet($head['head']),
                (float) $head['net_value'], 0.01,
                "the head [{$head['head']}] must add up to the same figure the Categories section prints"
            );
        }
    }

    /* ── shape ───────────────────────────────────────────────────────────── */

    public function test_a_nested_root_shows_its_children_and_a_flat_one_does_not(): void
    {
        $this->seedDay();
        $heads = app(SalesReportEngine::class)->byCategoryItems($this->filters());

        $biryani = $this->headNamed($heads, 'Biryani');
        $this->assertNotNull($biryani, 'items filed under a child report under the ROOT head');
        $this->assertTrue($biryani['nested'], 'Biryani has Plain and Special beneath it');
        $this->assertSame(['Special', 'Plain'], array_column($biryani['groups'], 'name'),
            'sub-heads come biggest first: Special 1,400 before Plain 1,200');
        $this->assertEqualsWithDelta(2600.00, (float) $biryani['net_value'], 0.01, '1,200 + 1,400');

        $drinks = $this->headNamed($heads, 'Drinks');
        $this->assertNotNull($drinks);
        $this->assertFalse($drinks['nested'],
            'a flat category must not print a sub-head that only repeats the head above it');
        $this->assertEqualsWithDelta(600.00, (float) $drinks['net_value'], 0.01);
    }

    public function test_heads_and_items_are_ordered_by_value(): void
    {
        $this->seedDay();
        $heads = app(SalesReportEngine::class)->byCategoryItems($this->filters());

        $values = array_column($heads, 'net_value');
        $sorted = $values;
        rsort($sorted);
        $this->assertSame($sorted, $values, 'the biggest head leads, as the Items section does');
    }

    /* ── the ways this could double-count ────────────────────────────────── */

    public function test_deals_are_not_in_this_section(): void
    {
        $this->seedDay();
        $heads = app(SalesReportEngine::class)->byCategoryItems($this->filters());

        $this->assertNull($this->headNamed($heads, 'Deals'),
            'a deal has its own section; counting it here as well would double its money');

        $engine = app(SalesReportEngine::class);
        $deals  = collect($engine->byDeal($this->filters()))->sum('net_value');
        $this->assertEqualsWithDelta(2000.00, (float) $deals, 0.01, 'the deal is still counted — once, over there');

        $categories = collect($engine->byCategory($this->filters()))
            ->sum(fn ($r) => (float) $r['net'] - (float) ($r['returns_amount'] ?? 0));
        $this->assertEqualsWithDelta(
            $categories,
            collect($heads)->sum('net_value') + $deals, 0.01,
            'Categories = this section + Deals, with nothing counted twice and nothing lost'
        );
    }

    public function test_a_component_line_is_not_an_item_of_its_own(): void
    {
        $this->sellDeal(2000);
        $heads = app(SalesReportEngine::class)->byCategoryItems($this->filters());

        $this->assertSame([], $heads,
            "a deal contributes a header and a zero-priced component; neither belongs here, so a day "
            . 'of deals alone leaves this section empty rather than listing the parts');
    }

    /* ── the bridge to NET SALES ─────────────────────────────────────────── */

    /**
     * BRIDGE-DEALS-1 — a section that leaves the deals out is short of NET SALES by the deals as
     * well as by the charges. The A4 bridge used to print that whole gap as "Plus Delivery & Other
     * Charges": at Kashif Food on 2 September it read 95,859 where the real charges were 4,369,
     * and the missing 91,490 was deal money wearing a name that was not its own.
     *
     * `dealsNet()` is what lets the bridge name them separately, so it must agree with the Deals
     * section to the paisa — two numbers for "the deals" is how this drifts again.
     */
    public function test_the_bridges_deals_figure_agrees_with_the_deals_section(): void
    {
        $this->seedDay();
        $engine = app(SalesReportEngine::class);

        $this->assertEqualsWithDelta(
            (float) collect($engine->byDeal($this->filters()))->sum('net_value'),
            $engine->dealsNet($this->filters()), 0.01,
            'the bridge and the Deals section must not disagree about what the deals came to'
        );
    }

    /** Items + deals is the whole of the merchandise — what is left over really is charges. */
    public function test_items_plus_deals_is_the_whole_of_the_merchandise(): void
    {
        $this->seedDay();
        $engine = app(SalesReportEngine::class);

        $items = collect($engine->byCategoryItems($this->filters()))->sum('net_value');
        $deals = $engine->dealsNet($this->filters());
        $cats  = collect($engine->byCategory($this->filters()))
            ->sum(fn ($r) => (float) $r['net'] - (float) ($r['returns_amount'] ?? 0));

        $this->assertEqualsWithDelta($cats, $items + $deals, 0.01,
            'whatever the bridge then calls "charges" is the gap to NET SALES and nothing else');
    }

    /** A tenant with no deals gets no deals line — the bridge must not invent one. */
    public function test_a_tenant_with_no_deals_has_nothing_to_bridge_for_them(): void
    {
        $this->sell($this->drinkProduct, 120, 5);

        $this->assertEqualsWithDelta(0.0, app(SalesReportEngine::class)->dealsNet($this->filters()), 0.01);
    }

    /* ── returns ─────────────────────────────────────────────────────────── */

    public function test_a_refund_comes_off_the_head_it_was_sold_under(): void
    {
        $this->seedDay();
        $saleId = $this->sell($this->specialProduct, 700, 1);   // Special now 2,100
        $this->refund($saleId, 700, $this->specialProduct);

        $heads   = app(SalesReportEngine::class)->byCategoryItems($this->filters());
        $biryani = $this->headNamed($heads, 'Biryani');
        $special = collect($biryani['groups'])->firstWhere('name', 'Special');

        $this->assertEqualsWithDelta(700.00, (float) $special['returns_amount'], 0.01);
        $this->assertEqualsWithDelta(1400.00, (float) $special['net_value'], 0.01,
            '2,100 sold less 700 handed back');
        $this->assertEqualsWithDelta(2600.00, (float) $biryani['net_value'], 0.01,
            'and the head carries the refund with it');
        $this->assertEqualsWithDelta(
            $this->categoryNet('Biryani'),
            (float) $biryani['net_value'], 0.01,
            'still reconciles after a refund'
        );
    }

    /* ── nothing to show ─────────────────────────────────────────────────── */

    public function test_a_day_with_no_sales_returns_an_empty_section(): void
    {
        $this->assertSame([], app(SalesReportEngine::class)->byCategoryItems($this->filters()));
    }
}
