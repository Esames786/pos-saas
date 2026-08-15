@extends('layouts.app')

@section('title', 'Catering Events')

@section('content')
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
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    @foreach(\App\Models\Tenant\CateringEvent::STATUSES as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Filter</button>
                <a href="{{ url('/catering/events') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Event #</th>
                        <th>Customer</th>
                        <th>Event Date</th>
                        <th>Venue</th>
                        <th class="text-end">PAX</th>
                        <th class="text-end">Estimate</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                    <tr>
                        <td><a href="{{ url('/catering/events/' . $event->id) }}">{{ $event->event_no }}</a></td>
                        <td>
                            {{ $event->customer_name }}
                            @if($event->customer_name_ur)
                                <div class="text-muted" dir="rtl" lang="ur">{{ $event->customer_name_ur }}</div>
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
                        <td class="text-end">
                            @if($event->currentEstimate)
                                {{ number_format($event->currentEstimate->grand_total, 2) }}
                                <span class="text-muted">Q{{ $event->currentEstimate->version_no }}</span>
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
                        <td class="text-end">
                            <a href="{{ url('/catering/events/' . $event->id) }}" class="btn btn-sm btn-light">Open</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No catering events yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($events->hasPages())
        <div class="card-footer">{{ $events->links() }}</div>
    @endif
</div>
@endsection
