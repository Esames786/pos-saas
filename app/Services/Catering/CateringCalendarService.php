<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * KASHIF-CATERING-CALENDAR-1 — the booking calendar behind the dashboard widget.
 *
 * A caterer's diary is the business. The two questions asked all day are "what
 * is coming up" and "am I already booked that night", and neither was on the
 * dashboard at all.
 *
 * Windowing is deliberate. A kitchen that has traded for years accumulates
 * thousands of events, and loading all of them to draw one month is wasteful on
 * a branch terminal. The default window is the CURRENT month plus the two
 * before it — three months up to today — and older months are fetched only when
 * the operator actually steps back to them.
 */
class CateringCalendarService
{
    /** Months shown before the anchor month, inclusive of it. */
    public const WINDOW_MONTHS = 3;

    /**
     * Events for a three-month window ending at $anchor's month.
     *
     * @return array{
     *   from: CarbonImmutable, to: CarbonImmutable, anchor: CarbonImmutable,
     *   months: array<int, array{key: string, label: string, days: array}>,
     *   totals: array{upcoming: int, past_open: int, value: float}
     * }
     */
    public function window(?CarbonImmutable $anchor = null, ?int $branchId = null): array
    {
        $anchor = ($anchor ?? CarbonImmutable::today())->startOfMonth();

        $from = $anchor->subMonths(self::WINDOW_MONTHS - 1)->startOfMonth();
        $to = $anchor->endOfMonth();

        $events = CateringEvent::query()
            ->with('currentEstimate:id,catering_event_id,grand_total,version_no,status')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('event_date')
            ->orderBy('service_time')
            ->get();

        $today = CarbonImmutable::today();

        $byDate = $events->groupBy(fn (CateringEvent $e) => $e->event_date->toDateString())
            ->map(fn (Collection $day) => $day->map(fn (CateringEvent $e) => $this->present($e, $today))->values()->all());

        return [
            'from' => $from,
            'to' => $to,
            'anchor' => $anchor,
            'months' => $this->buildMonths($from, $anchor, $byDate, $today),
            'totals' => $this->totals($events, $today),
        ];
    }

    /**
     * One event as the calendar needs it — including the money, because "how
     * much is that booking worth" is the next question after "when is it".
     */
    private function present(CateringEvent $event, CarbonImmutable $today): array
    {
        $isPast = $event->event_date->lt($today);

        return [
            'id' => $event->id,
            'event_no' => $event->event_no,
            'customer' => $event->customer_name,
            'venue' => $event->venue,
            'pax' => (int) $event->pax,
            'date' => $event->event_date->format('D, d M Y'),
            'time' => $event->service_time ? substr((string) $event->service_time, 0, 5) : null,
            'status' => $event->status,
            'status_label' => ucwords(str_replace('_', ' ', $event->status)),
            'amount' => (float) ($event->currentEstimate?->grand_total ?? 0),
            // An estimate row always exists from the moment a booking is created, so
            // its presence proves nothing. What the operator needs to know is whether
            // a PRICE has been put on it yet.
            'quoted' => (float) ($event->currentEstimate?->grand_total ?? 0) > 0,
            'is_past' => $isPast,
            // A date that has passed while the booking is still open is the one
            // an operator must act on — it is neither "upcoming" nor "done".
            'needs_attention' => $isPast && $event->isOpen(),
            'tone' => $this->tone($event, $isPast),
            'url' => '/catering/events/'.$event->id,
        ];
    }

    /**
     * Colour band. Past-but-open is called out separately from past-and-settled,
     * because they demand completely different responses from the operator.
     */
    private function tone(CateringEvent $event, bool $isPast): string
    {
        if ($event->isCancelled()) {
            return 'cancelled';
        }

        if ($isPast) {
            return $event->isOpen() ? 'overdue' : 'done';
        }

        return match ($event->status) {
            CateringEvent::STATUS_CONFIRMED,
            CateringEvent::STATUS_PRODUCTION_READY,
            CateringEvent::STATUS_RELEASED => 'confirmed',
            CateringEvent::STATUS_QUOTED => 'quoted',
            default => 'draft',
        };
    }

    /** @return array<int, array{key: string, label: string, days: array}> */
    private function buildMonths(CarbonImmutable $from, CarbonImmutable $anchor, Collection $byDate, CarbonImmutable $today): array
    {
        $months = [];
        $cursor = $from;

        while ($cursor->lte($anchor)) {
            $monthStart = $cursor->startOfMonth();
            $monthEnd = $cursor->endOfMonth();

            // Pad to whole weeks so the grid never renders a ragged first row.
            $gridStart = $monthStart->startOfWeek(CarbonImmutable::MONDAY);
            $gridEnd = $monthEnd->endOfWeek(CarbonImmutable::SUNDAY);

            $days = [];
            for ($d = $gridStart; $d->lte($gridEnd); $d = $d->addDay()) {
                $key = $d->toDateString();
                $days[] = [
                    'date' => $key,
                    'day' => $d->day,
                    'in_month' => $d->month === $monthStart->month,
                    'is_today' => $d->isSameDay($today),
                    'events' => $byDate[$key] ?? [],
                ];
            }

            $months[] = [
                'key' => $monthStart->format('Y-m'),
                'label' => $monthStart->format('F Y'),
                'days' => $days,
            ];

            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /** @param Collection<int, CateringEvent> $events */
    private function totals(Collection $events, CarbonImmutable $today): array
    {
        $live = $events->reject(fn (CateringEvent $e) => $e->isCancelled());

        return [
            'upcoming' => $live->filter(fn ($e) => $e->event_date->gte($today))->count(),
            'past_open' => $live->filter(fn ($e) => $e->event_date->lt($today) && $e->isOpen())->count(),
            'value' => (float) $live
                ->filter(fn ($e) => $e->event_date->gte($today))
                ->sum(fn ($e) => (float) ($e->currentEstimate?->grand_total ?? 0)),
        ];
    }
}
