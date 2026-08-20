<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLineCostBlockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-LINE-SNAPSHOT-1 — a quotation remembers how it was priced.
 *
 * The dish below is the business's own worked example:
 *
 *   chicken 100/KG material x 0.50 KG per KG dish =  50
 *   rice     80/KG material x 0.40 KG per KG dish =  32
 *   making                                        = 300
 *                                                   ---
 *   calculated rate                                 382 / KG
 *
 * Two things are protected here. The COPY, so that re-rating a dish in March
 * cannot rewrite what a customer agreed to in January. And the EVENT OVERRIDE,
 * so an operator can say tonight needs three kilos of chicken without editing
 * the recipe every other quotation is priced from.
 */
class CateringLineSnapshotMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private int $branchId;

    private int $biryaniId;

    private int $chickenId;

    private int $riceId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'units', 'products', 'categories', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->unitId,
            'default_selling_price' => 500,
        ]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        $this->riceId = $this->makeProduct($categoryId, [
            'name' => 'Rice', 'sku' => 'RM-RICE', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        foreach ([[$this->chickenId, 80], [$this->riceId, 55]] as [$id, $rate]) {
            CateringMaterialRate::create([
                'product_id' => $id, 'rate' => $rate, 'unit_id' => $this->unitId,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
        }

        $this->buildBiryani();
    }

    private function buildBiryani(): void
    {
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryaniId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

        $material = fn (string $label, int $productId, float $rate, float $ratio, int $order) => CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => $label,
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $productId, 'quantity_per_unit' => $ratio,
            'unit_id' => $this->unitId, 'rate' => $rate,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'sort_order' => $order,
        ]);

        $material('Chicken', $this->chickenId, 100, 0.50, 1);
        $material('Rice', $this->riceId, 80, 0.40, 2);

        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE,
            'rate' => 300, 'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'sort_order' => 3,
        ]);
    }

    private function addSetupCharge(float $amount = 3000): void
    {
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Live counter setup',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE,
            'rate' => $amount, 'charge_basis' => CateringProductCostBlock::BASIS_LUMP_SUM,
            'sort_order' => 9,
        ]);
    }

    private function booking(float $quantity = 5, string $customer = 'Snapshot Customer'): CateringEstimateLine
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => $customer,
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 100,
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => $quantity, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);

        return $event->refresh()->currentEstimate->lines()->firstOrFail();
    }

    private function snapshot(CateringEstimateLine $line, string $label): CateringEstimateLineCostBlock
    {
        return $this->lineBlocks->snapshotsFor($line)->firstWhere('label', $label);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The copy.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_putting_a_block_dish_on_a_line_copies_its_blocks(): void
    {
        $line = $this->booking(5);

        $snapshots = $this->lineBlocks->snapshotsFor($line);
        $this->assertCount(3, $snapshots, 'chicken, rice and making all copied');

        $chicken = $this->snapshot($line, 'Chicken');
        $this->assertSame('material', $chicken->block_type);
        $this->assertSame('per_material_unit', $chicken->rate_basis);
        $this->assertSame(100.0, round((float) $chicken->rate, 2));
        $this->assertSame('Chicken', $chicken->material_name);
        $this->assertEqualsWithDelta(0.50, (float) $chicken->quantity_per_unit, 0.001);
    }

    /** The business's arithmetic, reproduced from the line rather than the dish. */
    public function test_the_line_prices_at_the_worked_example(): void
    {
        $line = $this->booking(5)->refresh();

        $this->assertSame(382.0, round((float) $line->calculated_rate, 2));
        $this->assertSame(382.0, round((float) $line->rate, 2), 'quoted defaults to calculated');
        $this->assertSame(1910.0, round((float) $line->amount, 2));

        $this->assertSame(250.0, round((float) $this->snapshot($line, 'Chicken')->amount, 2), '2.5 KG x 100');
        $this->assertSame(160.0, round((float) $this->snapshot($line, 'Rice')->amount, 2), '2 KG x 80');
        $this->assertSame(1500.0, round((float) $this->snapshot($line, 'Making')->amount, 2), '5 KG x 300');
    }

    /** The three numbers are all on the snapshot, and all different. */
    public function test_the_snapshot_keeps_charge_requirement_and_cost_apart(): void
    {
        $chicken = $this->snapshot($this->booking(5), 'Chicken');

        $this->assertSame(250.0, round((float) $chicken->amount, 2), 'the customer is charged 250');
        $this->assertEqualsWithDelta(2.5, (float) $chicken->event_material_qty, 0.001, 'the kitchen draws 2.5 KG');
        $this->assertEqualsWithDelta(200.0, (float) $chicken->material_cost, 0.01, '2.5 KG at 80 costs 200');
        $this->assertEqualsWithDelta(80.0, (float) $chicken->material_rate_at_quote, 0.01);
    }

    /**
     * THE REASON THE COPY EXISTS. Re-rating the dish afterwards must not rewrite
     * a line that was already priced from it.
     */
    public function test_re_rating_the_dish_does_not_rewrite_an_existing_line(): void
    {
        $line = $this->booking(5)->refresh();
        $originalAmount = round((float) $line->amount, 2);

        CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Chicken')->update(['rate' => 900]);
        CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Making')->update(['rate' => 1200]);

        $this->assertSame(100.0, round((float) $this->snapshot($line->fresh(), 'Chicken')->rate, 2),
            'the line still knows what chicken was charged at when it was quoted');
        $this->assertSame($originalAmount, round((float) $line->fresh()->amount, 2));
        $this->assertSame(382.0, round((float) $line->fresh()->calculated_rate, 2));
    }

    /** A material with no rate book entry records null cost, never zero. */
    public function test_an_unpriced_material_records_no_cost_rather_than_free(): void
    {
        CateringMaterialRate::where('product_id', $this->chickenId)->delete();

        $chicken = $this->snapshot($this->booking(5), 'Chicken');

        $this->assertNull($chicken->material_rate_at_quote);
        $this->assertNull($chicken->material_cost, 'unknown is not the same as free');
        $this->assertSame(250.0, round((float) $chicken->amount, 2), 'but the customer charge is unaffected');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The event override.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_event_can_need_more_material_than_the_ratio_says(): void
    {
        $line = $this->booking(5);
        $chicken = $this->snapshot($line, 'Chicken');

        $this->lineBlocks->overrideMaterialQuantity($chicken, 3.0);

        $chicken = $this->snapshot($line->fresh(), 'Chicken');
        $this->assertEqualsWithDelta(3.0, (float) $chicken->event_material_qty, 0.001);
        $this->assertTrue($chicken->is_overridden);
        $this->assertSame(300.0, round((float) $chicken->amount, 2), '3 KG x 100');
        $this->assertEqualsWithDelta(2.5, (float) $chicken->default_material_qty, 0.001,
            'and the ratio default is still remembered, so it can be reset to');

        // 300 chicken + 160 rice + 1,500 making = 1,960 over 5 KG = 392/KG
        $line = $line->fresh();
        $this->assertSame(1960.0, round((float) $line->amount, 2));
        $this->assertSame(392.0, round((float) $line->calculated_rate, 2));
    }

    /** The dish, its blocks and every other booking are untouched by it. */
    public function test_an_override_touches_nothing_but_its_own_line(): void
    {
        $first = $this->booking(5, 'First Customer');
        $second = $this->booking(5, 'Second Customer');

        $masterBefore = CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->orderBy('id')->get(['id', 'rate', 'quantity_per_unit'])->toArray();

        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($first, 'Chicken'), 3.0);

        $this->assertEquals($masterBefore, CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->orderBy('id')->get(['id', 'rate', 'quantity_per_unit'])->toArray(),
            'the dish everyone else is quoted from must not move');

        $otherChicken = $this->snapshot($second->fresh(), 'Chicken');
        $this->assertEqualsWithDelta(2.5, (float) $otherChicken->event_material_qty, 0.001);
        $this->assertFalse($otherChicken->is_overridden);
        $this->assertSame(1910.0, round((float) $second->fresh()->amount, 2),
            'another booking of the same dish is entirely unaffected');
    }

    public function test_reset_to_default_puts_a_material_back_on_the_ratio(): void
    {
        $line = $this->booking(5);
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($line, 'Chicken'), 3.0);

        $this->lineBlocks->resetMaterialQuantity($this->snapshot($line->fresh(), 'Chicken'));

        $chicken = $this->snapshot($line->fresh(), 'Chicken');
        $this->assertEqualsWithDelta(2.5, (float) $chicken->event_material_qty, 0.001);
        $this->assertFalse($chicken->is_overridden);
        $this->assertSame(1910.0, round((float) $line->fresh()->amount, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Changing the dish quantity.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_changing_the_dish_quantity_recalculates_untouched_materials(): void
    {
        $line = $this->booking(5);

        $line->forceFill(['quantity' => 10])->save();
        DB::connection('tenant')->transaction(fn () => $this->lineBlocks->recalculateForQuantityLocked($line->fresh()));

        $chicken = $this->snapshot($line->fresh(), 'Chicken');
        $this->assertEqualsWithDelta(5.0, (float) $chicken->event_material_qty, 0.001, '10 KG dish needs 5 KG');
        $this->assertSame(500.0, round((float) $chicken->amount, 2));
        $this->assertSame(3820.0, round((float) $line->fresh()->amount, 2), '10 x 382');
    }

    /**
     * An operator who typed three kilos meant three kilos. Growing the order
     * must not silently discard that — it stays, visibly overridden.
     */
    public function test_an_override_survives_a_dish_quantity_change(): void
    {
        $line = $this->booking(5);
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($line, 'Chicken'), 3.0);

        $line->forceFill(['quantity' => 10])->save();
        DB::connection('tenant')->transaction(fn () => $this->lineBlocks->recalculateForQuantityLocked($line->fresh()));

        $chicken = $this->snapshot($line->fresh(), 'Chicken');
        $this->assertEqualsWithDelta(3.0, (float) $chicken->event_material_qty, 0.001,
            'the deliberate figure stands');
        $this->assertTrue($chicken->is_overridden);
        $this->assertEqualsWithDelta(5.0, (float) $chicken->default_material_qty, 0.001,
            'while the default it could be reset to has moved with the order');

        // Rice, untouched, followed the order: 4 KG x 80 = 320.
        $this->assertSame(320.0, round((float) $this->snapshot($line->fresh(), 'Rice')->amount, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lump sums belong to the line.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_line_lump_sum_is_charged_once_and_kept_off_the_rate(): void
    {
        $this->addSetupCharge(3000);
        $line = $this->booking(5)->refresh();

        $this->assertSame(382.0, round((float) $line->calculated_rate, 2),
            'a flat fee never enters a per-unit rate');
        $this->assertSame(3000.0, round((float) $line->lump_sum_amount, 2));
        $this->assertSame(4910.0, round((float) $line->amount, 2), '1,910 + 3,000 once');
    }

    public function test_a_lump_sum_does_not_grow_with_the_order(): void
    {
        $this->addSetupCharge(3000);
        $line = $this->booking(5);

        $line->forceFill(['quantity' => 50])->save();
        DB::connection('tenant')->transaction(fn () => $this->lineBlocks->recalculateForQuantityLocked($line->fresh()));

        $line = $line->fresh();
        $this->assertSame(3000.0, round((float) $line->lump_sum_amount, 2), 'still once, at ten times the order');
        $this->assertSame(22100.0, round((float) $line->amount, 2), '50 x 382 + 3,000');
    }

    /**
     * A quotation can carry several one-off charges for different reasons. The
     * document's single other-charge field cannot say which is which, so they
     * live on their lines and the document total adds them up.
     */
    public function test_two_lines_can_carry_different_lump_sums(): void
    {
        $this->addSetupCharge(3000);

        $packingDish = $this->makeProduct($this->makeCategory(), [
            'name' => 'Packed Platter', 'sku' => 'CAT-PACK', 'unit_id' => $this->unitId,
        ]);
        CateringProductProfile::updateOrCreate(['product_id' => $packingDish],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']);
        CateringProductCostBlock::create([
            'product_id' => $packingDish, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => 100,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT, 'sort_order' => 1,
        ]);
        CateringProductCostBlock::create([
            'product_id' => $packingDish, 'label' => 'Packing',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => 1500,
            'charge_basis' => CateringProductCostBlock::BASIS_LUMP_SUM, 'sort_order' => 2,
        ]);

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Two Lump Sums',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(4)->toDateString(),
            'pax' => 80,
        ]);
        $estimate = $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
                'quantity' => 5, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0],
            ['product_id' => $packingDish, 'item_name' => 'Packed Platter',
                'quantity' => 10, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0],
        ]);

        $lines = $estimate->refresh()->lines->keyBy('item_name');
        $this->assertSame(3000.0, round((float) $lines['Chicken Biryani']->lump_sum_amount, 2));
        $this->assertSame(1500.0, round((float) $lines['Packed Platter']->lump_sum_amount, 2));

        // 4,910 + (10 x 100 + 1,500) = 4,910 + 2,500
        $this->assertSame(7410.0, round((float) $estimate->subtotal, 2),
            'both one-off charges reach the quotation, each on its own line');
    }

    /** The document's own other-charge field stays a separate, manual thing. */
    public function test_the_document_other_charge_is_untouched_by_line_lump_sums(): void
    {
        $this->addSetupCharge(3000);
        $line = $this->booking(5);
        $estimate = CateringEstimate::findOrFail($line->catering_estimate_id);

        $this->assertSame(0.0, round((float) $estimate->other_charge_amount, 2),
            'a line lump sum must not be smuggled into the document charge');
        $this->assertSame(4910.0, round((float) $estimate->subtotal, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Calculated versus quoted.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_operator_may_quote_a_different_rate_with_a_reason(): void
    {
        $line = $this->booking(5)->refresh();

        $this->lineBlocks->overrideQuotedRate($line, 500, 'Existing customer agreed rate');

        $line = $line->fresh();
        $this->assertSame(382.0, round((float) $line->calculated_rate, 2), 'what the blocks say, unchanged');
        $this->assertSame(500.0, round((float) $line->rate, 2), 'what the customer is being charged');
        $this->assertSame('Existing customer agreed rate', $line->rate_override_reason);
        $this->assertSame(2500.0, round((float) $line->amount, 2));
        $this->assertTrue($line->hasQuotedRateOverride());
    }

    /**
     * Without a reason, "382 calculated, 350 quoted" is indistinguishable from a
     * typing mistake six months later.
     */
    public function test_a_quoted_rate_override_requires_a_reason(): void
    {
        $line = $this->booking(5)->refresh();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/needs a reason/');

        $this->lineBlocks->overrideQuotedRate($line, 350, '   ');
    }

    /** Overriding the quotation changes nothing about the dish. */
    public function test_a_quoted_rate_override_does_not_touch_the_cost_blocks(): void
    {
        $line = $this->booking(5)->refresh();
        $before = CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->orderBy('id')->get(['id', 'rate'])->toArray();

        $this->lineBlocks->overrideQuotedRate($line, 500, 'Wedding package');

        $this->assertEquals($before, CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->orderBy('id')->get(['id', 'rate'])->toArray());
        $this->assertSame(382.0, round((float) $line->fresh()->calculated_rate, 2));
    }

    /** Choosing the catalog price is a quotation decision, not hidden arithmetic. */
    public function test_quoting_the_catalog_rate_is_an_override_not_an_addition(): void
    {
        $line = $this->booking(5)->refresh();

        $this->lineBlocks->overrideQuotedRate($line, 500, 'Catalog rate agreed with customer');

        $line = $line->fresh();
        $this->assertSame(500.0, round((float) $line->rate, 2));
        $this->assertNotSame(882.0, round((float) $line->rate, 2),
            'the catalog price replaces the calculated rate; it is never added to it');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // History.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_sent_quotation_refuses_a_material_override(): void
    {
        $line = $this->booking(5);
        $this->estimates->markSent($line->estimate->refresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has been sent/');

        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($line->fresh(), 'Chicken'), 4.0);
    }

    public function test_a_sent_quotation_refuses_a_rate_override(): void
    {
        $line = $this->booking(5);
        $this->estimates->markSent($line->estimate->refresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has been sent/');

        $this->lineBlocks->overrideQuotedRate($line->fresh(), 500, 'too late');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Boundaries.
    // ─────────────────────────────────────────────────────────────────────────

    /** A recipe-costed line has no block snapshot and behaves as it always did. */
    public function test_a_recipe_line_gets_no_snapshot(): void
    {
        $recipeDish = $this->makeProduct($this->makeCategory(), [
            'name' => 'Recipe Dish', 'sku' => 'CAT-REC', 'unit_id' => $this->unitId,
        ]);
        CateringProductProfile::updateOrCreate(['product_id' => $recipeDish],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'recipe']);

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Recipe Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 50,
        ]);
        $estimate = $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $recipeDish, 'item_name' => 'Recipe Dish',
            'quantity' => 4, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 250,
        ]]);

        $line = $estimate->refresh()->lines->first();
        $this->assertCount(0, $this->lineBlocks->snapshotsFor($line));
        $this->assertNull($line->calculated_rate);
        $this->assertSame(1000.0, round((float) $line->amount, 2), '4 x 250, exactly as before');
    }

    /** Quoting is not a business event: nothing posts and nothing moves. */
    public function test_editing_a_draft_quotation_posts_nothing_and_moves_no_stock(): void
    {
        $before = $this->ledgerCounts();

        $line = $this->booking(5);
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($line, 'Chicken'), 3.0);
        $this->lineBlocks->overrideQuotedRate($line->fresh(), 450, 'Package rate');

        $this->assertSame($before, $this->ledgerCounts());
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        $c = DB::connection('tenant');

        return [
            'journal_entries' => (int) $c->table('journal_entries')->count(),
            'journal_lines' => (int) $c->table('journal_lines')->count(),
            'stock_ledgers' => (int) $c->table('stock_ledgers')->count(),
        ];
    }
}
