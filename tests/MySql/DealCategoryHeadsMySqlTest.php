<?php

namespace Tests\MySql;

use App\Services\Reports\SalesReportEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * DEAL-CATEGORY-1 — a deal reports under its own head, and is counted exactly once.
 *
 * A deal has no product of its own: its `combo_header` line sits on whichever product it was built
 * from, and the report asked that product which category it belonged to. At Kashif Food, Classic
 * Platter 3 begins with Arabic Rice, so 26,400 of platters was filed under **Extras** — beside the
 * cheese slices — and every "Deal N" scattered into Singaporean Rice and Chicken Biryani. On one
 * ordinary Tuesday, 94,705 sat under the wrong head.
 *
 * The combo already knew its head (`combos.category_id`, populated since POS-COMBO-CATEGORY-1 and
 * declared display-only at the time). This reads it — through ONE expression used by the grouping,
 * the FILTER and the rollup alike, because three separate copies of the rule is how this family of
 * bugs keeps coming back.
 *
 * The assertion that matters most is the last one: the day's total must not move by a paisa.
 * Money changes ROW, never amount.
 */
class DealCategoryHeadsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $dealsRoot;      // "Deals"
    private int $plattersHead;   // "Deals → Platters"
    private int $extrasCat;      // where the anchor product lives — the wrong head
    private int $riceCat;
    private int $anchorProduct;  // "Arabic Rice" — an Extras product the deal is built on
    private int $riceProduct;
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

        $this->extrasCat  = $this->makeCategory(['name' => 'Extras', 'slug' => 'extras-' . Str::random(5)]);
        $this->riceCat    = $this->makeCategory(['name' => 'Singaporean Rice', 'slug' => 'rice-' . Str::random(5)]);
        $this->dealsRoot  = $this->makeCategory(['name' => 'Deals', 'slug' => 'deals-' . Str::random(5)]);
        $this->plattersHead = $this->makeCategory([
            'name' => 'Platters', 'slug' => 'platters-' . Str::random(5), 'parent_id' => $this->dealsRoot,
        ]);

        $this->anchorProduct = $this->makeProduct($this->extrasCat, ['name' => 'Arabic Rice']);
        $this->riceProduct   = $this->makeProduct($this->riceCat, ['name' => 'Singaporean Rice (Regular)']);

        $this->comboId = (int) $c->table('combos')->insertGetId([
            'branch_id' => $this->branchId, 'category_id' => $this->plattersHead,
            'code' => 'CP3-' . Str::upper(Str::random(5)), 'name' => 'Classic Platter 3 (3 Persons)',
            'price' => 3300, 'sort_order' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('combo_components')->insert([
            ['combo_id' => $this->comboId, 'product_id' => $this->anchorProduct, 'quantity' => 1,
             'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['combo_id' => $this->comboId, 'product_id' => $this->riceProduct, 'quantity' => 1,
             'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->day = app(\App\Support\TenantClock::class)
            ->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
    }

    /* ── fixtures ────────────────────────────────────────────────────────── */

    /** A punched deal: the header carries all the money on the anchor product, parts carry zero. */
    private function sellDeal(float $price, ?int $comboId = null): int
    {
        $saleId = $this->makeSale($this->branchId, [
            'sale_no' => 'SO-' . Str::upper(Str::random(10)), 'status' => 'paid',
            'order_type' => 'takeaway', 'business_date' => $this->day,
            'grand_total' => $price, 'subtotal' => $price,
        ]);

        $this->makeSaleLine($saleId, $this->anchorProduct, [
            'quantity' => 1, 'unit_price' => $price, 'line_total' => $price,
            'line_kind' => 'combo_header', 'combo_id' => $comboId ?? $this->comboId,
        ]);
        foreach ([$this->anchorProduct, $this->riceProduct] as $pid) {
            $this->makeSaleLine($saleId, $pid, [
                'quantity' => 1, 'unit_price' => 0, 'line_total' => 0,
                'line_kind' => 'component', 'combo_id' => $comboId ?? $this->comboId,
            ]);
        }

        return $saleId;
    }

    /** An ordinary product sale, nothing to do with deals. */
    private function sellPlain(int $productId, float $price): int
    {
        $saleId = $this->makeSale($this->branchId, [
            'sale_no' => 'SO-' . Str::upper(Str::random(10)), 'status' => 'paid',
            'order_type' => 'takeaway', 'business_date' => $this->day,
            'grand_total' => $price, 'subtotal' => $price,
        ]);
        $this->makeSaleLine($saleId, $productId, [
            'quantity' => 1, 'unit_price' => $price, 'line_total' => $price,
        ]);

        return $saleId;
    }

    private function filters(array $extra = []): array
    {
        return app(SalesReportEngine::class)->normalizeFilters(array_merge([
            'date_from' => $this->day, 'date_to' => $this->day, 'branch_ids' => [$this->branchId],
        ], $extra));
    }

    /** Net of one root head in the Categories section. */
    private function headNet(string $name, array $f = null): float
    {
        foreach (app(SalesReportEngine::class)->byCategory($f ?? $this->filters()) as $root) {
            if ($root['name'] === $name) {
                return (float) $root['net'];
            }
        }

        return 0.0;
    }

    /* ── the fix ─────────────────────────────────────────────────────────── */

    /** THE test: the platter's money leaves Extras and lands on the deal's own head. */
    public function test_a_deal_files_under_its_own_head_not_its_anchor_products(): void
    {
        $this->sellDeal(3300);
        $this->sellPlain($this->anchorProduct, 100);   // a real Extras sale — must stay put

        $this->assertEqualsWithDelta(3300.00, $this->headNet('Deals'), 0.01,
            "the platter belongs to its own head, not to the shelf its first component sits on");
        $this->assertEqualsWithDelta(100.00, $this->headNet('Extras'), 0.01,
            'Extras keeps only what was actually sold as an extra — the old report showed 3,400');
    }

    /** It lands on the right CHILD, not merely the right root. */
    public function test_the_deal_lands_on_its_child_head(): void
    {
        $this->sellDeal(3300);

        $deals = collect(app(SalesReportEngine::class)->byCategory($this->filters()))
            ->firstWhere('name', 'Deals');

        $this->assertNotNull($deals);
        $child = collect($deals['children'])->firstWhere('name', 'Platters');
        $this->assertNotNull($child, 'the platter must appear under Platters, not loose under Deals');
        $this->assertEqualsWithDelta(3300.00, (float) $child['net'], 0.01);
    }

    /**
     * The FILTER follows the grouping. Without this, a report filtered to the deal's head would
     * find nothing while the Categories section happily filed it there — a contradiction that
     * looks deliberate, and so is worse than the original fault.
     */
    public function test_filtering_by_the_deals_head_finds_the_deal(): void
    {
        $this->sellDeal(3300);
        $this->sellPlain($this->riceProduct, 600);

        $f = $this->filters(['category_id' => $this->plattersHead]);

        $this->assertEqualsWithDelta(3300.00, $this->headNet('Deals', $f), 0.01,
            'filtering by Platters must return the platter');
        $this->assertEqualsWithDelta(0.00, $this->headNet('Singaporean Rice', $f), 0.01,
            'and nothing else');
    }

    /** A combo with no head of its own keeps today's behaviour — the anchor's category. */
    public function test_a_combo_without_a_head_falls_back_to_the_product(): void
    {
        DB::connection('tenant')->table('combos')->where('id', $this->comboId)->update(['category_id' => null]);

        $this->sellDeal(3300);

        $this->assertEqualsWithDelta(3300.00, $this->headNet('Extras'), 0.01,
            'with no head set, nothing changes — the fallback is the old behaviour');
        $this->assertEqualsWithDelta(0.00, $this->headNet('Deals'), 0.01);
    }

    /* ── counted once ────────────────────────────────────────────────────── */

    /** Items drops the deals; Deals carries them. Neither carries the other's money. */
    public function test_items_and_deals_split_the_trading_between_them(): void
    {
        $this->sellDeal(3300);
        $this->sellPlain($this->riceProduct, 600);

        $engine = app(SalesReportEngine::class);
        $items  = collect($engine->byItem($this->filters(), 'net', true));
        $deals  = collect($engine->byDeal($this->filters()));

        $this->assertEqualsWithDelta(600.00, $items->sum('net'), 0.01,
            'Items holds the plain sale only');
        $this->assertEqualsWithDelta(3300.00, $deals->sum('net'), 0.01,
            'Deals holds the platter only');
        $this->assertCount(1, $items, 'the deal must not appear in Items');
        $this->assertSame('Classic Platter 3 (3 Persons)', $deals->first()['deal']);
        $this->assertSame('Platters', $deals->first()['head']);
    }

    /** Called the old way, Items still carries everything — nothing that exists today changes. */
    public function test_by_item_still_includes_deals_when_not_asked_to_exclude_them(): void
    {
        $this->sellDeal(3300);
        $this->sellPlain($this->riceProduct, 600);

        $items = collect(app(SalesReportEngine::class)->byItem($this->filters()));

        $this->assertEqualsWithDelta(3900.00, $items->sum('net'), 0.01,
            'the default is unchanged, so every existing caller behaves as before');
    }

    /** A refunded deal credits the row it sold on, not the anchor product's shelf. */
    public function test_a_refunded_deal_credits_its_own_head(): void
    {
        $saleId = $this->sellDeal(3300);
        $headerLineId = DB::connection('tenant')->table('sales_order_lines')
            ->where('sales_order_id', $saleId)->where('line_kind', 'combo_header')->value('id');

        $returnId = DB::connection('tenant')->table('sales_returns')->insertGetId([
            'return_no' => 'RT-' . Str::upper(Str::random(8)), 'sales_order_id' => $saleId,
            'branch_id' => $this->branchId, 'return_date' => now(), 'business_date' => $this->day,
            'subtotal' => 3300, 'grand_total' => 3300, 'refund_amount' => 3300,
            'refund_method' => 'cash', 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('sales_return_lines')->insert([
            'sales_return_id' => $returnId, 'sales_order_line_id' => $headerLineId,
            'product_id' => $this->anchorProduct, 'quantity' => 1, 'unit_price' => 3300,
            'line_total' => 3300, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $deals = collect(app(SalesReportEngine::class)->byDeal($this->filters()));

        $this->assertEqualsWithDelta(3300.00, (float) $deals->sum('returns_amount'), 0.01,
            'the refund must land on the deal, since the combo comes off the original sale line');
        $this->assertEqualsWithDelta(0.00, (float) $deals->sum('net_value'), 0.01);
    }

    /* ── the one that guards the money ───────────────────────────────────── */

    /**
     * The whole point: money changes ROW, never AMOUNT. If this ever fails, the change has stopped
     * being a re-filing and started being an error.
     */
    public function test_the_days_total_does_not_move_by_a_paisa(): void
    {
        $this->sellDeal(3300);
        $this->sellDeal(3300);
        $this->sellPlain($this->anchorProduct, 100);
        $this->sellPlain($this->riceProduct, 600);

        $engine = app(SalesReportEngine::class);
        $categoriesTotal = collect($engine->byCategory($this->filters()))->sum('net');
        $items = collect($engine->byItem($this->filters(), 'net', true))->sum('net');
        $deals = collect($engine->byDeal($this->filters()))->sum('net');

        $this->assertEqualsWithDelta(7300.00, (float) $categoriesTotal, 0.01,
            '3300 + 3300 + 100 + 600 — whichever heads they land on');
        $this->assertEqualsWithDelta((float) $categoriesTotal, $items + $deals, 0.01,
            'Items + Deals must reconcile to the Categories total, or a section is printing short');
    }

    /** A tenant with no deals at all cannot be touched by any of this. */
    public function test_a_tenant_with_no_deals_is_unaffected(): void
    {
        DB::connection('tenant')->table('combos')->delete();

        $this->sellPlain($this->anchorProduct, 100);
        $this->sellPlain($this->riceProduct, 600);

        $engine = app(SalesReportEngine::class);

        $this->assertEqualsWithDelta(100.00, $this->headNet('Extras'), 0.01);
        $this->assertEqualsWithDelta(600.00, $this->headNet('Singaporean Rice'), 0.01);
        $this->assertEqualsWithDelta(0.00, $this->headNet('Deals'), 0.01);
        $this->assertSame([], $engine->byDeal($this->filters()), 'no deals, no Deals section');
    }
}
