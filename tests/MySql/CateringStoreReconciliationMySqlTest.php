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
use App\Services\Catering\CateringStoreRequirementService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CAT-STORE-001 — what the store still owes, and what it must refuse to hand out
 * twice.
 *
 * A storeman on a Saturday is covering several weddings at once. Before this the
 * only requirement figure in the system was per production release, derived from
 * recipes, and it could not be compared with what had actually left the door. So
 * the same ten kilos of chicken could go out twice, an hour apart, to two people
 * holding the same sheet, and nothing on any screen would look wrong afterwards.
 *
 * Four numbers, kept apart on purpose:
 *
 *      PHYSICAL   what the kitchen needs
 *      SUPPLIED   the part the customer is bringing
 *      OURS       what we hand over
 *      ISSUED     what we already handed over
 *      REMAINING  what is left, never below zero
 */
class CateringStoreReconciliationMySqlTest extends MySqlTenantTestCase
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

        $this->buildBiryani();
        $this->openingStock();
    }

    private function buildBiryani(): void
    {
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
    }

    private function openingStock(): void
    {
        foreach ([$this->chickenId, $this->riceId] as $id) {
            app(InventoryService::class)->postIn(
                \App\Models\Tenant\Branch::findOrFail($this->branchId),
                \App\Models\Tenant\Product::findOrFail($id),
                null, 500, 80, 'opening_stock', 'test_seed', $id, 'SEED'
            );
        }
    }

    private function booking(string $customer, float $qty = 20, ?string $date = null): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => $customer,
            'booking_date' => now()->toDateString(),
            'event_date' => $date ?? now()->addDays(3)->toDateString(),
            'pax' => 150,
        ]);

        return $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => $qty, 'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);
    }

    private function snapshot(CateringEstimate $estimate, string $label): CateringEstimateLineCostBlock
    {
        return $this->lineBlocks->snapshotsFor($estimate->refresh()->lines->first())->firstWhere('label', $label);
    }

    /** @return array<string, array> */
    private function rowsFor(array $eventIds): array
    {
        return collect($this->store->forEvents($eventIds, $this->branchId)['rows'])->keyBy('name')->all();
    }

    private function issue(array $eventIds, float $chicken, bool $over = false): void
    {
        $this->issues->issueDirect(
            lines: [['product_id' => $this->chickenId, 'quantity' => $chicken]],
            branchId: $this->branchId,
            eventIds: $eventIds,
            releaseId: null,
            userId: null,
            note: null,
            allowOverIssue: $over,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F · many bookings, one material
    // ─────────────────────────────────────────────────────────────────────────

    public function test_two_bookings_on_one_day_aggregate_into_one_material_line(): void
    {
        $a = $this->booking('Wedding A', 20);
        $b = $this->booking('Wedding B', 30);

        $rows = $this->rowsFor([$a->catering_event_id, $b->catering_event_id]);

        $this->assertCount(2, $rows, 'one row per material, not one per booking');
        $this->assertEqualsWithDelta(25.0, $rows['Chicken']['required_qty'], 0.001, '0.5 x 20 + 0.5 x 30');
        $this->assertEqualsWithDelta(20.0, $rows['Rice']['required_qty'], 0.001, '0.4 x 50');
        $this->assertCount(2, $rows['Chicken']['by_event'], 'and it can still be broken down per booking');
    }

    public function test_a_business_date_reconciles_every_booking_on_it(): void
    {
        $date = now()->addDays(5)->toDateString();
        $this->booking('Date A', 20, $date);
        $this->booking('Date B', 10, $date);
        $this->booking('Other day', 100, now()->addDays(9)->toDateString());

        $rows = collect($this->store->forDate($date, $this->branchId)['rows'])->keyBy('name');

        $this->assertEqualsWithDelta(15.0, $rows['Chicken']['required_qty'], 0.001,
            'only that day — the 100 KG booking next week is somebody else\'s morning');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C · customer supplied never becomes our problem
    // ─────────────────────────────────────────────────────────────────────────

    public function test_customer_supplied_is_shown_but_never_asked_of_our_store(): void
    {
        $a = $this->booking('Supplied', 20);
        $this->lineBlocks->setCustomerSupplied($this->snapshot($a, 'Rice'), true);

        $rows = $this->rowsFor([$a->catering_event_id]);

        $this->assertEqualsWithDelta(8.0, $rows['Rice']['physical_qty'], 0.001, 'the kitchen still needs it');
        $this->assertEqualsWithDelta(8.0, $rows['Rice']['customer_supplied_qty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $rows['Rice']['required_qty'], 0.001, 'we hand over none of it');
        $this->assertEqualsWithDelta(0.0, $rows['Rice']['remaining_qty'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // G / H · partial, then complete
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_partial_issue_leaves_the_right_amount_remaining(): void
    {
        $a = $this->booking('Partial', 20);
        $events = [$a->catering_event_id];

        $this->assertEqualsWithDelta(10.0, $this->rowsFor($events)['Chicken']['remaining_qty'], 0.001);

        $this->issue($events, 6);

        $row = $this->rowsFor($events)['Chicken'];
        $this->assertEqualsWithDelta(10.0, $row['required_qty'], 0.001);
        $this->assertEqualsWithDelta(6.0, $row['issued_qty'], 0.001);
        $this->assertEqualsWithDelta(4.0, $row['remaining_qty'], 0.001);
    }

    public function test_a_fully_issued_material_has_nothing_remaining(): void
    {
        $a = $this->booking('Full', 20);
        $events = [$a->catering_event_id];

        $this->issue($events, 10);

        $row = $this->rowsFor($events)['Chicken'];
        $this->assertEqualsWithDelta(10.0, $row['issued_qty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['remaining_qty'], 0.001);
    }

    /** One trip covering several weddings counts against all of them. */
    public function test_an_issue_covering_several_bookings_is_credited_to_all_of_them(): void
    {
        $a = $this->booking('Multi A', 20);
        $b = $this->booking('Multi B', 20);
        $events = [$a->catering_event_id, $b->catering_event_id];

        $this->issue($events, 12);

        $this->assertEqualsWithDelta(20.0, $this->rowsFor($events)['Chicken']['required_qty'], 0.001);
        $this->assertEqualsWithDelta(12.0, $this->rowsFor($events)['Chicken']['issued_qty'], 0.001);
        $this->assertEqualsWithDelta(8.0, $this->rowsFor($events)['Chicken']['remaining_qty'], 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // I / J · the duplicate and the top-up
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_same_sheet_issued_twice_is_refused(): void
    {
        $a = $this->booking('Duplicate', 20);
        $events = [$a->catering_event_id];

        $this->issue($events, 10);

        try {
            $this->issue($events, 10);
            $this->fail('a second full issue must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still need', $e->getMessage());
        }

        $this->assertEqualsWithDelta(10.0, $this->rowsFor($events)['Chicken']['issued_qty'], 0.001,
            'and the refusal writes nothing');
        $this->assertSame(1, DB::connection('tenant')->table('catering_material_issues')->count());
    }

    public function test_a_top_up_for_what_is_left_is_allowed(): void
    {
        $a = $this->booking('Top up', 20);
        $events = [$a->catering_event_id];

        $this->issue($events, 6);
        $this->issue($events, 4);

        $row = $this->rowsFor($events)['Chicken'];
        $this->assertEqualsWithDelta(10.0, $row['issued_qty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['remaining_qty'], 0.001);
        $this->assertSame(2, DB::connection('tenant')->table('catering_material_issues')->count());
    }

    /** Over-issue stays possible — but only as a deliberate act. */
    public function test_an_over_issue_is_possible_only_when_it_is_deliberate(): void
    {
        $a = $this->booking('Over', 20);
        $events = [$a->catering_event_id];

        $this->issue($events, 10);
        $this->issue($events, 3, over: true);

        $row = $this->rowsFor($events)['Chicken'];
        $this->assertEqualsWithDelta(13.0, $row['issued_qty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['remaining_qty'], 0.001, 'never negative');
        $this->assertEqualsWithDelta(3.0, $row['over_issued_qty'], 0.001, 'and the excess is visible');
    }

    /** Daily prep leaves the store against no booking, and always could. */
    public function test_an_issue_with_no_booking_is_never_bounded(): void
    {
        $this->booking('Unrelated', 20);

        $this->issue([], 40);

        $this->assertSame(1, DB::connection('tenant')->table('catering_material_issues')->count());
    }

    /**
     * A material the customer is bringing IS bounded — at zero. Handing it over
     * anyway is a real exception worth one deliberate tick and a reason.
     */
    public function test_issuing_a_customer_supplied_material_needs_a_deliberate_reason(): void
    {
        $a = $this->booking('Supplied bound', 20);
        $this->lineBlocks->setCustomerSupplied($this->snapshot($a, 'Rice'), true);
        $events = [$a->catering_event_id];

        try {
            $this->issues->issueDirect(
                lines: [['product_id' => $this->riceId, 'quantity' => 8]],
                branchId: $this->branchId, eventIds: $events,
            );
            $this->fail('we agreed the customer was bringing this');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still need 0 of Rice', $e->getMessage());
        }

        // The customer's delivery failed and the kitchen still has to cook.
        $this->issues->issueDirect(
            lines: [['product_id' => $this->riceId, 'quantity' => 8]],
            branchId: $this->branchId, eventIds: $events,
            releaseId: null, userId: null, note: 'customer rice never arrived',
            allowOverIssue: true,
        );

        $this->assertEqualsWithDelta(8.0, $this->rowsFor($events)['Rice']['issued_qty'], 0.001);
    }

    /**
     * But a material that shows up only BECAUSE something was issued has no
     * requirement to bound it — an earlier handover must not become a ceiling.
     */
    public function test_a_prior_issue_alone_never_becomes_a_ceiling(): void
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'No lines yet',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(4)->toDateString(),
            'pax' => 50,
        ]);

        $this->issue([$event->id], 10);
        $this->issue([$event->id], 5);

        $this->assertEqualsWithDelta(15.0, $this->rowsFor([$event->id])['Chicken']['issued_qty'], 0.001,
            'a booking with nothing quoted bounds nothing');
    }

    /** A material nobody quoted is not refused either — it is a real handover. */
    public function test_a_material_no_booking_requires_is_not_bounded(): void
    {
        $a = $this->booking('Unquoted', 20);
        $salt = $this->makeProduct($this->makeCategory(), [
            'name' => 'Salt', 'sku' => 'RM-SALT', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        app(InventoryService::class)->postIn(
            \App\Models\Tenant\Branch::findOrFail($this->branchId),
            \App\Models\Tenant\Product::findOrFail($salt),
            null, 50, 10, 'opening_stock', 'test_seed', $salt, 'SEED'
        );

        $this->issues->issueDirect(
            lines: [['product_id' => $salt, 'quantity' => 5]],
            branchId: $this->branchId,
            eventIds: [$a->catering_event_id],
        );

        $this->assertSame(1, DB::connection('tenant')->table('catering_material_issues')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // K · work nobody is doing
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_cancelled_booking_requires_nothing_of_the_store(): void
    {
        $a = $this->booking('Cancelled', 20);
        $b = $this->booking('Alive', 20);
        $events = [$a->catering_event_id, $b->catering_event_id];

        $this->estimates->cancelEvent($a->event, 'Customer cancelled');

        $rows = $this->rowsFor($events);

        $this->assertEqualsWithDelta(10.0, $rows['Chicken']['required_qty'], 0.001,
            'only the live booking is work the store owes');

        $summary = collect($this->store->forEvents($events, $this->branchId)['events'])
            ->firstWhere('id', $a->catering_event_id);
        $this->assertFalse($summary['counts_towards_requirement'],
            'and the cancellation is stated, not silently dropped');
    }

    /** But stock that really left stays visible against it. */
    public function test_material_already_issued_to_a_cancelled_booking_is_still_shown(): void
    {
        $a = $this->booking('Cancel after issue', 20);
        $events = [$a->catering_event_id];

        $this->issue($events, 6);
        $this->estimates->cancelEvent($a->event, 'Cancelled late');

        $rows = $this->rowsFor($events);

        $this->assertEqualsWithDelta(0.0, $rows['Chicken']['required_qty'], 0.001);
        $this->assertEqualsWithDelta(6.0, $rows['Chicken']['issued_qty'], 0.001,
            'six kilos really did leave the store, whatever happened to the booking afterwards');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // One writer of stock, and it is not this.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reconciling_moves_no_stock(): void
    {
        $a = $this->booking('Read only', 20);
        $before = DB::connection('tenant')->table('stock_ledgers')->count();

        $this->store->forEvents([$a->catering_event_id], $this->branchId);
        $this->store->forDate(now()->addDays(3)->toDateString(), $this->branchId);

        $this->assertSame($before, DB::connection('tenant')->table('stock_ledgers')->count(),
            'looking at what is owed has never moved stock and still does not');
    }

    public function test_an_issue_still_moves_stock_exactly_once_per_line(): void
    {
        $a = $this->booking('One movement', 20);
        $before = DB::connection('tenant')->table('stock_ledgers')->count();

        $this->issue([$a->catering_event_id], 10);

        $this->assertSame($before + 1, DB::connection('tenant')->table('stock_ledgers')->count(),
            'one real FEFO movement — the reconciliation added no second writer');
    }
}
