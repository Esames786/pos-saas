<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringEventController;
use App\Models\Tenant\CateringEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-NO-RELOAD-2 — Event create/edit without page reloads.
 *
 * The contract: fixing a validation mistake never costs a reload (422 with
 * field errors, values kept on screen), a SUCCESSFUL create answers with the
 * new event's real URL for one clean transition, a successful edit re-renders
 * the details in place, and none of it goes near finance, stock or the
 * quotation lifecycle — creating and editing a booking commits nothing.
 */
class CateringEventAjaxMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();
        View::share('errors', new \Illuminate\Support\ViewErrorBag);
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true); // nullable => guests pass too

        $this->cleanTenant([
            'catering_email_logs',
            'catering_estimate_line_instruction', 'catering_instructions',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
    }

    private function controller(): CateringEventController
    {
        return app(CateringEventController::class);
    }

    private function jsonRequest(array $data): Request
    {
        $request = Request::create('/x', 'POST', $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branchId,
            'customer_name' => 'Ajax Customer',
            'customer_phone' => '0300-1231234',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 120,
            'venue' => 'Fortress Lawn',
        ], $overrides);
    }

    private function ledgerCounts(): array
    {
        $db = DB::connection('tenant');

        return [$db->table('journal_lines')->count(), $db->table('stock_ledgers')->count()];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Create.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ajax_create_returns_the_new_events_own_url_and_makes_exactly_one_event(): void
    {
        $before = $this->ledgerCounts();

        $response = $this->controller()->store($this->jsonRequest($this->valid()));

        $this->assertInstanceOf(JsonResponse::class, $response,
            'an ajax create is answered in JSON, never with a page');
        $payload = $response->getData(true);

        $event = CateringEvent::firstOrFail();
        $this->assertSame($event->id, $payload['event_id']);
        $this->assertStringContainsString('/catering/events/'.$event->id, $payload['redirect'],
            'the address the browser transitions to is the created event, never the create form');
        $this->assertStringContainsString($event->event_no, $payload['message']);

        $this->assertSame(1, CateringEvent::count(), 'one submission, one event');
        $this->assertSame(1, DB::connection('tenant')->table('catering_estimates')->count(),
            'the booking arrives with its one draft estimate, as always');
        $this->assertSame($before, $this->ledgerCounts(), 'creating a booking commits nothing');
    }

    public function test_ajax_create_validation_failure_creates_nothing(): void
    {
        try {
            $this->controller()->store($this->jsonRequest($this->valid(['customer_name' => ''])));
            $this->fail('a nameless booking must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_name', $e->errors(),
                'the 422 payload names the field, so the form can show it in place');
        }

        $this->assertSame(0, CateringEvent::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Edit.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ajax_edit_saves_in_place_and_keeps_the_events_identity(): void
    {
        $this->controller()->store($this->jsonRequest($this->valid()));
        $event = CateringEvent::firstOrFail();
        $no = $event->event_no;
        $before = $this->ledgerCounts();

        $response = $this->controller()->update(
            $this->jsonRequest($this->valid(['customer_name' => 'Renamed Customer', 'pax' => 250, 'venue' => 'New Venue'])),
            $event
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $payload = $response->getData(true);
        $this->assertSame($event->id, $payload['event_id'], 'editing never changes which event this is');
        $this->assertStringContainsString('/catering/events/'.$event->id, $payload['redirect']);

        $event->refresh();
        $this->assertSame('Renamed Customer', $event->customer_name);
        $this->assertSame(250, (int) $event->pax);
        $this->assertSame('New Venue', $event->venue);
        $this->assertSame($no, $event->event_no, 'the booking number survives every edit');

        $this->assertSame(1, CateringEvent::count());
        $this->assertSame($before, $this->ledgerCounts(), 'editing a booking touches neither finance nor stock');
    }

    public function test_ajax_edit_validation_failure_saves_nothing(): void
    {
        $this->controller()->store($this->jsonRequest($this->valid()));
        $event = CateringEvent::firstOrFail();

        try {
            $this->controller()->update($this->jsonRequest($this->valid(['pax' => -5])), $event);
            $this->fail('negative guests must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pax', $e->errors());
        }

        $this->assertSame(120, (int) $event->refresh()->pax, 'a refused edit changes nothing');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The screens carry the wiring.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_create_form_is_ajax_wired_with_in_place_errors_and_the_duplicate_guard(): void
    {
        $html = View::make('tenant.catering.events.form', [
            'event' => null,
            'branches' => collect(),
            'bookedDates' => [],
        ])->render();

        $this->assertStringContainsString('data-event-ajax="navigate"', $html,
            'create posts by fetch; only SUCCESS performs one clean GET into the new event');
        $this->assertStringContainsString('event-form-errors', $html,
            'validation renders into the form, values intact — never a reload');
        $this->assertStringContainsString('data-event-form-root', $html);
        $this->assertStringContainsString('_submit_token', $html,
            'the duplicate-submit guard still stamps the form');
        $this->assertStringContainsString('window.initCateringEventForm', $html);
    }

    public function test_the_workspace_offers_edit_beside_the_work_not_a_navigation(): void
    {
        $this->controller()->store($this->jsonRequest($this->valid()));
        $event = CateringEvent::with('currentEstimate')->firstOrFail();

        $html = View::make('tenant.catering.events.show', [
            'event' => $event->load('advances', 'refunds', 'productionReleases', 'finalInvoice'),
            'units' => collect(),
            'branches' => collect(),
            'bookedDates' => [],
            'activeInstructions' => collect(),
            'profileMap' => collect(),
            'paymentMethods' => collect(),
            'costingReadiness' => null,
            'printers' => collect(),
            'position' => app(\App\Services\Catering\CateringFinancialPositionService::class)->position($event),
            'headline' => app(\App\Services\Catering\CateringFinancialPositionService::class)->headline($event),
            'ledger' => app(\App\Services\Catering\CateringFinancialPositionService::class)->ledger($event),
        ])->render();

        $this->assertStringContainsString('data-bs-target="#editEventOffcanvas"', $html,
            'Edit Event opens the offcanvas — the operator never leaves the booking');
        $this->assertStringContainsString('id="editEventOffcanvas"', $html);
        $this->assertStringContainsString('data-event-ajax="refresh" data-no-ajax', $html,
            'the offcanvas form has its OWN handler, excluded from the generic engine');
        $this->assertStringContainsString('cateringWorkspaceRefresh', $html,
            'a successful edit re-renders the workspace in place');
        $this->assertStringContainsString('offcanvas-backdrop', $html,
            'the swap engine cleans offcanvas remains');
    }
}
