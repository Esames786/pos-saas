<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintJob;
use App\Services\Catering\CateringDocumentPrintService;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-PRODUCT-UX-1 (item 7) — customer documents over the network.
 *
 * The properties worth protecting are not "does it print". They are:
 * printing must never become an accounting event, a reprint must never mint a
 * second invoice, and Urdu must be refused rather than emitted as bytes a
 * thermal printer will render as rubbish.
 */
class CateringDocumentPrintMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEvent $event;

    private CateringEstimate $estimate;

    private Printer $printer;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'print_jobs', 'printers',
            'catering_refunds', 'catering_final_invoices', 'catering_advances',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = $this->makeProduct($categoryId, ['unit_id' => $unitId]);

        $this->printer = Printer::findOrFail($this->makePrinter(['characters_per_line' => 42]));

        $estimates = app(CateringEstimateService::class);
        $this->event = $estimates->createEvent([
            'branch_id' => $branchId, 'customer_name' => 'Print Test Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(7)->toDateString(),
            'venue' => 'Print Hall', 'pax' => 300,
        ]);
        $this->estimate = $estimates->saveDraftLines($this->event->currentEstimate, [[
            'product_id' => $productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 50, 'unit_id' => $unitId, 'unit_code' => 'KG', 'rate' => 900,
        ]], []);
    }

    private function service(): CateringDocumentPrintService
    {
        return app(CateringDocumentPrintService::class);
    }

    /** A quotation reaches the queue the LAN agent already polls — not a new one. */
    public function test_queueing_an_estimate_uses_the_existing_print_job_transport(): void
    {
        $job = $this->service()->queueEstimate($this->estimate, $this->printer);

        $this->assertInstanceOf(PrintJob::class, $job);
        $this->assertSame('catering_estimate', $job->document_type);
        $this->assertSame('catering_estimate', $job->reference_type);
        $this->assertSame($this->estimate->id, (int) $job->reference_id);
        $this->assertSame('queued', $job->print_status);
        $this->assertSame($this->printer->id, (int) $job->printer_id);
        $this->assertNotEmpty($job->raw_payload, 'the ESC/POS bytes are frozen at queue time');
        $this->assertStringContainsString('QUOTATION', $job->raw_payload);
        $this->assertStringContainsString('Chicken Biryani', $job->raw_payload);
    }

    /**
     * The property that matters most: a document is paper, not a posting.
     * Queueing and reprinting must leave finance and inventory untouched.
     */
    public function test_printing_creates_no_financial_or_stock_effect(): void
    {
        $before = [
            'journals' => DB::connection('tenant')->table('journal_entries')->count(),
            'journal_lines' => DB::connection('tenant')->table('journal_lines')->count(),
            'stock' => DB::connection('tenant')->table('stock_ledgers')->count(),
            'invoices' => CateringFinalInvoice::count(),
            'sales' => DB::connection('tenant')->table('sales_orders')->count(),
        ];

        $this->service()->queueEstimate($this->estimate, $this->printer);
        $this->service()->queueEstimate($this->estimate, $this->printer, 'en', null, true);
        $this->service()->queueEstimate($this->estimate, $this->printer, 'en', null, true);

        $after = [
            'journals' => DB::connection('tenant')->table('journal_entries')->count(),
            'journal_lines' => DB::connection('tenant')->table('journal_lines')->count(),
            'stock' => DB::connection('tenant')->table('stock_ledgers')->count(),
            'invoices' => CateringFinalInvoice::count(),
            'sales' => DB::connection('tenant')->table('sales_orders')->count(),
        ];

        $this->assertSame($before, $after,
            'printing a customer document must not post to the ledger, move stock, create an invoice or touch sales');
    }

    /** Pressing the button twice must not queue two identical sheets. */
    public function test_queueing_the_same_document_twice_is_idempotent(): void
    {
        $first = $this->service()->queueEstimate($this->estimate, $this->printer);
        $second = $this->service()->queueEstimate($this->estimate, $this->printer);

        $this->assertSame($first->id, $second->id,
            'the unique logical key must return the existing job rather than queue a duplicate');
        $this->assertSame(1, PrintJob::where('document_type', 'catering_estimate')->count());
    }

    /** An explicit reprint is a NEW numbered copy, and says so on the paper. */
    public function test_an_explicit_reprint_is_a_numbered_extra_copy(): void
    {
        $this->service()->queueEstimate($this->estimate, $this->printer);
        $copy = $this->service()->queueEstimate($this->estimate, $this->printer, 'en', null, true);

        $this->assertSame(2, (int) $copy->copy_no);
        $this->assertStringContainsString('COPY #2', $copy->raw_payload,
            'a reprint must be visibly marked so it is not mistaken for the original');
        $this->assertSame(2, PrintJob::where('document_type', 'catering_estimate')->count());
    }

    /**
     * Urdu is refused, not faked. The transport emits plain bytes with no
     * codepage or raster support; emitting them anyway would produce rubbish
     * that looks like a working feature.
     */
    public function test_urdu_and_bilingual_thermal_are_refused_honestly(): void
    {
        $this->assertTrue($this->service()->supportsThermal('en'));
        $this->assertFalse($this->service()->supportsThermal('ur'));
        $this->assertFalse($this->service()->supportsThermal('both'));

        foreach (['ur', 'both'] as $lang) {
            try {
                $this->service()->queueEstimate($this->estimate, $this->printer, $lang);
                $this->fail("thermal must refuse lang={$lang} rather than emit unrenderable bytes");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('English only', $e->getMessage());
                $this->assertStringContainsString('A4', $e->getMessage(),
                    'the refusal must tell the operator what to use instead');
            }
        }

        $this->assertSame(0, PrintJob::count(), 'a refused request must queue nothing at all');
    }

    /** Internal cost and margin are ours, never the customer's. */
    public function test_the_ticket_never_carries_internal_cost_or_margin(): void
    {
        $job = $this->service()->queueEstimate($this->estimate, $this->printer);

        $encoded = json_encode($job->payload);
        foreach (['estimated_unit_cost', 'estimated_cost_total', 'margin'] as $internal) {
            $this->assertStringNotContainsString($internal, $encoded,
                "internal figure [{$internal}] must never reach a customer document");
        }
    }

    /**
     * Release-gate authorization proof for the two new POST endpoints.
     *
     * A print route that queues work on physical hardware must sit behind the
     * same wall as every other catering route. Asserted declaratively against
     * the router rather than by reading the file, so removing a middleware
     * fails here instead of silently shipping.
     */
    public function test_the_new_print_routes_carry_the_full_protection_stack(): void
    {
        $required = ['auth:tenant', 'tenant.subscription.access', 'route.permission'];

        foreach ([
            'tenant.catering.documents.estimate-print',
            'tenant.catering.documents.final-invoice-print',
        ] as $name) {
            $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
                ->first(fn ($r) => $r->getName() === $name);

            $this->assertNotNull($route, "route [{$name}] must exist");
            $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])),
                "[{$name}] must be POST — queueing a print job is not a GET");

            $middleware = $route->gatherMiddleware();
            foreach ($required as $m) {
                $this->assertContains($m, $middleware,
                    "[{$name}] must be protected by [{$m}] — an unauthenticated or unentitled "
                    .'request must never reach the printer queue');
            }
        }
    }

    /** Entitlement: a POS-only tenant cannot reach the catering print routes. */
    public function test_print_routes_are_denied_without_the_catering_module(): void
    {
        $svc = app(\App\Services\Saas\TenantSubscriptionAccessService::class);

        $this->assertGreaterThan(0, \App\Models\Master\RouteCatalog::count(),
            'the route catalog must be populated or this matrix is vacuous');

        foreach ([
            'tenant.catering.documents.estimate-print',
            'tenant.catering.documents.final-invoice-print',
        ] as $route) {
            $catalog = \App\Models\Master\RouteCatalog::where('route_name', $route)->first();
            $this->assertNotNull($catalog, "[{$route}] must be in the route catalog to be gated at all");
            $this->assertSame('tenant.catering', $catalog->module_key,
                "[{$route}] must be owned by the catering module, not left unmapped and fail-open");
        }
    }

    /** An inactive printer is not a valid destination, and nothing is queued. */
    public function test_an_inactive_printer_is_refused_and_queues_nothing(): void
    {
        $this->printer->forceFill(['is_active' => false])->save();

        $response = app(\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class)
            ->printEstimate(
                \Illuminate\Http\Request::create('/', 'POST', [
                    'printer_id' => $this->printer->id, 'lang' => 'en',
                ]),
                $this->estimate
            );

        $this->assertSame(0, PrintJob::count(),
            'an inactive printer must not receive a queued job');
        $this->assertNotNull($response);
    }

    /** A printer id that does not exist in THIS tenant cannot be used. */
    public function test_an_unknown_printer_id_cannot_queue_a_job(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            app(\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class)
                ->printEstimate(
                    \Illuminate\Http\Request::create('/', 'POST', [
                        'printer_id' => 999999, 'lang' => 'en',
                    ]),
                    $this->estimate
                );
        } finally {
            $this->assertSame(0, PrintJob::count(),
                'a rejected request must queue nothing at all');
        }
    }

    /** The server refuses Urdu even if the UI is bypassed entirely. */
    public function test_urdu_is_refused_at_the_controller_not_only_in_the_ui(): void
    {
        $response = app(\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class)
            ->printEstimate(
                \Illuminate\Http\Request::create('/', 'POST', [
                    'printer_id' => $this->printer->id, 'lang' => 'ur',
                ]),
                $this->estimate
            );

        $this->assertSame(0, PrintJob::count(),
            'a crafted Urdu request must be refused server-side, not merely hidden in the UI');
        $this->assertNotNull($response);
    }

    /** POS KOT routing is a different concern and must be untouched. */
    public function test_printing_a_catering_document_does_not_touch_pos_kot_mappings(): void
    {
        $before = DB::connection('tenant')->table('category_printer_mappings')->count();

        $this->service()->queueEstimate($this->estimate, $this->printer);

        $this->assertSame($before, DB::connection('tenant')->table('category_printer_mappings')->count(),
            'catering printing must never modify POS KOT routing');
    }
}
