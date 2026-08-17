<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialIssue;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringMaterialIssueService;
use App\Services\Inventory\InventoryService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-STORE-2 — one trip to the store, many bookings.
 *
 * The kitchen man comes once and takes eighty kilos of chicken covering twelve
 * weddings. Before this, the issue could name one booking, so the storeman
 * either picked one and left eleven unrecorded, or split one real handover into
 * twelve fictional ones.
 *
 * The single invariant worth all of these assertions: the number of bookings
 * attached has NO effect on what left the store. Twelve references are twelve
 * reasons, not twelve movements — one document, one set of FEFO stock-outs, one
 * COGS posting, and the same quantity as if nobody had written a reference at
 * all.
 *
 * Also protected: these are references, never allocations. The record says
 * eighty kilos went out for twelve bookings. It must never be made to say each
 * of them took 6.67.
 */
class CateringStoreIssueBookingsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringMaterialIssueService $issues;

    private CateringEstimateService $estimates;

    private int $branchId;

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
            'catering_estimate_lines', 'catering_estimates', 'catering_refunds', 'catering_advances',
            'catering_events', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'accounts', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->issues = app(CateringMaterialIssueService::class);
        $this->estimates = app(CateringEstimateService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->kgUnitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Raw Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        $this->riceId = $this->makeProduct($categoryId, [
            'name' => 'Basmati Rice', 'sku' => 'RM-RICE', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        $this->stockUp($this->chickenId, 500, 320);
        $this->stockUp($this->riceId, 500, 120);
    }

    private function stockUp(int $productId, float $qty, float $cost): void
    {
        app(InventoryService::class)->postIn(
            \App\Models\Tenant\Branch::findOrFail($this->branchId),
            \App\Models\Tenant\Product::findOrFail($productId),
            null, $qty, $cost, 'opening_stock', 'test_seed', $productId, 'SEED', 'seed', null
        );
    }

    private function booking(string $customer, ?string $date = null): CateringEvent
    {
        return $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => $customer,
            'booking_date' => now()->toDateString(),
            'event_date' => $date ?? now()->addDays(2)->toDateString(),
            'pax' => 200,
        ]);
    }

    /** @param array<int,int> $eventIds */
    private function issue(array $eventIds, float $chicken = 80, float $rice = 50): CateringMaterialIssue
    {
        return $this->issues->issueDirect(
            lines: [
                ['product_id' => $this->chickenId, 'quantity' => $chicken],
                ['product_id' => $this->riceId, 'quantity' => $rice],
            ],
            branchId: $this->branchId,
            eventIds: $eventIds,
            releaseId: null,
            userId: null,
            note: 'morning collection',
        );
    }

    private function stockOnHand(int $productId): float
    {
        return round((float) DB::connection('tenant')->table('stock_balances')
            ->where('product_id', $productId)->where('branch_id', $this->branchId)
            ->sum('quantity_on_hand'), 3);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The three shapes a store issue takes.
    // ─────────────────────────────────────────────────────────────────────────

    /** Daily prep and staff food leave the store too, and reference nothing. */
    public function test_a_general_issue_with_no_booking_is_a_complete_record(): void
    {
        $issue = $this->issue([]);

        $this->assertCount(0, $issue->events);
        $this->assertNull($issue->catering_event_id);
        $this->assertSame(420.0, $this->stockOnHand($this->chickenId), '500 less the 80 handed over');
        $this->assertNotNull($issue->cogs_journal_entry_id, 'and it still costs what it cost');
    }

    public function test_an_issue_against_one_booking_records_that_booking(): void
    {
        $event = $this->booking('Mr Ali');

        $issue = $this->issue([$event->id]);

        $this->assertCount(1, $issue->fresh()->events);
        $this->assertSame($event->id, $issue->fresh()->events->first()->id);
    }

    /** The case this whole tranche exists for. */
    public function test_an_issue_against_twelve_bookings_records_all_twelve(): void
    {
        $eventIds = collect(range(1, 12))
            ->map(fn ($n) => $this->booking("Customer {$n}")->id)
            ->all();

        $issue = $this->issue($eventIds);

        $this->assertCount(12, $issue->fresh()->events);
        $this->assertSame(
            collect($eventIds)->sort()->values()->all(),
            $issue->fresh()->events->pluck('id')->sort()->values()->all(),
            'every booking the trip covered, not the first one someone happened to pick'
        );
        $this->assertSame(12, (int) DB::connection('tenant')->table('catering_material_issue_events')
            ->where('catering_material_issue_id', $issue->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // References do not multiply stock. This is the invariant.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Twelve bookings must take exactly as much out of the store as none would.
     * Anything else means the reference field is quietly issuing stock.
     */
    public function test_twelve_bookings_move_the_same_stock_as_none(): void
    {
        $eventIds = collect(range(1, 12))->map(fn ($n) => $this->booking("Customer {$n}")->id)->all();

        $issue = $this->issue($eventIds);

        $this->assertSame(420.0, $this->stockOnHand($this->chickenId), '80 out, once');
        $this->assertSame(450.0, $this->stockOnHand($this->riceId), '50 out, once');

        $ledgers = DB::connection('tenant')->table('stock_ledgers')
            ->where('reference_type', 'catering_material_issue')
            ->where('reference_id', $issue->id)
            ->get();

        $this->assertCount(2, $ledgers, 'two materials, two movements — not twenty-four');

        $chicken = $ledgers->firstWhere('product_id', $this->chickenId);
        $this->assertSame('out', $chicken->direction);
        $this->assertSame(80.0, round((float) $chicken->quantity, 3), 'the quantity handed over, once');
    }

    /** One document and one COGS posting, however many reasons are attached. */
    public function test_twelve_bookings_produce_one_document_and_one_posting(): void
    {
        $eventIds = collect(range(1, 12))->map(fn ($n) => $this->booking("Customer {$n}")->id)->all();

        $this->issue($eventIds);

        $this->assertSame(1, CateringMaterialIssue::count());
        $this->assertSame(1, (int) DB::connection('tenant')->table('journal_entries')
            ->where('source_type', 'catering_material_issue')->count(), 'twelve reasons, one cost of sale');
    }

    /**
     * The record must not be readable as an allocation. Nothing anywhere claims
     * how much of the eighty kilos each booking took, because nobody measured it.
     */
    public function test_the_record_never_claims_how_much_each_booking_took(): void
    {
        $eventIds = collect(range(1, 12))->map(fn ($n) => $this->booking("Customer {$n}")->id)->all();

        $issue = $this->issue($eventIds);

        $pivotColumns = array_keys((array) DB::connection('tenant')
            ->table('catering_material_issue_events')
            ->where('catering_material_issue_id', $issue->id)->first());

        foreach (['quantity', 'qty', 'allocated', 'share', 'amount'] as $forbidden) {
            $this->assertNotContains($forbidden, $pivotColumns,
                'a reference says why stock left, never how much each reason took');
        }

        // The issued quantity is whole and undivided on the line.
        $line = $issue->lines->firstWhere('product_id', $this->chickenId);
        $this->assertSame(80.0, round((float) $line->issued_qty, 3));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Duplicates and legacy.
    // ─────────────────────────────────────────────────────────────────────────

    /** The same booking named twice is still one reason. */
    public function test_the_same_booking_selected_twice_is_recorded_once(): void
    {
        $event = $this->booking('Mr Ali');

        $issue = $this->issue([$event->id, $event->id, $event->id]);

        $this->assertSame(1, (int) DB::connection('tenant')->table('catering_material_issue_events')
            ->where('catering_material_issue_id', $issue->id)->count());
    }

    /**
     * The legacy column keeps pointing at a real booking. Anything still reading
     * it, and a rollback of the pivot migration, sees one of the reasons rather
     * than nothing at all.
     */
    public function test_the_legacy_column_mirrors_the_first_booking(): void
    {
        $first = $this->booking('First Customer');
        $second = $this->booking('Second Customer');

        $issue = $this->issue([$first->id, $second->id]);

        $this->assertSame($first->id, (int) $issue->catering_event_id);
        $this->assertCount(2, $issue->fresh()->events, 'while the relation still knows about both');
    }

    /** A general issue leaves the legacy column empty, as it always did. */
    public function test_a_general_issue_leaves_the_legacy_column_null(): void
    {
        $this->assertNull($this->issue([])->catering_event_id);
    }

    /**
     * Deleting one of the bookings clears that reference and leaves everything
     * else standing — the same rule the single-booking column already followed.
     * The movement is the fact; a booking was only ever the explanation.
     */
    public function test_deleting_one_booking_clears_only_its_reference(): void
    {
        $keep = $this->booking('Staying Customer');
        $drop = $this->booking('Going Customer');

        $issue = $this->issue([$keep->id, $drop->id]);

        DB::connection('tenant')->table('catering_estimates')->where('catering_event_id', $drop->id)->delete();
        CateringEvent::whereKey($drop->id)->delete();

        $surviving = CateringMaterialIssue::find($issue->id);

        $this->assertNotNull($surviving, 'the stock record outlives any booking that explained it');
        $this->assertSame([$keep->id], $surviving->events->pluck('id')->all(),
            'the other reason is still on the record');
        $this->assertSame(420.0, $this->stockOnHand($this->chickenId), 'and the stock stays where it went');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The document stays immutable.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Bookings are attached when the stock moves and are part of the posted
     * record. An "edit the bookings" path on a document that already reduced
     * stock would be an invitation to rewrite why material left.
     */
    public function test_a_posted_issue_cannot_be_edited(): void
    {
        $issue = $this->issue([$this->booking('Mr Ali')->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/immutable stock document/');

        $issue->update(['note' => 'changed my mind', 'branch_id' => $this->makeBranch()]);
    }
}
