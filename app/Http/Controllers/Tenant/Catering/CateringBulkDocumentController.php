<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringSetting;
use App\Services\Catering\CateringFinancialPositionService;
use Illuminate\Http\Request;

/**
 * KASHIF-CATERING-OPERATOR-UI-1 — bulk documents for a selected set of bookings.
 *
 * Friday afternoon at a caterer is eight bookings for Saturday: eight
 * quotations to hand the drivers, eight kitchen sheets, one address list. The
 * old software printed them as a batch; ours made the operator open eight
 * screens.
 *
 * Everything here is READ-ONLY composition for the browser's A4 print dialog:
 * no stock moves, nothing posts, no quotation changes state, and no print jobs
 * are queued — the existing single-document print transport keeps that role.
 * The document bodies are the SAME partials the single-document screens render,
 * so a bulk copy can never say something the single copy would not.
 *
 * The selection cap is a guard against a select-all on a decade of history:
 * a print run is a day's bookings, not an archive export.
 */
class CateringBulkDocumentController extends Controller
{
    public const MAX_SELECTION = 40;

    /** One page (or more) per selected booking's CURRENT estimate. */
    public function quotations(Request $request, CateringFinancialPositionService $positions)
    {
        $events = $this->selectedEvents($request, ['currentEstimate.lines', 'customer']);

        $documents = $events
            ->filter(fn (CateringEvent $e) => $e->currentEstimate && $e->currentEstimate->lines->isNotEmpty())
            ->map(fn (CateringEvent $e) => [
                'event' => $e,
                'estimate' => $e->currentEstimate,
                'position' => $positions->position($e),
            ])
            ->values();

        abort_if($documents->isEmpty(), 422, 'None of the selected bookings has a printable quotation.');

        return view('tenant.catering.documents.bulk-quotations', [
            'documents' => $documents,
            'lang' => $this->language($request),
            'businessName' => $this->businessName(),
            'skipped' => $events->count() - $documents->count(),
        ]);
    }

    /**
     * One kitchen sheet per selected booking that HAS a production release —
     * a booking without one has no kitchen document to print, and inventing a
     * provisional sheet from a draft estimate is exactly what a release exists
     * to prevent. Skipped bookings are named, not silently dropped.
     */
    public function kitchenSheets(Request $request)
    {
        $events = $this->selectedEvents($request, ['productionReleases.lines', 'productionReleases.event']);

        $releases = collect();
        $skipped = [];
        foreach ($events as $event) {
            $release = $event->productionReleases
                ->where('status', 'released')
                ->sortByDesc('released_at')
                ->first();
            if ($release) {
                $releases->push($release);
            } else {
                $skipped[] = $event->event_no;
            }
        }

        // This page opens in a NEW TAB, so an abort() shows the operator a
        // framework error for a situation where nothing is actually wrong: the
        // bookings they picked simply have not been released to the kitchen
        // yet. Say that, in the tab they are already looking at.
        if ($releases->isEmpty()) {
            return response()->view('tenant.catering.documents.nothing-to-print', [
                'title' => 'No kitchen sheet yet',
                'message' => $skipped === []
                    ? 'No bookings were selected.'
                    : 'These bookings have not been released to the kitchen yet, so there is no kitchen sheet to print:',
                'references' => $skipped,
                'hint' => 'Release production for the booking first — from the events list Actions menu, or from the booking itself. The kitchen sheet is created at that moment.',
            ], 422);
        }

        return view('tenant.catering.documents.bulk-kitchen-sheets', [
            'releases' => $releases->values(),
            'lang' => $this->language($request),
            'businessName' => $this->businessName(),
            'skippedEvents' => $skipped,
        ]);
    }

    /** The drivers' list: who, when, where, how many — one row per booking. */
    public function addressSheet(Request $request)
    {
        $events = $this->selectedEvents($request, ['currentEstimate:id,catering_event_id,version_no,status']);

        return view('tenant.catering.documents.address-sheet', [
            'events' => $events,
            'businessName' => $this->businessName(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, CateringEvent> */
    private function selectedEvents(Request $request, array $with)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTION],
            'ids.*' => ['integer'],
        ]);

        $events = CateringEvent::with($with)
            ->whereIn('id', $data['ids'])
            ->orderBy('event_date')
            ->orderBy('service_time')
            ->get();

        abort_if($events->isEmpty(), 404);

        return $events;
    }

    private function language(Request $request): string
    {
        $lang = $request->input('lang');
        if (! in_array($lang, CateringSetting::PRINT_PROFILES, true)) {
            $lang = CateringSetting::tenantDefault()->print_language_profile;
        }

        // A tenant that never touched catering settings has no stored profile
        // yet; a bulk page is not the place to fail over that.
        return $lang ?: 'en';
    }

    private function businessName(): string
    {
        try {
            return app('tenant')->business_name ?? config('saas.brand_name', 'Bingoo');
        } catch (\Throwable) {
            return config('saas.brand_name', 'Bingoo');
        }
    }
}
