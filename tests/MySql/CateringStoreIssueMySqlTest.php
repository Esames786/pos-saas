<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialIssue;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringMaterialIssueService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-STORE-1 — the store answers to nobody.
 *
 * The old rule was enforced in the schema, not the code: the release reference
 * was NOT NULL and UNIQUE, so an issue could not exist without an order and an
 * order could never be issued against twice. Neither matches how the kitchen
 * works.
 *
 * What must stay true is everything below the document header — stock still
 * moves through the one approved mutator, at real FEFO cost, with COGS posted.
 * Freeing the paperwork must not loosen the accounting.
 */
class CateringStoreIssueMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private Product $chicken;

    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_material_issue_lines', 'catering_material_issues',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_estimate_lines', 'catering_estimates', 'catering_refunds', 'catering_events',
            'journal_lines', 'journal_entries', 'accounts',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        // COGS posts to real accounts, so the chart has to exist first. Without
        // it the failure reads "account 5200 not found", which looks like a code
        // fault and is actually a missing fixture.
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder)->run();

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->chicken = Product::findOrFail($this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
            'is_pos_visible' => false, 'is_sellable' => false,
        ]));
        $this->rice = Product::findOrFail($this->makeProduct($categoryId, [
            'name' => 'Rice', 'sku' => 'RM-RICE', 'unit_id' => $unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
            'is_pos_visible' => false, 'is_sellable' => false,
        ]));

        // Put real stock in, so FEFO has batches to draw from at a known cost.
        $branch = \App\Models\Tenant\Branch::findOrFail($this->branchId);
        foreach ([[$this->chicken, 100, 200.0], [$this->rice, 100, 120.0]] as [$product, $qty, $cost]) {
            app(InventoryService::class)->postIn(
                $branch, $product, null, $qty, $cost,
                'opening_stock', 'test_seed', 0, 'SEED', 'seed', null
            );
        }
    }

    private function service(): CateringMaterialIssueService
    {
        return app(CateringMaterialIssueService::class);
    }

    /** On-hand quantity lives in stock_balances, not on the batch rows. */
    private function stockOf(Product $product): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')
            ->where('product_id', $product->id)
            ->sum('quantity_on_hand');
    }

    /** The whole point: an issue with no order at all is a complete record. */
    public function test_material_can_be_issued_with_no_booking_reference(): void
    {
        $issue = $this->service()->issueDirect(
            lines: [['product_id' => $this->chicken->id, 'quantity' => 12]],
            branchId: $this->branchId,
        );

        $this->assertNull($issue->catering_event_id, 'no booking was referenced, and that is valid');
        $this->assertNull($issue->catering_production_release_id, 'no production release either');
        $this->assertSame(88.0, $this->stockOf($this->chicken), 'stock still fell by exactly what was handed over');
        $this->assertGreaterThan(0, (float) $issue->total_fefo_cost, 'and it was costed at the real batch price');
    }

    /** One trip to the store can cover several bookings at once. */
    public function test_one_issue_can_cover_several_materials_in_one_trip(): void
    {
        $issue = $this->service()->issueDirect(
            lines: [
                ['product_id' => $this->chicken->id, 'quantity' => 12],
                ['product_id' => $this->rice->id, 'quantity' => 20],
            ],
            branchId: $this->branchId,
            note: 'covers three weddings tomorrow',
        );

        $this->assertCount(2, $issue->lines);
        $this->assertSame(88.0, $this->stockOf($this->chicken));
        $this->assertSame(80.0, $this->stockOf($this->rice));

        // 12 × 200 + 20 × 120 = 4,800
        $this->assertEqualsWithDelta(4800.0, (float) $issue->total_fefo_cost, 0.01);
    }

    /**
     * The constraint that used to make this impossible: one issue per release,
     * ever. A kitchen draws material twice for the same booking all the time.
     */
    public function test_the_same_booking_can_be_issued_against_more_than_once(): void
    {
        $event = app(CateringEstimateService::class)->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Twice Test',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 100,
        ]);

        $first = $this->service()->issueDirect(
            lines: [['product_id' => $this->chicken->id, 'quantity' => 10]],
            branchId: $this->branchId, eventId: $event->id,
        );
        $second = $this->service()->issueDirect(
            lines: [['product_id' => $this->chicken->id, 'quantity' => 5]],
            branchId: $this->branchId, eventId: $event->id,
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, CateringMaterialIssue::where('catering_event_id', $event->id)->count(),
            'a second trip to the store for the same booking must be allowed');
        $this->assertSame(85.0, $this->stockOf($this->chicken));
    }

    /** Quantity is whatever was handed over, not what an order calculated. */
    public function test_the_quantity_issued_is_whatever_the_store_gave(): void
    {
        $issue = $this->service()->issueDirect(
            lines: [['product_id' => $this->chicken->id, 'quantity' => 7.25]],
            branchId: $this->branchId,
        );

        $this->assertEqualsWithDelta(7.25, (float) $issue->lines->first()->issued_qty, 0.001);
        $this->assertEqualsWithDelta(92.75, $this->stockOf($this->chicken), 0.001);
    }

    /** Cost of goods sold still posts — freeing the paperwork must not loosen the books. */
    public function test_issuing_still_posts_cost_of_goods_sold(): void
    {
        $before = DB::connection('tenant')->table('journal_entries')->count();

        $issue = $this->service()->issueDirect(
            lines: [['product_id' => $this->chicken->id, 'quantity' => 10]],
            branchId: $this->branchId,
        );

        $this->assertGreaterThan($before, DB::connection('tenant')->table('journal_entries')->count(),
            'a stock movement without a COGS posting would leave the books wrong');
        $this->assertNotNull($issue->cogs_journal_entry_id);

        $balance = DB::connection('tenant')->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS diff')->value('diff');
        $this->assertEqualsWithDelta(0.0, (float) $balance, 0.01, 'the ledger must still balance');
    }

    /** An empty request is refused rather than writing a document with no lines. */
    public function test_an_issue_with_nothing_in_it_is_refused(): void
    {
        foreach ([[], [['product_id' => $this->chicken->id, 'quantity' => 0]]] as $lines) {
            try {
                $this->service()->issueDirect(lines: $lines, branchId: $this->branchId);
                $this->fail('an issue with no positive quantity must be refused');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Nothing to issue', $e->getMessage());
            }
        }

        $this->assertSame(0, CateringMaterialIssue::count());
        $this->assertSame(100.0, $this->stockOf($this->chicken), 'and nothing moved');
    }

    /**
     * Deleting a booking must never delete the record of material that
     * physically left the store. The reference clears; the movement stays.
     */
    public function test_deleting_the_referenced_booking_keeps_the_stock_record(): void
    {
        $event = app(CateringEstimateService::class)->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Delete Me',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 10,
        ]);

        $issue = $this->service()->issueDirect(
            lines: [['product_id' => $this->chicken->id, 'quantity' => 6]],
            branchId: $this->branchId, eventId: $event->id,
        );

        DB::connection('tenant')->table('catering_estimates')->where('catering_event_id', $event->id)->delete();
        CateringEvent::whereKey($event->id)->delete();

        $surviving = CateringMaterialIssue::find($issue->id);

        $this->assertNotNull($surviving,
            'the stock movement is the fact — deleting the booking must not erase it');
        $this->assertNull($surviving->catering_event_id, 'only the reference clears');
        $this->assertSame(94.0, $this->stockOf($this->chicken), 'and the stock stays where it went');
    }
}
