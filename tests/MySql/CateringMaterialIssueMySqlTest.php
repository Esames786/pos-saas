<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringMaterialIssueService;
use App\Services\Catering\CateringProductionReleaseService;
use App\Services\Inventory\InventoryService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-GO-LIVE-READINESS-1 (§7/§8): "Issue Materials" moves official stock
 * ONLY through InventoryService::postOutFefo; the release stays a pure plan;
 * one issue per release (retry-idempotent); COGS posts at ACTUAL FEFO layer
 * cost (never the Material Rate Book); non-stock materials never fake
 * movements; failure rolls the entire issue back.
 */
class CateringMaterialIssueMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringMaterialIssueService $issues;

    private int $branchId;

    private int $chickenId;

    private int $riceId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders', 'catering_material_issue_lines', 'catering_material_issues',
            'catering_production_release_lines', 'catering_production_releases', 'catering_final_invoices',
            'catering_advances', 'catering_cost_snapshots', 'catering_estimate_lines', 'catering_estimates',
            'catering_events', 'catering_material_rates', 'catering_product_profiles', 'catering_settings',
            'recipe_ingredients', 'recipes', 'unit_conversions', 'units',
            'journal_lines', 'journal_entries', 'accounts',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sales_order_lines', 'sales_orders', 'products', 'categories', 'customers', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->issues = app(CateringMaterialIssueService::class);
        $this->branchId = $this->makeBranch();

        $tenant = $this->tenant();
        $kgId = $tenant->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $categoryId = $this->makeCategory();
        $biryaniId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Biryani', 'unit_id' => $kgId, 'inventory_consumption_method' => 'recipe',
        ]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Raw Chicken', 'item_kind' => 'ingredient', 'unit_id' => $kgId, 'default_purchase_price' => 650,
        ]);
        $this->riceId = $this->makeProduct($categoryId, [
            'name' => 'Basmati Rice', 'item_kind' => 'ingredient', 'unit_id' => $kgId, 'default_purchase_price' => 300,
        ]);
        // A pure-service line item (waiters) — is_stock_tracked = 0.
        $tenant->table('products')->where('id', $this->riceId)->update(['is_stock_tracked' => 1]);

        // Recipe: 10 KG batch = 2.5 KG chicken + 4 KG rice.
        $recipeId = $tenant->table('recipes')->insertGetId([
            'product_id' => $biryaniId, 'name' => 'Biryani Deg', 'yield_quantity' => 10,
            'yield_unit_id' => $kgId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tenant->table('recipe_ingredients')->insert([
            ['recipe_id' => $recipeId, 'product_id' => $this->chickenId, 'quantity' => 2.5, 'unit_id' => $kgId, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['recipe_id' => $recipeId, 'product_id' => $this->riceId, 'quantity' => 4, 'unit_id' => $kgId, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $tenant->table('catering_material_rates')->insert([
            // QUOTING rate deliberately DIFFERENT from inventory cost: 800 vs 650.
            ['product_id' => $this->chickenId, 'rate' => 800, 'effective_from' => now()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $this->riceId, 'rate' => 350, 'effective_from' => now()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $tenant->table('catering_material_rates')->insert([
            ['product_id' => $biryaniId, 'rate' => 320, 'effective_from' => now()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Official opening stock at INVENTORY cost: chicken 650/KG, rice 300/KG.
        $inventory = app(InventoryService::class);
        $branch = Branch::find($this->branchId);
        $inventory->postIn($branch, Product::find($this->chickenId), null, 100, 650, 'opening_stock', null, null, 'OPEN-CHK');
        $inventory->postIn($branch, Product::find($this->riceId), null, 100, 300, 'opening_stock', null, null, 'OPEN-RICE');

        $this->biryaniId = $biryaniId;
    }

    private int $biryaniId;

    /** Confirmed + released 100 KG Biryani event (→ 25 KG chicken + 40 KG rice). */
    private function releasedEvent(): CateringProductionRelease
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Issue Test Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(2)->toDateString(),
            'pax' => 300,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani', 'quantity' => 100,
                'unit_id' => $this->tenant()->table('units')->where('code', 'KG')->value('id'), 'rate' => 320],
        ]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        return app(CateringProductionReleaseService::class)->release($event->refresh());
    }

    private function onHand(int $productId): float
    {
        return (float) $this->tenant()->table('stock_balances')->where('product_id', $productId)->sum('quantity_on_hand');
    }

    public function test_issue_decrements_official_stock_via_fefo_and_posts_actual_cost_cogs(): void
    {
        $release = $this->releasedEvent();

        // Printing/releasing alone moved NOTHING (stock before = opening).
        $this->assertSame(100.0, $this->onHand($this->chickenId), 'release/print never consumes inventory');
        $this->assertSame(100.0, $this->onHand($this->riceId));
        $stockLedgersBefore = (int) $this->tenant()->table('stock_ledgers')->count(); // 2 opening rows

        $issue = $this->issues->issue($release);

        // Stock after: 100−25 chicken, 100−40 rice — via the official authority.
        $this->assertSame(75.0, $this->onHand($this->chickenId));
        $this->assertSame(60.0, $this->onHand($this->riceId));

        $movementRows = $this->tenant()->table('stock_ledgers')
            ->where('reference_type', 'catering_material_issue')->where('reference_id', $issue->id)->get();
        $this->assertCount(2, $movementRows);
        foreach ($movementRows as $row) {
            $this->assertSame('recipe_consumption', $row->movement_type, 'movement flows through the approved enum');
            $this->assertSame('out', $row->direction);
        }

        // ACTUAL FEFO cost: 25×650 + 40×300 = 28,250 — NOT the rate book (25×800+40×350=31,999…).
        $this->assertSame('28250.0000', (string) $issue->total_fefo_cost);
        $chickenLine = $issue->lines->firstWhere('product_id', $this->chickenId);
        $this->assertSame('16250.0000', (string) $chickenLine->fefo_cost_total);
        $this->assertNotEmpty($chickenLine->stock_ledger_ids, 'ledger references recorded on the issue line');

        // §8 COGS at actual cost: Dr 5200 / Cr 1400, source = the issue identity.
        $entry = $this->tenant()->table('journal_entries')
            ->where('source_type', 'catering_material_issue')->where('source_id', $issue->id)->first();
        $this->assertNotNull($entry, 'COGS journal exists');
        $this->assertEqualsWithDelta(28250.0, (float) $entry->total_debit, 0.001);
        $this->assertSame($issue->refresh()->cogs_journal_entry_id, $entry->id);

        // ── Idempotent retry: same document back, zero extra movement/journals ──
        $retry = $this->issues->issue($release);
        $this->assertSame($issue->id, $retry->id, 'one issue per release — retry returns the same document');
        $this->assertSame(75.0, $this->onHand($this->chickenId), 'no double decrement');
        $this->assertSame($stockLedgersBefore + 2, (int) $this->tenant()->table('stock_ledgers')->count());
        $this->assertSame(1, (int) $this->tenant()->table('journal_entries')
            ->where('source_type', 'catering_material_issue')->count());

        // Immutable stock document.
        try {
            $issue->refresh()->update(['total_fefo_cost' => 1]);
            $this->fail('a material issue must be immutable');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_insufficient_stock_follows_branch_policy_and_failure_rolls_everything_back(): void
    {
        // Drain rice so the requirement (40 KG) cannot be met; branch forbids negative.
        app(InventoryService::class)->postOutFefo(
            Branch::find($this->branchId), Product::find($this->riceId), null, 95, 'adjustment_out', null, null, 'DRAIN'
        );
        $release = $this->releasedEvent();
        $ledgersBefore = (int) $this->tenant()->table('stock_ledgers')->count();
        $chickenBefore = $this->onHand($this->chickenId);

        try {
            $this->issues->issue($release);
            $this->fail('insufficient stock must refuse under the no-negative branch policy');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Insufficient stock', $e->getMessage());
        }

        // WHOLE issue rolled back: no document, no lines, no partial chicken movement, no COGS.
        $this->assertSame(0, (int) $this->tenant()->table('catering_material_issues')->count());
        $this->assertSame(0, (int) $this->tenant()->table('catering_material_issue_lines')->count());
        $this->assertSame($chickenBefore, $this->onHand($this->chickenId), 'partial chicken issue rolled back');
        $this->assertSame($ledgersBefore, (int) $this->tenant()->table('stock_ledgers')->count());
        $this->assertSame(0, (int) $this->tenant()->table('journal_entries')
            ->where('source_type', 'catering_material_issue')->count());

        // With allow-negative branches the existing policy applies instead.
        $this->tenant()->table('branches')->where('id', $this->branchId)->update(['allow_negative_stock' => 1]);
        $issue = $this->issues->issue($release);
        $this->assertLessThan(0, $this->onHand($this->riceId), 'allow-negative branch issues into negative stock');
        $this->assertGreaterThan(0, (float) $issue->total_fefo_cost);
    }

    public function test_non_stock_materials_never_create_fake_movements(): void
    {
        // Waiter service inside the recipe: not stock-tracked.
        $serviceId = $this->makeProduct($this->tenant()->table('categories')->value('id'), [
            'name' => 'Service Staff', 'item_kind' => 'ingredient', 'is_stock_tracked' => 0,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $serviceId, 'rate' => 500, 'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $recipeId = $this->tenant()->table('recipes')->value('id');
        $this->tenant()->table('recipe_ingredients')->insert([
            'recipe_id' => $recipeId, 'product_id' => $serviceId, 'quantity' => 1,
            'unit_id' => null, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $release = $this->releasedEvent();
        $issue = $this->issues->issue($release);

        $serviceLine = $issue->lines->firstWhere('product_id', $serviceId);
        $this->assertSame('non_stock', $serviceLine->line_status);
        $this->assertSame('0.000', (string) $serviceLine->issued_qty);
        $this->assertSame(0, (int) $this->tenant()->table('stock_ledgers')->where('product_id', $serviceId)->count(),
            'service materials never fake stock movements');
    }
}
