<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * CATERING-SLICE-1: events list/dashboard + event CRUD + lifecycle actions.
 * Estimates are documents under the event; no sales/stock/GL interaction.
 */
class CateringEventController extends Controller
{
    public function __construct(private readonly CateringEstimateService $estimates) {}

    public function index(Request $request)
    {
        $today = now()->startOfDay();

        $filter = $request->input('filter');
        $status = $request->input('status');

        // KASHIF-CATERING-OPERATOR-UI-1: predictable search over the fields an
        // operator actually holds — booking number, customer, phone, venue or
        // address. Deliberately NOT a cross-module global search.
        $q = trim((string) $request->input('q', ''));

        $query = CateringEvent::with(['currentEstimate', 'finalInvoice:id,catering_event_id,balance_due,status'])
            ->withCount('productionReleases')
            ->withSum('advances as advances_sum', 'amount')
            ->withSum('refunds as refunds_sum', 'amount')
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $like = '%'.str_replace(['%', '_'], ["\%", "\_"], $q).'%';
                $w->where('event_no', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_name_ur', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like)
                    ->orWhere('venue', 'like', $like)
                    ->orWhere('customer_address', 'like', $like);
            }))
            ->orderBy('event_date')
            ->orderByDesc('id');

        match ($filter) {
            'today' => $query->whereDate('event_date', $today),
            'tomorrow' => $query->whereDate('event_date', $today->copy()->addDay()),
            'week' => $query->whereBetween('event_date', [$today, $today->copy()->addDays(7)]),
            'unconfirmed' => $query->whereIn('status', [
                CateringEvent::STATUS_INQUIRY, CateringEvent::STATUS_DRAFT, CateringEvent::STATUS_QUOTED,
            ])->whereDate('event_date', '>=', $today),
            default => null,
        };

        if ($status && in_array($status, CateringEvent::STATUSES, true)) {
            $query->where('status', $status);
        }

        $events = $query->paginate(25)->withQueryString();

        $upcoming = fn ($from, $to = null) => CateringEvent::query()
            ->whereNotIn('status', [CateringEvent::STATUS_CANCELLED, CateringEvent::STATUS_CLOSED])
            ->whereDate('event_date', '>=', $from)
            ->when($to, fn ($q) => $q->whereDate('event_date', '<=', $to))
            ->count();

        $buckets = [
            'today' => $upcoming($today, $today),
            'tomorrow' => $upcoming($today->copy()->addDay(), $today->copy()->addDay()),
            'week' => $upcoming($today, $today->copy()->addDays(7)),
            'unconfirmed' => CateringEvent::query()
                ->whereIn('status', [CateringEvent::STATUS_INQUIRY, CateringEvent::STATUS_DRAFT, CateringEvent::STATUS_QUOTED])
                ->whereDate('event_date', '>=', $today)
                ->count(),
        ];

        return view('tenant.catering.events.index', compact('events', 'buckets', 'filter', 'status', 'q'));
    }

    public function create()
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.catering.events.form', [
            'event' => null,
            'branches' => $branches,
            'bookedDates' => $this->bookedDates(),
        ]);
    }

    /**
     * KASHIF-UAT-2 — dates this kitchen is already committed to.
     *
     * A caterer's first question when a customer names a date is "am I already
     * booked that night?" A bare date field can never answer it, so the form
     * shows the clash inline while they are still typing. Read-only, and
     * cancelled bookings are excluded because they free the date up again.
     *
     * @return array<string, array<int, array{event_no: string, customer: string, pax: int}>>
     */
    private function bookedDates(): array
    {
        return CateringEvent::query()
            ->whereNotIn('status', [CateringEvent::STATUS_CANCELLED, CateringEvent::STATUS_CLOSED])
            ->whereDate('event_date', '>=', now()->subMonth()->toDateString())
            ->orderBy('event_date')
            ->get(['id', 'event_no', 'event_date', 'customer_name', 'pax'])
            ->groupBy(fn ($e) => $e->event_date->toDateString())
            ->map(fn ($group) => $group->map(fn ($e) => [
                'event_no' => $e->event_no,
                'customer' => $e->customer_name,
                'pax' => (int) $e->pax,
            ])->values()->all())
            ->all();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $event = $this->estimates->createEvent($data, $request->user()?->id);

        // KASHIF-CATERING-REDIRECT-FIX-1 — url(), not route().
        //
        // Tenant routes live under Route::domain('{subdomain}.…'), so the FIRST
        // parameter route() fills is the subdomain. Passing the model consumed
        // it as the subdomain and left {cateringEvent} empty, throwing
        // UrlGenerationException — after the event had already been created. The
        // rest of the application uses url() paths for exactly this reason.
        // KASHIF-CATERING-NO-RELOAD-2: the ajax form asks for JSON and gets the
        // destination to navigate to — one clean GET, no POST page render.
        // Validation failures never reach here (the framework answers 422 JSON).
        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => url('/catering/events/'.$event->id),
                'event_id' => $event->id,
                'message' => "Event {$event->event_no} created.",
            ]);
        }

        return redirect()
            ->to('/catering/events/'.$event->id)
            ->with('status', "Event {$event->event_no} created.");
    }

    public function show(CateringEvent $cateringEvent)
    {
        $cateringEvent->load([
            'customer',
            'branch',
            'estimates.lines',
            'currentEstimate.lines.product.cateringProfile',
            // The line's own copy of the dish's blocks — what it was priced from,
            // which the dish itself may no longer agree with.
            'currentEstimate.lines.costBlocks',
            // KASHIF-CATERING-INSTRUCTIONS-1: the managed selections per line.
            'currentEstimate.lines.managedInstructions',
            'advances.paymentMethod',
            'refunds.paymentMethod',
            'productionReleases',
            'finalInvoice',
        ]);

        $paymentMethods = \App\Models\Tenant\PaymentMethod::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $units = \App\Models\Tenant\Unit::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);

        // KASHIF-CATERING-NO-RELOAD-2: the Edit Event offcanvas shares the
        // standalone form's fields, so it needs the same supporting data.
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $bookedDates = $this->bookedDates();

        // KASHIF-CATERING-INSTRUCTIONS-1: the active vocabulary for the builder.
        $activeInstructions = \App\Models\Tenant\CateringInstruction::active()->ordered()->get(['id', 'label', 'label_ur', 'sort_order']);

        // Catering profile defaults (rate/unit/Urdu label) keyed by product for the builder.
        $profileMap = \App\Models\Tenant\CateringProductProfile::with(['product.translations'])
            ->where('catering_enabled', true)
            ->get()
            ->mapWithKeys(fn ($profile) => [$profile->product_id => [
                'rate' => (float) ($profile->default_catering_rate ?? 0),
                'unit_id' => $profile->default_quote_unit_id,
                'minimum_qty' => (float) ($profile->minimum_qty ?? 0),
                'pricing_mode' => $profile->pricing_mode,
                'name_ur' => optional($profile->product->translations->firstWhere('language_code', 'ur'))->name,
                'instructions' => $profile->instructions,
            ]]);

        // CATERING-V1-CLOSURE-1 (§2): costing readiness for the current estimate —
        // send/confirm fail closed server-side; the panel shows why beforehand.
        // KASHIF-CATERING-COSTING-SOURCE-1: through the dispatcher, so the panel
        // and the server-side send/confirm gate can never reach different
        // verdicts about the same estimate.
        $costingReadiness = null;
        if ($cateringEvent->currentEstimate && $cateringEvent->currentEstimate->lines->isNotEmpty()) {
            $costingReadiness = app(\App\Services\Catering\CateringEstimateCostingService::class)
                ->readiness($cateringEvent->currentEstimate);
        }

        // KASHIF-CATERING-PRODUCT-UX-1 (item 7) — destinations for sending a
        // quotation or invoice straight to a printer. Any active printer, not
        // the catering KOT mappings: those route kitchen sheets by station, and
        // a customer document has no station.
        $printers = \App\Models\Tenant\Printer::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'paper_size']);

        // KASHIF-CATERING-CUSTOMER-CREDIT-1: where this booking stands
        // financially, and how it got there. Computed once, by the one service
        // that owns the answer, so the summary and the statement cannot disagree
        // and no screen works it out for itself again.
        $finance = app(\App\Services\Catering\CateringFinancialPositionService::class);

        return view('tenant.catering.events.show', [
            'event' => $cateringEvent,
            'units' => $units,
            'branches' => $branches,
            'bookedDates' => $bookedDates,
            'activeInstructions' => $activeInstructions,
            'profileMap' => $profileMap,
            'paymentMethods' => $paymentMethods,
            'costingReadiness' => $costingReadiness,
            'printers' => $printers,
            'position' => $finance->position($cateringEvent),
            'headline' => $finance->headline($cateringEvent),
            'ledger' => $finance->ledger($cateringEvent),
        ]);
    }

    public function edit(CateringEvent $cateringEvent)
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.catering.events.form', [
            'event' => $cateringEvent,
            'branches' => $branches,
            'bookedDates' => $this->bookedDates(),
        ]);
    }

    public function update(Request $request, CateringEvent $cateringEvent)
    {
        $data = $this->validated($request);

        try {
            $this->estimates->updateEvent($cateringEvent, $data);
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'errors' => ['event' => [$e->getMessage()]]], 422);
            }

            return back()->withErrors(['event' => $e->getMessage()])->withInput();
        }

        // KASHIF-CATERING-NO-RELOAD-2: the offcanvas closes and re-renders the
        // workspace in place from this answer; nothing navigates.
        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => url('/catering/events/'.$cateringEvent->id),
                'event_id' => $cateringEvent->id,
                'message' => 'Event updated.',
            ]);
        }

        return redirect()
            ->to('/catering/events/'.$cateringEvent->id)
            ->with('status', 'Event updated.');
    }

    public function confirm(CateringEvent $cateringEvent)
    {
        try {
            $this->estimates->confirmEvent($cateringEvent);
        } catch (RuntimeException $e) {
            return back()->withErrors(['event' => $e->getMessage()]);
        }

        $emailResult = app(\App\Services\Catering\CateringMailService::class)->send(
            \App\Mail\Catering\CateringCustomerMail::TYPE_BOOKING_CONFIRMED,
            $cateringEvent,
            $cateringEvent->currentEstimate,
            ['advance_total' => (float) $cateringEvent->advances()->sum('amount')],
            'confirmation',
        );

        $message = "Booking {$cateringEvent->event_no} confirmed.";
        if ($emailResult === 'sent') {
            $message .= ' Confirmation emailed to the customer.';
        }

        return back()->with('status', $message);
    }

    public function cancel(Request $request, CateringEvent $cateringEvent)
    {
        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ], [
            'cancel_reason.required' => 'Please say why this booking is being cancelled — it becomes part of the record.',
            'cancel_reason.min' => 'Give a real reason, not a placeholder.',
        ]);

        try {
            $this->estimates->cancelEvent($cateringEvent, $data['cancel_reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['event' => $e->getMessage()]);
        }

        // An advance already received stays on the ledger. Say so here rather
        // than let the operator assume cancelling undid the money as well.
        $advanceTotal = (float) $cateringEvent->advances()->sum('amount');

        $message = "Event {$cateringEvent->event_no} cancelled.";
        if ($advanceTotal > 0) {
            $message .= ' An advance of '.number_format($advanceTotal, 2).' was already received and remains'
                .' on the ledger — refunding it is a separate action.';
        }

        return back()->with('status', $message);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_name_ur' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_address' => ['nullable', 'string', 'max:2000'],
            'event_type' => ['nullable', 'string', 'max:50'],
            'booking_date' => ['required', 'date'],
            'event_date' => ['required', 'date'],
            'service_time' => ['nullable', 'date_format:H:i'],
            'venue' => ['nullable', 'string', 'max:255'],
            'pax' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
