<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateCostingService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLineCostBlockService;
use App\Services\Catering\CateringRequirementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CAT-PROD-001 / CAT-COST-001 — operations follow the QUOTATION, not the dish.
 *
 * An independent product audit found that everything downstream of the quotation
 * was still reading the product master. The commercial screens showed the
 * operator one thing and the kitchen, the store and the margin were computed
 * from another:
 *
 *   - a block-costed dish with no recipe asked the store for NOTHING
 *   - a block-costed dish with a dormant old recipe asked for the DORMANT one
 *   - "this wedding needs 12 KG, not the ratio's 10" never reached the storeman
 *   - "the customer is bringing the rice" never reached them either, so the
 *     store would have been asked to issue rice the business agreed not to supply
 *   - and the margin above Cost Details disagreed with the breakdown inside it
 *
 * The rule proved here: STRUCTURE and QUANTITY come from the frozen snapshot;
 * only the material COST RATE is allowed to be an as-of lookup. A dish re-costed
 * in March cannot change what a January booking asks the store for.
 */
class CateringSnapshotOperationsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private CateringRequirementService $requirements;

    private int $branchId;

    private int $biryaniId;

    private int $recipeDishId;

    private int $chickenId;

    private int $riceId;

    private int $kgUnitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_material_issue_events', 'catering_material_issue_lines', 'catering_material_issues',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_cost_snapshots',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'recipe_ingredients', 'recipes',
            'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'units', 'products', 'categories', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);
        $this->requirements = app(CateringRequirementService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();

        $this->kgUnitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->kgUnitId]);
        $this->recipeDishId = $this->makeProduct($categoryId, ['name' => 'Recipe Qorma', 'sku' => 'CAT-QOR', 'unit_id' => $this->kgUnitId]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        $this->riceId = $this->makeProduct($categoryId, [
            'name' => 'Rice', 'sku' => 'RM-RICE', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        foreach ([[$this->chickenId, 80], [$this->riceId, 55]] as [$id, $rate]) {
            CateringMaterialRate::create([
                'product_id' => $id, 'rate' => $rate, 'unit_id' => $this->kgUnitId,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
        }

        $this->buildBiryani();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures.
    // ─────────────────────────────────────────────────────────────────────────

    private function buildBiryani(): void
    {
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryaniId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

        // Chicken 0.5/KG, Rice 0.4/KG, plus a charge that must draw nothing.
        foreach ([['Chicken', $this->chickenId, 0.50, 100], ['Rice', $this->riceId, 0.40, 80]] as $i => [$label, $mat, $ratio, $rate]) {
            CateringProductCostBlock::create([
                'product_id' => $this->biryaniId, 'label' => $label,
                'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
                'material_product_id' => $mat, 'quantity_per_unit' => $ratio,
                'unit_id' => $this->kgUnitId, 'rate' => $rate,
                'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
                'sort_order' => $i + 1, 'is_active' => true,
            ]);
        }
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => 300,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'sort_order' => 3, 'is_active' => true,
        ]);
    }

    /** A dormant recipe on the SAME dish — it must take no part in anything. */
    private function giveBiryaniADormantRecipe(): void
    {
        $recipeId = DB::connection('tenant')->table('recipes')->insertGetId([
            'product_id' => $this->biryaniId, 'name' => 'Old Biryani Deg', 'yield_quantity' => 10,
            'yield_unit_id' => $this->kgUnitId, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('recipe_ingredients')->insert([
            'recipe_id' => $recipeId, 'product_id' => $this->riceId, 'quantity' => 99,
            'unit_id' => $this->kgUnitId, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buildRecipeQorma(): void
    {
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->recipeDishId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'recipe']
        );

        $recipeId = DB::connection('tenant')->table('recipes')->insertGetId([
            'product_id' => $this->recipeDishId, 'name' => 'Qorma Deg', 'yield_quantity' => 10,
            'yield_unit_id' => $this->kgUnitId, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('recipe_ingredients')->insert([
            'recipe_id' => $recipeId, 'product_id' => $this->chickenId, 'quantity' => 3,
            'unit_id' => $this->kgUnitId, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function draft(string $customer, array $lines): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => $customer,
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(7)->toDateString(),
            'pax' => 150,
        ]);

        return $this->estimates->saveDraftLines($event->currentEstimate, $lines);
    }

    private function biryaniLine(float $qty = 20): array
    {
        return [
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => $qty, 'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 0,
        ];
    }

    private function line(CateringEstimate $estimate): CateringEstimateLine
    {
        return $estimate->refresh()->lines->first();
    }

    private function snapshot(CateringEstimate $estimate, string $label): CateringEstimateLineCostBlock
    {
        return $this->lineBlocks->snapshotsFor($this->line($estimate))->firstWhere('label', $label);
    }

    /** @return array<string, array> keyed by material name */
    private function requirementsFor(CateringEstimate $estimate): array
    {
        $rows = $this->requirements->consolidatedForEstimate($estimate->refresh(), $this->branchId)['requirements'];

        return collect($rows)->keyBy('name')->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A · the quotation is the authority
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_block_costed_dish_produces_a_store_requirement_at_all(): void
    {
        $estimate = $this->draft('Blocks only', [$this->biryaniLine(20)]);

        $req = $this->requirementsFor($estimate);

        $this->assertArrayHasKey('Chicken', $req, 'a block-costed dish with no recipe used to ask the store for nothing');
        $this->assertEqualsWithDelta(10.0, $req['Chicken']['required_qty'], 0.001, '0.5 x 20');
        $this->assertEqualsWithDelta(8.0, $req['Rice']['required_qty'], 0.001, '0.4 x 20');
        $this->assertSame(['quotation'], $req['Chicken']['sources']);
    }

    /** A charge block is work, not goods. It must draw nothing. */
    public function test_a_charge_block_asks_the_store_for_nothing(): void
    {
        $estimate = $this->draft('Charge only', [$this->biryaniLine(20)]);

        $names = array_keys($this->requirementsFor($estimate));

        $this->assertNotContains('Making', $names);
        $this->assertCount(2, $names, 'chicken and rice — the making is labour');
    }

    /** A dormant recipe on a block-costed dish must not be consulted. */
    public function test_a_dormant_recipe_never_overrules_the_quoted_blocks(): void
    {
        $this->giveBiryaniADormantRecipe();
        $estimate = $this->draft('Dormant recipe', [$this->biryaniLine(20)]);

        $req = $this->requirementsFor($estimate);

        $this->assertEqualsWithDelta(8.0, $req['Rice']['required_qty'], 0.001,
            'the quoted 0.4/KG, not the dormant recipe that would have asked for 198 KG');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B · the event's own quantity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_event_material_override_reaches_the_store(): void
    {
        $estimate = $this->draft('Override', [$this->biryaniLine(20)]);
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($estimate, 'Chicken'), 12);

        $req = $this->requirementsFor($estimate);

        $this->assertEqualsWithDelta(12.0, $req['Chicken']['required_qty'], 0.001,
            'the operator said twelve, so the storeman is asked for twelve');
        $this->assertEqualsWithDelta(12.0, $req['Chicken']['physical_qty'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C · customer supplied — two different numbers
    // ─────────────────────────────────────────────────────────────────────────

    public function test_customer_supplied_material_is_needed_by_the_kitchen_and_issued_by_nobody(): void
    {
        $estimate = $this->draft('Customer supplied', [$this->biryaniLine(20)]);
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Rice'), true);

        $req = $this->requirementsFor($estimate);

        $this->assertEqualsWithDelta(8.0, $req['Rice']['physical_qty'], 0.001,
            'the kitchen still needs the rice — the dish is the same dish');
        $this->assertEqualsWithDelta(0.0, $req['Rice']['required_qty'], 0.001,
            'but our store hands over none of it');
        $this->assertEqualsWithDelta(8.0, $req['Rice']['customer_supplied_qty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $req['Rice']['shortfall'], 0.001,
            'and it can never read as a shortage we have to go and buy');

        $this->assertEqualsWithDelta(10.0, $req['Chicken']['required_qty'], 0.001,
            'the other material is untouched');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D · the master may move; the booking may not
    // ─────────────────────────────────────────────────────────────────────────

    public function test_editing_the_dish_after_quoting_does_not_change_what_the_store_is_asked_for(): void
    {
        $estimate = $this->draft('Master moved', [$this->biryaniLine(20)]);

        CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Chicken')->update(['quantity_per_unit' => 5.0]);
        CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Rice')->update(['is_active' => false]);

        $req = $this->requirementsFor($estimate);

        $this->assertEqualsWithDelta(10.0, $req['Chicken']['required_qty'], 0.001,
            'the booking was quoted at 0.5/KG and that is what it consumes');
        $this->assertArrayHasKey('Rice', $req,
            'a material removed from the dish afterwards is still needed by a booking that quoted it');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E · mixed event
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_recipe_dish_and_a_block_dish_aggregate_on_the_same_material(): void
    {
        $this->buildRecipeQorma();

        $estimate = $this->draft('Mixed', [
            $this->biryaniLine(20),
            [
                'product_id' => $this->recipeDishId, 'item_name' => 'Recipe Qorma',
                'quantity' => 20, 'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 500,
            ],
        ]);

        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Rice'), true);

        $req = $this->requirementsFor($estimate);

        // Biryani blocks 0.5 x 20 = 10, plus Qorma recipe 3 per 10 KG batch x 2 = 6.
        $this->assertEqualsWithDelta(16.0, $req['Chicken']['required_qty'], 0.001,
            'one material, one line, both authorities counted');
        $this->assertEqualsWithDelta(0.0, $req['Rice']['required_qty'], 0.001,
            'and the customer-supplied decision survives the aggregation');
        $this->assertContains('quotation', $req['Chicken']['sources']);
        $this->assertContains('recipe', $req['Chicken']['sources']);
    }

    /** A recipe-only booking behaves exactly as it always did. */
    public function test_a_recipe_only_booking_is_unaffected(): void
    {
        $this->buildRecipeQorma();

        $estimate = $this->draft('Recipe only', [[
            'product_id' => $this->recipeDishId, 'item_name' => 'Recipe Qorma',
            'quantity' => 20, 'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 500,
        ]]);

        $req = $this->requirementsFor($estimate);

        $this->assertEqualsWithDelta(6.0, $req['Chicken']['required_qty'], 0.001);
        $this->assertSame(['recipe'], $req['Chicken']['sources']);
    }

    /** And a free-text line with no product asks for nothing, without failing. */
    public function test_a_free_text_line_contributes_nothing_and_does_not_break(): void
    {
        $estimate = $this->draft('Free text', [
            ['item_name' => 'Hall decoration', 'quantity' => 1, 'rate' => 25000],
        ]);

        $this->assertSame([], $this->requirements
            ->consolidatedForEstimate($estimate->refresh(), $this->branchId)['requirements']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // L / M · internal costing follows the same snapshot
    // ─────────────────────────────────────────────────────────────────────────

    public function test_block_costing_uses_the_events_own_quantity(): void
    {
        $estimate = $this->draft('Costing override', [$this->biryaniLine(20)]);
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($estimate, 'Chicken'), 12);

        $result = app(CateringEstimateCostingService::class)->readiness($estimate->refresh())['result'];
        $line = $result['lines'][0];

        // 12 KG chicken at 80 + 8 KG rice at 55 = 960 + 440.
        $this->assertEqualsWithDelta(1400.0, $line['line_cost'], 0.01,
            'the margin above Cost Details must agree with the breakdown inside it');
    }

    public function test_block_costing_charges_nothing_for_a_customer_supplied_material(): void
    {
        $estimate = $this->draft('Costing supplied', [$this->biryaniLine(20)]);
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Rice'), true);

        $result = app(CateringEstimateCostingService::class)->readiness($estimate->refresh())['result'];

        $this->assertEqualsWithDelta(800.0, $result['lines'][0]['line_cost'], 0.01,
            '10 KG of chicken at 80 — the business did not buy the rice');
    }

    public function test_block_costing_is_not_disturbed_by_a_later_master_edit(): void
    {
        $estimate = $this->draft('Costing master moved', [$this->biryaniLine(20)]);

        $before = app(CateringEstimateCostingService::class)->readiness($estimate->refresh())['result']['lines'][0]['line_cost'];

        CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Chicken')->update(['quantity_per_unit' => 5.0]);

        $after = app(CateringEstimateCostingService::class)->readiness($estimate->refresh())['result']['lines'][0]['line_cost'];

        $this->assertEqualsWithDelta($before, $after, 0.01,
            'a dish re-costed in March cannot change what a January booking cost');
    }

    /**
     * Readiness must describe the quotation. A material added to the dish AFTER
     * the quote — with no cost rate — is not this booking's problem.
     */
    public function test_readiness_ignores_a_material_added_to_the_dish_after_the_quote(): void
    {
        $estimate = $this->draft('Readiness master moved', [$this->biryaniLine(20)]);

        $unrated = $this->makeProduct($this->makeCategory(), [
            'name' => 'Saffron', 'sku' => 'RM-SAF', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Saffron',
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $unrated, 'quantity_per_unit' => 0.01,
            'unit_id' => $this->kgUnitId, 'rate' => 900,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'sort_order' => 9, 'is_active' => true,
        ]);

        $readiness = app(CateringEstimateCostingService::class)->readiness($estimate->refresh());

        $this->assertTrue($readiness['ready'],
            'the quotation was complete when it was made and nobody has changed it');
    }

    /** And a customer-supplied material needs no cost rate to be costable. */
    public function test_readiness_does_not_demand_a_cost_rate_for_what_the_customer_brings(): void
    {
        $unrated = $this->makeProduct($this->makeCategory(), [
            'name' => 'Special Rice', 'sku' => 'RM-SPRICE', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Rice')->update(['material_product_id' => $unrated]);

        $estimate = $this->draft('Supplied unrated', [$this->biryaniLine(20)]);

        $this->assertFalse(app(CateringEstimateCostingService::class)->readiness($estimate->refresh())['ready'],
            'while WE are buying it, a missing cost rate is a real blocker');

        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Rice'), true);

        $this->assertTrue(app(CateringEstimateCostingService::class)->readiness($estimate->refresh())['ready'],
            'once the customer brings it, its cost to us is zero and there is nothing to look up');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Boundary.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reading_requirements_moves_no_stock_and_posts_nothing(): void
    {
        $estimate = $this->draft('Read only', [$this->biryaniLine(20)]);
        $this->requirementsFor($estimate);
        app(CateringEstimateCostingService::class)->readiness($estimate->refresh());

        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count());
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
    }
}
