<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringNumberService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-NUMBERING-1 and DOUBLE-SUBMIT-1.
 *
 * Two unrelated fixes that share a cause: both were invisible until real people
 * used the software. The numbering reset every midnight so the second booking
 * of a Tuesday and the second booking of the year were both 0001. And nothing
 * stopped a form being submitted twice — Kashif's live data showed four
 * bookings inside two seconds, three of them in the same second.
 */
class CateringNumberingAndDuplicateMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        // Every table the numbering reads must be cleaned, not just events.
        // Leaving production releases behind made the PR series start above 0001
        // depending on which test ran first — the assertion was about numbering
        // but the failure was about isolation.
        $this->cleanTenant([
            'catering_material_issue_lines', 'catering_material_issues',
            'catering_final_invoices',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeEvent(): CateringEvent
    {
        return app(CateringEstimateService::class)->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Numbering Test',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(20)->toDateString(),
            'pax' => 50,
        ]);
    }

    /** The counter must NOT restart when the date rolls over. */
    public function test_the_sequence_continues_across_days_within_a_year(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        $a = $this->makeEvent();
        $b = $this->makeEvent();

        Carbon::setTestNow('2026-08-17 09:00:00');
        $c = $this->makeEvent();

        $this->assertSame('EV-20260816-0001', $a->event_no);
        $this->assertSame('EV-20260816-0002', $b->event_no);
        $this->assertSame('EV-20260817-0003', $c->event_no,
            'the day changed but the year did not — the counter must continue, not reset to 0001');
    }

    /** It must restart on 1 January, and the date part follows the new year. */
    public function test_the_sequence_resets_at_the_turn_of_the_year(): void
    {
        Carbon::setTestNow('2026-12-31 22:00:00');
        $last = $this->makeEvent();

        Carbon::setTestNow('2027-01-01 09:00:00');
        $first = $this->makeEvent();

        $this->assertSame('EV-20261231-0001', $last->event_no);
        $this->assertSame('EV-20270101-0001', $first->event_no,
            'a new year starts a new series');
    }

    /**
     * Past 9 the sequence crosses a digit boundary. Ordering on the raw string
     * would put '...-0010' behind '...-0009' once the day differs, handing out
     * a duplicate number.
     */
    public function test_the_sequence_survives_crossing_ten(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        for ($i = 0; $i < 9; $i++) {
            $this->makeEvent();
        }

        Carbon::setTestNow('2026-08-18 10:00:00');
        $tenth = $this->makeEvent();
        $eleventh = $this->makeEvent();

        $this->assertSame('EV-20260818-0010', $tenth->event_no);
        $this->assertSame('EV-20260818-0011', $eleventh->event_no);

        $this->assertSame(
            11, CateringEvent::distinct()->count('event_no'),
            'every number issued must be unique'
        );
    }

    /** Other document types keep their own independent yearly series. */
    public function test_each_document_type_counts_separately(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        $numbers = app(CateringNumberService::class);

        $this->makeEvent();

        $this->assertStringStartsWith('PR-20260816-0001', $numbers->nextProductionReleaseNo(),
            'a release series is not affected by how many events exist');
        $this->assertStringStartsWith('CI-20260816-0001', $numbers->nextFinalInvoiceNo());
    }

    // ── double submit ────────────────────────────────────────────────────

    /** The first request claims the token; the second is turned away. */
    public function test_a_repeated_submit_token_is_refused(): void
    {
        $middleware = new \App\Http\Middleware\PreventDuplicateSubmission;
        $token = (string) \Illuminate\Support\Str::uuid();

        $reached = 0;
        $run = function () use ($middleware, $token, &$reached) {
            $request = \Illuminate\Http\Request::create('/catering/events', 'POST', ['_submit_token' => $token]);
            $request->setLaravelSession(app('session.store'));

            return $middleware->handle($request, function () use (&$reached) {
                $reached++;

                return redirect('/ok');
            });
        };

        $run();
        $run();
        $run();

        $this->assertSame(1, $reached,
            'three presses of the same button must reach the controller exactly once');
    }

    /** A request without a token behaves exactly as before — nothing is gated. */
    public function test_a_request_without_a_token_is_untouched(): void
    {
        $middleware = new \App\Http\Middleware\PreventDuplicateSubmission;

        $reached = 0;
        for ($i = 0; $i < 3; $i++) {
            $request = \Illuminate\Http\Request::create('/catering/events', 'POST', []);
            $request->setLaravelSession(app('session.store'));
            $middleware->handle($request, function () use (&$reached) {
                $reached++;

                return redirect('/ok');
            });
        }

        $this->assertSame(3, $reached,
            'the guard must be inert for forms that have not opted in, or it would '
            .'silently block every other module in the application');
    }

    /** Two different presses of two different forms must both go through. */
    public function test_different_tokens_do_not_block_each_other(): void
    {
        $middleware = new \App\Http\Middleware\PreventDuplicateSubmission;

        $reached = 0;
        foreach ([\Illuminate\Support\Str::uuid(), \Illuminate\Support\Str::uuid()] as $token) {
            $request = \Illuminate\Http\Request::create('/catering/events', 'POST', ['_submit_token' => (string) $token]);
            $request->setLaravelSession(app('session.store'));
            $middleware->handle($request, function () use (&$reached) {
                $reached++;

                return redirect('/ok');
            });
        }

        $this->assertSame(2, $reached);
    }

    /** Catering POST routes carry the guard. */
    public function test_the_catering_post_routes_are_guarded(): void
    {
        foreach ([
            'tenant.catering.events.store',
            'tenant.catering.advances.store',
            'tenant.catering.production-releases.store',
            'tenant.catering.final-invoices.store',
        ] as $name) {
            $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
                ->first(fn ($r) => $r->getName() === $name);

            $this->assertNotNull($route, "route [{$name}] must exist");
            $this->assertContains('prevent.duplicate.submit', $route->gatherMiddleware(),
                "[{$name}] writes real records and must be behind the duplicate guard");
        }
    }
}
