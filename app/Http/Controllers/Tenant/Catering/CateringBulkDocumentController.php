<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringSetting;
use App\Services\Catering\CateringFinancialPositionService;
use App\Services\Catering\CateringProductionReleaseService;
use Illuminate\Http\Request;
use RuntimeException;

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
     * Har chuni hui booking ka ek kitchen sheet — CHAAHE US KA STATUS KUCH BHI HO.
     *
     * Yahan pehle likha tha: booking jis ki release na hui ho us ka koi kitchen
     * document nahi banta, aur draft estimate se aarzi parcha gharna theek wohi
     * cheez hai jise rokne ke liye release ka nizam bana hai. Malik ne 27
     * September ko is ke khilaf faisla diya — bawarchi-khane ko parcha release
     * se PEHLE chahiye, har status par (draft, quoted, confirmed, released).
     * Wo caveat yahan se HATA diya gaya hai, chhupaya nahi: jo baat ab sach na
     * ho, usay comment me chhod dena baad me aane wale ko ghalat samjhata hai.
     *
     * Us caveat ka asal khauf — ke aarzi parcha asli jaisa dikhega aur
     * bawarchi-khane ke paas do sach ho jayenge — is tarah door kiya gaya ke
     * aarzi parcha KHUD apne oopar likhta hai ke wo jaari nahi hua
     * (`! $release->exists` par preview band). Aur wo kuch mehfooz nahi karta:
     * na release banti hai, na release number kharch hota hai, na status hilta.
     *
     * Ab sirf ek hi booking chhoot sakti hai: jis par koi estimate hi na ho.
     * Wo naam le kar batai jati hai, khamoshi se giraayi nahi jati.
     */
    public function kitchenSheets(Request $request)
    {
        $events = $this->selectedEvents($request, ['productionReleases.lines', 'productionReleases.event']);

        $previews = app(CateringProductionReleaseService::class);
        $releases = collect();
        $skipped = [];
        foreach ($events as $event) {
            $release = $event->productionReleases
                ->where('status', 'released')
                ->sortByDesc('released_at')
                ->first();

            if ($release) {
                $releases->push($release);

                continue;
            }

            // Release nahi hui — usi mojooda estimate se aarzi parcha.
            try {
                $releases->push($previews->preview($event));
            } catch (RuntimeException $e) {
                // Ab yahan aane ki ek hi asli wajah bachti hai: booking par
                // koi estimate hi nahi.
                $skipped[] = $event->event_no;
            }
        }

        // This page opens in a NEW TAB, so an abort() shows the operator a
        // framework error for a situation where nothing is actually wrong.
        //
        // Ye paighaam pehle kehta tha "release nahi hui, is liye parcha nahi
        // bana" — ab wo jhooth hoga: release se pehle bhi parcha banta hai.
        // Ab yahan aane ki ek hi soorat hai: chuni hui booking par koi
        // quotation hi nahi.
        if ($releases->isEmpty()) {
            return response()->view('tenant.catering.documents.nothing-to-print', [
                'title' => 'No kitchen sheet yet',
                'message' => $skipped === []
                    ? 'No bookings were selected.'
                    : 'These bookings have no quotation yet, so there are no dishes to put on a kitchen sheet:',
                'references' => $skipped,
                'hint' => 'Add the dishes to the booking first. The kitchen sheet can then be printed at any status — before production is released it prints as a clearly marked PREVIEW.',
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
