<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringCommercialRateImpactService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLineCostBlockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-RATE-IMPACT-1 — release hardening.
 *
 * The first tranche proved the arithmetic of a house rate change. This proves
 * the things that decide whether it is safe to hand to a business:
 *
 *   HISTORY      two rates on one day are two decisions, and the morning's one
 *                must survive the afternoon's
 *   UNITS        120 per KG must never be multiplied by a quantity in GM
 *   HONESTY      a row that cannot take the rate is not shown a number that
 *                looks like it could
 *   DECISIONS    the operator sees the three rates that actually matter — what
 *                the line calculates now, what it would calculate, and what the
 *                customer was quoted, which is often none of the above
 *   AUDIT        every applied rate is attributable afterwards, or a selective
 *                apply is indistinguishable from a price that drifted
 *   REVISIONS    a sent quotation takes the rate by becoming a new version, and
 *                either the whole thing happens or none of it does
 *   LOCKS        an invoiced or closed event is not a document with a slow apply
 *                button; it is finished
 *
 * The worked example throughout is the business's own: chicken 100/KG x 0.50,
 * rice 80/KG x 0.40, making 300 — a biryani at 382/KG, and 392/KG once chicken
 * reaches 120.
 */
class CateringCommercialRateHardeningMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private CateringCommercialRateImpactService $impact;

    private int $branchId;

    private int $biryaniId;

    private int $karahiId;

    private int $premiumId;

    private int $gramDishId;

    private int $chickenId;

    private int $riceId;

    private int $kgUnitId;

    private int $gmUnitId;

    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_commercial_rate_applications',
            'catering_final_invoices',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'catering_cost_snapshots', 'recipe_ingredients', 'recipes',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'users', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);
        $this->impact = app(CateringCommercialRateImpactService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->actorId = $this->makeUser(['name' => 'Rate Operator', 'employee_code' => 'RATEOP']);

        $units = DB::connection('tenant')->table('units');
        $this->kgUnitId = $units->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->gmUnitId = $units->insertGetId([
            'code' => 'GM', 'name' => 'Gram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->kgUnitId]);
        $this->karahiId = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'sku' => 'CAT-KAR', 'unit_id' => $this->kgUnitId]);
        $this->premiumId = $this->makeProduct($categoryId, ['name' => 'Premium Counter', 'sku' => 'CAT-PREM', 'unit_id' => $this->kgUnitId]);
        $this->gramDishId = $this->makeProduct($categoryId, ['name' => 'Gram Plated Dish', 'sku' => 'CAT-GRAM', 'unit_id' => $this->kgUnitId]);

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

        CateringMaterialCommercialRate::create([
            'product_id' => $this->chickenId, 'rate' => 100, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $this->buildDishes();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures.
    // ─────────────────────────────────────────────────────────────────────────

    private function block(int $dishId, array $attrs): CateringProductCostBlock
    {
        return CateringProductCostBlock::create(array_merge([
            'product_id' => $dishId,
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'unit_id' => $this->kgUnitId,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'commercial_rate_source' => CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK,
            'sort_order' => 1,
        ], $attrs));
    }

    private function charge(int $dishId, string $label, float $rate, int $order): void
    {
        CateringProductCostBlock::create([
            'product_id' => $dishId, 'label' => $label,
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => $rate,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT, 'sort_order' => $order,
        ]);
    }

    private function buildDishes(): void
    {
        foreach ([$this->biryaniId, $this->karahiId, $this->premiumId, $this->gramDishId] as $dish) {
            CateringProductProfile::updateOrCreate(
                ['product_id' => $dish],
                ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
            );
        }

        // The worked example. Chicken follows the house rate; rice was agreed by
        // hand and must be left alone by every house change.
        $this->block($this->biryaniId, ['label' => 'Chicken', 'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 0.50, 'rate' => 100]);
        $this->block($this->biryaniId, ['label' => 'Rice', 'material_product_id' => $this->riceId,
            'quantity_per_unit' => 0.40, 'rate' => 80, 'sort_order' => 2,
            'commercial_rate_source' => CateringProductCostBlock::SOURCE_MANUAL]);
        $this->charge($this->biryaniId, 'Making', 300, 3);

        $this->block($this->karahiId, ['label' => 'Chicken', 'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 0.60, 'rate' => 100]);
        $this->charge($this->karahiId, 'Making', 200, 2);

        // A rate somebody chose on purpose.
        $this->block($this->premiumId, ['label' => 'Chicken', 'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 0.50, 'rate' => 140,
            'commercial_rate_source' => CateringProductCostBlock::SOURCE_MANUAL]);
        $this->charge($this->premiumId, 'Making', 400, 2);

        // Measured in GRAMS while the house book quotes per KILO. Linked, so the
        // ONLY thing standing between it and 500 x 120 is the unit check.
        $this->block($this->gramDishId, ['label' => 'Chicken', 'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 500, 'rate' => 0.1, 'unit_id' => $this->gmUnitId]);
        $this->charge($this->gramDishId, 'Making', 100, 2);
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
            'quantity' => $qty, 'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 0,
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

    private function raiseChickenTo(float $rate, ?string $date = null): void
    {
        CateringMaterialCommercialRate::create([
            'product_id' => $this->chickenId, 'rate' => $rate, 'unit_id' => $this->kgUnitId,
            'effective_from' => $date ?? now()->toDateString(),
        ]);
    }

    private function draftRow(CateringEstimate $estimate, string $label = 'Chicken'): ?array
    {
        return collect($this->impact->draftImpact($this->chickenId))
            ->firstWhere('snapshot_id', $this->snapshot($estimate, $label)->id);
    }

    private function productRow(int $dishId): ?array
    {
        return collect($this->impact->productImpact($this->chickenId)['products'])
            ->firstWhere('product_id', $dishId);
    }

    private function ineligibleRow(int $dishId): ?array
    {
        return collect($this->impact->productImpact($this->chickenId)['ineligible'])
            ->firstWhere('product_id', $dishId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §5 — the book keeps every decision, including two on one day.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Chicken at 100 in the morning and 120 in the afternoon are two commercial
     * decisions. A unique key on (product, date) would have silently replaced
     * the first, and a quotation applied against it at eleven o'clock would have
     * become unexplainable by lunchtime.
     */
    public function test_two_rates_recorded_on_the_same_day_are_both_kept(): void
    {
        $today = now()->toDateString();
        $this->raiseChickenTo(110, $today);
        $this->raiseChickenTo(130, $today);

        $this->assertSame(3, CateringMaterialCommercialRate::where('product_id', $this->chickenId)->count(),
            'the opening rate and both of the day\'s decisions');
        $this->assertSame(2, CateringMaterialCommercialRate::where('product_id', $this->chickenId)
            ->whereDate('effective_from', $today)->count());
    }

    /** The later one is simply the current one — resolved by id when the date ties. */
    public function test_the_last_rate_recorded_on_a_day_is_the_current_one(): void
    {
        $today = now()->toDateString();
        $this->raiseChickenTo(110, $today);
        $this->raiseChickenTo(130, $today);

        $this->assertEqualsWithDelta(130.0, (float) CateringMaterialCommercialRate::rateFor($this->chickenId), 0.01);
        $this->assertEqualsWithDelta(100.0,
            (float) CateringMaterialCommercialRate::rateFor($this->chickenId, now()->subDay()->toDateString()), 0.01,
            'and yesterday still reads as yesterday');
    }

    /**
     * A rate that starts next Monday is a decision already taken and a price
     * nobody is charged today. Treating it as current is how somebody quotes
     * this morning at a rate that is not in effect this morning.
     */
    public function test_a_future_dated_rate_is_not_todays_rate(): void
    {
        $this->raiseChickenTo(120, now()->addWeek()->toDateString());

        $this->assertEqualsWithDelta(100.0, (float) CateringMaterialCommercialRate::rateFor($this->chickenId), 0.01,
            'today is still 100');
        $this->assertEqualsWithDelta(120.0,
            (float) CateringMaterialCommercialRate::rateFor($this->chickenId, now()->addWeek()->toDateString()), 0.01,
            'and next week really is 120 — it was recorded, not ignored');
    }

    /** The business decision uses the same resolver the screen does. */
    public function test_todays_impact_is_worked_out_at_todays_rate_not_next_weeks(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Future Customer');
        $this->raiseChickenTo(120, now()->addWeek()->toDateString());

        $impact = $this->impact->productImpact($this->chickenId);
        $this->assertEqualsWithDelta(100.0, $impact['recommended'], 0.01,
            'the impact screen offers what the house charges now');

        $row = $this->draftRow($estimate);
        $this->assertEqualsWithDelta(0.0, $row['difference'], 0.01,
            'nothing to move: the quotation is already at the current rate');

        // Applying today applies TODAY's rate, not the scheduled one.
        $this->impact->applyToDrafts($this->chickenId, [$row['snapshot_id']], $this->actorId);
        $this->assertEqualsWithDelta(100.0, (float) $this->snapshot($estimate, 'Chicken')->rate, 0.01);
    }

    /** An explicit as-of preview is the only way to look forward. */
    public function test_a_scheduled_rate_can_be_previewed_deliberately_by_date(): void
    {
        $this->raiseChickenTo(120, now()->addWeek()->toDateString());

        $future = $this->impact->productImpact($this->chickenId, null, now()->addWeek()->toDateString());

        $this->assertEqualsWithDelta(120.0, $future['recommended'], 0.01);
        $biryani = collect($future['products'])->firstWhere('product_id', $this->biryaniId);
        $this->assertEqualsWithDelta(392.0, $biryani['projected_calculated_rate'], 0.01,
            'what the dish would become once next week arrives');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §4 — units. The guard against a plausible-looking factor of a thousand.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_dish_measured_in_grams_is_not_offered_a_rate_quoted_per_kilo(): void
    {
        $this->raiseChickenTo(120);

        $this->assertNull($this->productRow($this->gramDishId),
            '500 GM x 120/KG would read as a valid price and be wrong by a factor of a thousand');

        $row = $this->ineligibleRow($this->gramDishId);
        $this->assertNotNull($row);
        $this->assertSame(CateringCommercialRateImpactService::STATE_UNIT_MISMATCH, $row['state']);
        $this->assertStringContainsString('Unit mismatch', $row['reason']);
        $this->assertArrayNotHasKey('difference', $row, 'and it is never shown an impact figure');
    }

    public function test_a_unit_mismatch_cannot_be_applied_to_a_dish_even_by_id(): void
    {
        $this->raiseChickenTo(120);
        $block = CateringProductCostBlock::where('product_id', $this->gramDishId)
            ->where('label', 'Chicken')->first();

        $applied = $this->impact->applyToProducts($this->chickenId, [$block->id], $this->actorId);

        $this->assertSame(0, $applied);
        $this->assertEqualsWithDelta(0.1, (float) $block->refresh()->rate, 0.0001,
            'the preview filtered it out and the apply refuses it again');
    }

    public function test_a_quotation_priced_in_another_unit_shows_no_impact_and_cannot_be_applied(): void
    {
        $estimate = $this->draft($this->gramDishId, 'Gram Plated Dish', 10, 'Gram Customer');
        $this->raiseChickenTo(120);

        $row = $this->draftRow($estimate);
        $this->assertSame(CateringCommercialRateImpactService::STATE_UNIT_MISMATCH, $row['state']);
        $this->assertFalse($row['eligible']);
        $this->assertNull($row['difference'], 'no arithmetic is offered for a comparison that cannot be made');
        $this->assertNull($row['projected_calculated_rate']);

        $applied = $this->impact->applyToDrafts($this->chickenId, [$row['snapshot_id']], $this->actorId);
        $this->assertSame(0, $applied);
        $this->assertEqualsWithDelta(0.1, (float) $this->snapshot($estimate, 'Chicken')->rate, 0.0001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §8 / §9 — the numbers an operator actually decides on.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The business's own worked example, read off the QUOTATION rather than the
     * dish: 382 a kilo becomes 392, and 20 KG moves by 200.
     */
    public function test_a_draft_shows_its_calculated_rate_moving_from_382_to_392(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Decision Customer');
        $this->raiseChickenTo(120);

        $row = $this->draftRow($estimate);

        $this->assertEqualsWithDelta(382.0, $row['old_calculated_rate'], 0.01, 'chicken 50 + rice 32 + making 300');
        $this->assertEqualsWithDelta(392.0, $row['projected_calculated_rate'], 0.01, 'chicken becomes 60 a kilo of dish');
        $this->assertEqualsWithDelta(382.0, $row['quoted_rate'], 0.01, 'nobody agreed anything different');
        $this->assertEqualsWithDelta(200.0, $row['difference'], 0.01, '12 -> nothing: 10 KG of chicken at +20');
        $this->assertEqualsWithDelta(200.0, $row['quotation_difference'], 0.01, '20 KG x 10 more per KG');
        $this->assertFalse($row['quoted_is_override']);
    }

    /** And applying it produces exactly the figure the preview promised. */
    public function test_applying_produces_the_rate_the_preview_predicted(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Decision Customer');
        $this->raiseChickenTo(120);

        $predicted = $this->draftRow($estimate)['projected_calculated_rate'];
        $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($estimate, 'Chicken')->id], $this->actorId);

        $this->assertEqualsWithDelta($predicted, (float) $this->line($estimate)->calculated_rate, 0.01,
            'a preview that does not predict the apply is worse than no preview');
        $this->assertEqualsWithDelta(392.0, (float) $this->line($estimate)->calculated_rate, 0.01);
    }

    /**
     * §8's second case, and the one most likely to be misread: an agreed rate
     * does NOT move because the house moved. Showing a quotation difference here
     * would promise the customer's total a change that is never going to happen.
     */
    public function test_an_agreed_rate_stays_put_while_the_calculation_underneath_it_moves(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Agreed Customer');
        $this->lineBlocks->overrideQuotedRate($this->line($estimate), 500, 'Customer agreed rate');
        $this->raiseChickenTo(120);

        $row = $this->draftRow($estimate);

        $this->assertEqualsWithDelta(382.0, $row['old_calculated_rate'], 0.01);
        $this->assertEqualsWithDelta(392.0, $row['projected_calculated_rate'], 0.01);
        $this->assertEqualsWithDelta(500.0, $row['quoted_rate'], 0.01);
        $this->assertTrue($row['quoted_is_override']);
        $this->assertSame('Customer agreed rate', $row['quoted_override_reason']);
        $this->assertEqualsWithDelta(0.0, $row['quotation_difference'], 0.01,
            'the customer still pays 500 — only the calculation moved');
    }

    /**
     * §9 — the projection is taken from the SNAPSHOT, so an event's own quantity
     * drives it rather than the dish's ratio. Twelve kilos, not the ratio's ten.
     */
    public function test_the_projection_follows_the_quantity_this_event_settled_on(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Override Customer');
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($estimate, 'Chicken'), 12);
        $this->raiseChickenTo(120);

        $row = $this->draftRow($estimate);

        $this->assertEqualsWithDelta(12.0, $row['material_qty'], 0.001);
        $this->assertEqualsWithDelta(240.0, $row['difference'], 0.01, '12 KG at 20 more, not the ratio\'s 10');
        $this->assertEqualsWithDelta(392.0, $row['old_calculated_rate'], 0.01, '1,200 chicken + 640 rice + 6,000 making over 20');
        $this->assertEqualsWithDelta(404.0, $row['projected_calculated_rate'], 0.01);
    }

    /** And the other materials on the line are read as stored, never recomputed. */
    public function test_the_projection_leaves_every_other_part_of_the_line_alone(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Untouched Customer');

        // The dish master moves AFTER the quotation was priced. The projection
        // must not quietly fold this in.
        CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Making')->update(['rate' => 900]);
        $this->raiseChickenTo(120);

        $this->assertEqualsWithDelta(392.0, $this->draftRow($estimate)['projected_calculated_rate'], 0.01,
            'the quotation is projected as the document it is, not as the dish would price today');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §10 — no fake impact.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_hand_agreed_snapshot_is_not_shown_a_difference_at_all(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Manual Customer');
        $this->raiseChickenTo(120);

        $rice = collect($this->impact->draftImpact($this->riceId))
            ->firstWhere('snapshot_id', $this->snapshot($estimate, 'Rice')->id);

        // Rice has no house rate at all, so it is not even offered a row.
        $this->assertNull($rice);

        // And a chicken snapshot that was set by hand is listed, explained, and
        // given no number — a "+200" beside "not linked" reads as a change the
        // system is refusing to make.
        $premium = $this->draft($this->premiumId, 'Premium Counter', 10, 'Premium Customer');
        $row = $this->draftRow($premium);

        $this->assertSame(CateringCommercialRateImpactService::STATE_MANUAL, $row['state']);
        $this->assertNull($row['difference']);
        $this->assertNull($row['new_amount']);
        $this->assertNull($row['projected_calculated_rate']);
        $this->assertNull($row['quotation_difference']);
        $this->assertStringContainsString('agreed for this quotation', $row['reason']);
    }

    /**
     * Customer-supplied is the one exclusion whose impact really IS a number.
     * Zero is the true answer, and more useful than a dash.
     */
    public function test_a_customer_supplied_material_reports_zero_rather_than_nothing(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Supplied Customer');
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Chicken'), true);
        $this->raiseChickenTo(500);

        $row = $this->draftRow($estimate);

        $this->assertSame(CateringCommercialRateImpactService::STATE_CUSTOMER_SUPPLIED, $row['state']);
        $this->assertSame(0.0, $row['difference'], 'the customer is not charged for it, at any house rate');
        $this->assertSame(0.0, $row['quotation_difference']);
        $this->assertFalse($row['eligible']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §6 / §7 — the record of what was done, and who did it.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_applying_to_a_dish_records_the_actor_and_both_sides_of_the_change(): void
    {
        $this->raiseChickenTo(120);
        $block = CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Chicken')->first();

        $this->impact->applyToProducts($this->chickenId, [$block->id], $this->actorId);

        $entry = CateringCommercialRateApplication::where('action',
            CateringCommercialRateApplication::ACTION_PRODUCT_APPLIED)->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->actorId, (int) $entry->performed_by_user_id);
        $this->assertSame($this->chickenId, (int) $entry->material_product_id);
        $this->assertSame('Chicken', $entry->material_name);
        $this->assertEqualsWithDelta(100.0, (float) $entry->old_commercial_rate, 0.01);
        $this->assertEqualsWithDelta(120.0, (float) $entry->new_commercial_rate, 0.01);
        $this->assertEqualsWithDelta(382.0, (float) $entry->old_calculated_rate, 0.01);
        $this->assertEqualsWithDelta(392.0, (float) $entry->new_calculated_rate, 0.01);
        $this->assertStringContainsString('Chicken Biryani', $entry->target_label);
    }

    public function test_applying_to_a_draft_records_the_document_it_moved(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Audited Customer');
        $this->raiseChickenTo(120);

        $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($estimate, 'Chicken')->id], $this->actorId);

        $entry = CateringCommercialRateApplication::where('action',
            CateringCommercialRateApplication::ACTION_DRAFT_APPLIED)->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($estimate->id, (int) $entry->catering_estimate_id);
        $this->assertSame($this->actorId, (int) $entry->performed_by_user_id);
        $this->assertEqualsWithDelta(382.0, (float) $entry->old_calculated_rate, 0.01);
        $this->assertEqualsWithDelta(392.0, (float) $entry->new_calculated_rate, 0.01,
            'the figure the line actually ended up with, read back after repricing');
    }

    /** Every changed target is traceable, one row each, not one row per click. */
    public function test_a_multi_select_apply_leaves_one_record_per_dish(): void
    {
        $this->raiseChickenTo(120);
        $ids = CateringProductCostBlock::whereIn('product_id', [$this->biryaniId, $this->karahiId])
            ->where('label', 'Chicken')->pluck('id')->all();

        $this->impact->applyToProducts($this->chickenId, $ids, $this->actorId);

        $this->assertSame(2, CateringCommercialRateApplication::where('action',
            CateringCommercialRateApplication::ACTION_PRODUCT_APPLIED)->count());
    }

    /**
     * §7 — the audit commits with the change or not at all. A log that survived a
     * rolled-back apply would assert a price movement that never happened, which
     * is worse than no log.
     */
    public function test_a_rolled_back_application_leaves_no_record_claiming_success(): void
    {
        $this->raiseChickenTo(120);
        $block = CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Chicken')->first();

        try {
            DB::connection('tenant')->transaction(function () use ($block) {
                $this->impact->applyToProducts($this->chickenId, [$block->id], $this->actorId);

                throw new RuntimeException('something later in the operation failed');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, CateringCommercialRateApplication::count(),
            'the record is written inside the same transaction as the change');
        $this->assertEqualsWithDelta(100.0, (float) $block->refresh()->rate, 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §11 / §12 / §13 — a sent quotation takes the rate by becoming a new one.
    // ─────────────────────────────────────────────────────────────────────────

    private function sentBiryani(): CateringEstimate
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Sent Customer');

        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($estimate, 'Chicken'), 12);
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Rice'), true);
        $this->lineBlocks->overrideQuotedRate($this->line($estimate), 500, 'Customer agreed rate');

        return $this->estimates->markSent($estimate->refresh());
    }

    /** THE MANDATORY SCENARIO. Everything the quotation knows must survive it. */
    public function test_a_sent_quotation_takes_the_house_rate_through_a_new_revision(): void
    {
        $sent = $this->sentBiryani();
        $this->raiseChickenTo(120);

        $revision = $this->impact->applyThroughRevision($this->chickenId, $sent->id, $this->actorId);

        $this->assertTrue($revision->isDraft());
        $this->assertSame(2, (int) $revision->version_no);

        $chicken = $this->snapshot($revision, 'Chicken');
        $rice = $this->snapshot($revision, 'Rice');
        $line = $this->line($revision);

        $this->assertEqualsWithDelta(120.0, (float) $chicken->rate, 0.01, 'the new version takes the house rate');
        $this->assertEqualsWithDelta(12.0, $chicken->physicalRequirement(), 0.001, 'and keeps this event\'s own quantity');
        $this->assertTrue($chicken->is_overridden, 'still marked as a deliberate override, not a fresh default');
        $this->assertTrue($rice->isCustomerSupplied(), 'the customer is still bringing the rice');
        $this->assertEqualsWithDelta(0.0, (float) $rice->amount, 0.01);

        $this->assertEqualsWithDelta(500.0, (float) $line->rate, 0.01, 'the agreed rate is what the customer agreed to');
        $this->assertSame('Customer agreed rate', $line->rate_override_reason);
        $this->assertEqualsWithDelta(372.0, (float) $line->calculated_rate, 0.01,
            '12 KG x 120 = 1,440 chicken, rice nil, making 6,000, over 20 KG');
        $this->assertEqualsWithDelta(10000.0, (float) $revision->refresh()->subtotal, 0.01, '500 x 20');
    }

    public function test_the_sent_version_is_left_exactly_as_it_was(): void
    {
        $sent = $this->sentBiryani();
        $sentChickenRate = (float) $this->snapshot($sent, 'Chicken')->rate;
        $sentCalculated = (float) $this->line($sent)->calculated_rate;

        $this->raiseChickenTo(120);
        $this->impact->applyThroughRevision($this->chickenId, $sent->id, $this->actorId);

        $sent->refresh();
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $sent->status);
        $this->assertEqualsWithDelta(100.0, $sentChickenRate, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $this->snapshot($sent, 'Chicken')->rate, 0.01,
            'what the customer was sent is what the customer was sent');
        $this->assertEqualsWithDelta($sentCalculated, (float) $this->line($sent)->calculated_rate, 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $this->line($sent)->rate, 0.01);
    }

    public function test_the_revision_is_recorded_as_its_own_kind_of_application(): void
    {
        $sent = $this->sentBiryani();
        $this->raiseChickenTo(120);
        $revision = $this->impact->applyThroughRevision($this->chickenId, $sent->id, $this->actorId);

        $entry = CateringCommercialRateApplication::where('action',
            CateringCommercialRateApplication::ACTION_REVISION_APPLIED)->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($revision->id, (int) $entry->catering_estimate_id);
        $this->assertSame($this->actorId, (int) $entry->performed_by_user_id);
        $this->assertStringContainsString('Revised from v1', $entry->note);
    }

    /** The preview says so too, rather than offering a checkbox that would fail. */
    public function test_a_sent_quotation_is_shown_its_real_impact_and_told_to_revise(): void
    {
        $sent = $this->sentBiryani();
        $this->raiseChickenTo(120);

        $row = $this->draftRow($sent);

        $this->assertSame(CateringCommercialRateImpactService::STATE_REVISION_REQUIRED, $row['state']);
        $this->assertFalse($row['eligible'], 'never repriced in place');
        $this->assertTrue($row['revisable']);
        $this->assertEqualsWithDelta(240.0, $row['difference'], 0.01, 'and the figure shown is the real one');
    }

    /** A sent snapshot posted straight at the draft apply is still refused. */
    public function test_a_sent_snapshot_cannot_be_repriced_in_place_by_id(): void
    {
        $sent = $this->sentBiryani();
        $this->raiseChickenTo(120);

        $applied = $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($sent, 'Chicken')->id], $this->actorId);

        $this->assertSame(0, $applied);
        $this->assertEqualsWithDelta(100.0, (float) $this->snapshot($sent, 'Chicken')->rate, 0.01);
        $this->assertSame(CateringEstimate::STATUS_SENT, $sent->refresh()->status);
    }

    /**
     * §12 — a revision that would change nothing is refused BEFORE anything is
     * superseded. Otherwise the business is left with a new version of a document
     * nobody asked to change, and the previous one marked dead.
     */
    public function test_a_revision_that_would_change_nothing_is_refused_before_superseding(): void
    {
        $estimate = $this->draft($this->premiumId, 'Premium Counter', 10, 'Premium Sent');
        $sent = $this->estimates->markSent($estimate->refresh());
        $this->raiseChickenTo(120);

        try {
            $this->impact->applyThroughRevision($this->chickenId, $sent->id, $this->actorId);
            $this->fail('a revision for a rate that applies to nothing must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('would change nothing', $e->getMessage());
        }

        $this->assertSame(CateringEstimate::STATUS_SENT, $sent->refresh()->status,
            'and the sent version is left alive');
        $this->assertSame(1, CateringEstimate::where('catering_event_id', $sent->catering_event_id)->count());
    }

    /**
     * §12 in its hardest form: the revision and the reprice are ONE transaction.
     *
     * A rollback forced from outside can only leave a superseded v1 or an orphan
     * v2 behind if some part of the operation committed early on its own.
     */
    public function test_a_rolled_back_revision_apply_leaves_the_sent_version_alive(): void
    {
        $sent = $this->sentBiryani();
        $this->raiseChickenTo(120);

        try {
            DB::connection('tenant')->transaction(function () use ($sent) {
                $this->impact->applyThroughRevision($this->chickenId, $sent->id, $this->actorId);

                throw new RuntimeException('something later in the operation failed');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(CateringEstimate::STATUS_SENT, $sent->refresh()->status,
            'v1 must not be left superseded by a revision that was rolled back');
        $this->assertSame(1, CateringEstimate::where('catering_event_id', $sent->catering_event_id)->count(),
            'and no orphan revision may survive');
        $this->assertEqualsWithDelta(100.0, (float) $this->snapshot($sent, 'Chicken')->rate, 0.01);
        $this->assertSame(0, CateringCommercialRateApplication::where('action',
            CateringCommercialRateApplication::ACTION_REVISION_APPLIED)->count(),
            'and the audit does not claim an application that was undone');
    }

    public function test_a_draft_is_edited_rather_than_revised(): void
    {
        $draft = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Draft Customer');
        $this->raiseChickenTo(120);

        $this->expectException(RuntimeException::class);
        $this->impact->applyThroughRevision($this->chickenId, $draft->id, $this->actorId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §14 — a finished document is finished.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_invoiced_event_is_locked_against_every_rate_action(): void
    {
        $sent = $this->sentBiryani();
        $event = $sent->event;

        CateringFinalInvoice::create([
            'invoice_no' => 'FI-LOCK-1',
            'catering_event_id' => $event->id,
            'catering_estimate_id' => $sent->id,
            'snapshot' => ['lines' => []],
            'subtotal' => 10000, 'service_charge_amount' => 0, 'other_charge_amount' => 0,
            'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 10000,
            'advance_total' => 0, 'advance_applied' => 0, 'balance_due' => 10000,
            'status' => CateringFinalInvoice::STATUS_ISSUED, 'issued_at' => now(),
        ]);

        $this->raiseChickenTo(120);

        $row = $this->draftRow($sent);
        $this->assertSame(CateringCommercialRateImpactService::STATE_LOCKED, $row['state']);
        $this->assertFalse($row['eligible']);
        $this->assertFalse($row['revisable']);
        $this->assertNull($row['projected_calculated_rate'], 'a locked document is shown no projection to act on');

        $this->assertSame(0, $this->impact->applyToDrafts($this->chickenId, [$row['snapshot_id']], $this->actorId));

        $this->expectException(RuntimeException::class);
        $this->impact->applyThroughRevision($this->chickenId, $sent->id, $this->actorId);
    }

    public function test_a_closed_event_is_locked_against_every_rate_action(): void
    {
        $sent = $this->sentBiryani();
        $sent->event->forceFill(['status' => CateringEvent::STATUS_CLOSED])->save();
        $this->raiseChickenTo(120);

        $row = $this->draftRow($sent->refresh());
        $this->assertSame(CateringCommercialRateImpactService::STATE_LOCKED, $row['state']);
        $this->assertFalse($this->impact->isRevisable($sent->refresh()));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // §16 — the apply trusts nothing the preview said.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_hand_agreed_dish_cannot_be_applied_by_posting_its_id(): void
    {
        $this->raiseChickenTo(120);
        $block = CateringProductCostBlock::where('product_id', $this->premiumId)
            ->where('label', 'Chicken')->first();

        $this->assertSame(0, $this->impact->applyToProducts($this->chickenId, [$block->id], $this->actorId));
        $this->assertEqualsWithDelta(140.0, (float) $block->refresh()->rate, 0.01);
        $this->assertSame(0, CateringCommercialRateApplication::where('action',
            CateringCommercialRateApplication::ACTION_PRODUCT_APPLIED)->count(),
            'and nothing is recorded, because nothing happened');
    }

    public function test_a_legacy_per_dish_block_cannot_be_applied_by_posting_its_id(): void
    {
        $this->raiseChickenTo(120);
        $block = CateringProductCostBlock::where('product_id', $this->karahiId)
            ->where('label', 'Chicken')->first();
        $block->forceFill(['rate_basis' => CateringProductCostBlock::RATE_PER_DISH_UNIT])->save();

        $this->assertSame(0, $this->impact->applyToProducts($this->chickenId, [$block->id], $this->actorId));
        $this->assertEqualsWithDelta(100.0, (float) $block->refresh()->rate, 0.01);
    }

    /** A rice block cannot be moved by posting its id to chicken's apply. */
    public function test_a_block_belonging_to_another_material_cannot_be_applied(): void
    {
        $this->raiseChickenTo(120);
        CateringMaterialCommercialRate::create([
            'product_id' => $this->riceId, 'rate' => 95, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->toDateString(),
        ]);

        $rice = CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Rice')->first();

        $this->assertSame(0, $this->impact->applyToProducts($this->chickenId, [$rice->id], $this->actorId));
        $this->assertEqualsWithDelta(80.0, (float) $rice->refresh()->rate, 0.01);
    }

    public function test_a_customer_supplied_snapshot_cannot_be_applied_by_posting_its_id(): void
    {
        $estimate = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Supplied Customer');
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Chicken'), true);
        $this->raiseChickenTo(120);

        $snapshotId = $this->snapshot($estimate, 'Chicken')->id;

        $this->assertSame(0, $this->impact->applyToDrafts($this->chickenId, [$snapshotId], $this->actorId));
        $this->assertEqualsWithDelta(100.0, (float) $this->snapshot($estimate, 'Chicken')->rate, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $this->snapshot($estimate, 'Chicken')->amount, 0.01);
    }

    public function test_a_quotation_nobody_selected_is_left_where_it_was(): void
    {
        $selected = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Selected');
        $untouched = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Untouched');
        $this->raiseChickenTo(120);

        $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($selected, 'Chicken')->id], $this->actorId);

        $this->assertEqualsWithDelta(392.0, (float) $this->line($selected)->calculated_rate, 0.01);
        $this->assertEqualsWithDelta(382.0, (float) $this->line($untouched)->calculated_rate, 0.01,
            'selective means selective');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The boundary this whole feature sits inside.
    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // §21 — revise() was changed outside this feature, so prove the quotations
    // that have nothing to do with cost blocks still revise.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A recipe-costed quotation has no cost-block snapshots at all. The clone
     * loop must simply find none and carry on — not assume every line has a
     * breakdown to copy.
     */
    public function test_a_recipe_costed_quotation_still_revises(): void
    {
        $recipeDish = $this->makeProduct($this->makeCategory(), [
            'name' => 'Recipe Biryani', 'sku' => 'CAT-RECBIR', 'unit_id' => $this->kgUnitId,
        ]);
        CateringProductProfile::updateOrCreate(
            ['product_id' => $recipeDish],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'recipe']
        );

        $recipeId = DB::connection('tenant')->table('recipes')->insertGetId([
            'product_id' => $recipeDish, 'name' => 'Recipe Biryani Deg', 'yield_quantity' => 10,
            'yield_unit_id' => $this->kgUnitId, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('recipe_ingredients')->insert([
            'recipe_id' => $recipeId, 'product_id' => $this->riceId, 'quantity' => 4,
            'unit_id' => $this->kgUnitId, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Recipe Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(7)->toDateString(),
            'pax' => 100,
        ]);
        $v1 = $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $recipeDish, 'item_name' => 'Recipe Biryani',
            'quantity' => 20, 'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 400,
        ]]);
        $this->estimates->markSent($v1->refresh());

        $v2 = $this->estimates->revise($v1->refresh());

        $this->assertSame(2, (int) $v2->version_no);
        $this->assertSame(1, $v2->lines()->count());
        $this->assertEqualsWithDelta(400.0, (float) $this->line($v2)->rate, 0.01,
            'a recipe quotation carries its rate across unchanged');
        $this->assertSame(0, $this->lineBlocks->snapshotsFor($this->line($v2))->count(),
            'and gains no breakdown it never had');
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $v1->refresh()->status);
    }

    /**
     * The oldest quotations of all: free-text lines with no product behind them,
     * from before any costing source existed. Revising one must not fail looking
     * for a breakdown.
     */
    public function test_a_quotation_with_no_cost_blocks_at_all_still_revises(): void
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Legacy Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(7)->toDateString(),
            'pax' => 100,
        ]);
        $v1 = $this->estimates->saveDraftLines($event->currentEstimate, [
            ['item_name' => 'Chicken Biryani', 'quantity' => 300, 'rate' => 250],
            ['item_name' => 'Raita', 'quantity' => 300, 'rate' => 30],
        ]);
        $this->estimates->markSent($v1->refresh());

        $v2 = $this->estimates->revise($v1->refresh());

        $this->assertSame(2, $v2->lines()->count());
        $this->assertEqualsWithDelta((float) $v1->refresh()->grand_total, (float) $v2->grand_total, 0.01,
            'the same quotation, one version later');
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $v1->refresh()->status);
    }

    public function test_no_rate_action_ever_posts_a_journal_or_moves_stock(): void
    {
        $sent = $this->sentBiryani();
        $draft = $this->draft($this->biryaniId, 'Chicken Biryani', 20, 'Ledger Customer');
        $this->raiseChickenTo(120);

        $block = CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Chicken')->first();

        $this->impact->applyToProducts($this->chickenId, [$block->id], $this->actorId);
        $this->impact->applyToDrafts($this->chickenId, [$this->snapshot($draft, 'Chicken')->id], $this->actorId);
        $this->impact->applyThroughRevision($this->chickenId, $sent->id, $this->actorId);

        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, DB::connection('tenant')->table('journal_lines')->count());
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count());
    }
}
