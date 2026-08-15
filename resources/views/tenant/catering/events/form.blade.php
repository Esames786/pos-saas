@extends('layouts.app')

@section('title', $event ? 'Edit Event ' . $event->event_no : 'New Catering Event')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">{{ $event ? 'Edit Event ' . $event->event_no : 'New Catering Event' }}</h1>
    <a href="{{ $event ? url('/catering/events/' . $event->id) : url('/catering/events') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ $event ? url('/catering/events/' . $event->id) : url('/catering/events') }}">
    @csrf
    @if($event) @method('PUT') @endif

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">Customer</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Existing Customer (optional)</label>
                    <select name="customer_id" id="customer-select" class="form-select">
                        @if(old('customer_id', $event?->customer_id))
                            <option value="{{ old('customer_id', $event?->customer_id) }}" selected>
                                {{ $event?->customer?->name ?? 'Selected customer' }}
                            </option>
                        @endif
                    </select>
                    <div class="form-text">Search by name or phone; leave empty for a walk-in customer.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                    <input type="text" name="customer_name" class="form-control" required
                           value="{{ old('customer_name', $event?->customer_name) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Customer Name (Urdu, optional)</label>
                    <input type="text" name="customer_name_ur" class="form-control" dir="rtl" lang="ur"
                           value="{{ old('customer_name_ur', $event?->customer_name_ur) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="customer_phone" class="form-control"
                           value="{{ old('customer_phone', $event?->customer_phone) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="customer_email" class="form-control"
                           value="{{ old('customer_email', $event?->customer_email) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Address</label>
                    <input type="text" name="customer_address" class="form-control"
                           value="{{ old('customer_address', $event?->customer_address) }}">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">Event</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="">—</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(old('branch_id', $event?->branch_id) == $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Event Type</label>
                    <input type="text" name="event_type" class="form-control" list="event-types"
                           value="{{ old('event_type', $event?->event_type) }}">
                    <datalist id="event-types">
                        <option value="Wedding"><option value="Walima"><option value="Mehndi">
                        <option value="Corporate"><option value="Birthday"><option value="Other">
                    </datalist>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Booking Date <span class="text-danger">*</span></label>
                    <input type="date" name="booking_date" id="booking-date" class="form-control" required
                           value="{{ old('booking_date', $event?->booking_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}">
                    <div class="form-text">The day the customer booked with you.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Event Date <span class="text-danger">*</span></label>
                    <input type="date" name="event_date" id="event-date" class="form-control" required
                           value="{{ old('event_date', $event?->event_date?->format('Y-m-d')) }}">
                    {{-- Filled by JS: weekday, days away, and any clashing booking. --}}
                    <div class="form-text" id="event-date-hint"></div>
                    <div class="d-flex flex-wrap gap-1 mt-2" id="date-chips">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-days="0">Today</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-days="1">Tomorrow</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-weekend="1">This weekend</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-days="30">In a month</button>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Service Time</label>
                    <input type="time" name="service_time" id="service-time" class="form-control"
                           value="{{ old('service_time', $event?->service_time ? \Carbon\Carbon::parse($event->service_time)->format('H:i') : '') }}">
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-time="13:00">Lunch 1 PM</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-time="20:00">Dinner 8 PM</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-time="21:00">9 PM</button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Venue</label>
                    <input type="text" name="venue" class="form-control"
                           value="{{ old('venue', $event?->venue) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">PAX (guests) <span class="text-danger">*</span></label>
                    <input type="number" name="pax" class="form-control" min="0" required
                           value="{{ old('pax', $event?->pax ?? 100) }}">
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes', $event?->notes) }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">{{ $event ? 'Save Changes' : 'Create Event' }}</button>
</form>
@endsection

@push('scripts')
<script>
$(function () {
    $('#customer-select').select2({
        width: '100%',
        allowClear: true,
        placeholder: 'Search customers…',
        ajax: {
            url: '{{ url('/ajax/customers') }}',
            dataType: 'json',
            delay: 200,
            data: params => ({ q: params.term }),
            processResults: data => ({
                results: (data.customers || []).map(c => ({
                    id: c.id,
                    text: c.phone ? (c.name + ' — ' + c.phone) : c.name,
                    customer: c,
                })),
            }),
        },
    });

    $('#customer-select').on('select2:select', function (e) {
        const c = e.params.data.customer || {};
        if (c.name && !$('[name=customer_name]').val()) $('[name=customer_name]').val(c.name);
        if (c.phone) $('[name=customer_phone]').val(c.phone);
        if (c.email) $('[name=customer_email]').val(c.email);
        const addr = (c.addresses && c.addresses.length) ? c.addresses[0].address : c.legacy_address;
        if (addr) $('[name=customer_address]').val(addr);
    });
});

/**
 * KASHIF-UAT-2 — event date/time usability.
 *
 * Deliberately progressive enhancement over the native inputs rather than a
 * date-picker library: these terminals run offline in the branch, so pulling in
 * a CDN widget would break exactly where it is needed most. The native control
 * stays the source of truth; this only adds the three things a bare date field
 * cannot answer — which weekday it falls on, whether it is in the past, and
 * whether the kitchen is already booked that night.
 */
(function () {
    const eventDate   = document.getElementById('event-date');
    const bookingDate = document.getElementById('booking-date');
    const hint        = document.getElementById('event-date-hint');
    if (! eventDate || ! hint) return;

    const booked = @json($bookedDates ?? []);
    const iso = d => d.getFullYear() + '-'
        + String(d.getMonth() + 1).padStart(2, '0') + '-'
        + String(d.getDate()).padStart(2, '0');

    const today = new Date(); today.setHours(0, 0, 0, 0);

    // An event can never happen before it was booked.
    const syncMin = () => { if (bookingDate) eventDate.min = bookingDate.value || ''; };

    function render() {
        const val = eventDate.value;
        if (! val) { hint.innerHTML = ''; return; }

        const d = new Date(val + 'T00:00:00');
        if (isNaN(d)) { hint.innerHTML = ''; return; }

        const weekday = d.toLocaleDateString(undefined, { weekday: 'long' });
        const days = Math.round((d - today) / 86400000);
        const away = days === 0 ? 'today'
            : days === 1 ? 'tomorrow'
            : days > 0 ? 'in ' + days + ' days'
            : Math.abs(days) + ' days ago';

        let html = '<span class="fw-semibold">' + weekday + '</span> · ' + away;

        if (days < 0) {
            html += ' <span class="text-danger">— this date has already passed</span>';
        }

        const clashes = booked[val] || [];
        if (clashes.length) {
            const list = clashes
                .map(c => c.event_no + ' (' + c.customer + ', ' + c.pax + ' pax)')
                .join(', ');
            html += '<div class="text-warning mt-1"><i class="ti ti-alert-triangle"></i> '
                + 'Already booked: ' + list + '</div>';
        }

        hint.innerHTML = html;
    }

    // Quick-pick chips — a caterer thinks "next Saturday", not "2026-08-22".
    document.querySelectorAll('#date-chips [data-days], #date-chips [data-weekend]').forEach(btn => {
        btn.addEventListener('click', () => {
            const d = new Date(today);
            if (btn.dataset.weekend) {
                // Next Saturday; if today IS Saturday, keep today.
                d.setDate(d.getDate() + ((6 - d.getDay()) + 7) % 7);
            } else {
                d.setDate(d.getDate() + parseInt(btn.dataset.days, 10));
            }
            eventDate.value = iso(d);
            render();
        });
    });

    document.querySelectorAll('[data-time]').forEach(btn => {
        btn.addEventListener('click', () => {
            const t = document.getElementById('service-time');
            if (t) t.value = btn.dataset.time;
        });
    });

    eventDate.addEventListener('change', render);
    bookingDate?.addEventListener('change', syncMin);
    syncMin();
    render();
})();
</script>
@endpush
