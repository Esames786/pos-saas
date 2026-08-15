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
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
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

        $byNo = collect($this->allEvents($this->service()->window()))->keyBy('event_no');

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

        $window = $this->service()->window();
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
        $event = $this->event(CarbonImmutable::today()->addDays(3)->toDateString(), CateringEvent::STATUS_QUOTED, 750);

        $one = collect($this->allEvents($this->service()->window()))->firstWhere('event_no', $event->event_no);

        $this->assertSame(7500.0, $one['amount']);
        $this->assertTrue($one['quoted']);
        $this->assertSame('/catering/events/'.$event->id, $one['url']);
    }

    /** An unpriced booking says so rather than showing a misleading zero. */
    public function test_an_unpriced_booking_is_marked_not_quoted(): void
    {
        $event = $this->event(CarbonImmutable::today()->addDays(4)->toDateString(), CateringEvent::STATUS_DRAFT);

        $one = collect($this->allEvents($this->service()->window()))->firstWhere('event_no', $event->event_no);

        $this->assertFalse($one['quoted']);
        $this->assertSame(0.0, $one['amount']);
    }

    /** The widget renders, and shows the money and the deep link. */
    public function test_the_widget_renders_with_amounts_and_links(): void
    {
        View::share('errors', new \Illuminate\Support\ViewErrorBag);

        $event = $this->event(CarbonImmutable::today()->addDays(2)->toDateString(), CateringEvent::STATUS_CONFIRMED, 1200);

        $html = View::make('tenant.partials.catering-calendar', [
            'cateringCalendar' => $this->service()->window(),
            'selectedBranch' => null,
        ])->render();

        $this->assertStringContainsString('Booking Calendar', $html);
        $this->assertStringContainsString($event->event_no, $html);
        $this->assertStringContainsString('/catering/events/'.$event->id, $html,
            'the dot must carry a deep link to the booking');
        $this->assertStringContainsString('Date passed, still open', $html,
            'the legend must explain the colour that matters most');
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
