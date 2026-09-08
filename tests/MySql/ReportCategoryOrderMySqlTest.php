<?php

namespace Tests\MySql;

use App\Services\Reports\SalesReportEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * REPORT-CATEGORY-ORDER-1 — the report prints in the order the owner arranged the menu in.
 *
 * `categories.sort_order` is the number set on the Catalog screen, and the POS has laid its pills
 * out by it since the beginning. The reports never looked: CATEGORIES came back in whatever order
 * MySQL happened to group the rows, and ITEMS BY CATEGORY led with the biggest earner. At Khatri
 * that printed Singaporean Rice, Desserts, Beverages, Extras, Beef Khatri… when the shop's own
 * numbering says Beef Khatri Biryani 1, Beef Changezi Pulao 2, Chicken Biryani 3.
 *
 * The owner asked for the CATEGORY order only: items under a head stay biggest-first, because a
 * head is arranged by a person and the items beneath it are read by size.
 */
class ReportCategoryOrderMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private string $day;
    private array $cat = [];
    private array $product = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanTenant([
            'sales_return_lines', 'sales_returns', 'sales_order_lines', 'sales_orders',
            'combo_components', 'combos', 'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        $this->branchId = $this->makeBranch(['name' => 'Main']);

        // Deliberately NOT in menu order, and deliberately not in value order either: the biggest
        // earner is numbered last, so a report sorted by money and one sorted by the menu cannot
        // accidentally agree.
        foreach ([['Drinks', 7], ['Biryani', 1], ['Desserts', 5]] as [$name, $order]) {
            $this->cat[$name] = $this->makeCategory([
                'name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(5), 'sort_order' => $order,
            ]);
        }

        $this->product['Cola']    = $this->makeProduct($this->cat['Drinks'], ['name' => 'Cola']);
        $this->product['Biryani'] = $this->makeProduct($this->cat['Biryani'], ['name' => 'Chicken Biryani']);
        $this->product['Kheer']   = $this->makeProduct($this->cat['Desserts'], ['name' => 'Kheer']);

        $this->day = app(\App\Support\TenantClock::class)
            ->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
    }

    private function sell(int $productId, float $price, float $qty = 1): void
    {
        $saleId = $this->makeSale($this->branchId, [
            'sale_no' => 'SO-' . Str::upper(Str::random(10)), 'status' => 'paid',
            'order_type' => 'takeaway', 'business_date' => $this->day,
            'grand_total' => $price * $qty, 'subtotal' => $price * $qty,
        ]);
        // The sale line carries its own frozen name; without it every row reports as "Product".
        $this->makeSaleLine($saleId, $productId, [
            'product_name' => DB::connection('tenant')->table('products')->where('id', $productId)->value('name'),
            'quantity' => $qty, 'unit_price' => $price, 'line_total' => $price * $qty,
        ]);
    }

    private function filters(): array
    {
        return app(SalesReportEngine::class)->normalizeFilters([
            'date_from' => $this->day, 'date_to' => $this->day, 'branch_ids' => [$this->branchId],
        ]);
    }

    /** Drinks earns most, Biryani least — so money order is the exact reverse of menu order. */
    private function seedDay(): void
    {
        $this->sell($this->product['Cola'], 100, 50);       // Drinks   5,000  (menu 7)
        $this->sell($this->product['Kheer'], 200, 10);      // Desserts 2,000  (menu 5)
        $this->sell($this->product['Biryani'], 400, 2);     // Biryani    800  (menu 1)
    }

    public function test_the_categories_section_prints_in_menu_order(): void
    {
        $this->seedDay();

        $this->assertSame(
            ['Biryani', 'Desserts', 'Drinks'],
            array_column(app(SalesReportEngine::class)->byCategory($this->filters()), 'name'),
            'the shop numbered Biryani 1 and Drinks 7; the report must not lead with whichever earned most'
        );
    }

    public function test_items_by_category_prints_its_heads_in_the_same_order(): void
    {
        $this->seedDay();

        $this->assertSame(
            ['Biryani', 'Desserts', 'Drinks'],
            array_column(app(SalesReportEngine::class)->byCategoryItems($this->filters()), 'head'),
            'the two sections are read side by side — they must agree on the order'
        );
    }

    /** A sub-category is a category too, so it follows the menu inside its head. */
    public function test_sub_heads_follow_the_menu_inside_their_head(): void
    {
        $small = $this->makeCategory(['name' => 'Small', 'slug' => 'small-' . Str::random(5),
            'parent_id' => $this->cat['Biryani'], 'sort_order' => 1]);
        $large = $this->makeCategory(['name' => 'Large', 'slug' => 'large-' . Str::random(5),
            'parent_id' => $this->cat['Biryani'], 'sort_order' => 2]);

        // Large earns far more, so value order would put it first.
        $this->sell($this->makeProduct($small, ['name' => 'Biryani Small']), 400, 1);
        $this->sell($this->makeProduct($large, ['name' => 'Biryani Large']), 800, 10);

        $head = collect(app(SalesReportEngine::class)->byCategoryItems($this->filters()))
            ->firstWhere('head', 'Biryani');

        $this->assertSame(['Small', 'Large'], array_column($head['groups'], 'name'),
            'Small is numbered 1 in the menu even though Large took ten times the money');
    }

    /** The owner asked for the CATEGORY order only — items stay biggest-first. */
    public function test_items_inside_a_head_are_still_biggest_first(): void
    {
        $cheap = $this->makeProduct($this->cat['Drinks'], ['name' => 'Water']);
        $this->sell($this->product['Cola'], 100, 50);   // 5,000
        $this->sell($cheap, 50, 2);                     //   100

        $head = collect(app(SalesReportEngine::class)->byCategoryItems($this->filters()))
            ->firstWhere('head', 'Drinks');
        $items = $head['groups'][0]['items'];

        $this->assertSame(['Cola', 'Water'], array_column($items, 'item'),
            'a head is arranged by a person; the items beneath it are read by size');
    }

    /** Two categories sharing a number must not swap places between two runs of the same report. */
    public function test_a_tie_on_sort_order_is_broken_by_name(): void
    {
        DB::connection('tenant')->table('categories')
            ->whereIn('id', [$this->cat['Drinks'], $this->cat['Desserts']])->update(['sort_order' => 4]);
        $this->seedDay();

        $this->assertSame(
            ['Biryani', 'Desserts', 'Drinks'],
            array_column(app(SalesReportEngine::class)->byCategory($this->filters()), 'name'),
            'equal numbers fall back to the name, so the same day never prints two different ways'
        );
    }

    /** Reordering the menu must move rows and nothing else. */
    public function test_reordering_moves_rows_and_not_a_single_rupee(): void
    {
        $this->seedDay();
        $engine = app(SalesReportEngine::class);

        $before = collect($engine->byCategory($this->filters()))
            ->mapWithKeys(fn ($r) => [$r['name'] => round((float) $r['net'], 2)])->all();
        $beforeTotal = array_sum($before);

        DB::connection('tenant')->table('categories')
            ->where('id', $this->cat['Drinks'])->update(['sort_order' => 0]);

        $after = collect($engine->byCategory($this->filters()))
            ->mapWithKeys(fn ($r) => [$r['name'] => round((float) $r['net'], 2)])->all();

        $this->assertSame(['Drinks', 'Biryani', 'Desserts'], array_keys($after), 'Drinks moved to the top');
        $this->assertEqualsWithDelta($beforeTotal, array_sum($after), 0.01, 'the day still totals the same');
        foreach ($before as $name => $value) {
            $this->assertEqualsWithDelta($value, $after[$name], 0.01, "{$name} kept its own figure");
        }
    }
}
