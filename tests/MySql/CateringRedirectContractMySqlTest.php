<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringEventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-REDIRECT-FIX-1 — post-action redirects must actually resolve.
 *
 * Every tenant route is declared under Route::domain('{subdomain}.…'), so the
 * FIRST parameter route() fills is the subdomain. Calling
 * route('tenant.catering.events.show', $event) therefore consumed the model as
 * the subdomain and left {cateringEvent} empty, throwing UrlGenerationException
 * — AFTER the event had already been written. The operator saw a 500 and
 * assumed nothing had happened, while the booking existed.
 *
 * Six catering redirects were affected. Nothing caught it because the service
 * tests call services and the render tests render views; no test had ever
 * followed a controller through to its redirect.
 *
 * The source guard is the durable half: url() paths are what the rest of this
 * application uses, and a future route() call would reintroduce the same fault
 * silently.
 */
class CateringRedirectContractMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
    }

    /** Creating a booking must land on the booking, not on a 500. */
    public function test_creating_an_event_redirects_to_the_event(): void
    {
        $request = Request::create('/catering/events', 'POST', [
            'branch_id' => $this->branchId,
            'customer_name' => 'Redirect Contract',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(9)->toDateString(),
            'pax' => 80,
        ]);

        $response = app(CateringEventController::class)->store($request);

        $event = \App\Models\Tenant\CateringEvent::latest('id')->firstOrFail();

        $this->assertStringContainsString('/catering/events/'.$event->id, $response->getTargetUrl(),
            'the redirect must point at the booking that was just created');
    }

    /** Updating a booking must return to it. */
    public function test_updating_an_event_redirects_back_to_it(): void
    {
        $event = app(\App\Services\Catering\CateringEstimateService::class)->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Update Contract',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(12)->toDateString(),
            'pax' => 40,
        ]);

        $request = Request::create('/', 'PUT', [
            'branch_id' => $this->branchId,
            'customer_name' => 'Update Contract Renamed',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(12)->toDateString(),
            'pax' => 45,
        ]);

        $response = app(CateringEventController::class)->update($request, $event->fresh());

        $this->assertStringContainsString('/catering/events/'.$event->id, $response->getTargetUrl());
    }

    /**
     * The durable guard. route() cannot fill {subdomain} on a tenant route, so
     * a catering controller must never use it — the failure is silent at write
     * time and only surfaces as a 500 in front of the operator.
     */
    public function test_no_catering_controller_builds_urls_with_route(): void
    {
        $dir = app_path('Http/Controllers/Tenant/Catering');
        $offenders = [];
        $scanned = 0;

        foreach (glob($dir.'/*.php') as $file) {
            $scanned++;
            $src = file_get_contents($file);
            if (preg_match_all("/route\('tenant\.[^']*'/", $src, $m)) {
                $offenders[basename($file)] = $m[0];
            }
        }

        $this->assertGreaterThan(5, $scanned, 'the scan must actually cover the catering controllers');
        $this->assertSame([], $offenders,
            'catering controllers must build URLs with url(), not route(): tenant routes carry a '
            .'{subdomain} parameter that route() fills first, swallowing the real parameter. Found: '
            .json_encode($offenders));
    }

    /** The advance flash message must not contradict what the action just did. */
    public function test_the_advance_message_does_not_deny_the_posting_it_just_made(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Tenant/Catering/CateringAdvanceController.php'));

        $this->assertStringNotContainsString('no accounting entry in V1', $src,
            'recording an advance posts a journal entry and moves the cash/bank balance — '
            .'the confirmation must not tell the operator the opposite');
        $this->assertStringContainsString('general ledger', $src);
    }
}
