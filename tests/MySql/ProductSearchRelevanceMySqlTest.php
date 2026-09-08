<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Ajax\ProductLookupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRODUCT-SEARCH-RELEVANCE-1 — the thing you named comes first.
 *
 * The lookup matched correctly and ordered alphabetically, and on a 909-item
 * catalogue that is the same as not finding anything: typing "chicken" filled
 * the whole first page with dishes that merely contain the word — Aaloo Gosht
 * Chicken, Achar Gosht Chicken — while the dishes actually called "Chicken …"
 * sat hundreds of rows below, past any page an operator would open. The client
 * reported it as products missing from the system. Nothing was missing.
 *
 * The fixture is deliberately shaped like the real catalogue, because that
 * shape is the bug: many buried matches, few leading ones.
 */
class ProductSearchRelevanceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant(['product_barcodes', 'product_variants', 'products', 'categories', 'branches']);
        $this->makeBranch();
        $this->categoryId = $this->makeCategory();
    }

    private function product(string $name, string $sku): int
    {
        return $this->makeProduct($this->categoryId, [
            'name' => $name, 'sku' => $sku, 'is_sellable' => true, 'status' => 'active',
        ]);
    }

    /** @return array<int, string> names, in the order the operator would see them */
    private function search(string $term): array
    {
        $request = Request::create('/ajax/products', 'GET', ['q' => $term, 'sellable' => 1]);
        $body = json_decode(
            app(ProductLookupController::class)($request)->getContent(),
            true
        );

        return array_map(fn ($r) => preg_replace('/^.*?\s+—\s+/u', '', $r['text'] ?? ''), $body['results'] ?? []);
    }

    public function test_a_dish_named_for_the_term_outranks_one_that_merely_contains_it(): void
    {
        // Alphabetically these all sort ABOVE "Chicken Karahi". That was the bug.
        $this->product('Aaloo Gosht Chicken', 'A1');
        $this->product('Achar Gosht Chicken', 'A2');
        $this->product('Afghani Boti Chicken', 'A3');
        $this->product('Chicken Karahi', 'C1');
        $this->product('Chicken Biryani', 'C2');

        $results = $this->search('Chicken');

        $this->assertNotEmpty($results);
        $this->assertSame(['Chicken Biryani', 'Chicken Karahi'], array_slice($results, 0, 2),
            'names that BEGIN with the term come first — alphabetical inside the band');

        // The buried ones are still found; they are simply not first.
        $this->assertContains('Aaloo Gosht Chicken', $results,
            'a contains-match must never be dropped, only ranked');
    }

    public function test_an_exact_name_wins_outright(): void
    {
        $this->product('Raita Onion Fried', 'R1');
        $this->product('Burani Raita Tadka', 'R2');
        $this->product('Raita', 'R3');

        $this->assertSame('Raita', $this->search('Raita')[0] ?? null,
            'when the operator types the whole name, that is the dish they mean');
    }

    public function test_a_whole_word_match_outranks_a_word_fragment(): void
    {
        // "Chops" inside a longer phrase beats nothing else here, but a name
        // where it stands as its own word must come before one where it is
        // swallowed mid-phrase.
        $this->product('Zebra Chopsuey Special', 'Z1');   // fragment: "chops" inside "chopsuey"
        $this->product('Mutton Chops Grill', 'M1');       // whole word, later alphabetically

        $this->assertSame('Mutton Chops Grill', $this->search('Chops')[0] ?? null,
            'the word as a word beats the word as a fragment, alphabet notwithstanding');
    }

    public function test_ordering_is_untouched_when_nothing_was_typed(): void
    {
        $this->product('Zebra Cake', 'Z9');
        $this->product('Apple Pie', 'A9');

        $results = $this->search('');

        $this->assertSame('Apple Pie', $results[0] ?? null,
            'with no term there is no relevance to judge — plain alphabetical, as before');
    }
}
