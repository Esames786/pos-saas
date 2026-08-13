<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringMaterialRate;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringRateImpactService;
use App\Services\Catering\CateringRecipeCostingService;
use App\Services\Catering\CateringRequirementService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-SLICE-2 — pure recipe costing invariants (spec §7/§8/§25):
 * requirement math + unit conversion, rate-book effective dating, purchase
 * price fallback, zero inventory interaction, selective draft repricing,
 * consolidated raw-material requirements, and rate-impact draft scoping.
 */
class CateringCostingMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringRecipeCostingService $costing;

    private int $kgUnitId;

    private int $gmUnitId;

    private int $biryaniId;

    private int $chickenId;

    private int $riceId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders', 'catering_material_issue_lines', 'catering_material_issues', 'catering_production_release_lines',
            'catering_production_releases', 'catering_final_invoices', 'catering_advances', 'catering_cost_snapshots',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_material_rates', 'catering_product_profiles', 'catering_settings',
            'recipe_ingredients', 'recipes', 'unit_conversions', 'units',
            'stock_ledgers', 'stock_balances', 'journal_lines', 'journal_entries',
            'products', 'categories', 'customers', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->costing = app(CateringRecipeCostingService::class);

        $tenant = $this->tenant();
        $this->kgUnitId = $tenant->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->gmUnitId = $tenant->table('units')->insertGetId([
            'code' => 'GM', 'name' => 'Gram', 'unit_type' => 'weight', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tenant->table('unit_conversions')->insert([
            'from_unit_id' => $this->gmUnitId, 'to_unit_id' => $this->kgUnitId, 'factor' => 0.001,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $categoryId = $this->makeCategory();
        $this->biryaniId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Biryani', 'unit_id' => $this->kgUnitId,
            'inventory_consumption_method' => 'recipe',
        ]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Raw Chicken', 'item_kind' => 'ingredient', 'unit_id' => $this->kgUnitId,
            'default_purchase_price' => 650,
        ]);
        $this->riceId = $this->makeProduct($categoryId, [
            'name' => 'Basmati Rice', 'item_kind' => 'ingredient', 'unit_id' => $this->kgUnitId,
            'default_purchase_price' => 300,
        ]);

        // Recipe: 10 KG Biryani batch = 2,500 GM chicken + 4 KG rice.
        $recipeId = $tenant->table('recipes')->insertGetId([
            'product_id' => $this->biryaniId, 'name' => 'Biryani Deg', 'yield_quantity' => 10,
            'yield_unit_id' => $this->kgUnitId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tenant->table('recipe_ingredients')->insert([
            ['recipe_id' => $recipeId, 'product_id' => $this->chickenId, 'quantity' => 2500, 'unit_id' => $this->gmUnitId, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['recipe_id' => $recipeId, 'product_id' => $this->riceId, 'quantity' => 4, 'unit_id' => $this->kgUnitId, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function draftEstimateWithBiryani(float $qty = 100): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'customer_name' => 'Costing Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 300,
        ]);
        $estimate = $event->currentEstimate;

        return $this->estimates->saveDraftLines($estimate, [
            ['product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani', 'quantity' => $qty, 'unit_id' => $this->kgUnitId, 'rate' => 250],
        ]);
    }

    public function test_recipe_costing_converts_units_and_uses_rate_book_with_purchase_price_fallback(): void
    {
        // Rate book: chicken 720/KG (rice has no rate row → purchase price 300).
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 720, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(10)->toDateString(),
        ]);

        $estimate = $this->draftEstimateWithBiryani(100); // 10 batches
        $result = $this->costing->calculate($estimate);

        $line = $result['lines'][0];
        $this->assertSame('recipe', $line['method']);
        $this->assertEqualsWithDelta(10.0, $line['batch_count'], 0.0001);

        [$chicken, $rice] = $line['ingredients'];
        // 2,500 GM × 10 batches = 25,000 GM → 25 KG × 720 = 18,000.
        $this->assertEqualsWithDelta(25000.0, $chicken['required_qty'], 0.001, 'raw requirement in recipe unit (GM)');
        $this->assertEqualsWithDelta(25.0, $chicken['priced_qty'], 0.001, 'converted GM → KG for pricing');
        $this->assertSame('rate_book', $chicken['rate_source']);
        $this->assertEqualsWithDelta(18000.0, $chicken['cost'], 0.01);

        // 4 KG × 10 = 40 KG × 300 purchase price = 12,000.
        $this->assertSame('purchase_price', $rice['rate_source']);
        $this->assertEqualsWithDelta(12000.0, $rice['cost'], 0.01);

        $this->assertEqualsWithDelta(30000.0, $result['total_material_cost'], 0.01);
        $this->assertSame([], $result['warnings']);

        // Pure calculation: zero inventory/GL interaction.
        foreach (['stock_ledgers', 'stock_balances', 'journal_entries'] as $table) {
            $this->assertSame(0, (int) $this->tenant()->table($table)->count(),
                "recipe costing must never write {$table}");
        }
    }

    public function test_rate_history_is_effective_dated_and_never_mutates_inventory_or_pos_prices(): void
    {
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 720, 'unit_id' => $this->kgUnitId,
            'effective_from' => '2026-08-01',
        ]);
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 800, 'unit_id' => $this->kgUnitId,
            'effective_from' => '2026-08-13',
        ]);

        $this->assertSame('720.0000', (string) CateringMaterialRate::effectiveFor($this->chickenId, '2026-08-05')->rate);
        $this->assertSame('800.0000', (string) CateringMaterialRate::effectiveFor($this->chickenId, '2026-08-13')->rate);
        $this->assertNull(CateringMaterialRate::effectiveFor($this->chickenId, '2026-07-01'), 'no rate before first effective date');

        $estimate = $this->draftEstimateWithBiryani(100);
        $old = $this->costing->calculate($estimate, '2026-08-05');
        $new = $this->costing->calculate($estimate, '2026-08-13');
        $this->assertEqualsWithDelta(30000.0, $old['total_material_cost'], 0.01, '25 KG × 720 + 40 × 300');
        $this->assertEqualsWithDelta(32000.0, $new['total_material_cost'], 0.01, '25 KG × 800 + 40 × 300');

        // The rate book NEVER writes inventory cost or POS selling price.
        $product = $this->tenant()->table('products')->where('id', $this->chickenId)->first();
        $this->assertSame('650.00', (string) $product->default_purchase_price, 'purchase price untouched');
        $this->assertSame(0, (int) $this->tenant()->table('stock_balances')->count(), 'no average_cost rows created');
    }

    public function test_missing_unit_conversion_is_a_warning_not_a_silent_wrong_number(): void
    {
        $literUnitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'LTR', 'name' => 'Liter', 'unit_type' => 'volume', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $oilId = $this->makeProduct($this->tenant()->table('categories')->value('id'), [
            'name' => 'Cooking Oil', 'item_kind' => 'ingredient', 'unit_id' => $literUnitId,
            'default_purchase_price' => 500,
        ]);
        // Ingredient quoted in KG but oil's unit is LTR and no KG→LTR conversion exists.
        $recipeId = $this->tenant()->table('recipes')->where('product_id', $this->biryaniId)->value('id');
        $this->tenant()->table('recipe_ingredients')->insert([
            'recipe_id' => $recipeId, 'product_id' => $oilId, 'quantity' => 1, 'unit_id' => $this->kgUnitId,
            'sort_order' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $estimate = $this->draftEstimateWithBiryani(10); // 1 batch
        $result = $this->costing->calculate($estimate);

        $oil = collect($result['lines'][0]['ingredients'])->firstWhere('product_id', $oilId);
        $this->assertNotNull($oil['warning'], 'missing conversion must surface as a warning');
        $this->assertSame(0.0, $oil['cost'], 'unconvertible ingredient is excluded, not mispriced');
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_snapshot_repricing_is_draft_only_and_selective(): void
    {
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 720, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(30)->toDateString(),
        ]);
        // §2 send gate: every ingredient needs a rate before markSent($sent) below.
        CateringMaterialRate::create([
            'product_id' => $this->riceId, 'rate' => 300, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(30)->toDateString(),
        ]);

        $draftA = $this->draftEstimateWithBiryani(100);
        $draftB = $this->draftEstimateWithBiryani(100);
        $sent = $this->draftEstimateWithBiryani(100);

        $this->costing->snapshot($draftA);
        $this->costing->snapshot($draftB);
        $this->costing->snapshot($sent);
        $this->estimates->markSent($sent->refresh());

        $this->assertSame('30000.00', (string) $draftA->refresh()->estimated_material_cost);

        // Chicken 720 → 800.
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 800, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->toDateString(),
        ]);

        $impact = app(CateringRateImpactService::class);
        $rows = $impact->impactForProduct($this->chickenId);

        $this->assertCount(2, $rows, 'only DRAFT estimates appear in the impact list — sent ones never do');
        $this->assertEqualsWithDelta(32000.0, $rows[0]['new_cost'], 0.01);
        $this->assertEqualsWithDelta(2000.0, $rows[0]['cost_delta'], 0.01);

        // Update SELECTED drafts: only draftA.
        $updated = $impact->applyToDrafts([$draftA->id, $sent->id]);
        $this->assertSame(1, $updated, 'sent estimate silently excluded from repricing');
        $this->assertSame('32000.00', (string) $draftA->refresh()->estimated_material_cost);
        $this->assertSame('30000.00', (string) $draftB->refresh()->estimated_material_cost, 'unselected draft untouched');
        $this->assertSame('30000.00', (string) $sent->refresh()->estimated_material_cost, 'sent estimate untouched');

        // Direct snapshot on a sent estimate must throw (frozen costing basis).
        try {
            $this->costing->snapshot($sent);
            $this->fail('snapshotting a sent estimate must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('frozen', $e->getMessage());
        }
    }

    public function test_shared_raw_materials_consolidate_into_one_requirement_line(): void
    {
        // Second recipe product sharing Raw Chicken: Qorma (3 KG chicken per 10 KG batch).
        $categoryId = $this->tenant()->table('categories')->value('id');
        $qormaId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Qorma', 'unit_id' => $this->kgUnitId, 'inventory_consumption_method' => 'recipe',
        ]);
        $qormaRecipeId = $this->tenant()->table('recipes')->insertGetId([
            'product_id' => $qormaId, 'name' => 'Qorma Deg', 'yield_quantity' => 10,
            'yield_unit_id' => $this->kgUnitId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->tenant()->table('recipe_ingredients')->insert([
            'recipe_id' => $qormaRecipeId, 'product_id' => $this->chickenId, 'quantity' => 3,
            'unit_id' => $this->kgUnitId, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = $this->estimates->createEvent([
            'customer_name' => 'Consolidation Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 500,
        ]);
        $estimate = $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani', 'quantity' => 100, 'unit_id' => $this->kgUnitId, 'rate' => 250],
            ['product_id' => $qormaId, 'item_name' => 'Chicken Qorma', 'quantity' => 50, 'unit_id' => $this->kgUnitId, 'rate' => 300],
        ]);

        $result = app(CateringRequirementService::class)->consolidatedForEstimate($estimate);

        $chickenRows = collect($result['requirements'])->where('product_id', $this->chickenId);
        $this->assertCount(1, $chickenRows, 'shared Raw Chicken must consolidate into ONE requirement');

        $chicken = $chickenRows->first();
        // Biryani: 2,500 GM × 10 batches = 25 KG. Qorma: 3 KG × 5 batches = 15 KG. Total 40 KG.
        $this->assertEqualsWithDelta(40.0, $chicken['required_qty'], 0.001);
        $this->assertSame('KG', $chicken['unit_code']);
        $this->assertEqualsAssociativeArray(['Chicken Biryani', 'Chicken Qorma'], $chicken['used_by']);

        // Read-only planning: no stock rows were created or modified.
        $this->assertSame(0, (int) $this->tenant()->table('stock_ledgers')->count());
    }

    // ── CATERING-V1-CLOSURE-1 (§3): agreed events stay visible, never mutated ──

    public function test_rate_impact_lists_agreed_events_read_only_and_revision_reenters_drafts(): void
    {
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 720, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(30)->toDateString(),
        ]);
        CateringMaterialRate::create([
            'product_id' => $this->riceId, 'rate' => 300, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(30)->toDateString(),
        ]);

        $agreed = $this->draftEstimateWithBiryani(100);
        $this->costing->snapshot($agreed);
        $this->estimates->markSent($agreed->refresh());
        $this->estimates->markAccepted($agreed->refresh());

        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 800, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->toDateString(),
        ]);

        $impact = app(CateringRateImpactService::class);

        // Owner keeps visibility: the accepted estimate appears in the AGREED group…
        $agreedRows = $impact->agreedImpactForProduct($this->chickenId);
        $this->assertCount(1, $agreedRows);
        $row = $agreedRows->first();
        $this->assertSame($agreed->id, $row['estimate']->id);
        $this->assertEqualsWithDelta(30000.0, $row['old_cost'], 0.01, 'cost basis at agreement time');
        $this->assertEqualsWithDelta(32000.0, $row['new_cost'], 0.01, 'recomputed at the new rate');

        // …but never in the actionable draft group, and bulk repricing skips it.
        $this->assertCount(0, $impact->impactForProduct($this->chickenId));
        $this->assertSame(0, $impact->applyToDrafts([$agreed->id]),
            'agreed documents are never silently repriced');
        $this->assertSame('30000.00', (string) $agreed->refresh()->estimated_material_cost);

        // The sanctioned path: Create Revision → new DRAFT appears in the actionable group.
        $revision = $this->estimates->revise($agreed->refresh());
        $draftRows = $impact->impactForProduct($this->chickenId);
        $this->assertCount(1, $draftRows);
        $this->assertSame($revision->id, $draftRows->first()['estimate']->id);
        $this->assertSame(1, $impact->applyToDrafts([$revision->id]));
        $this->assertSame('32000.00', (string) $revision->refresh()->estimated_material_cost);
        $this->assertSame('30000.00', (string) $agreed->refresh()->estimated_material_cost,
            'superseded agreed version keeps its historical cost basis');
    }

    // ── CATERING-V1-CLOSURE-1 (§2): costing readiness fails closed ────────────

    public function test_send_is_blocked_while_an_ingredient_has_only_the_purchase_price_fallback(): void
    {
        // Chicken has a rate; rice deliberately does NOT (draft preview falls back).
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 720, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(10)->toDateString(),
        ]);
        $estimate = $this->draftEstimateWithBiryani(100);

        $readiness = $this->costing->readiness($estimate);
        $this->assertFalse($readiness['ready']);
        $this->assertStringContainsString('Basmati Rice', implode(' ', $readiness['blockers']));
        $this->assertStringContainsString('FALLBACK', implode(' ', $readiness['blockers']),
            'purchase price must be labelled as fallback, never presented as a market rate');

        try {
            $this->estimates->markSent($estimate);
            $this->fail('send must be refused while a material has no effective Catering rate');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cost basis is incomplete', $e->getMessage());
        }

        try {
            $this->estimates->confirmEvent($estimate->event()->first());
            $this->fail('confirm must also be refused while the cost basis is incomplete');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cost basis is incomplete', $e->getMessage());
        }

        $this->assertSame(CateringEstimate::STATUS_DRAFT, $estimate->refresh()->status,
            'blocked send leaves the estimate a draft');
    }

    public function test_send_is_blocked_by_a_missing_unit_conversion_but_draft_preview_only_warns(): void
    {
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 720, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(10)->toDateString(),
        ]);
        CateringMaterialRate::create([
            'product_id' => $this->riceId, 'rate' => 300, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(10)->toDateString(),
        ]);

        // Break conversion: chicken quoted in GM in the recipe, rate is per KG.
        $this->tenant()->table('unit_conversions')->delete();

        $estimate = $this->draftEstimateWithBiryani(100);

        // Draft preview: warning surfaces, calculation does not explode.
        $result = $this->costing->calculate($estimate);
        $this->assertNotEmpty($result['warnings'], 'draft preview reports the missing conversion');

        // Send: hard refusal.
        try {
            $this->estimates->markSent($estimate);
            $this->fail('send must be refused on a missing unit conversion');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No unit conversion', $e->getMessage());
        }
    }

    public function test_send_succeeds_when_rates_and_conversions_are_complete(): void
    {
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 720, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(10)->toDateString(),
        ]);
        CateringMaterialRate::create([
            'product_id' => $this->riceId, 'rate' => 300, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(10)->toDateString(),
        ]);

        $estimate = $this->draftEstimateWithBiryani(100);
        $readiness = $this->costing->readiness($estimate);
        $this->assertTrue($readiness['ready']);
        $this->assertSame([], $readiness['blockers']);

        $this->estimates->markSent($estimate);
        $this->assertSame(CateringEstimate::STATUS_SENT, $estimate->refresh()->status);
    }

    public function test_invalid_recipe_yield_blocks_send(): void
    {
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 720, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(10)->toDateString(),
        ]);
        CateringMaterialRate::create([
            'product_id' => $this->riceId, 'rate' => 300, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDays(10)->toDateString(),
        ]);
        $this->tenant()->table('recipes')->where('product_id', $this->biryaniId)->update(['yield_quantity' => 0]);

        $estimate = $this->draftEstimateWithBiryani(100);
        $readiness = $this->costing->readiness($estimate);
        $this->assertFalse($readiness['ready']);
        $this->assertStringContainsString('invalid yield', implode(' ', $readiness['blockers']));

        try {
            $this->estimates->markSent($estimate);
            $this->fail('send must be refused on an invalid recipe yield');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('invalid yield', $e->getMessage());
        }
    }

    /** Order-insensitive list comparison helper. */
    private function assertEqualsAssociativeArray(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }
}
