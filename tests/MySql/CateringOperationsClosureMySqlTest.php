<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLineCostBlockService;
use App\Services\Catering\CateringMaterialIssueService;
use App\Services\Catering\CateringProductionReleaseService;
use App\Services\Catering\CateringStoreRequirementService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\MySql\Support\TenantFixtures;

/**
 * CAT-PROD-002 / CAT-STORE-002 — the last two places where one concept was
 * standing in for another.
 *
 * CAT-PROD-002. The requirement service learned to keep "what the kitchen needs"
 * apart from "what our store issues", but the production release screen and the
 * kitchen sheet both printed only the second. A material the customer brings has
 * an our-store figure of zero, so it read as 0 and effectively vanished from the
 * sheet — the kitchen would arrive to cook a biryani and be told it needed no
 * rice. The dish needs eight kilos of rice whoever carries it through the door.
 *
 * CAT-STORE-002. A store issue may reference several bookings, and the
 * established contract is explicit that those are REASONS, not allocations: one
 * 12 KG handover covering weddings A and B does not record that A took 7 and B
 * took 5, and nothing in the system knows which. Reconciling [A, B] together is
 * honest. Crediting A alone with all 12 is not — it would tell the storeman A was
 * finished while a real top-up was still owed. Splitting it 6/6 would be the same
 * invention with better manners.
 */
class CateringOperationsClosureMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private CateringStoreRequirementService $store;

    private CateringMaterialIssueService $issues;

    private int $branchId;

    private int $biryaniId;

    private int $chickenId;

    private int $riceId;

    private int $kgUnitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();
        View::share('errors', new \Illuminate\Support\ViewErrorBag);

        $this->cleanTenant([
            'catering_material_issue_events', 'catering_material_issue_lines', 'catering_material_issues',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'recipe_ingredients', 'recipes',
            'journal_lines', 'journal_entries', 'accounts',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'units', 'products', 'categories', 'branches',
        ]);

        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);
        $this->store = app(CateringStoreRequirementService::class);
        $this->issues = app(CateringMaterialIssueService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();

        $this->kgUnitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->kgUnitId]);
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

        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryaniId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );
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

        foreach ([$this->chickenId, $this->riceId] as $id) {
            app(InventoryService::class)->postIn(
                \App\Models\Tenant\Branch::findOrFail($this->branchId),
                \App\Models\Tenant\Product::findOrFail($id),
                null, 500, 80, 'opening_stock', 'test_seed', $id, 'SEED'
            );
        }
    }

    private function booking(string $customer, float $qty = 20): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => $customer,
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 150,
        ]);

        return $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => $qty, 'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);
    }

    private function snapshot(CateringEstimate $e, string $label): CateringEstimateLineCostBlock
    {
        return $this->lineBlocks->snapshotsFor($e->refresh()->lines->first())->firstWhere('label', $label);
    }

    private function release(CateringEstimate $estimate)
    {
        $event = $estimate->event;
        $this->estimates->markSent($estimate->refresh());
        $this->estimates->markAccepted($estimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        return app(CateringProductionReleaseService::class)->release($event->refresh());
    }

    /** @return array<string, array> */
    private function releaseRequirements($release): array
    {
        return collect($release->requirements_snapshot['requirements'] ?? [])->keyBy('name')->all();
    }

    /** @return array<string, array> */
    private function rowsFor(array $eventIds): array
    {
        return collect($this->store->forEvents($eventIds, $this->branchId)['rows'])->keyBy('name')->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CAT-PROD-002 · the kitchen keeps its number
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_release_keeps_both_the_kitchen_and_the_store_figure(): void
    {
        $estimate = $this->booking('Supplied release');
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Rice'), true);

        $req = $this->releaseRequirements($this->release($estimate));

        $this->assertEqualsWithDelta(8.0, $req['Rice']['physical_qty'], 0.001,
            'the kitchen still needs eight kilos of rice');
        $this->assertEqualsWithDelta(0.0, $req['Rice']['required_qty'], 0.001,
            'and our store issues none of it');
        $this->assertEqualsWithDelta(8.0, $req['Rice']['customer_supplied_qty'], 0.001);
        $this->assertEqualsWithDelta(10.0, $req['Chicken']['physical_qty'], 0.001);
        $this->assertEqualsWithDelta(10.0, $req['Chicken']['required_qty'], 0.001);
    }

    public function test_the_kitchen_sheet_still_asks_for_customer_supplied_material(): void
    {
        $estimate = $this->booking('Supplied sheet');
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Rice'), true);
        $release = $this->release($estimate);

        $html = View::make('tenant.catering.documents.kitchen-sheet', [
            'release' => $release->fresh(['lines', 'event']),
            'event' => $release->event,
            'lang' => 'en',
            'businessName' => 'Test Caterer',
        ])->render();

        $this->assertStringContainsString('Rice', $html,
            'a material the customer brings must not disappear from the kitchen sheet');
        $this->assertStringContainsString('Kitchen Needs', $html);
        $this->assertStringContainsString('From Our Store', $html);
        $this->assertStringContainsString('Customer supplied', $html,
            'and the sheet must say who is bringing it, or the kitchen will chase our store for it');
    }

    public function test_the_release_screen_shows_both_figures(): void
    {
        $estimate = $this->booking('Supplied screen');
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Rice'), true);
        $release = $this->release($estimate);

        $html = View::make('tenant.catering.releases.show', [
            'release' => $release->fresh(['lines', 'event']),
            'requirements' => $release->requirements_snapshot['requirements'] ?? [],
            'issue' => null,
            'issuedByProduct' => collect(),
        ])->render();

        $this->assertStringContainsString('Kitchen Needs', $html);
        $this->assertStringContainsString('From Our Store', $html);
        $this->assertStringContainsString('customer', strtolower($html));
    }

    public function test_releasing_issues_no_customer_supplied_stock(): void
    {
        $estimate = $this->booking('Supplied issue');
        $this->lineBlocks->setCustomerSupplied($this->snapshot($estimate, 'Rice'), true);
        $release = $this->release($estimate);

        $this->issues->issue($release->fresh());

        $riceMoves = DB::connection('tenant')->table('stock_ledgers')
            ->where('product_id', $this->riceId)->where('movement_type', '!=', 'opening_stock')->count();
        $chickenMoves = DB::connection('tenant')->table('stock_ledgers')
            ->where('product_id', $this->chickenId)->where('movement_type', '!=', 'opening_stock')->count();

        $this->assertSame(0, $riceMoves, 'we agreed not to supply the rice, so none of ours may leave');
        $this->assertGreaterThan(0, $chickenMoves, 'but the chicken is still ours to issue');
    }

    public function test_an_event_override_survives_all_the_way_to_the_release(): void
    {
        $estimate = $this->booking('Override release');
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($estimate, 'Chicken'), 12);

        $req = $this->releaseRequirements($this->release($estimate));

        $this->assertEqualsWithDelta(12.0, $req['Chicken']['physical_qty'], 0.001,
            'the operator said twelve, and twelve is what reaches the kitchen');
        $this->assertEqualsWithDelta(12.0, $req['Chicken']['required_qty'], 0.001);
    }

    public function test_editing_the_dish_afterwards_does_not_rewrite_a_frozen_release(): void
    {
        $estimate = $this->booking('Frozen release');
        $release = $this->release($estimate);

        CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Chicken')->update(['quantity_per_unit' => 5.0]);

        $req = $this->releaseRequirements($release->fresh());

        $this->assertEqualsWithDelta(10.0, $req['Chicken']['required_qty'], 0.001,
            'a release is a frozen document — the dish changing later cannot reach into it');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CAT-STORE-002 · a reference is not an allocation
    // ─────────────────────────────────────────────────────────────────────────

    private function shared12(): array
    {
        $a = $this->booking('Wedding A');
        $b = $this->booking('Wedding B');
        $ids = [$a->catering_event_id, $b->catering_event_id];

        $this->issues->issueDirect(
            lines: [['product_id' => $this->chickenId, 'quantity' => 12]],
            branchId: $this->branchId, eventIds: $ids,
        );

        return $ids;
    }

    public function test_the_whole_referenced_set_reconciles_exactly(): void
    {
        [$a, $b] = $this->shared12();

        $row = $this->rowsFor([$a, $b])['Chicken'];

        $this->assertEqualsWithDelta(20.0, $row['required_qty'], 0.001);
        $this->assertEqualsWithDelta(12.0, $row['issued_qty'], 0.001, 'counted once, not once per booking');
        $this->assertEqualsWithDelta(8.0, $row['remaining_qty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['shared_unallocated_qty'], 0.001);
        $this->assertTrue($row['remaining_is_certain']);
    }

    public function test_one_booking_alone_is_never_credited_with_a_shared_issue(): void
    {
        [$a] = $this->shared12();

        $row = $this->rowsFor([$a])['Chicken'];

        $this->assertEqualsWithDelta(0.0, $row['issued_qty'], 0.001,
            'we do not know how the twelve was split, so none of it is A\'s for certain');
        $this->assertEqualsWithDelta(12.0, $row['shared_unallocated_qty'], 0.001,
            'but it is reported, by name — not hidden and not guessed at');
        $this->assertFalse($row['remaining_is_certain']);
    }

    public function test_the_other_booking_alone_is_treated_the_same_way(): void
    {
        [, $b] = $this->shared12();

        $row = $this->rowsFor([$b])['Chicken'];

        $this->assertEqualsWithDelta(0.0, $row['issued_qty'], 0.001);
        $this->assertEqualsWithDelta(12.0, $row['shared_unallocated_qty'], 0.001);
    }

    /** A superset still contains every reference, so it is attributable. */
    public function test_a_wider_selection_that_contains_the_whole_set_counts_it(): void
    {
        [$a, $b] = $this->shared12();
        $c = $this->booking('Wedding C');

        $row = $this->rowsFor([$a, $b, $c->catering_event_id])['Chicken'];

        $this->assertEqualsWithDelta(12.0, $row['issued_qty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['shared_unallocated_qty'], 0.001);
        $this->assertEqualsWithDelta(18.0, $row['remaining_qty'], 0.001, '30 required less 12 issued');
    }

    public function test_a_single_booking_issue_reconciles_normally(): void
    {
        $a = $this->booking('Solo');
        $ids = [$a->catering_event_id];

        $this->issues->issueDirect(
            lines: [['product_id' => $this->chickenId, 'quantity' => 6]],
            branchId: $this->branchId, eventIds: $ids,
        );

        $row = $this->rowsFor($ids)['Chicken'];
        $this->assertEqualsWithDelta(6.0, $row['issued_qty'], 0.001);
        $this->assertEqualsWithDelta(4.0, $row['remaining_qty'], 0.001);
        $this->assertTrue($row['remaining_is_certain']);
    }

    /** Definite and ambiguous quantities stay distinguishable side by side. */
    public function test_a_shared_issue_and_a_single_issue_are_reported_apart(): void
    {
        [$a, $b] = $this->shared12();

        $this->issues->issueDirect(
            lines: [['product_id' => $this->chickenId, 'quantity' => 3]],
            branchId: $this->branchId, eventIds: [$a],
        );

        $row = $this->rowsFor([$a])['Chicken'];

        $this->assertEqualsWithDelta(3.0, $row['issued_qty'], 0.001, 'this three is definitely A\'s');
        $this->assertEqualsWithDelta(12.0, $row['shared_unallocated_qty'], 0.001, 'the twelve is not');
        $this->assertFalse($row['remaining_is_certain']);
    }

    /**
     * The ambiguity must not turn into a stock refusal. Blocking a legitimate
     * top-up because of a quantity we chose to guess at would be the invented
     * allocation doing its damage through the back door.
     */
    public function test_an_unattributable_prior_issue_never_blocks_a_legitimate_top_up(): void
    {
        [$a] = $this->shared12();

        $this->issues->issueDirect(
            lines: [['product_id' => $this->chickenId, 'quantity' => 10]],
            branchId: $this->branchId, eventIds: [$a],
        );

        $this->assertSame(2, DB::connection('tenant')->table('catering_material_issues')->count(),
            'A really might still need its full ten — the system cannot prove otherwise');
    }

    /** Where the remainder IS certain, the duplicate guard still bites. */
    public function test_the_duplicate_guard_still_works_when_attribution_is_certain(): void
    {
        $a = $this->booking('Certain');
        $ids = [$a->catering_event_id];

        $this->issues->issueDirect(
            lines: [['product_id' => $this->chickenId, 'quantity' => 10]],
            branchId: $this->branchId, eventIds: $ids,
        );

        $this->expectExceptionMessageMatches('/still need/');
        $this->issues->issueDirect(
            lines: [['product_id' => $this->chickenId, 'quantity' => 10]],
            branchId: $this->branchId, eventIds: $ids,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The store screen shows all of it.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_store_screen_shows_the_reconciliation_itself(): void
    {
        $html = View::make('tenant.catering.store-issues.index', [
            'issues' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25),
            'hasMaterials' => true,
            'branches' => \App\Models\Tenant\Branch::all(['id', 'name']),
            'events' => collect(),
        ])->render();

        foreach ([
            'What these bookings still need',
            'Kitchen needs', 'Customer supplied', 'From our store',
            'Already issued', 'Remaining',
            'never how much each one took',
            'more than these bookings still need',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html,
                "the storeman must see [{$expected}] on the page, not in a JSON response");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Planning is still planning.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reconciling_moves_no_stock_and_posts_no_gl(): void
    {
        [$a, $b] = $this->shared12();

        $stock = DB::connection('tenant')->table('stock_ledgers')->count();
        $gl = DB::connection('tenant')->table('journal_entries')->count();

        $this->store->forEvents([$a], $this->branchId);
        $this->store->forEvents([$a, $b], $this->branchId);
        $this->store->forDate(now()->addDays(3)->toDateString(), $this->branchId);

        $this->assertSame($stock, DB::connection('tenant')->table('stock_ledgers')->count());
        $this->assertSame($gl, DB::connection('tenant')->table('journal_entries')->count());
    }
}
