<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringCostSnapshot;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateCostingService;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-COSTING-SOURCE-1 — one estimate, two costing sources.
 *
 * A quotation can hold a dish priced from blocks beside a dish priced from a
 * recipe, so the verdict has to be reached per line against that line's own
 * authority. A document-level `if blocks … else recipe …` would let one dish's
 * arrangement decide another dish's fate, which is the bug this file exists to
 * make impossible.
 *
 * The second thing protected here is quieter and worse. The block cost
 * calculator is read-only and does not throw: a material with no rate is
 * excluded and reported, so calling it alone returns an UNDERSTATED number.
 * Persisted, that would not look like a failure — it would look like an
 * unusually good margin. The orchestrator therefore checks readiness itself
 * before recording anything.
 */
class CateringMixedCostingMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateCostingService $costing;

    private CateringEstimateService $estimates;

    private int $branchId;

    private int $kgUnitId;

    private int $karahiId;   // blocks

    private int $biryaniId;  // recipe

    private int $chickenId;

    private int $riceId;

    private int $karahiRecipeId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_cost_snapshots', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'recipe_ingredients', 'recipes',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'branches',
        ]);

        $this->costing = app(CateringEstimateCostingService::class);
        $this->estimates = app(CateringEstimateService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->kgUnitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->karahiId = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'sku' => 'CAT-KAR', 'unit_id' => $this->kgUnitId]);
        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->kgUnitId]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Raw Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'default_purchase_price' => 320,
        ]);
        $this->riceId = $this->makeProduct($categoryId, [
            'name' => 'Basmati Rice', 'sku' => 'RM-RICE', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'default_purchase_price' => 120,
        ]);

        foreach ([[$this->chickenId, 320], [$this->riceId, 120]] as [$productId, $rate]) {
            CateringMaterialRate::create([
                'product_id' => $productId, 'rate' => $rate, 'unit_id' => $this->kgUnitId,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
        }

        $this->makeKarahiBlocks();
        $this->makeBiryaniRecipe();
        $this->makeKarahiDormantRecipe();
    }

    /** Karahi: chicken 200 charged, 0.5 KG drawn; making 500. Rate 700/KG. */
    private function makeKarahiBlocks(): void
    {
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->karahiId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

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

    /** Biryani: a real recipe — 10 KG batch takes 4 KG rice. */
    private function makeBiryaniRecipe(): void
    {
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryaniId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'recipe']
        );

        $recipeId = DB::connection('tenant')->table('recipes')->insertGetId([
            'product_id' => $this->biryaniId, 'name' => 'Biryani Deg', 'yield_quantity' => 10,
            'yield_unit_id' => $this->kgUnitId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('recipe_ingredients')->insert([
            'recipe_id' => $recipeId, 'product_id' => $this->riceId, 'quantity' => 4,
            'unit_id' => $this->kgUnitId, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Karahi also keeps an old recipe with a rate-less ingredient. It is dormant
     * — blocks are the authority — and must take no part in any verdict.
     */
    private function makeKarahiDormantRecipe(): void
    {
        $unrated = $this->makeProduct($this->makeCategory(), [
            'name' => 'Unrated Spice', 'sku' => 'RM-SPICE', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'default_purchase_price' => 0,
        ]);

        $this->karahiRecipeId = DB::connection('tenant')->table('recipes')->insertGetId([
            'product_id' => $this->karahiId, 'name' => 'Old Karahi Deg', 'yield_quantity' => 10,
            'yield_unit_id' => $this->kgUnitId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('recipe_ingredients')->insert([
            'recipe_id' => $this->karahiRecipeId, 'product_id' => $unrated, 'quantity' => 1,
            'unit_id' => $this->kgUnitId, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A draft holding one block-costed dish and one recipe-costed dish. */
    private function mixedDraft(): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Mixed Costing Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(7)->toDateString(),
            'pax' => 200,
        ]);

        return $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->karahiId, 'item_name' => 'Chicken Karahi', 'quantity' => 10,
                'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 700],
            ['product_id' => $this->biryaniId, 'item_name' => 'Biryani', 'quantity' => 20,
                'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 900],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // O / M / N. Each line judged by its own authority.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The headline case: two dishes, two costing sources, one verdict — and the
     * dormant configuration on each of them is incomplete and irrelevant.
     */
    public function test_a_mixed_estimate_is_ready_when_every_line_is_ready_its_own_way(): void
    {
        $readiness = $this->costing->readiness($this->mixedDraft());

        $this->assertTrue($readiness['ready'], implode(' | ', $readiness['blockers']));

        $modes = collect($readiness['result']['lines'])->pluck('costing_mode', 'item_name');
        $this->assertSame('blocks', $modes['Chicken Karahi']);
        $this->assertSame('recipe', $modes['Biryani'],
            'each line records which authority costed it, so the snapshot says where the number came from');
    }

    /**
     * Karahi's dormant recipe has an ingredient with no rate. Under the recipe
     * authority that is a hard blocker; under blocks it is simply not consulted.
     */
    public function test_a_dormant_incomplete_recipe_does_not_block_a_block_costed_dish(): void
    {
        $readiness = $this->costing->readiness($this->mixedDraft());

        $this->assertTrue($readiness['ready']);
        $this->assertStringNotContainsString('Unrated Spice', implode(' ', $readiness['blockers']),
            'the stored recipe is dormant — it is kept so the switch is reversible, not so it can veto');
    }

    /** And the reverse: stored blocks take no part while the recipe is active. */
    public function test_dormant_incomplete_blocks_do_not_block_a_recipe_costed_dish(): void
    {
        // Give the recipe-costed Biryani a block that could never be quoted.
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'orphan', 'block_type' => 'material',
            'material_product_id' => null, 'rate' => 50, 'charge_basis' => 'per_unit', 'sort_order' => 1,
        ]);

        $readiness = $this->costing->readiness($this->mixedDraft());

        $this->assertTrue($readiness['ready'], implode(' | ', $readiness['blockers']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // P / Q. Either side can fail, and the blocker says which dish and which way.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_unready_block_line_fails_the_whole_estimate_and_names_itself(): void
    {
        CateringMaterialRate::where('product_id', $this->chickenId)->delete();

        $readiness = $this->costing->readiness($this->mixedDraft());

        $this->assertFalse($readiness['ready']);
        $blockers = implode(' ', $readiness['blockers']);
        $this->assertStringContainsString('Chicken Karahi', $blockers, 'the operator must be told which dish');
        $this->assertStringContainsString('Cost Blocks', $blockers, 'and which authority is complaining');
        $this->assertStringContainsString('Material Rate Book', $blockers, 'and exactly what is missing');
    }

    public function test_an_unready_recipe_line_fails_the_whole_estimate(): void
    {
        CateringMaterialRate::where('product_id', $this->riceId)->delete();

        $readiness = $this->costing->readiness($this->mixedDraft());

        $this->assertFalse($readiness['ready']);
        $this->assertStringContainsString('Basmati Rice', implode(' ', $readiness['blockers']));
    }

    /** Send is refused through the same authority the screen displays. */
    public function test_send_is_blocked_by_an_unready_block_line(): void
    {
        CateringMaterialRate::where('product_id', $this->chickenId)->delete();
        $estimate = $this->mixedDraft();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cost Blocks/');

        $this->estimates->markSent($estimate->refresh());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // R. The snapshot adds up, and each half came from the right place.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Karahi 10 KG: 5 KG chicken at 320 = 1,600. It is CHARGED 7,000, and that
     * number must appear nowhere in the cost.
     * Biryani 20 KG: two batches of 4 KG rice at 120 = 960.
     */
    public function test_a_mixed_snapshot_totals_both_authorities_correctly(): void
    {
        $snapshot = $this->costing->snapshot($this->mixedDraft());

        $lines = collect($snapshot->breakdown['lines'])->keyBy('item_name');

        $this->assertEqualsWithDelta(1600.0, $lines['Chicken Karahi']['line_cost'], 0.01,
            'the material it draws, not the 7,000 it charges');
        $this->assertEqualsWithDelta(960.0, $lines['Biryani']['line_cost'], 0.01);
        $this->assertEqualsWithDelta(2560.0, (float) $snapshot->total_material_cost, 0.01);
    }

    /** The commercial block total must never be mistaken for cost. */
    public function test_the_snapshot_never_records_what_the_customer_was_charged_as_cost(): void
    {
        $snapshot = $this->costing->snapshot($this->mixedDraft());
        $karahi = collect($snapshot->breakdown['lines'])->firstWhere('item_name', 'Chicken Karahi');

        $this->assertNotEqualsWithDelta(7000.0, $karahi['line_cost'], 0.01,
            'charging 700 a kilo and paying 160 a kilo is the margin — recording the charge would erase it');
        $this->assertEqualsWithDelta(160.0, $karahi['unit_cost'], 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // S. The fail-closed boundary.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The block cost calculator excludes a rate-less material and returns a
     * smaller number without complaint. Persisting that would look like a good
     * margin rather than a fault, so the orchestrator refuses outright.
     */
    public function test_an_unready_estimate_cannot_persist_an_understated_cost(): void
    {
        CateringMaterialRate::where('product_id', $this->chickenId)->delete();
        $estimate = $this->mixedDraft();

        $before = (float) $estimate->estimated_material_cost;

        try {
            $this->costing->snapshot($estimate);
            $this->fail('a cost that cannot be worked out must not be written down');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be worked out', $e->getMessage());
            $this->assertStringContainsString('Chicken Karahi', $e->getMessage(),
                'and the operator is told what to fix rather than given a figure they cannot doubt');
        }

        $this->assertSame(0, CateringCostSnapshot::count(), 'nothing recorded');
        $this->assertSame($before, (float) $estimate->fresh()->estimated_material_cost,
            'and the estimate keeps whatever it honestly had before');
    }

    /**
     * Proof the refusal is not merely cosmetic: the calculator really does
     * return the smaller number, which is exactly why it must not be persisted.
     */
    public function test_the_calculator_alone_really_would_have_understated_it(): void
    {
        CateringMaterialRate::where('product_id', $this->chickenId)->delete();
        $estimate = $this->mixedDraft();

        $karahi = collect($this->costing->calculate($estimate)['lines'])
            ->firstWhere('item_name', 'Chicken Karahi');

        $this->assertSame(0.0, $karahi['line_cost'],
            'the chicken silently drops out — 1,600 of real cost reported as nothing');
        $this->assertNotEmpty($karahi['blockers'],
            'which is why readiness, not the calculator, is what stands between this and the record');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T. History does not move.
    // ─────────────────────────────────────────────────────────────────────────

    /** A sent quotation's costing basis is frozen, whatever changes afterwards. */
    public function test_a_sent_estimate_keeps_its_snapshot_after_later_changes(): void
    {
        $estimate = $this->mixedDraft();
        $this->costing->snapshot($estimate);
        $this->estimates->markSent($estimate->refresh());

        $recorded = (float) $estimate->fresh()->estimated_material_cost;
        $this->assertEqualsWithDelta(2560.0, $recorded, 0.01);

        // Everything the cost depends on moves underneath it.
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 900, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->toDateString(),
        ]);
        CateringProductCostBlock::where('product_id', $this->karahiId)
            ->where('label', 'chicken')->update(['quantity_per_unit' => 2]);

        $this->assertEqualsWithDelta($recorded, (float) $estimate->fresh()->estimated_material_cost, 0.01,
            'a quotation records what it recorded; the world moving does not rewrite it');

        // And it cannot be re-snapshotted into agreement with the new world.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/frozen/');
        $this->costing->snapshot($estimate->fresh());
    }
}
