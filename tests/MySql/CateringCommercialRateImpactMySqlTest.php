<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringCommercialRateImpactService;
use App\Services\Catering\CateringCostBlockService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLineCostBlockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-RATE-IMPACT-1 — what a house rate change would do, before it
 * does it.
 *
 * Chicken goes from 100 a kilo to 120. Several dishes use chicken at different
 * ratios, and drafts already exist quoted at the old rate. Nothing may move on
 * its own; the whole feature is a preview and a selection.
 *
 * The dish is the business's worked example — chicken 100/KG x 0.50, rice
 * 80/KG x 0.40, making 300 — priced at 382/KG, and 392/KG once chicken is 120.
 *
 * Three exclusions carry most of the risk, and each has its own reason:
 * a hand-chosen rate was chosen on purpose; a legacy per-dish rate is measured
 * in a different unit from the house book entirely; and a customer-supplied
 * material is not being charged for, so its charge rate cannot move anything.
 */
class CateringCommercialRateImpactMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private CateringCommercialRateImpactService $impact;

    private int $branchId;

    private int $biryaniId;

    private int $karahiId;

    private int $premiumId;

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
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);
        $this->impact = app(CateringCommercialRateImpactService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->unitId]);
        $this->karahiId = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'sku' => 'CAT-KAR', 'unit_id' => $this->unitId]);
        $this->premiumId = $this->makeProduct($categoryId, ['name' => 'Premium Counter', 'sku' => 'CAT-PREM', 'unit_id' => $this->unitId]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        $this->riceId = $this->makeProduct($categoryId, [
            'name' => 'Rice', 'sku' => 'RM-RICE', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        // What the materials COST us — a different book entirely.
        foreach ([[$this->chickenId, 80], [$this->riceId, 55]] as [$id, $rate]) {
            CateringMaterialRate::create([
                'product_id' => $id, 'rate' => $rate, 'unit_id' => $this->unitId,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
        }

        // What we CHARGE for them.
        CateringMaterialCommercialRate::create([
            'product_id' => $this->chickenId, 'rate' => 100, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $this->buildDishes();
    }

    private function block(int $dishId, array $attrs): CateringProductCostBlock
    {
        return CateringProductCostBlock::create(array_merge([
            'product_id' => $dishId,
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'unit_id' => $this->unitId,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'commercial_rate_source' => CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK,
            'sort_order' => 1,
        ], $attrs));
    }

    private function buildDishes(): void
    {
        foreach ([$this->biryaniId, $this->karahiId, $this->premiumId] as $dish) {
            CateringProductProfile::updateOrCreate(
                ['product_id' => $dish],
                ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
            );
        }

        // Biryani — the worked example, following the house rate.
        $this->block($this->biryaniId, ['label' => 'Chicken', 'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 0.50, 'rate' => 100]);
        $this->block($this->biryaniId, ['label' => 'Rice', 'material_product_id' => $this->riceId,
            'quantity_per_unit' => 0.40, 'rate' => 80, 'sort_order' => 2,
            'commercial_rate_source' => CateringProductCostBlock::SOURCE_MANUAL]);
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => 300,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT, 'sort_order' => 3,
        ]);

        // Karahi — also follows the house rate, at a different ratio.
        $this->block($this->karahiId, ['label' => 'Chicken', 'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 0.60, 'rate' => 100]);
        CateringProductCostBlock::create([
            'product_id' => $this->karahiId, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => 200,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT, 'sort_order' => 2,
        ]);

        // Premium — a rate somebody chose on purpose. It must be left alone.
        $this->block($this->premiumId, ['label' => 'Chicken', 'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 0.50, 'rate' => 140,
            'commercial_rate_source' => CateringProductCostBlock::SOURCE_MANUAL]);
        CateringProductCostBlock::create([
            'product_id' => $this->premiumId, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => 400,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT, 'sort_order' => 2,
        ]);
    }

    private function draft(int $dishId, string $name, float $qty, string $customer): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => $customer,
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(7)->toDateString(),
            'pax' => 150,
        ]);

        return $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $dishId, 'item_name' => $name,
            'quantity' => $qty, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);
    }

    private function line(CateringEstimate $estimate): CateringEstimateLine
    {
        return $estimate->refresh()->lines->first();
    }

    private function snapshot(CateringEstimate $estimate, string $label): CateringEstimateLineCostBlock
    {
        return $this->lineBlocks->snapshotsFor($this->line($estimate))->firstWhere('label', $label);
    }

    private function raiseChickenTo(float $rate): void
    {
        CateringMaterialCommercialRate::create([
            'product_id' => $this->chickenId, 'rate' => $rate, 'unit_id' => $this->unitId,
            'effective_from' => now()->toDateString(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The two books stay apart.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_cost_book_and_the_commercial_book_are_different_things(): void
    {
        $this->assertEqualsWithDelta(80.0,
            (float) CateringMaterialRate::effectiveFor($this->chickenId)->rate, 0.01,
            'what chicken costs us');
        $this->assertEqualsWithDelta(100.0,
            (float) CateringMaterialCommercialRate::rateFor($this->chickenId), 0.01,
            'what we charge for it');
    }

    public function test_the_commercial_book_keeps_its_history(): void
    {
        $this->raiseChickenTo(120);

        $this->assertEqualsWithDelta(120.0, (float) CateringMaterialCommercialRate::rateFor($this->chickenId), 0.01);
        $this->assertEqualsWithDelta(100.0,
            (float) CateringMaterialCommercialRate::rateFor($this->chickenId, now()->subDay()->toDateString()), 0.01,
            'last week the house rate was 100, and a quotation from then must stay explicable');
        $this->assertSame(2, CateringMaterialCommercialRate::where('product_id', $this->chickenId)->count());
    }

    /** Raising the cost of chicken is not a reason to charge more for it. */
    public function test_a_cost_book_change_is_not_a_commercial_change(): void
    {
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 95, 'unit_id' => $this->unitId,
            'effective_from' => now()->toDateString(),
        ]);

        $this->assertEqualsWithDelta(100.0, (float) CateringMaterialCommercialRate::rateFor($this->chickenId), 0.01);
        $this->assertSame([], $this->impact->productImpact($this->chickenId)['products'][0]['difference'] === 0.0
            ? [] : ['unexpected impact'], 'a cost movement produces no commercial impact');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Nothing moves on its own.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_raising_the_house_rate_reprices_nothing_by_itself(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 5, 'Before Customer');
        $this->assertSame(382.0, round((float) $this->line($estimate)->calculated_rate, 2));

        $this->raiseChickenTo(120);

        $this->assertSame(100.0, round((float) CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Chicken')->value('rate'), 2), 'the dish still charges what it charged');
        $this->assertSame(382.0, round((float) $this->line($estimate)->calculated_rate, 2));
        $this->assertSame(1910.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Product preview.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_product_preview_shows_applied_against_recommended(): void
    {
        $this->raiseChickenTo(120);

        $impact = $this->impact->productImpact($this->chickenId);
        $byName = collect($impact['products'])->keyBy('product_name');

        $this->assertEqualsWithDelta(120.0, $impact['recommended'], 0.01);

        // Biryani: chicken 0.50 x 100 = 50 → 0.50 x 120 = 60, so 382 → 392.
        $biryani = $byName['Chicken Biryani'];
        $this->assertSame(100.0, round($biryani['applied_rate'], 2));
        $this->assertSame(382.0, $biryani['old_calculated_rate']);
        $this->assertSame(392.0, $biryani['projected_calculated_rate']);
        $this->assertSame(10.0, $biryani['difference']);

        // Karahi at a different ratio moves differently: 0.60 x 20 = 12.
        $karahi = $byName['Chicken Karahi'];
        $this->assertSame(260.0, $karahi['old_calculated_rate'], '0.6 x 100 + 200 making');
        $this->assertSame(272.0, $karahi['projected_calculated_rate']);
        $this->assertSame(12.0, $karahi['difference']);
    }

    /** A rate somebody chose for a dish is not a dish that forgot to update. */
    public function test_a_hand_chosen_rate_is_never_offered_the_house_change(): void
    {
        $this->raiseChickenTo(120);

        $impact = $this->impact->productImpact($this->chickenId);

        $this->assertNotContains('Premium Counter', collect($impact['products'])->pluck('product_name')->all());

        $premium = collect($impact['ineligible'])->firstWhere('product_name', 'Premium Counter');
        $this->assertNotNull($premium);
        $this->assertStringContainsString('set by hand', $premium['reason']);
    }

    /** A legacy per-dish rate is measured in a different unit from the book. */
    public function test_a_legacy_per_dish_rate_cannot_follow_the_house_book(): void
    {
        CateringProductCostBlock::where('product_id', $this->karahiId)->where('label', 'Chicken')
            ->update(['rate_basis' => CateringProductCostBlock::RATE_PER_DISH_UNIT]);

        $this->raiseChickenTo(120);
        $impact = $this->impact->productImpact($this->chickenId);

        $karahi = collect($impact['ineligible'])->firstWhere('product_name', 'Chicken Karahi');
        $this->assertNotNull($karahi, 'it must be excluded, not silently repriced in the wrong unit');
        $this->assertStringContainsString('different measurement', $karahi['reason']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Product apply.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_applying_to_selected_dishes_leaves_the_others_alone(): void
    {
        $this->raiseChickenTo(120);

        $biryaniBlock = CateringProductCostBlock::where('product_id', $this->biryaniId)->where('label', 'Chicken')->first();
        $premiumBlock = CateringProductCostBlock::where('product_id', $this->premiumId)->where('label', 'Chicken')->first();
        $karahiBlock = CateringProductCostBlock::where('product_id', $this->karahiId)->where('label', 'Chicken')->first();

        $applied = $this->impact->applyToProducts($this->chickenId, [$biryaniBlock->id]);

        $this->assertSame(1, $applied);
        $this->assertSame(120.0, round((float) $biryaniBlock->fresh()->rate, 2));
        $this->assertSame(100.0, round((float) $karahiBlock->fresh()->rate, 2), 'not selected, not touched');
        $this->assertSame(140.0, round((float) $premiumBlock->fresh()->rate, 2), 'and the hand-set rate stands');

        $this->assertSame(392.0, app(CateringCostBlockService::class)->rateFor($this->biryaniId));
    }

    /** A request naming a manual block must not be able to overwrite it. */
    public function test_apply_refuses_a_hand_chosen_block_even_when_named(): void
    {
        $this->raiseChickenTo(120);
        $premiumBlock = CateringProductCostBlock::where('product_id', $this->premiumId)->where('label', 'Chicken')->first();

        $applied = $this->impact->applyToProducts($this->chickenId, [$premiumBlock->id]);

        $this->assertSame(0, $applied);
        $this->assertSame(140.0, round((float) $premiumBlock->fresh()->rate, 2));
    }

    /** Applying to a dish changes what FUTURE quotations are priced at. */
    public function test_a_later_quotation_picks_up_the_applied_rate(): void
    {
        $this->raiseChickenTo(120);
        $block = CateringProductCostBlock::where('product_id', $this->biryaniId)->where('label', 'Chicken')->first();
        $this->impact->applyToProducts($this->chickenId, [$block->id]);

        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 5, 'Later Customer');

        $this->assertSame(392.0, round((float) $this->line($estimate)->calculated_rate, 2));
        $this->assertSame(120.0, round((float) $this->snapshot($estimate, 'Chicken')->rate, 2));
    }

    /** And leaves quotations that already exist exactly where they were. */
    public function test_applying_to_a_dish_does_not_move_an_existing_quotation(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 5, 'Earlier Customer');
        $this->raiseChickenTo(120);

        $block = CateringProductCostBlock::where('product_id', $this->biryaniId)->where('label', 'Chicken')->first();
        $this->impact->applyToProducts($this->chickenId, [$block->id]);

        $this->assertSame(382.0, round((float) $this->line($estimate)->calculated_rate, 2));
        $this->assertSame(1910.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Draft preview and apply — the worked figures.
    // ─────────────────────────────────────────────────────────────────────────

    /** 20 KG of biryani draws 10 KG of chicken: 1,000 → 1,200, so +200. */
    public function test_the_draft_preview_shows_the_worked_impact(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Impact Customer');
        $this->raiseChickenTo(120);

        $row = collect($this->impact->draftImpact($this->chickenId))
            ->firstWhere('estimate_id', $estimate->id);

        $this->assertTrue($row['eligible']);
        $this->assertEqualsWithDelta(10.0, $row['material_qty'], 0.001);
        $this->assertSame(1000.0, $row['old_amount']);
        $this->assertSame(1200.0, $row['new_amount']);
        $this->assertSame(200.0, $row['difference']);
    }

    public function test_applying_to_a_draft_moves_the_line_and_the_quotation(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Apply Customer');
        $this->raiseChickenTo(120);

        $applied = $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($estimate, 'Chicken')->id]);

        $this->assertSame(1, $applied);
        $line = $this->line($estimate);
        $this->assertSame(392.0, round((float) $line->calculated_rate, 2));
        $this->assertSame(7840.0, round((float) $line->amount, 2), '20 x 392');
        $this->assertSame(7840.0, round((float) $estimate->refresh()->subtotal, 2));

        // Rice was a hand-set rate and is untouched.
        $this->assertSame(80.0, round((float) $this->snapshot($estimate, 'Rice')->rate, 2));
    }

    public function test_an_unselected_draft_is_untouched(): void
    {
        $selected = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Selected');
        $other = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Not Selected');
        $this->raiseChickenTo(120);

        $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($selected, 'Chicken')->id]);

        $this->assertSame(392.0, round((float) $this->line($selected)->calculated_rate, 2));
        $this->assertSame(382.0, round((float) $this->line($other)->calculated_rate, 2));
        $this->assertSame(7640.0, round((float) $other->refresh()->subtotal, 2), '20 x 382');
    }

    /** The quantity this event settled on drives the impact, not the ratio's. */
    public function test_an_event_quantity_override_drives_the_impact(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Override Customer');
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($estimate, 'Chicken'), 12.0);
        $this->raiseChickenTo(120);

        $row = collect($this->impact->draftImpact($this->chickenId))->firstWhere('estimate_id', $estimate->id);

        $this->assertEqualsWithDelta(12.0, $row['material_qty'], 0.001, 'twelve, not the ratio-derived ten');
        $this->assertSame(1200.0, $row['old_amount'], '12 x 100');
        $this->assertSame(1440.0, $row['new_amount'], '12 x 120');
        $this->assertSame(240.0, $row['difference']);
    }

    /** Nobody is charged for a material the customer brought. */
    public function test_a_customer_supplied_material_has_no_commercial_impact(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Supplied Customer');
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Chicken'), true);
        $this->raiseChickenTo(500);

        $row = collect($this->impact->draftImpact($this->chickenId))->firstWhere('estimate_id', $estimate->id);

        $this->assertSame(0.0, $row['difference'], 'even at five times the rate');
        $this->assertFalse($row['eligible']);
        $this->assertTrue($row['customer_supplied']);
        $this->assertStringContainsString('supplying this material', $row['reason']);

        // And the requirement is still recorded, while our store issues none.
        $snapshot = $this->snapshot($estimate, 'Chicken');
        $this->assertEqualsWithDelta(10.0, $snapshot->physicalRequirement(), 0.001);
        $this->assertSame(0.0, $snapshot->ourStockRequirement());
    }

    public function test_apply_refuses_a_customer_supplied_snapshot_even_when_named(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Supplied Customer');
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Chicken'), true);
        $this->raiseChickenTo(120);

        $applied = $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($estimate, 'Chicken')->id]);

        $this->assertSame(0, $applied);
        $this->assertSame(0.0, round((float) $this->snapshot($estimate, 'Chicken')->amount, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Quoted rate.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_untracked_quoted_rate_follows_the_new_calculation(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Tracking Customer');
        $this->raiseChickenTo(120);

        $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($estimate, 'Chicken')->id]);

        $this->assertSame(392.0, round((float) $this->line($estimate)->rate, 2));
    }

    public function test_a_negotiated_price_survives_the_impact(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Agreed Customer');
        $this->lineBlocks->overrideQuotedRate($this->line($estimate), 500, 'Customer agreed rate');
        $this->raiseChickenTo(120);

        $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($estimate, 'Chicken')->id]);

        $line = $this->line($estimate);
        $this->assertSame(392.0, round((float) $line->calculated_rate, 2), 'the calculation moved');
        $this->assertSame(500.0, round((float) $line->rate, 2), 'the agreed price did not');
        $this->assertSame('Customer agreed rate', $line->rate_override_reason);
        $this->assertSame(10000.0, round((float) $estimate->refresh()->subtotal, 2), '20 x 500');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // History.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_sent_quotation_is_shown_but_never_applied_to(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Sent Customer');
        $snapshotId = $this->snapshot($estimate, 'Chicken')->id;
        $this->estimates->markSent($estimate->refresh());
        $this->raiseChickenTo(120);

        $row = collect($this->impact->draftImpact($this->chickenId))->firstWhere('estimate_id', $estimate->id);
        $this->assertFalse($row['eligible']);
        $this->assertStringContainsString('has been sent', $row['reason']);

        $applied = $this->impact->applyToDrafts($this->chickenId, [$snapshotId]);

        $this->assertSame(0, $applied);
        $this->assertSame(7640.0, round((float) $estimate->refresh()->subtotal, 2), 'untouched');
    }

    /**
     * A revision must carry HOW the quotation was priced, or the new version
     * arrives with no breakdown and the operator reconstructs it from memory.
     */
    public function test_a_revision_carries_the_whole_costing_across(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Revision Customer');
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($estimate, 'Chicken'), 12.0);
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Rice'), true);
        $this->lineBlocks->overrideQuotedRate($this->line($estimate), 500, 'Customer agreed rate');
        $this->estimates->markSent($estimate->refresh());

        $revision = $this->estimates->revise($estimate->refresh());

        $line = $revision->refresh()->lines->first();
        $this->assertNotNull($line);
        $this->assertSame(500.0, round((float) $line->rate, 2));
        $this->assertSame('Customer agreed rate', $line->rate_override_reason);
        $this->assertNotNull($line->calculated_rate);

        $blocks = $this->lineBlocks->snapshotsFor($line)->keyBy('label');
        $this->assertCount(3, $blocks, 'the whole breakdown came across');
        $this->assertEqualsWithDelta(12.0, (float) $blocks['Chicken']->event_material_qty, 0.001);
        $this->assertTrue($blocks['Chicken']->is_overridden);
        $this->assertTrue($blocks['Rice']->isCustomerSupplied());

        // And the sent original is untouched.
        $this->assertSame('superseded', $estimate->refresh()->status);
        $this->assertCount(3, $this->lineBlocks->snapshotsFor($this->line($estimate)));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Nothing posts.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_none_of_this_posts_or_moves_stock(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Ledger Customer');
        $before = $this->ledgerCounts();

        $this->raiseChickenTo(120);
        $block = CateringProductCostBlock::where('product_id', $this->biryaniId)->where('label', 'Chicken')->first();
        $this->impact->applyToProducts($this->chickenId, [$block->id]);
        $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($estimate, 'Chicken')->id]);

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
