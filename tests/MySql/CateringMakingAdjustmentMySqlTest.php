<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLineCostBlockService;
use App\Services\Catering\CateringMakingAdjustmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-MAKING-1 — Making moves only what was explicitly named Making.
 *
 * The worked example: Chicken 0.5 × 100 = 50, Making 300, Packing 40 →
 * calculated 390/KG. Making 300 → 350 moves the calculated rate by exactly
 * +50 per unit; a 20 KG booking's Making contribution goes 6,000 → 7,000.
 * Packing is a charge with NO role and must never move. A lump-sum Making is
 * charged once and never joins the per-unit rate. And the whole flow touches
 * neither stock nor the general ledger — proven, not assumed.
 */
class CateringMakingAdjustmentMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private CateringMakingAdjustmentService $making;

    private int $branchId;

    private int $biryaniId;

    private int $chickenId;

    private int $unitId;

    private CateringProductCostBlock $makingBlock;

    private CateringProductCostBlock $packingBlock;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();
        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);
        \Illuminate\Support\Facades\Gate::before(fn (?\App\Models\Tenant\User $user = null) => true);

        $this->cleanTenant([
            'catering_commercial_rate_applications',
            'catering_estimate_line_instruction', 'catering_instructions',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances',
            'units', 'products', 'categories', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);
        $this->making = app(CateringMakingAdjustmentService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'unit_id' => $this->unitId]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK-MAKING', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 80, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryaniId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Chicken',
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $this->chickenId, 'quantity_per_unit' => 0.5,
            'unit_id' => $this->unitId, 'rate' => 100,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'sort_order' => 1,
        ]);
        $this->makingBlock = CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE,
            'charge_role' => CateringProductCostBlock::ROLE_MAKING,
            'rate' => 300, 'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'sort_order' => 2,
        ]);
        $this->packingBlock = CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Packing',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE,
            'rate' => 40, 'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'sort_order' => 3,
        ]);
    }

    private function booking(float $qty = 20): CateringEstimateLine
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Making Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 100,
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => $qty, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);

        return $event->refresh()->currentEstimate->lines()->firstOrFail();
    }

    private function ledgerCounts(): array
    {
        $db = DB::connection('tenant');

        return [$db->table('journal_lines')->count(), $db->table('stock_ledgers')->count()];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Identity: role, not label.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_snapshot_copies_the_charge_role_and_survives_a_master_edit(): void
    {
        $line = $this->booking(20);

        $snapshot = $line->costBlocks->firstWhere('label', 'Making');
        $this->assertSame('making', $snapshot->charge_role, 'the quote remembers WHICH charge was Making');
        $this->assertNull($line->costBlocks->firstWhere('label', 'Packing')->charge_role);

        // Reclassifying the dish tomorrow must not rewrite yesterday's quote.
        $this->makingBlock->update(['charge_role' => null]);
        $this->assertSame('making', $snapshot->refresh()->charge_role,
            'old snapshots keep the role they were priced with');
    }

    public function test_only_a_making_labelled_by_role_participates_never_by_label_text(): void
    {
        // A charge LABELLED "Making" but not classified is invisible here.
        $this->makingBlock->update(['charge_role' => null]);

        $preview = $this->making->preview(350.0);
        $this->assertSame([], $preview['products'],
            'an unclassified charge never participates, whatever its label says');

        $this->makingBlock->update(['charge_role' => 'making']);
        $this->assertCount(1, $this->making->preview(350.0)['products']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The worked math.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_preview_shows_the_worked_example(): void
    {
        $line = $this->booking(20);
        $this->assertSame(390.0, (float) $line->calculated_rate, '50 chicken + 300 making + 40 packing');

        $preview = $this->making->preview(350.0);

        $product = $preview['products'][0];
        $this->assertSame(300.0, $product['current_making']);
        $this->assertSame(390.0, $product['old_calculated_rate']);
        $this->assertSame(440.0, $product['new_calculated_rate']);
        $this->assertSame(50.0, $product['difference']);

        $draft = $preview['drafts'][0];
        $this->assertSame(390.0, $draft['old_calculated_rate']);
        $this->assertSame(440.0, $draft['new_calculated_rate']);
        $this->assertSame(20.0, $draft['quantity']);
    }

    public function test_a_lump_sum_making_never_joins_the_per_unit_rate(): void
    {
        $this->makingBlock->update(['charge_basis' => CateringProductCostBlock::BASIS_LUMP_SUM, 'rate' => 3000]);
        $line = $this->booking(20);

        $this->assertSame(90.0, (float) $line->calculated_rate, 'chicken 50 + packing 40; lump sum stays out');
        $this->assertSame(3000.0, (float) $line->lump_sum_amount);

        $preview = $this->making->preview(3500.0);
        $this->assertSame(90.0, $preview['products'][0]['new_calculated_rate'],
            'changing a lump sum moves the one-off amount, never the per-unit rate');
        $this->assertSame(0.0, $preview['products'][0]['difference']);

        $this->making->applyToDrafts(3500.0, [$line->costBlocks()->where('label', 'Making')->value('id')]);
        $line->refresh();
        $this->assertSame(90.0, (float) $line->calculated_rate);
        $this->assertSame(3500.0, (float) $line->lump_sum_amount, 'the once-charged amount moved instead');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Selective apply.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_product_apply_is_selective_and_leaves_other_charges_alone(): void
    {
        $applied = $this->making->applyToProducts(350.0, [$this->makingBlock->id]);

        $this->assertSame(1, $applied);
        $this->assertSame(350.0, (float) $this->makingBlock->refresh()->rate);
        $this->assertSame(40.0, (float) $this->packingBlock->refresh()->rate, 'Packing never moves');

        // The audit names the act without inventing a material.
        $audit = CateringCommercialRateApplication::where('action', 'making_product_applied')->firstOrFail();
        $this->assertNull($audit->material_product_id);
        $this->assertSame(300.0, (float) $audit->old_commercial_rate);
        $this->assertSame(350.0, (float) $audit->new_commercial_rate);
        $this->assertSame(390.0, (float) $audit->old_calculated_rate);
        $this->assertSame(440.0, (float) $audit->new_calculated_rate);
    }

    public function test_product_apply_never_rewrites_existing_snapshots(): void
    {
        $line = $this->booking(20);

        $this->making->applyToProducts(350.0, [$this->makingBlock->id]);

        $this->assertSame(300.0, (float) $line->costBlocks()->where('label', 'Making')->value('rate'),
            'the quotation keeps the Making it was priced at');
        $this->assertSame(390.0, (float) $line->refresh()->calculated_rate);
    }

    public function test_draft_apply_is_selective_and_moves_the_worked_amounts(): void
    {
        $lineA = $this->booking(20);
        $lineB = $this->booking(10);

        $snapA = $lineA->costBlocks()->where('label', 'Making')->value('id');

        $applied = $this->making->applyToDrafts(350.0, [$snapA]);
        $this->assertSame(1, $applied);

        $lineA->refresh();
        $this->assertSame(440.0, (float) $lineA->calculated_rate);
        $this->assertSame(7000.0, (float) $lineA->costBlocks()->where('label', 'Making')->value('amount'),
            '20 KG × 350 — the 6,000 contribution became 7,000');
        $this->assertSame(800.0, (float) $lineA->costBlocks()->where('label', 'Packing')->value('amount'),
            'Packing 20 × 40 untouched');

        $this->assertSame(390.0, (float) $lineB->refresh()->calculated_rate, 'unselected draft unchanged');

        $audit = CateringCommercialRateApplication::where('action', 'making_draft_applied')->firstOrFail();
        $this->assertNull($audit->material_product_id);
        $this->assertSame(440.0, (float) $audit->new_calculated_rate);
    }

    public function test_an_agreed_quoted_rate_and_its_reason_survive_the_apply(): void
    {
        $line = $this->booking(20);
        $this->lineBlocks->overrideQuotedRate($line, 500.0, 'wedding package agreed rate');

        $this->making->applyToDrafts(350.0, [$line->costBlocks()->where('label', 'Making')->value('id')]);

        $line->refresh();
        $this->assertSame(440.0, (float) $line->calculated_rate, 'the calculation moved');
        $this->assertSame(500.0, (float) $line->rate, 'the agreed rate did not');
        $this->assertSame('wedding package agreed rate', $line->rate_override_reason);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Immutability.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_sent_quotation_is_never_mutated_and_is_named_in_the_preview(): void
    {
        $line = $this->booking(20);
        $this->estimates->markSent($line->estimate);

        $preview = $this->making->preview(350.0);
        $this->assertSame([], $preview['drafts']);
        $this->assertCount(1, $preview['ineligible_documents']);
        $this->assertStringContainsString('revision', $preview['ineligible_documents'][0]['reason']);

        // Even a forced id list fails closed under the lock.
        $applied = $this->making->applyToDrafts(350.0, [
            $line->costBlocks()->where('label', 'Making')->value('id'),
        ]);
        $this->assertSame(0, $applied);
        $this->assertSame(300.0, (float) $line->costBlocks()->where('label', 'Making')->value('rate'));

        // The sanctioned road: revise → the NEW draft's snapshot is adjustable.
        $revision = $this->estimates->revise($line->estimate->refresh());
        $newLine = $revision->lines()->firstOrFail();
        $this->assertSame('making', $newLine->costBlocks()->where('label', 'Making')->value('charge_role'),
            'the revision carries the role, so the new draft is adjustable');
        $this->assertSame(1, $this->making->applyToDrafts(350.0, [
            $newLine->costBlocks()->where('label', 'Making')->value('id'),
        ]));
        $this->assertSame(440.0, (float) $newLine->refresh()->calculated_rate);
    }

    public function test_the_screen_renders_the_preview_tables(): void
    {
        $this->booking(20);

        $html = \Illuminate\Support\Facades\View::make('tenant.catering.making-adjustment.index', [
            'preview' => $this->making->preview(350.0),
        ])->render();

        $this->assertStringContainsString('Making Adjustment', $html);
        $this->assertStringContainsString('Current Making', $html);
        $this->assertStringContainsString('New Calculated Rate', $html);
        $this->assertStringContainsString('440.00', $html, 'the worked projection is on screen');
        $this->assertStringContainsString('Apply to Selected Dishes', $html);
        $this->assertStringContainsString('Apply to Selected Drafts', $html);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // No stock, no GL — proven.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_making_preview_and_apply_touch_neither_stock_nor_the_ledger(): void
    {
        $line = $this->booking(20);
        $before = $this->ledgerCounts();

        $this->making->preview(350.0);
        $this->making->applyToProducts(350.0, [$this->makingBlock->id]);
        $this->making->applyToDrafts(350.0, [$line->costBlocks()->where('label', 'Making')->value('id')]);

        $this->assertSame($before, $this->ledgerCounts(),
            'Making adjustment posts nothing and moves nothing — it reprices documents');
    }
}
