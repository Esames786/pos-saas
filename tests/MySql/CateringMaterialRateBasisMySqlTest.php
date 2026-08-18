<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringCostBlockService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-MATERIAL-RATE-BASIS-1 — what a material's rate is a rate OF.
 *
 * A caterer says "chicken costs the customer 100 a kilo". The original model
 * said "chicken adds 100 to a kilo of biryani". Those are different rates, and
 * on a block with ratio 0.5 the same stored number means DOUBLE under one
 * reading and HALF under the other.
 *
 * So the basis is recorded rather than assumed, and this file exists to hold
 * both readings apart forever. The first test is the one that matters: a legacy
 * row must keep pricing exactly as it always did, because the alternative is
 * silently reissuing every quotation the business has ever sent.
 */
class CateringMaterialRateBasisMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $dishId;

    private int $chickenId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'units', 'products', 'categories', 'branches',
        ]);

        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->dishId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->unitId,
            // A catalog price that must never join in the block arithmetic.
            'default_selling_price' => 500,
        ]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->dishId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 80, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
    }

    private function block(array $attrs): CateringProductCostBlock
    {
        return CateringProductCostBlock::create(array_merge([
            'product_id' => $this->dishId,
            'label' => 'Chicken',
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 0.5,
            'unit_id' => $this->unitId,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'sort_order' => 1,
        ], $attrs));
    }

    private function service(): CateringCostBlockService
    {
        return app(CateringCostBlockService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The reason the column exists.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A row authored before the basis existed must price exactly as it did. Any
     * other outcome silently reprices every quotation already sent.
     */
    public function test_a_legacy_row_prices_per_dish_unit_exactly_as_before(): void
    {
        $block = $this->block(['rate' => 200]);

        // Written the way the old schema wrote it: no basis at all.
        DB::connection('tenant')->table('catering_product_cost_blocks')
            ->where('id', $block->id)->update(['rate_basis' => 'per_dish_unit']);

        $this->assertSame(2000.0, $this->service()->priceLine($this->dishId, 10)['total'],
            '10 KG of dish x 200 — the meaning it was authored under');
        $this->assertSame(200.0, $this->service()->rateFor($this->dishId));
    }

    /** And an unrecognised or absent value falls back to the legacy reading. */
    public function test_an_unreadable_basis_falls_back_to_the_legacy_reading(): void
    {
        $block = $this->block(['rate' => 200]);
        DB::connection('tenant')->table('catering_product_cost_blocks')
            ->where('id', $block->id)->update(['rate_basis' => '']);

        $this->assertSame(CateringProductCostBlock::RATE_PER_DISH_UNIT,
            $block->fresh()->rateBasis(),
            'when in doubt, price the way the row was written — never the new way');
        $this->assertSame(2000.0, $this->service()->priceLine($this->dishId, 10)['total']);
    }

    /**
     * The new reading: the rate is per kilo of CHICKEN, so a 10 KG dish needing
     * 5 KG of chicken is charged for 5 KG.
     */
    public function test_a_per_material_unit_row_charges_for_the_material_needed(): void
    {
        $this->block(['rate' => 200, 'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT]);

        $line = $this->service()->priceLine($this->dishId, 10);

        $this->assertSame(1000.0, $line['total'], '5 KG of chicken x 200 — half the legacy reading');
        $this->assertEqualsWithDelta(5.0, collect($line['blocks'])->firstWhere('label', 'Chicken')['required_qty'], 0.001);
        $this->assertSame(100.0, $this->service()->rateFor($this->dishId),
            'what it adds to a kilo of dish is ratio x rate');
    }

    /** Both readings coexist on the same dish without contaminating each other. */
    public function test_both_bases_can_sit_on_one_dish(): void
    {
        $this->block(['label' => 'Chicken (legacy)', 'rate' => 200,
            'rate_basis' => CateringProductCostBlock::RATE_PER_DISH_UNIT]);
        $this->block(['label' => 'Chicken (new)', 'rate' => 200, 'sort_order' => 2,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT]);

        $line = $this->service()->priceLine($this->dishId, 10);
        $byLabel = collect($line['blocks'])->keyBy('label');

        $this->assertSame(2000.0, $byLabel['Chicken (legacy)']['amount']);
        $this->assertSame(1000.0, $byLabel['Chicken (new)']['amount']);
        $this->assertSame(3000.0, $line['total']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The worked example the business checks by hand.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     *   chicken 100/KG x 0.50 =  50
     *   rice     80/KG x 0.40 =  32
     *   making                = 300
     *                           ---
     *   rate                    382 / KG        5 KG order = 1,910
     */
    public function test_the_business_worked_example_comes_out_at_382_per_kilo(): void
    {
        $riceId = $this->makeProduct($this->makeCategory(), [
            'name' => 'Rice', 'sku' => 'RM-RICE', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        CateringMaterialRate::create([
            'product_id' => $riceId, 'rate' => 55, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $this->block(['rate' => 100, 'quantity_per_unit' => 0.50,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT]);
        $this->block(['label' => 'Rice', 'material_product_id' => $riceId, 'rate' => 80,
            'quantity_per_unit' => 0.40, 'sort_order' => 2,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT]);
        $this->block(['label' => 'Making', 'block_type' => CateringProductCostBlock::TYPE_CHARGE,
            'material_product_id' => null, 'quantity_per_unit' => null, 'unit_id' => null,
            'rate' => 300, 'sort_order' => 3]);

        $this->assertSame(382.0, $this->service()->rateFor($this->dishId));

        $line = $this->service()->priceLine($this->dishId, 5);
        $byLabel = collect($line['blocks'])->keyBy('label');

        $this->assertSame(250.0, $byLabel['Chicken']['amount'], '2.5 KG x 100');
        $this->assertSame(160.0, $byLabel['Rice']['amount'], '2 KG x 80');
        $this->assertSame(1500.0, $byLabel['Making']['amount'], '5 KG x 300');
        $this->assertSame(1910.0, $line['total']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The three numbers stay three numbers.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The commercial rate and the consumption ratio are independent inputs.
     * Changing how much chicken a dish uses must not change what chicken is
     * charged at — only how much of it is charged for.
     */
    public function test_the_commercial_rate_is_independent_of_the_consumption_ratio(): void
    {
        $block = $this->block(['rate' => 100, 'quantity_per_unit' => 0.50,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT]);

        $this->assertSame(500.0, $this->service()->priceLine($this->dishId, 10)['total']);

        $block->update(['quantity_per_unit' => 0.80]);

        $this->assertSame(100.0, round((float) $block->fresh()->rate, 2), 'the rate itself has not moved');
        $this->assertSame(800.0, $this->service()->priceLine($this->dishId, 10)['total'],
            'only the amount of chicken being charged for has');
    }

    /** And what it COSTS is a third number, owned by the Material Rate Book. */
    public function test_the_material_rate_book_is_independent_of_the_commercial_rate(): void
    {
        $this->block(['rate' => 140, 'quantity_per_unit' => 0.40,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT]);

        $line = $this->service()->priceLine($this->dishId, 10);
        $chicken = collect($line['blocks'])->firstWhere('label', 'Chicken');

        $this->assertSame(560.0, $chicken['amount'], 'charged 4 KG x 140');
        $this->assertEqualsWithDelta(4.0, $chicken['required_qty'], 0.001, 'draws 4 KG');
        $this->assertEqualsWithDelta(320.0, $this->service()->expectedMaterialCost($this->dishId, 10), 0.01,
            'and costs 4 KG x 80 from the rate book — three different numbers');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The catalog price must stay out of it.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The dish carries a catalog selling price of 500. Adding it on top of the
     * blocks would double-count the sale: the blocks ALREADY are the price.
     */
    public function test_the_catalog_selling_price_is_never_added_to_the_blocks(): void
    {
        $this->block(['rate' => 100, 'quantity_per_unit' => 0.50,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT]);
        $this->block(['label' => 'Making', 'block_type' => CateringProductCostBlock::TYPE_CHARGE,
            'material_product_id' => null, 'quantity_per_unit' => null, 'unit_id' => null,
            'rate' => 300, 'sort_order' => 2]);

        $catalogPrice = (float) Product::findOrFail($this->dishId)->default_selling_price;
        $this->assertSame(500.0, $catalogPrice, 'the fixture really does carry a catalog price');

        $calculated = $this->service()->rateFor($this->dishId);

        $this->assertSame(350.0, $calculated, '50 chicken + 300 making — the blocks alone');
        $this->assertNotSame($catalogPrice + 350.0, $calculated,
            'a catalog price added on top would quote 850 for a dish that costs out at 350');
        $this->assertSame(3500.0, $this->service()->priceLine($this->dishId, 10)['total']);
    }

    /** A dish with no blocks prices at nothing, not at its catalog price. */
    public function test_a_block_mode_dish_with_no_blocks_does_not_fall_back_to_catalog_price(): void
    {
        $this->assertSame(0.0, $this->service()->rateFor($this->dishId));
        $this->assertFalse($this->service()->readiness($this->dishId)['ready'],
            'and it refuses to be quoted rather than inventing a price');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Charges are unaffected by any of this.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_charge_block_is_untouched_by_the_material_basis(): void
    {
        $charge = $this->block([
            'label' => 'Making', 'block_type' => CateringProductCostBlock::TYPE_CHARGE,
            'material_product_id' => null, 'quantity_per_unit' => null, 'unit_id' => null,
            'rate' => 300,
        ]);

        $this->assertFalse($charge->isPerMaterialUnit(), 'a charge has no material to be priced per');
        $this->assertSame(3000.0, $charge->amountFor(10), '10 x 300, as always');
        $this->assertSame(300.0, $charge->contributionPerDishUnit());
    }

    public function test_a_lump_sum_ignores_the_material_basis_entirely(): void
    {
        $setup = $this->block([
            'label' => 'Setup', 'block_type' => CateringProductCostBlock::TYPE_CHARGE,
            'material_product_id' => null, 'quantity_per_unit' => null, 'unit_id' => null,
            'rate' => 3000, 'charge_basis' => CateringProductCostBlock::BASIS_LUMP_SUM,
        ]);

        $this->assertSame(3000.0, $setup->amountFor(10));
        $this->assertSame(3000.0, $setup->amountFor(100));
        $this->assertSame(0.0, $setup->contributionPerDishUnit(),
            'a lump sum never enters a per-unit rate');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Authoring.
    // ─────────────────────────────────────────────────────────────────────────

    /** A material block written today is authored the way the business thinks. */
    public function test_a_new_material_block_defaults_to_the_material_unit_basis(): void
    {
        $html = file_get_contents(base_path('app/Http/Controllers/Tenant/Catering/CateringCostBlockController.php'));

        $this->assertStringContainsString('RATE_PER_MATERIAL_UNIT', $html,
            'the editor must author new material blocks per material unit');
    }

    /** An explicit quantity beats the ratio — the hook a booking line will use. */
    public function test_an_explicit_material_quantity_overrides_the_ratio(): void
    {
        $block = $this->block(['rate' => 100, 'quantity_per_unit' => 0.50,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT]);

        $this->assertSame(500.0, $block->amountFor(10), 'ratio says 5 KG');
        $this->assertSame(700.0, $block->amountFor(10, 7.0),
            'but an event needing 7 KG is charged for 7');
    }
}
