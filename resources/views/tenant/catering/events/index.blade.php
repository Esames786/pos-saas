@extends('layouts.app')

@section('title', 'Catering Events')

@section('content')
@php $q = $q ?? ''; @endphp
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Catering Events &amp; Estimates</h1>
    @can('tenant.catering.events.create')
        <a href="{{ url('/catering/events/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1"></i>New Event
        </a>
    @endcan
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', ['manages' => 'Every booking and the quotations attached to it.', 'managesUr' => 'تمام بکنگ اور ان کے تخمینے۔', 'reversible' => 'safe', 'note' => 'Viewing or filtering this list changes nothing. Money and stock only move from inside an individual event.', 'noteUr' => 'یہ فہرست دیکھنے سے کچھ تبدیل نہیں ہوتا۔'])
@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="row g-3 mb-4">
    @php
        $bucketCards = [
            'today' => ['label' => 'Today', 'icon' => 'ti-calendar-bolt', 'color' => 'danger'],
            'tomorrow' => ['label' => 'Tomorrow', 'icon' => 'ti-calendar-up', 'color' => 'warning'],
            'week' => ['label' => 'Next 7 Days', 'icon' => 'ti-calendar-week', 'color' => 'primary'],
            'unconfirmed' => ['label' => 'Unconfirmed', 'icon' => 'ti-help-circle', 'color' => 'secondary'],
        ];
    @endphp
    @foreach($bucketCards as $key => $card)
        <div class="col-6 col-lg-3">
            <a href="{{ url('/catering/events?filter=' . $key) }}" class="text-decoration-none">
                <div class="card border-{{ $filter === $key ? $card['color'] : 'light' }} h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <i class="ti {{ $card['icon'] }} fs-24 text-{{ $card['color'] }}"></i>
                        <div>
                            <div class="fs-24 fw-bold">{{ $buckets[$key] }}</div>
                            <div class="text-muted">{{ $card['label'] }}</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endforeach
</div>

<div class="card">
    <div class="card-body pb-0">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                {{-- KASHIF-CATERING-OPERATOR-UI-1: the fields an operator actually
                     holds when the phone rings — number, name, phone, venue. --}}
                <input type="search" name="q" value="{{ $q }}" class="form-control"
                       placeholder="Search booking #, customer, phone, venue or address…">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    @foreach(\App\Models\Tenant\CateringEvent::STATUSES as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Search</button>
                <a href="{{ url('/catering/events') }}" class="btn btn-light">Clear</a>
            </div>
            <div class="col text-end">
                {{-- Bulk documents for the ticked bookings. GET pages that compose
                     A4 print runs — nothing moves, posts, or changes state. --}}
                <div class="btn-group" id="bulk-print-group" style="display:none">
                    @can('tenant.catering.documents.bulk-quotations')
                        <button type="button" class="btn btn-outline-secondary bulk-print" data-url="{{ url('/catering/documents/bulk/quotations') }}">
                            <i class="ti ti-printer me-1"></i>Quotations
                        </button>
                    @endcan
                    @can('tenant.catering.documents.bulk-kitchen-sheets')
                        <button type="button" class="btn btn-outline-secondary bulk-print" data-url="{{ url('/catering/documents/bulk/kitchen-sheets') }}">
                            <i class="ti ti-chef-hat me-1"></i>Kitchen Sheets
                        </button>
                    @endcan
                    @can('tenant.catering.documents.bulk-address-sheet')
                        <button type="button" class="btn btn-outline-secondary bulk-print" data-url="{{ url('/catering/documents/bulk/address-sheet') }}">
                            <i class="ti ti-map-pin me-1"></i>Address Sheet
                        </button>
                    @endcan
                </div>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:30px" class="ps-3">
                            <input type="checkbox" class="form-check-input" id="select-all-events"
                                   title="Select every booking on this page">
                        </th>
                        <th>Event #</th>
                        <th>Customer</th>
                        <th>Event Date</th>
                        <th>Venue</th>
                        <th class="text-end">PAX</th>
                        <th>Quotation</th>
                        <th class="text-end">Position</th>
                        <th>Status</th>
                        <th>Next Action</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                    <tr>
                        <td class="ps-3">
                            <input type="checkbox" class="form-check-input event-check" value="{{ $event->id }}">
                        </td>
                        <td><a href="{{ url('/catering/events/' . $event->id) }}">{{ $event->event_no }}</a></td>
                        <td>
                            {{ $event->customer_name }}
                            @if($event->customer_phone)
                                <div class="text-muted fs-12"><i class="ti ti-phone me-1"></i>{{ $event->customer_phone }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $event->event_date->format('d M Y') }}
                            @if($event->service_time)
                                <span class="text-muted">{{ \Carbon\Carbon::parse($event->service_time)->format('g:i A') }}</span>
                            @endif
                        </td>
                        <td>{{ $event->venue ?? '—' }}</td>
                        <td class="text-end">{{ number_format($event->pax) }}</td>
                        <td>
                            @if($event->currentEstimate)
                                <div>{{ number_format($event->currentEstimate->grand_total, 2) }}</div>
                                <div class="fs-12 text-muted">Q{{ $event->currentEstimate->version_no }} · {{ ucfirst($event->currentEstimate->status) }}</div>
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-end">
                            {{-- Compact money position from the same inputs the
                                 workspace's position service reads: what was billed
                                 (invoice once issued, else the current quotation)
                                 against what is held net of refunds. --}}
                            @php
                                $received = (float) ($event->advances_sum ?? 0) - (float) ($event->refunds_sum ?? 0);
                                $billed = $event->finalInvoice
                                    ? null // the invoice's own frozen balance is the authority
                                    : (float) ($event->currentEstimate?->grand_total ?? 0);
                                $balance = $event->finalInvoice
                                    ? (float) $event->finalInvoice->balance_due
                                    : $billed - $received;
                            @endphp
                            @if($received > 0 || ($event->currentEstimate && (float) $event->currentEstimate->grand_total > 0))
                                <div class="fs-12 text-muted">recv {{ number_format($received, 2) }}</div>
                                @if($balance > 0.009)
                                    <div class="fw-semibold text-danger">due {{ number_format($balance, 2) }}</div>
                                @elseif($balance < -0.009)
                                    <div class="fw-semibold text-warning-emphasis">credit {{ number_format(-$balance, 2) }}</div>
                                @else
                                    <div class="fw-semibold text-success">settled</div>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @php
                                $badge = match($event->status) {
                                    'confirmed', 'production_ready', 'released' => 'success',
                                    'quoted' => 'info',
                                    'completed', 'closed' => 'dark',
                                    'cancelled' => 'danger',
                                    default => 'secondary',
                                };
                            @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucwords(str_replace('_', ' ', $event->status)) }}</span>
                        </td>
                        <td class="fs-12">{{ app(\App\Services\Catering\CateringCalendarService::class)->nextAction($event) }}</td>
                        <td class="text-end">
                            <a href="{{ url('/catering/events/' . $event->id) }}" class="btn btn-sm btn-light">Open</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="11" class="text-center text-muted py-4">
                        {{ $q !== '' ? 'Nothing matches that search.' : 'No catering events yet.' }}
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($events->hasPages())
        <div class="card-footer">{{ $events->links() }}</div>
    @endif
</div>

@push('scripts')
<script>
(function () {
    // KASHIF-CATERING-OPERATOR-UI-1 — bulk document selection. Ticking rows only
    // reveals print buttons; the pages they open are read-only compositions.
    var group = document.getElementById('bulk-print-group');
    if (!group) return;

    var refresh = function () {
        var any = document.querySelectorAll('.event-check:checked').length > 0;
        group.style.display = any ? '' : 'none';
    };

    document.addEventListener('change', function (e) {
        if (e.target.id === 'select-all-events') {
            document.querySelectorAll('.event-check').forEach(function (c) { c.checked = e.target.checked; });
        }
        if (e.target.id === 'select-all-events' || e.target.classList.contains('event-check')) refresh();
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.bulk-print');
        if (!btn) return;
        var ids = Array.from(document.querySelectorAll('.event-check:checked')).map(function (c) { return c.value; });
        if (!ids.length) return;
        var url = new URL(btn.getAttribute('data-url'), window.location.origin);
        ids.forEach(function (id) { url.searchParams.append('ids[]', id); });
        window.open(url.toString(), '_blank');
    });
})();
</script>
@endpush
@endsection
