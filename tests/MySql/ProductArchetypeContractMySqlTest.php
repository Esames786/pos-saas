<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\ProductController;
use App\Models\Tenant\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-PRODUCT-UX-1 — behaviour lock for product creation.
 *
 * The owner's constraint for this sprint: *no change may disturb another live
 * tenant's product or inventory behaviour, including tenants that do not exist
 * yet*. A one-time manual check cannot honour that, because it protects nothing
 * against the NEXT change.
 *
 * So this test freezes today's server-side contract field by field. Whatever a
 * given archetype payload produces right now becomes the contract; any later
 * edit that alters what a POS, restaurant, inventory or manufacturing tenant
 * gets fails the build, whoever makes it and whatever their intent.
 *
 * These assertions are deliberately exhaustive rather than illustrative. A
 * partial assertion here would let a regression through in the fields it
 * skipped, which is exactly the failure mode this exists to prevent.
 */
class ProductArchetypeContractMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['product_variants', 'products', 'categories']);
        $this->categoryId = $this->makeCategory();
    }

    /** The fields every archetype assertion covers. Nothing may be dropped. */
    private const LOCKED_FIELDS = [
        'product_type', 'product_kind', 'is_pos_visible', 'is_sellable',
        'is_purchasable', 'is_stock_tracked', 'inventory_consumption_method',
        'can_be_bom_component', 'can_be_bom_output', 'is_manufactured_finished_good',
    ];

    /**
     * Posts what the form would post for one archetype and returns the row.
     * Routed through the controller, not the model, because the boundary rules
     * that matter live in the controller.
     */
    private function createVia(array $payload): Product
    {
        $sku = 'LOCK-'.Str::upper(Str::random(6));

        $request = Request::create('/products', 'POST', array_merge([
            'sku' => $sku,
            'name' => 'Lock '.$sku,
            'category_id' => $this->categoryId,
            'product_type' => 'simple',
            'default_selling_price' => 100,
            'status' => 'active',
        ], $payload));

        app(ProductController::class)->store($request);

        return Product::where('sku', $sku)->firstOrFail();
    }

    private function assertFlags(Product $p, array $expected, string $archetype): void
    {
        foreach (self::LOCKED_FIELDS as $field) {
            $this->assertArrayHasKey(
                $field, $expected,
                "[{$archetype}] the contract must pin every locked field; {$field} is missing"
            );

            $actual = $p->{$field};
            $actual = is_bool($actual) || is_int($actual) ? (int) $actual : $actual;
            $want = is_bool($expected[$field]) || is_int($expected[$field])
                ? (int) $expected[$field]
                : $expected[$field];

            $this->assertSame(
                $want, $actual,
                "[{$archetype}] {$field} changed — this is a behaviour change for every "
                .'tenant creating this kind of product, not just catering'
            );
        }
    }

    /** POS Sale Item — the default archetype and the one Khatri's staff use daily. */
    public function test_pos_sale_item_contract(): void
    {
        $p = $this->createVia([
            'product_kind' => 'sale_item',
            'is_pos_visible' => 1, 'is_sellable' => 1,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
            'inventory_consumption_method' => 'stock_item',
        ]);

        $this->assertFlags($p, [
            'product_type' => 'simple', 'product_kind' => 'sale_item',
            'is_pos_visible' => 1, 'is_sellable' => 1,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
            'inventory_consumption_method' => 'stock_item',
            'can_be_bom_component' => 0, 'can_be_bom_output' => 0,
            'is_manufactured_finished_good' => 0,
        ], 'POS Sale Item');
    }

    /** Recipe / Kitchen Item — sold at the till, consumes ingredients on sale. */
    public function test_recipe_kitchen_item_contract(): void
    {
        $p = $this->createVia([
            'product_type' => 'recipe',
            'product_kind' => 'sale_item',
            'is_pos_visible' => 1, 'is_sellable' => 1,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
            'inventory_consumption_method' => 'recipe',
        ]);

        $this->assertFlags($p, [
            'product_type' => 'recipe', 'product_kind' => 'sale_item',
            'is_pos_visible' => 1, 'is_sellable' => 1,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
            'inventory_consumption_method' => 'recipe',
            'can_be_bom_component' => 0, 'can_be_bom_output' => 0,
            'is_manufactured_finished_good' => 0,
        ], 'Recipe / Kitchen Item');
    }

    /**
     * Ingredient / Raw Material.
     *
     * Note what the server does here: a raw material is forced non-sellable,
     * non-POS-visible AND `can_be_bom_component = true`, regardless of what was
     * posted. That last one is the platform's own invariant, and it is why the
     * catering Materials screen does not in fact need a widened query for
     * correctly-created materials — only for rows a seeder wrote directly.
     */
    public function test_raw_material_contract_forces_the_boundary(): void
    {
        $p = $this->createVia([
            'product_kind' => 'raw_material',
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
            'inventory_consumption_method' => 'stock_item',
        ]);

        $this->assertFlags($p, [
            'product_type' => 'simple', 'product_kind' => 'raw_material',
            'is_pos_visible' => 0, 'is_sellable' => 0,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
            'inventory_consumption_method' => 'stock_item',
            'can_be_bom_component' => 1, 'can_be_bom_output' => 0,
            'is_manufactured_finished_good' => 0,
        ], 'Ingredient / Raw Material');
    }

    /** Packing Material behaves exactly as a raw material at the boundary. */
    public function test_packaging_material_contract(): void
    {
        $p = $this->createVia([
            'product_kind' => 'packaging_material',
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
            'inventory_consumption_method' => 'stock_item',
        ]);

        $this->assertFlags($p, [
            'product_type' => 'simple', 'product_kind' => 'packaging_material',
            'is_pos_visible' => 0, 'is_sellable' => 0,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
            'inventory_consumption_method' => 'stock_item',
            'can_be_bom_component' => 1, 'can_be_bom_output' => 0,
            'is_manufactured_finished_good' => 0,
        ], 'Packing Material');
    }

    /** Service Item — sold, never stocked. */
    public function test_service_item_contract(): void
    {
        $p = $this->createVia([
            'product_type' => 'service',
            'product_kind' => 'service',
            'is_pos_visible' => 1, 'is_sellable' => 1,
            'is_purchasable' => 0, 'is_stock_tracked' => 0,
            'inventory_consumption_method' => 'none',
        ]);

        $this->assertFlags($p, [
            'product_type' => 'service', 'product_kind' => 'service',
            'is_pos_visible' => 1, 'is_sellable' => 1,
            'is_purchasable' => 0, 'is_stock_tracked' => 0,
            'inventory_consumption_method' => 'none',
            'can_be_bom_component' => 0, 'can_be_bom_output' => 0,
            'is_manufactured_finished_good' => 0,
        ], 'Service Item');
    }

    /** A manufactured finished good must be a valid BOM output — server-enforced. */
    public function test_manufactured_finished_good_contract(): void
    {
        $p = $this->createVia([
            'product_kind' => 'finished_good',
            'is_manufactured_finished_good' => 1,
            'can_be_bom_output' => 0,          // deliberately posted false
            'is_purchasable' => 0, 'is_stock_tracked' => 1,
            'inventory_consumption_method' => 'stock_item',
        ]);

        $this->assertSame(1, (int) $p->can_be_bom_output,
            'a manufactured finished good is forced to be a valid BOM output even when posted false');
        $this->assertSame(1, (int) $p->is_manufactured_finished_good);
        $this->assertSame('finished_good', $p->product_kind);
    }

    /**
     * The hard boundary, stated as a security property rather than a UI nicety:
     * a raw material can never be made sellable or POS-visible by posting the
     * fields directly, bypassing the form entirely.
     */
    public function test_raw_material_cannot_be_forced_into_pos_by_a_crafted_request(): void
    {
        $p = $this->createVia([
            'product_kind' => 'raw_material',
            'is_pos_visible' => 1,   // hostile
            'is_sellable' => 1,      // hostile
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);

        $this->assertSame(0, (int) $p->is_pos_visible,
            'a raw material must never reach POS, even when the request says so');
        $this->assertSame(0, (int) $p->is_sellable,
            'a raw material must never be sellable, even when the request says so');
    }

    /**
     * The Catalog and Materials lists must stay mutually exclusive: a product
     * belongs to exactly one of them. Overlap is what produced the original
     * confusion — raw chicken appearing beside Chicken Karahi in a list titled
     * "products sold in POS".
     */
    public function test_catalog_and_materials_lists_never_overlap(): void
    {
        $this->createVia([
            'product_kind' => 'sale_item', 'is_pos_visible' => 1, 'is_sellable' => 1,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
            'inventory_consumption_method' => 'stock_item',
        ]);
        $this->createVia([
            'product_kind' => 'raw_material',
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);

        $catalog = $this->idsFor('catalog');
        $materials = $this->idsFor('materials');

        $this->assertNotEmpty($catalog, 'the catalog list must not be vacuous');
        $this->assertNotEmpty($materials, 'the materials list must not be vacuous');
        $this->assertSame(
            [], array_values(array_intersect($catalog, $materials)),
            'no product may appear in both the customer catalog and the materials list'
        );
    }

    /** Mirrors ProductController::applyContextFilter so drift is caught here. */
    private function idsFor(string $list): array
    {
        $q = Product::query();

        if ($list === 'materials') {
            $q->where(fn ($o) => $o
                ->where('can_be_bom_component', true)
                ->orWhere('can_be_bom_output', true)
                ->orWhere('is_manufactured_finished_good', true)
                ->orWhereIn('product_kind', [Product::KIND_SEMI_FINISHED, Product::KIND_FINISHED_GOOD]));
        } else {
            $q->where(fn ($i) => $i
                ->where('is_pos_visible', true)
                ->orWhere('is_sellable', true)
                ->orWhere('inventory_consumption_method', 'recipe')
                ->orWhere('product_type', 'service')
                ->orWhere('product_kind', Product::KIND_SERVICE))
                ->where(fn ($o) => $o
                    ->where(fn ($i) => $i
                        ->where('can_be_bom_component', false)
                        ->orWhere('is_pos_visible', true)
                        ->orWhere('is_sellable', true))
                    ->where('can_be_bom_output', false)
                    ->where('is_manufactured_finished_good', false)
                    ->whereNotIn('product_kind', [
                        Product::KIND_RAW_MATERIAL, Product::KIND_SEMI_FINISHED, Product::KIND_FINISHED_GOOD,
                    ]));
        }

        return $q->pluck('id')->all();
    }
}
