<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringProductionRelease;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringProductionPrintService;
use App\Services\Catering\CateringProductionReleaseService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-V1-CLOSURE-1 (§6/§7): production tickets ride the existing
 * PrintJob transport as their own document type. Proofs: mapped-printer job
 * creation, idempotent retry, controlled reprint copies, independent station
 * routing, byte-identical POS mappings, price-free payloads, immutable
 * release snapshot, and zero POS/KOT side effects.
 */
class CateringProductionPrintMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringProductionPrintService $printing;

    private int $branchId;

    private int $riceStationPrinterId;

    private int $curryStationPrinterId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders', 'catering_material_issue_lines', 'catering_material_issues', 'catering_production_release_lines',
            'catering_production_releases', 'catering_final_invoices', 'catering_advances', 'catering_cost_snapshots',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_material_rates', 'catering_printer_mappings', 'catering_product_profiles', 'catering_settings',
            'category_printer_mappings', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'printers',
            'stock_ledgers', 'stock_balances', 'journal_lines', 'journal_entries',
            'sales_order_lines', 'sales_orders', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->printing = app(CateringProductionPrintService::class);
        $this->branchId = $this->makeBranch();
        $this->riceStationPrinterId = $this->makePrinter(['name' => 'Rice Station']);
        $this->curryStationPrinterId = $this->makePrinter(['name' => 'Curry Station']);
    }

    /** Confirmed event with a released production document across two stations. */
    private function releasedTwoStationEvent(): CateringProductionRelease
    {
        $categoryId = $this->makeCategory();
        $biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani']);
        $qormaId = $this->makeProduct($categoryId, ['name' => 'Chicken Qorma']);

        foreach ([
            [$biryaniId, 'Rice', 'Deg Biryani'],
            [$qormaId, 'Curry', 'Deg Qorma'],
        ] as [$productId, $station, $label]) {
            $this->tenant()->table('catering_product_profiles')->insert([
                'product_id' => $productId, 'catering_enabled' => 1, 'pricing_mode' => 'fixed',
                'production_station' => $station, 'production_label' => $label,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->tenant()->table('catering_material_rates')->insert([
                'product_id' => $productId, 'rate' => 300, 'effective_from' => now()->subDay()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Station-scoped catering routing: Rice → printer A, Curry → printer B.
        $this->tenant()->table('catering_printer_mappings')->insert([
            ['branch_id' => $this->branchId, 'category_id' => null, 'production_station' => 'Rice', 'printer_id' => $this->riceStationPrinterId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $this->branchId, 'category_id' => null, 'production_station' => 'Curry', 'printer_id' => $this->curryStationPrinterId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Print Test Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'venue' => 'Hall B', 'pax' => 200,
        ]);
        $estimate = $event->currentEstimate;
        $this->estimates->saveDraftLines($estimate, [
            ['product_id' => $biryaniId, 'item_name' => 'Chicken Biryani', 'quantity' => 60, 'rate' => 320, 'instructions' => 'Less spicy'],
            ['product_id' => $qormaId, 'item_name' => 'Chicken Qorma', 'quantity' => 30, 'rate' => 380],
        ]);
        $this->estimates->markSent($estimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        return app(CateringProductionReleaseService::class)->release($event->refresh());
    }

    public function test_release_routes_one_job_per_station_printer_with_price_free_payload(): void
    {
        $release = $this->releasedTwoStationEvent();

        $jobs = $this->printing->queueRelease($release);

        $this->assertCount(2, $jobs, 'two stations → two independent printer jobs');
        $byPrinter = $jobs->keyBy('printer_id');
        $this->assertTrue($byPrinter->has($this->riceStationPrinterId));
        $this->assertTrue($byPrinter->has($this->curryStationPrinterId));

        $riceJob = $byPrinter[$this->riceStationPrinterId];
        $this->assertSame('catering_production', $riceJob->document_type);
        $this->assertSame('catering_production_release', $riceJob->reference_type);
        $this->assertSame('queued', $riceJob->print_status);
        $this->assertCount(1, $riceJob->payload['lines'], 'rice printer receives only its station lines');
        $this->assertSame('Deg Biryani', $riceJob->payload['lines'][0]['item_name'], 'production label on the ticket');

        // NO commercial prices anywhere in payload or bytes.
        $flat = json_encode($riceJob->payload).$riceJob->raw_payload;
        foreach (['rate', 'amount', '320', '380', 'grand_total', 'subtotal'] as $priceMarker) {
            $this->assertStringNotContainsString($priceMarker, $flat,
                "kitchen payload must not contain commercial pricing marker [{$priceMarker}]");
        }
        $this->assertStringContainsString('NO PRICES', $riceJob->raw_payload);
        $this->assertStringContainsString('Less spicy', $riceJob->raw_payload);

        // A production ticket is NOT a POS KOT.
        $this->assertSame(0, (int) $this->tenant()->table('kot_batches')->count());
        $this->assertSame(0, (int) $this->tenant()->table('sales_orders')->count());
    }

    public function test_retry_is_idempotent_and_reprint_creates_a_controlled_copy(): void
    {
        $release = $this->releasedTwoStationEvent();

        $first = $this->printing->queueRelease($release);
        $retry = $this->printing->queueRelease($release);

        $this->assertSame(
            $first->pluck('id')->sort()->values()->all(),
            $retry->pluck('id')->sort()->values()->all(),
            'same release retry returns the SAME business jobs — no duplicates'
        );
        $this->assertSame(2, (int) $this->tenant()->table('print_jobs')->count());

        $reprint = $this->printing->reprintRelease($release, $this->riceStationPrinterId);
        $this->assertCount(1, $reprint);
        $this->assertSame(2, $reprint->first()->copy_no, 'explicit reprint is a new physical copy');
        $this->assertStringContainsString('COPY #2', $reprint->first()->raw_payload);
        $this->assertSame(3, (int) $this->tenant()->table('print_jobs')->count());

        // Payload is immutable at queue time: stored bytes survive later release lookups.
        $storedRaw = $this->tenant()->table('print_jobs')->where('id', $reprint->first()->id)->value('raw_payload');
        $this->assertNotEmpty($storedRaw);
    }

    public function test_pos_kot_mappings_stay_byte_identical_and_pos_routing_unaffected(): void
    {
        $categoryId = $this->makeCategory();
        $posPrinterId = $this->makePrinter(['name' => 'POS Kitchen']);
        $this->tenant()->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'category_id' => $categoryId, 'printer_id' => $posPrinterId,
            'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $posBefore = $this->tenant()->table('category_printer_mappings')->orderBy('id')->get();

        $release = $this->releasedTwoStationEvent();
        $this->printing->queueRelease($release);
        $this->printing->reprintRelease($release);

        // Catering printing changed NOTHING in POS routing.
        $this->assertEquals($posBefore, $this->tenant()->table('category_printer_mappings')->orderBy('id')->get());

        // And a catering mapping change does not alter POS routing either.
        $this->tenant()->table('catering_printer_mappings')->update(['printer_id' => $posPrinterId]);
        $this->assertEquals($posBefore, $this->tenant()->table('category_printer_mappings')->orderBy('id')->get());

        // No catering job ever references a sales_order.
        $this->assertSame(0, (int) $this->tenant()->table('print_jobs')->where('reference_type', 'sales_order')->count());
    }

    public function test_release_snapshot_stays_immutable_through_printing(): void
    {
        $release = $this->releasedTwoStationEvent();
        $this->printing->queueRelease($release);

        try {
            $release->refresh()->update(['requirements_snapshot' => ['tampered' => true]]);
            $this->fail('printing must not open any mutation path on the release snapshot');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }
}
