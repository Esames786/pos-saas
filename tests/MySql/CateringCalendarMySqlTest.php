<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringCalendarService;
use App\Services\Catering\CateringEstimateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-CALENDAR-1 — the dashboard booking diary.
 *
 * Two things are worth protecting here. The window must genuinely bound what is
 * loaded, or a kitchen with years of history pays for all of it on every
 * dashboard paint. And a booking whose date has passed while it is still open
 * must be called out separately from one that is finished — those demand
 * opposite responses, and colouring them the same would hide the only ones that
 * need action today.
 */
class CateringCalendarMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $productId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_estimate_lines', 'catering_estimates', 'catering_refunds', 'catering_events',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->branchId = $this->branchId ?? $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($categoryId, ['unit_id' => $this->unitId]);
    }

    private function event(string $date, string $status, float $rate = 0): CateringEvent
    {
        $estimates = app(CateringEstimateService::class);

        $event = $estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Cal '.$status.' '.$date,
            'booking_date' => CarbonImmutable::parse($date)->subDays(30)->toDateString(),
            'event_date' => $date,
            'venue' => 'Hall',
            'pax' => 100,
        ]);

        if ($rate > 0) {
            $estimates->saveDraftLines($event->currentEstimate, [[
                'product_id' => $this->productId, 'item_name' => 'Biryani',
                'quantity' => 10, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => $rate,
            ]], []);
        }

        $event->forceFill(['status' => $status])->save();

        return $event->fresh();
    }

    private function service(): CateringCalendarService
    {
        return app(CateringCalendarService::class);
    }

    /** The window is three months ending at the anchor — nothing older is loaded. */
    public function test_the_default_window_is_three_months_and_excludes_older_bookings(): void
    {
        $today = CarbonImmutable::today();

        $this->event($today->toDateString(), CateringEvent::STATUS_CONFIRMED, 500);
        $this->event($today->subMonths(1)->toDateString(), CateringEvent::STATUS_CLOSED, 500);
        $this->event($today->subMonths(2)->toDateString(), CateringEvent::STATUS_CLOSED, 500);
        $outside = $this->event($today->subMonths(5)->toDateString(), CateringEvent::STATUS_CLOSED, 500);

        $window = $this->service()->window();

        $this->assertCount(CateringCalendarService::WINDOW_MONTHS, $window['months']);
        $this->assertSame($today->startOfMonth()->subMonths(2)->toDateString(), $window['from']->toDateString());

        $numbers = $this->eventNumbers($window);
        $this->assertNotContains($outside->event_no, $numbers,
            'a booking five months back must NOT be loaded by the default window');
        $this->assertCount(3, $numbers, 'exactly the three in-window bookings');
    }

    /** Stepping back loads the older months that were deliberately excluded. */
    public function test_stepping_back_loads_the_older_months(): void
    {
        $today = CarbonImmutable::today();
        $old = $this->event($today->subMonths(4)->toDateString(), CateringEvent::STATUS_CLOSED, 500);

        $this->assertNotContains($old->event_no, $this->eventNumbers($this->service()->window()),
            'precondition: it is outside the default window');

        $older = $this->service()->window($today->subMonths(CateringCalendarService::WINDOW_MONTHS));

        $this->assertContains($old->event_no, $this->eventNumbers($older),
            'stepping back one window must reach it');
    }

    /**
     * The distinction the whole widget exists for: a past date with an OPEN
     * booking is not the same as a past date that is finished.
     */
    public function test_a_past_date_that_is_still_open_is_flagged_separately_from_one_that_is_done(): void
    {
        $today = CarbonImmutable::today();

        $overdue = $this->event($today->subDays(10)->toDateString(), CateringEvent::STATUS_CONFIRMED, 900);
        $done = $this->event($today->subDays(11)->toDateString(), CateringEvent::STATUS_CLOSED, 900);
        $upcoming = $this->event($today->addDays(10)->toDateString(), CateringEvent::STATUS_CONFIRMED, 900);

        // Anchored at the UPCOMING month: within ten days of a month end the
        // default window (which stops at the current month) has not reached the
        // +10-day booking yet, and this test used to fail on exactly those days.
        // The anchored window still reaches two months back, so the past
        // bookings stay covered whatever today is.
        $byNo = collect($this->allEvents($this->service()->window($today->addDays(10))))->keyBy('event_no');

        $this->assertSame('overdue', $byNo[$overdue->event_no]['tone']);
        $this->assertTrue($byNo[$overdue->event_no]['needs_attention']);

        $this->assertSame('done', $byNo[$done->event_no]['tone']);
        $this->assertFalse($byNo[$done->event_no]['needs_attention'],
            'a finished booking in the past needs nothing from the operator');

        $this->assertSame('confirmed', $byNo[$upcoming->event_no]['tone']);
        $this->assertFalse($byNo[$upcoming->event_no]['is_past']);
    }

    /** Cancelled bookings are visible but never counted as work or value. */
    public function test_cancelled_bookings_are_shown_but_excluded_from_totals(): void
    {
        $today = CarbonImmutable::today();

        $this->event($today->addDays(5)->toDateString(), CateringEvent::STATUS_CONFIRMED, 1000);
        $cancelled = $this->event($today->addDays(6)->toDateString(), CateringEvent::STATUS_CANCELLED, 1000);

        // Anchored at the month the bookings live in — a window that ends
        // at today's month-end cannot see a booking six days away in the next.
        $window = $this->service()->window($today->addDays(6));
        $byNo = collect($this->allEvents($window))->keyBy('event_no');

        $this->assertArrayHasKey($cancelled->event_no, $byNo->all(), 'it stays visible on the grid');
        $this->assertSame('cancelled', $byNo[$cancelled->event_no]['tone']);

        $this->assertSame(1, $window['totals']['upcoming'],
            'a cancelled booking is not upcoming work');
        $this->assertSame(10000.0, $window['totals']['value'],
            'and its value must not inflate the pipeline');
    }

    /** The money is on the dot, because "how much" is the next question. */
    public function test_each_event_carries_its_amount_and_a_link(): void
    {
        $date = CarbonImmutable::today()->addDays(3);
        $event = $this->event($date->toDateString(), CateringEvent::STATUS_QUOTED, 750);

        $one = collect($this->allEvents($this->service()->window($date)))->firstWhere('event_no', $event->event_no);

        $this->assertSame(7500.0, $one['amount']);
        $this->assertTrue($one['quoted']);
        $this->assertSame('/catering/events/'.$event->id, $one['url']);
    }

    /** An unpriced booking says so rather than showing a misleading zero. */
    public function test_an_unpriced_booking_is_marked_not_quoted(): void
    {
        $date = CarbonImmutable::today()->addDays(4);
        $event = $this->event($date->toDateString(), CateringEvent::STATUS_DRAFT);

        $one = collect($this->allEvents($this->service()->window($date)))->firstWhere('event_no', $event->event_no);

        $this->assertFalse($one['quoted']);
        $this->assertSame(0.0, $one['amount']);
    }

    /** The widget renders, and shows the money and the deep link. */
    public function test_the_widget_renders_with_amounts_and_links(): void
    {
        View::share('errors', new \Illuminate\Support\ViewErrorBag);

        $date = CarbonImmutable::today()->addDays(2);
        $event = $this->event($date->toDateString(), CateringEvent::STATUS_CONFIRMED, 1200);

        $html = View::make('tenant.partials.catering-calendar', [
            'cateringCalendar' => $this->service()->window($date),
            'selectedBranch' => null,
        ])->render();

        $this->assertStringContainsString('Booking Calendar', $html);
        $this->assertStringContainsString($event->event_no, $html);
        $this->assertStringContainsString('/catering/events/'.$event->id, $html,
            'the dot must carry a deep link to the booking');
        $this->assertStringContainsString('Date passed, still open', $html,
            'the legend must explain the colour that matters most');
    }

    /**
     * CAL-LEGEND-FILTER-1 — the legend is a filter, and it says which stage it
     * means.
     *
     * The owner circled the status chips: "these buttons should be clickable or
     * filterable", and separately asked how a QUOTATION is told apart from an
     * ESTIMATE. Both answers were already in the data and neither was on screen.
     *
     * A booking is `draft` until its quotation is finalized and sent, and
     * `quoted` from that moment — CateringEstimateService::send() moves the
     * estimate to `sent` and the event to `quoted` in the same transaction. The
     * calendar has always coloured by that. The chips simply never said which
     * stage each colour meant, and could not be clicked.
     *
     * Filtering is done in the page from the bookings each day already carries,
     * so there is no new route and no new permission to grant.
     */
    public function test_the_legend_filters_and_names_the_document_stage(): void
    {
        View::share('errors', new \Illuminate\Support\ViewErrorBag);

        $date = CarbonImmutable::today()->addDays(3);
        $this->event($date->toDateString(), CateringEvent::STATUS_QUOTED, 900);

        $html = View::make('tenant.partials.catering-calendar', [
            'cateringCalendar' => $this->service()->window($date),
            'selectedBranch' => null,
        ])->render();

        // The chips say which document stage they mean.
        $this->assertStringContainsString('Estimate — not yet sent', $html,
            'a booking with no quotation sent is still at estimate stage, and should say so');
        $this->assertStringContainsString('Quotation sent — awaiting reply', $html);

        // And they are buttons, one per tone, each carrying its own key.
        $this->assertSame(6, substr_count($html, 'class="badge fw-normal fs-12 border-0 cal-tone"'),
            'every status in the legend is clickable');
        foreach (['overdue', 'confirmed', 'quoted', 'draft', 'done', 'cancelled'] as $tone) {
            $this->assertStringContainsString('data-tone="'.$tone.'"', $html);
        }
        $this->assertStringContainsString('aria-pressed="false"', $html,
            'a filter chip is a toggle, and says so to a screen reader');

        // The BEHAVIOUR lives in @push('scripts'), which the layout collects and
        // a bare View::make of this partial never emits — so it is read from the
        // source rather than pretended to be in the render above.
        $source = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/partials/catering-calendar.blade.php'
        );

        // The filter reads the bookings each day already carries — no request.
        $this->assertStringContainsString('function applyTones()', $source);
        $this->assertStringContainsString("btn.setAttribute('data-filtered'", $source,
            'the day badge records what it counted');
        $this->assertStringContainsString("btn.getAttribute('data-filtered') || btn.getAttribute('data-events')", $source,
            'the day list must open exactly what the badge counted, or the two disagree');

        // Fetching another month replaces the whole body, so the filter has to be
        // put back — otherwise it silently lapses the first time a month changes.
        $afterFetch = strpos($source, 'body.innerHTML = html;');
        $this->assertNotFalse($afterFetch);
        $this->assertStringContainsString('applyTones();', substr($source, $afterFetch, 220),
            'a newly fetched month must come back filtered');
    }

    /** @return array<int, array> */
    private function allEvents(array $window): array
    {
        return collect($window['months'])
            ->flatMap(fn ($m) => collect($m['days'])->flatMap(fn ($d) => $d['events']))
            ->all();
    }

    /** @return array<int, string> */
    private function eventNumbers(array $window): array
    {
        return collect($this->allEvents($window))->pluck('event_no')->unique()->values()->all();
    }
}
