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
 * KASHIF-CATERING-LINE-SNAPSHOT-1 (hardening) — the two things that survive.
 *
 * A line's costing tests proved the arithmetic. They could not prove either of
 * the things that actually break a quotation in use:
 *
 *   THE DOCUMENT. Changing a line changed the line. The estimate it belongs to
 *   was never re-added, so a line could read 1,960 inside a quotation still
 *   totalling 1,910 — and whichever the customer was shown, one was wrong.
 *
 *   THE DECISIONS. Saving the estimate form deleted every line and rebuilt it.
 *   Harmless when a line was three numbers; destructive once it carries a
 *   material quantity someone typed for this event and a rate they agreed with
 *   the customer. Changing a venue would have silently discarded both.
 *
 * The dish is the business's worked example: chicken 100/KG x 0.50, rice 80/KG
 * x 0.40, making 300 — 382 per KG, 1,910 for five kilos.
 */
class CateringDraftEditPreservationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private int $branchId;

    private int $biryaniId;

    private int $karahiId;

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
            'journal_lines', 'journal_entries', 'stock_ledgers',
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

        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->unitId]);
        $this->karahiId = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'sku' => 'CAT-KAR', 'unit_id' => $this->unitId]);
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
        $this->buildKarahi();
    }

    private function profile(int $productId): void
    {
        CateringProductProfile::updateOrCreate(
            ['product_id' => $productId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );
    }

    private function material(int $dishId, string $label, int $materialId, float $rate, float $ratio, int $order): void
    {
        CateringProductCostBlock::create([
            'product_id' => $dishId, 'label' => $label,
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $materialId, 'quantity_per_unit' => $ratio,
            'unit_id' => $this->unitId, 'rate' => $rate,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'sort_order' => $order,
        ]);
    }

    private function charge(int $dishId, string $label, float $rate, int $order, bool $lump = false): void
    {
        CateringProductCostBlock::create([
            'product_id' => $dishId, 'label' => $label,
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => $rate,
            'charge_basis' => $lump
                ? CateringProductCostBlock::BASIS_LUMP_SUM
                : CateringProductCostBlock::BASIS_PER_UNIT,
            'sort_order' => $order,
        ]);
    }

    /** 382/KG — chicken 50, rice 32, making 300. */
    private function buildBiryani(): void
    {
        $this->profile($this->biryaniId);
        $this->material($this->biryaniId, 'Chicken', $this->chickenId, 100, 0.50, 1);
        $this->material($this->biryaniId, 'Rice', $this->riceId, 80, 0.40, 2);
        $this->charge($this->biryaniId, 'Making', 300, 3);
    }

    /** 300/KG plus a 2,000 setup charged once. */
    private function buildKarahi(): void
    {
        $this->profile($this->karahiId);
        $this->material($this->karahiId, 'Chicken', $this->chickenId, 100, 0.50, 1);
        $this->charge($this->karahiId, 'Making', 250, 2);
        $this->charge($this->karahiId, 'Setup', 2000, 3, lump: true);
    }

    private function draft(array $lines, array $charges = []): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Preservation Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(6)->toDateString(),
            'pax' => 120,
        ]);

        return $this->estimates->saveDraftLines($event->currentEstimate, $lines, $charges);
    }

    /** @return array<string, mixed> */
    private function lineInput(int $productId, string $name, float $qty, ?string $uuid = null): array
    {
        return array_filter([
            'line_uuid' => $uuid,
            'product_id' => $productId,
            'item_name' => $name,
            'quantity' => $qty,
            'unit_id' => $this->unitId,
            'unit_code' => 'KG',
            'rate' => 0,
        ], fn ($v) => $v !== null);
    }

    private function biryaniLine(CateringEstimate $estimate): CateringEstimateLine
    {
        return $estimate->refresh()->lines->firstWhere('product_id', $this->biryaniId);
    }

    private function snapshot(CateringEstimateLine $line, string $label): CateringEstimateLineCostBlock
    {
        return $this->lineBlocks->snapshotsFor($line)->firstWhere('label', $label);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BLOCKER 1 — the document follows the line.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_material_override_moves_the_quotation_total(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);
        $this->assertSame(1910.0, round((float) $estimate->subtotal, 2));

        $line = $this->biryaniLine($estimate);
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($line, 'Chicken'), 3.0);

        $estimate = $estimate->refresh();
        $this->assertSame(1960.0, round((float) $line->fresh()->amount, 2));
        $this->assertSame(1960.0, round((float) $estimate->subtotal, 2),
            'a line at 1,960 inside a quotation totalling 1,910 is a lie either way round');
        $this->assertSame(1960.0, round((float) $estimate->grand_total, 2));
    }

    public function test_resetting_a_material_moves_the_quotation_total_back(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);
        $line = $this->biryaniLine($estimate);

        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($line, 'Chicken'), 3.0);
        $this->lineBlocks->resetMaterialQuantity($this->snapshot($line->fresh(), 'Chicken'));

        $this->assertSame(1910.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    public function test_a_dish_quantity_change_moves_the_quotation_total(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);
        $line = $this->biryaniLine($estimate);

        $line->forceFill(['quantity' => 10])->save();
        $this->lineBlocks->recalculateForQuantity($line->fresh());

        $this->assertSame(3820.0, round((float) $estimate->refresh()->subtotal, 2), '10 x 382');
    }

    public function test_a_quoted_rate_override_moves_the_quotation_total(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);

        $this->lineBlocks->overrideQuotedRate($this->biryaniLine($estimate), 500, 'Customer agreed rate');

        $estimate = $estimate->refresh();
        $this->assertSame(2500.0, round((float) $estimate->subtotal, 2), '5 x 500');
        $this->assertSame(2500.0, round((float) $estimate->grand_total, 2));
    }

    public function test_returning_to_the_calculated_rate_moves_the_total_back(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);
        $line = $this->biryaniLine($estimate);

        $this->lineBlocks->overrideQuotedRate($line, 500, 'Customer agreed rate');
        $this->lineBlocks->useCalculatedRate($line->fresh());

        $line = $line->fresh();
        $this->assertSame(382.0, round((float) $line->rate, 2));
        $this->assertNull($line->rate_override_reason);
        $this->assertSame(1910.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    /** A lump sum reaches the total once, and a quoted override does not double it. */
    public function test_a_line_lump_sum_reaches_the_total_exactly_once(): void
    {
        $estimate = $this->draft([$this->lineInput($this->karahiId, 'Chicken Karahi', 5)]);

        // 5 x 300 + 2,000 once
        $this->assertSame(3500.0, round((float) $estimate->subtotal, 2));

        $this->lineBlocks->overrideQuotedRate($this->biryaniLineOrKarahi($estimate), 500, 'Package rate');

        // 5 x 500 + 2,000 once — the setup is not multiplied and not counted twice
        $this->assertSame(4500.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    public function test_several_lines_with_separate_lump_sums_add_up(): void
    {
        $estimate = $this->draft([
            $this->lineInput($this->biryaniId, 'Chicken Biryani', 5),
            $this->lineInput($this->karahiId, 'Chicken Karahi', 10),
        ]);

        // 1,910 + (10 x 300 + 2,000) = 1,910 + 5,000
        $this->assertSame(6910.0, round((float) $estimate->subtotal, 2));

        $lines = $estimate->refresh()->lines->keyBy('item_name');
        $this->assertSame(0.0, round((float) $lines['Chicken Biryani']->lump_sum_amount, 2));
        $this->assertSame(2000.0, round((float) $lines['Chicken Karahi']->lump_sum_amount, 2));
    }

    /** Document charges keep flowing through the one totals formula. */
    public function test_document_charges_still_apply_on_top_of_line_totals(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)], [
            'service_charge_amount' => 200,
            'other_charge_label' => 'Transport',
            'other_charge_amount' => 500,
            'tax_amount' => 100,
        ]);

        $this->assertSame(1910.0, round((float) $estimate->subtotal, 2));
        $this->assertSame(2710.0, round((float) $estimate->grand_total, 2), '1,910 + 200 + 500 + 100');

        // And they survive a line-level change rather than being recomputed away.
        $this->lineBlocks->overrideQuotedRate($this->biryaniLine($estimate), 500, 'Agreed');

        $estimate = $estimate->refresh();
        $this->assertSame(2500.0, round((float) $estimate->subtotal, 2));
        $this->assertSame(500.0, round((float) $estimate->other_charge_amount, 2), 'still separate, still there');
        $this->assertSame(3300.0, round((float) $estimate->grand_total, 2), '2,500 + 200 + 500 + 100');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BLOCKER 2 — an ordinary save keeps the decisions.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The scenario that would have lost real work: set a quantity for this event,
     * agree a rate with the customer, then edit the order size the ordinary way.
     */
    public function test_an_ordinary_save_keeps_the_material_override_and_the_agreed_rate(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);
        $line = $this->biryaniLine($estimate);
        $uuid = $line->line_uuid;

        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($line, 'Chicken'), 3.0);
        $this->lineBlocks->overrideQuotedRate($line->fresh(), 500, 'Customer agreed rate');

        // The operator now edits the order the ordinary way: 5 KG becomes 10.
        $this->estimates->saveDraftLines($estimate->refresh(), [
            $this->lineInput($this->biryaniId, 'Chicken Biryani', 10, $uuid),
        ]);

        $line = $this->biryaniLine($estimate);
        $this->assertSame($uuid, $line->line_uuid, 'the same logical line, updated rather than replaced');

        $chicken = $this->snapshot($line, 'Chicken');
        $this->assertEqualsWithDelta(3.0, (float) $chicken->event_material_qty, 0.001,
            'the quantity somebody typed for this event stands');
        $this->assertTrue($chicken->is_overridden);

        $this->assertSame(500.0, round((float) $line->rate, 2), 'and so does the rate they agreed');
        $this->assertSame('Customer agreed rate', $line->rate_override_reason);

        // Rice was never touched, so it follows the order: 4 KG x 80 = 320.
        $this->assertSame(320.0, round((float) $this->snapshot($line, 'Rice')->amount, 2));
        $this->assertEqualsWithDelta(5.0, (float) $chicken->default_material_qty, 0.001,
            'the default it could be reset to has moved with the order');

        // 10 x 500 quoted
        $this->assertSame(5000.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    /** Saving unrelated fields must not disturb a line at all. */
    public function test_saving_the_form_without_changing_a_line_leaves_it_alone(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);
        $line = $this->biryaniLine($estimate);
        $uuid = $line->line_uuid;

        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($line, 'Chicken'), 3.0);
        $before = $this->lineBlocks->snapshotsFor($line->fresh())
            ->map(fn ($s) => [$s->label, (float) $s->event_material_qty, (float) $s->amount])->all();

        $this->estimates->saveDraftLines($estimate->refresh(), [
            $this->lineInput($this->biryaniId, 'Chicken Biryani', 5, $uuid),
        ]);

        $after = $this->lineBlocks->snapshotsFor($this->biryaniLine($estimate))
            ->map(fn ($s) => [$s->label, (float) $s->event_material_qty, (float) $s->amount])->all();

        $this->assertSame($before, $after, 'an unchanged line must come through untouched');
    }

    /**
     * Changing the product is a different dish. Keeping a Chicken Biryani
     * breakdown under a Chicken Karahi would be worse than having none.
     */
    public function test_changing_the_product_starts_the_costing_again(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);
        $line = $this->biryaniLine($estimate);
        $uuid = $line->line_uuid;

        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($line, 'Chicken'), 3.0);
        $this->lineBlocks->overrideQuotedRate($line->fresh(), 500, 'Old dish rate');

        $this->estimates->saveDraftLines($estimate->refresh(), [
            $this->lineInput($this->karahiId, 'Chicken Karahi', 5, $uuid),
        ]);

        $line = $estimate->refresh()->lines->firstWhere('product_id', $this->karahiId);
        $this->assertNotNull($line);
        $this->assertNull($line->rate_override_reason, 'the old dish\'s agreed rate does not carry over');
        $this->assertSame(300.0, round((float) $line->calculated_rate, 2), 'priced from the new dish');
        $this->assertSame(2000.0, round((float) $line->lump_sum_amount, 2), 'including its own setup charge');

        $labels = $this->lineBlocks->snapshotsFor($line)->pluck('label')->all();
        $this->assertContains('Setup', $labels);
        $this->assertNotContains('Rice', $labels, 'nothing of the previous dish survives');
    }

    public function test_a_new_line_gets_its_own_snapshot(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);
        $uuid = $this->biryaniLine($estimate)->line_uuid;

        $this->estimates->saveDraftLines($estimate->refresh(), [
            $this->lineInput($this->biryaniId, 'Chicken Biryani', 5, $uuid),
            $this->lineInput($this->karahiId, 'Chicken Karahi', 4),
        ]);

        $karahi = $estimate->refresh()->lines->firstWhere('product_id', $this->karahiId);
        $this->assertNotNull($karahi);
        $this->assertCount(3, $this->lineBlocks->snapshotsFor($karahi));
        $this->assertSame(300.0, round((float) $karahi->calculated_rate, 2));
    }

    public function test_a_removed_line_takes_its_snapshot_with_it(): void
    {
        $estimate = $this->draft([
            $this->lineInput($this->biryaniId, 'Chicken Biryani', 5),
            $this->lineInput($this->karahiId, 'Chicken Karahi', 4),
        ]);
        $keepUuid = $this->biryaniLine($estimate)->line_uuid;
        $removed = $estimate->refresh()->lines->firstWhere('product_id', $this->karahiId);

        $this->estimates->saveDraftLines($estimate->refresh(), [
            $this->lineInput($this->biryaniId, 'Chicken Biryani', 5, $keepUuid),
        ]);

        $this->assertCount(1, $estimate->refresh()->lines);
        $this->assertSame(0, CateringEstimateLineCostBlock::where('catering_estimate_line_id', $removed->id)->count(),
            'the snapshot goes with the line it explained');
        $this->assertSame(1910.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    /** A line submitted without an identity is simply new. */
    public function test_a_line_with_no_identity_is_treated_as_new(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);

        $this->estimates->saveDraftLines($estimate->refresh(), [
            $this->lineInput($this->biryaniId, 'Chicken Biryani', 5),
        ]);

        $this->assertCount(1, $estimate->refresh()->lines, 'the old one is removed, the new one replaces it');
        $this->assertSame(1910.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Boundaries kept.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_sent_quotation_still_refuses_everything(): void
    {
        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);
        $line = $this->biryaniLine($estimate);
        $this->estimates->markSent($estimate->refresh());

        try {
            $this->lineBlocks->overrideQuotedRate($line->fresh(), 500, 'too late');
            $this->fail('a sent quotation must refuse a rate change');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sent quotation cannot be repriced', $e->getMessage());
        }

        try {
            $this->lineBlocks->useCalculatedRate($line->fresh());
            $this->fail('and must refuse being put back too');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sent quotation cannot be repriced', $e->getMessage());
        }

        $this->assertSame(1910.0, round((float) $estimate->refresh()->subtotal, 2), 'and nothing moved');
    }

    public function test_editing_a_draft_posts_nothing_and_moves_no_stock(): void
    {
        $before = $this->ledgerCounts();

        $estimate = $this->draft([$this->lineInput($this->biryaniId, 'Chicken Biryani', 5)]);
        $line = $this->biryaniLine($estimate);
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($line, 'Chicken'), 3.0);
        $this->lineBlocks->overrideQuotedRate($line->fresh(), 500, 'Agreed');
        $this->estimates->saveDraftLines($estimate->refresh(), [
            $this->lineInput($this->biryaniId, 'Chicken Biryani', 10, $line->line_uuid),
        ]);

        $this->assertSame($before, $this->ledgerCounts());
    }

    private function biryaniLineOrKarahi(CateringEstimate $estimate): CateringEstimateLine
    {
        return $estimate->refresh()->lines->first();
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
