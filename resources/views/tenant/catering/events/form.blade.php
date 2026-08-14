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
                    <input type="date" name="booking_date" class="form-control" required
                           value="{{ old('booking_date', $event?->booking_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Event Date <span class="text-danger">*</span></label>
                    <input type="date" name="event_date" class="form-control" required
                           value="{{ old('event_date', $event?->event_date?->format('Y-m-d')) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Service Time</label>
                    <input type="time" name="service_time" class="form-control"
                           value="{{ old('service_time', $event?->service_time ? \Carbon\Carbon::parse($event->service_time)->format('H:i') : '') }}">
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
</script>
@endpush
