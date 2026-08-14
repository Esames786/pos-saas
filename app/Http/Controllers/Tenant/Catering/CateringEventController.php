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

        $query = CateringEvent::with(['currentEstimate'])
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

        return view('tenant.catering.events.index', compact('events', 'buckets', 'filter', 'status'));
    }

    public function create()
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.catering.events.form', ['event' => null, 'branches' => $branches]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $event = $this->estimates->createEvent($data, $request->user()?->id);

        return redirect()
            ->route('tenant.catering.events.show', $event)
            ->with('status', "Event {$event->event_no} created.");
    }

    public function show(CateringEvent $cateringEvent)
    {
        $cateringEvent->load([
            'customer',
            'branch',
            'estimates.lines',
            'currentEstimate.lines.product.cateringProfile',
            'advances.paymentMethod',
            'productionReleases',
            'finalInvoice',
        ]);

        $paymentMethods = \App\Models\Tenant\PaymentMethod::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $units = \App\Models\Tenant\Unit::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);

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
        $costingReadiness = null;
        if ($cateringEvent->currentEstimate && $cateringEvent->currentEstimate->lines->isNotEmpty()) {
            $costingReadiness = app(\App\Services\Catering\CateringRecipeCostingService::class)
                ->readiness($cateringEvent->currentEstimate);
        }

        return view('tenant.catering.events.show', [
            'event' => $cateringEvent,
            'units' => $units,
            'profileMap' => $profileMap,
            'paymentMethods' => $paymentMethods,
            'costingReadiness' => $costingReadiness,
        ]);
    }

    public function edit(CateringEvent $cateringEvent)
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.catering.events.form', ['event' => $cateringEvent, 'branches' => $branches]);
    }

    public function update(Request $request, CateringEvent $cateringEvent)
    {
        $data = $this->validated($request);

        try {
            $this->estimates->updateEvent($cateringEvent, $data);
        } catch (RuntimeException $e) {
            return back()->withErrors(['event' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('tenant.catering.events.show', $cateringEvent)
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

    public function cancel(CateringEvent $cateringEvent)
    {
        try {
            $this->estimates->cancelEvent($cateringEvent);
        } catch (RuntimeException $e) {
            return back()->withErrors(['event' => $e->getMessage()]);
        }

        return back()->with('status', "Event {$cateringEvent->event_no} cancelled.");
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
