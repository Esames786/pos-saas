<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringCostBlockService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-COSTING-SOURCE-1 — choosing what decides a dish's cost.
 *
 * A dish is costed either from its recipe or from its cost blocks. Exactly one
 * of those is the authority at any moment; the other may still be stored.
 *
 * That storage rule is the whole point of this test file. Kashif has 15
 * production recipes and is not supplying more. Moving a dish to blocks must not
 * cost him the recipe he already has, or the decision becomes irreversible and
 * nobody will make it. Equally, a client who supplies recipes next month must be
 * able to switch back and find their blocks intact.
 *
 * Also protected here: the shared Product authority is untouched. A dish costed
 * from blocks is still an ordinary sale_item, because eight other tenants share
 * that taxonomy and none of them have heard of catering.
 */
class CateringCostingModeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $karahiId;

    private int $chickenId;

    private int $kgUnitId;

    private int $recipeId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'recipe_ingredients', 'recipes',
            'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'units', 'products', 'categories', 'branches',
        ]);

        $categoryId = $this->makeCategory();
        $this->kgUnitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->karahiId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Karahi', 'sku' => 'CAT-KAR', 'unit_id' => $this->kgUnitId,
        ]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Raw Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
            'default_purchase_price' => 320,
        ]);

        // A real recipe, so "preserved" means something to assert against.
        $this->recipeId = DB::connection('tenant')->table('recipes')->insertGetId([
            'product_id' => $this->karahiId, 'name' => 'Karahi Deg', 'yield_quantity' => 10,
            'yield_unit_id' => $this->kgUnitId, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('recipe_ingredients')->insert([
            'recipe_id' => $this->recipeId, 'product_id' => $this->chickenId,
            'quantity' => 5, 'unit_id' => $this->kgUnitId, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function profile(array $attrs = []): CateringProductProfile
    {
        return CateringProductProfile::updateOrCreate(
            ['product_id' => $this->karahiId],
            array_merge(['catering_enabled' => true, 'pricing_mode' => 'fixed'], $attrs)
        );
    }

    private function addBlocks(): void
    {
        CateringProductCostBlock::create([
            'product_id' => $this->karahiId, 'label' => 'chicken', 'block_type' => 'material',
            'material_product_id' => $this->chickenId, 'quantity_per_unit' => 0.5,
            'unit_id' => $this->kgUnitId, 'rate' => 200, 'charge_basis' => 'per_unit', 'sort_order' => 1,
        ]);
        CateringProductCostBlock::create([
            'product_id' => $this->karahiId, 'label' => 'making', 'block_type' => 'charge',
            'rate' => 500, 'charge_basis' => 'per_unit', 'sort_order' => 2,
        ]);
    }

    private function recipeIngredientCount(): int
    {
        return (int) DB::connection('tenant')->table('recipe_ingredients')
            ->where('recipe_id', $this->recipeId)->count();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A / B. The shared Product authority is not involved.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * There is no product_type = cost_block, and there must never be one. Cost
     * blocks are a catering costing arrangement, not a kind of thing you can buy.
     */
    public function test_a_block_costed_dish_is_still_an_ordinary_product(): void
    {
        $before = Product::findOrFail($this->karahiId)->product_kind;

        $this->profile(['costing_mode' => 'blocks']);
        $this->addBlocks();

        $this->assertSame($before, Product::findOrFail($this->karahiId)->product_kind,
            'switching costing source must not touch the taxonomy eight other tenants share');
    }

    public function test_a_material_stays_a_raw_material(): void
    {
        $this->profile(['costing_mode' => 'blocks']);
        $this->addBlocks();

        $this->assertSame('raw_material', Product::findOrFail($this->chickenId)->product_kind);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C / D. Neither side is destroyed by a switch.
    // ─────────────────────────────────────────────────────────────────────────

    /** Kashif's 15 recipes must survive being moved to blocks. */
    public function test_switching_to_blocks_leaves_the_recipe_intact(): void
    {
        $profile = $this->profile(['costing_mode' => 'recipe']);
        $this->assertSame(1, $this->recipeIngredientCount());

        $profile->update(['costing_mode' => 'blocks']);
        $this->addBlocks();

        $this->assertSame(1, $this->recipeIngredientCount(),
            'the recipe is dormant, not deleted — the switch has to be reversible');
        $this->assertNotNull(Product::findOrFail($this->karahiId)->activeRecipe,
            'and it is still the active recipe as far as the kitchen module is concerned');
    }

    /** And the blocks survive being moved back. */
    public function test_switching_back_to_recipe_leaves_the_blocks_intact(): void
    {
        $profile = $this->profile(['costing_mode' => 'blocks']);
        $this->addBlocks();

        $profile->update(['costing_mode' => 'recipe']);

        $this->assertSame(2, CateringProductCostBlock::where('product_id', $this->karahiId)->count(),
            'a client who supplies a recipe later must not lose the blocks they had configured');
    }

    /** A full round trip changes nothing but the pointer. */
    public function test_a_round_trip_returns_the_dish_exactly_where_it_started(): void
    {
        $profile = $this->profile(['costing_mode' => 'recipe']);
        $this->addBlocks();

        $profile->update(['costing_mode' => 'blocks']);
        $profile->update(['costing_mode' => 'recipe']);

        $this->assertSame(1, $this->recipeIngredientCount());
        $this->assertSame(2, CateringProductCostBlock::where('product_id', $this->karahiId)->count());
        $this->assertSame('recipe', $profile->fresh()->costingMode());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E. Exactly one authority.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_only_one_costing_source_is_ever_active(): void
    {
        $profile = $this->profile(['costing_mode' => 'blocks']);
        $this->addBlocks();

        $this->assertTrue($profile->usesBlocks());
        $this->assertTrue(app(CateringCostBlockService::class)->usesBlocks(Product::findOrFail($this->karahiId)));

        $profile->update(['costing_mode' => 'recipe']);

        $this->assertFalse($profile->fresh()->usesBlocks());
        $this->assertFalse(app(CateringCostBlockService::class)->usesBlocks(Product::findOrFail($this->karahiId)),
            'stored blocks that are not the active source must not answer for the dish');
    }

    /** An unset or unrecognised value means recipe — what every dish did before. */
    public function test_an_unset_costing_source_means_recipe(): void
    {
        $profile = $this->profile();

        $this->assertSame('recipe', $profile->costingMode());
        $this->assertFalse($profile->usesBlocks(),
            'nothing changes for an existing dish until it is deliberately switched');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F. The two "modes" are independent.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * pricing_mode and costing_mode are different questions. They sit next to
     * each other on one screen, which is exactly why moving one must never move
     * the other.
     */
    public function test_changing_the_costing_source_does_not_touch_the_pricing_method(): void
    {
        $profile = $this->profile(['pricing_mode' => 'per_pax', 'costing_mode' => 'recipe']);

        $profile->update(['costing_mode' => 'blocks']);

        $this->assertSame('per_pax', $profile->fresh()->pricing_mode,
            'how a dish is quoted has nothing to do with what decides its cost');
    }

    public function test_changing_the_pricing_method_does_not_touch_the_costing_source(): void
    {
        $profile = $this->profile(['pricing_mode' => 'per_pax', 'costing_mode' => 'blocks']);

        $profile->update(['pricing_mode' => 'fixed']);

        $this->assertSame('blocks', $profile->fresh()->costingMode());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // U / V. Configuration is configuration.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Deciding how a dish will be costed is not a business event. No money moves
     * and no stock moves until somebody quotes, releases or issues.
     */
    public function test_configuring_costing_posts_nothing_and_moves_no_stock(): void
    {
        $before = $this->ledgerCounts();

        $profile = $this->profile(['costing_mode' => 'recipe']);
        $this->addBlocks();
        $profile->update(['costing_mode' => 'blocks']);
        CateringProductCostBlock::where('product_id', $this->karahiId)
            ->where('label', 'making')->update(['rate' => 600]);
        $profile->update(['costing_mode' => 'recipe']);

        $this->assertSame($before, $this->ledgerCounts(),
            'editing what a dish costs is not the same as anything having happened');
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        $c = DB::connection('tenant');

        return [
            'journal_entries' => (int) $c->table('journal_entries')->count(),
            'journal_lines' => (int) $c->table('journal_lines')->count(),
            'stock_ledgers' => (int) $c->table('stock_ledgers')->count(),
            'stock_balances' => (int) $c->table('stock_balances')->count(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The screen contract.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The two fields must not both be called "Mode". They were confusable enough
     * to be worth naming apart in the model docs, and the screen is where the
     * confusion would actually cost something.
     */
    public function test_the_screen_names_the_two_settings_apart(): void
    {
        $html = file_get_contents(base_path('resources/views/tenant/catering/profiles/index.blade.php'));

        $this->assertNotFalse($html);
        $this->assertStringContainsString('Pricing Method', $html);
        $this->assertStringContainsString('Costing Source', $html);
        $this->assertStringNotContainsString('>Pricing Mode<', $html,
            'the bare word "Mode" is what made these two confusable in the first place');
    }

    /** Both accepted values must be offered, or half the feature is unreachable. */
    public function test_the_screen_offers_both_costing_sources(): void
    {
        $html = file_get_contents(base_path('resources/views/tenant/catering/profiles/index.blade.php'));

        foreach (CateringProductProfile::COSTING_MODES as $mode) {
            $this->assertStringContainsString('value="'.$mode.'"', $html);
        }
    }
}
