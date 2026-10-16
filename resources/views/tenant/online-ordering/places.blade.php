@extends('layouts.app')

@section('title', 'Delivery Places')

@section('content')
@php
    use App\Http\Controllers\Tenant\OnlineOrdering\DeliveryPlaceController as P;
    $sourceBadge = ['osm' => 'bg-info-subtle text-info-emphasis', 'platform' => 'bg-secondary-subtle text-secondary-emphasis',
        'manual' => 'bg-primary-subtle text-primary-emphasis', 'learned' => 'bg-success-subtle text-success-emphasis'];
    $chip = fn (array $q) => url('/online-ordering/places?'.http_build_query($q));
    $canEdit = auth('tenant')->user()?->can('tenant.online-ordering.places.update');
@endphp

{{-- ONLINE-ORDERING-1 — societies / neighbourhoods inside the delivery areas, one row each (owner, 16 Oct). The website
     uses them to find a customer's area from their location; later it also learns new ones from paid orders. --}}
<div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Delivery Places</h1>
        <p class="text-muted mb-0" style="max-width: 72ch">Societies and neighbourhoods inside your delivery areas. When a customer shares their location, the website uses these to find their area and to suggest places near them. The delivery fee always comes from the area.</p>
    </div>
    <a href="{{ url('/online-ordering/areas') }}" class="btn btn-outline-primary"><i class="ti ti-map-pin me-1"></i>Delivery Areas &amp; fees</a>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

{{-- Summary: tap a number to filter --}}
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ $chip([]) }}" class="btn btn-sm {{ ! array_filter(array_diff_key($filters, ['status' => 1])) && $filters['status'] === 'active' ? 'btn-dark' : 'btn-outline-dark' }}">All places <span class="badge bg-light text-dark ms-1">{{ $counts['active'] }}</span></a>
    <a href="{{ $chip(['source' => 'osm']) }}" class="btn btn-sm btn-outline-secondary">From map <span class="badge bg-light text-dark ms-1">{{ $counts['osm'] + $counts['platform'] }}</span></a>
    <a href="{{ $chip(['source' => 'manual']) }}" class="btn btn-sm btn-outline-secondary">Added by you <span class="badge bg-light text-dark ms-1">{{ $counts['manual'] }}</span></a>
    <a href="{{ $chip(['source' => 'learned']) }}" class="btn btn-sm btn-outline-secondary">Learned from orders <span class="badge bg-light text-dark ms-1">{{ $counts['learned'] }}</span></a>
    @if($counts['suggested'])
        <a href="{{ $chip(['status' => 'suggested']) }}" class="btn btn-sm btn-warning">Waiting for approval <span class="badge bg-light text-dark ms-1">{{ $counts['suggested'] }}</span></a>
    @endif
    <a href="{{ $chip(['status' => 'hidden']) }}" class="btn btn-sm btn-outline-secondary">Hidden <span class="badge bg-light text-dark ms-1">{{ $counts['hidden'] }}</span></a>
</div>

@can('tenant.online-ordering.places.store')
<div class="card mb-3">
    <div class="card-body">
        <h5 class="mb-1">Add a place</h5>
        <p class="text-muted small mb-3">A society or landmark your customers know, e.g. “Saima Arabian Villas”. It is found on the map automatically; paste a location only if it isn't.</p>
        <form method="POST" action="{{ url('/online-ordering/places') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-4">
                <label class="form-label" for="place-name">Place name</label>
                <input type="text" name="name" id="place-name" class="form-control" maxlength="190" required value="{{ old('name') }}" placeholder="e.g. Rufi Lake Drive">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="place-zone">Delivery area</label>
                <select name="zone_id" id="place-zone" class="form-select" required>
                    <option value="">— Choose —</option>
                    @foreach($zones->where('is_active', true) as $z)
                        <option value="{{ $z->id }}" @selected((int) old('zone_id', $filters['zone'] ?? 0) === $z->id)>{{ $z->area_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="place-location">Map location <span class="text-muted">(optional)</span></label>
                <input type="text" name="location" id="place-location" class="form-control" maxlength="500" value="{{ old('location') }}" placeholder="24.9521, 67.1412 or a Google Maps link">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary"><i class="ti ti-plus me-1"></i>Add place</button>
            </div>
        </form>
    </div>
</div>
@endcan

<div class="card">
    <div class="card-header">
        <form method="GET" action="{{ url('/online-ordering/places') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="search" name="q" class="form-control form-control-sm" value="{{ $filters['q'] ?? '' }}" placeholder="Search a place…" aria-label="Search a place">
            </div>
            <div class="col-md-3">
                <select name="zone" class="form-select form-select-sm" aria-label="Area">
                    <option value="">All areas</option>
                    @foreach($zones as $z)
                        <option value="{{ $z->id }}" @selected((int) ($filters['zone'] ?? 0) === $z->id)>{{ $z->area_name }}{{ $z->is_active ? '' : ' (not served)' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="source" class="form-select form-select-sm" aria-label="Source">
                    <option value="">All sources</option>
                    @foreach(P::SOURCES as $k => $label)
                        <option value="{{ $k }}" @selected(($filters['source'] ?? '') === $k)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="kind" class="form-select form-select-sm" aria-label="Type">
                    <option value="">All types</option>
                    @foreach(P::KINDS as $k => $label)
                        <option value="{{ $k }}" @selected(($filters['kind'] ?? '') === $k)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-dark flex-grow-1">Filter</button>
                <a href="{{ url('/online-ordering/places') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Place</th>
                <th>Delivery area</th>
                <th>Source</th>
                <th>Type <i class="ti ti-info-circle text-muted" title="Sure: when a customer's address has this name, the website says “You are in <area>”. Nearby guess: only helps pick the nearest area — never shown as sure."></i></th>
                <th class="text-center">Map</th>
                @if($canEdit)<th class="text-end">Actions</th>@endif
            </tr>
            </thead>
            <tbody>
            @forelse($rows as $r)
                @php $moved = $r['origin'] === 'platform' && $r['home_zone_id'] && $r['zone_id'] !== $r['home_zone_id']; @endphp
                <tr>
                    <td>
                        <span class="fw-semibold">{{ $r['name'] }}</span>
                        @if($r['orders_count'] > 0)<div class="small text-muted">{{ $r['orders_count'] }} {{ $r['orders_count'] === 1 ? 'order' : 'orders' }}</div>@endif
                    </td>
                    <td>
                        {{ $zoneNames[$r['zone_id']] ?? '—' }}
                        @if($moved)<div class="small text-muted">moved from {{ $zoneNames[$r['home_zone_id']] ?? '—' }}</div>@endif
                    </td>
                    <td><span class="badge {{ $sourceBadge[$r['source']] ?? 'bg-light text-dark' }}">{{ P::SOURCES[$r['source']] ?? ucfirst($r['source']) }}</span></td>
                    <td><span class="badge {{ $r['kind'] === 'sure' ? 'bg-success' : 'bg-light text-dark border' }}">{{ P::KINDS[$r['kind']] ?? $r['kind'] }}</span></td>
                    <td class="text-center">
                        @if($r['lat'] !== null && $r['lng'] !== null)
                            <a href="https://www.google.com/maps?q={{ $r['lat'] }},{{ $r['lng'] }}" target="_blank" rel="noopener" title="Open on the map" aria-label="Open {{ $r['name'] }} on the map"><i class="ti ti-map-pin"></i></a>
                        @else
                            <span class="text-muted small" title="Not found on the map — matched by name only">name only</span>
                        @endif
                    </td>
                    @if($canEdit)
                    <td class="text-end text-nowrap">
                        @if($r['status'] === 'active')
                            <form method="POST" action="{{ url('/online-ordering/places/'.$r['origin'].'/'.$r['id']) }}" class="d-inline-flex gap-1 align-items-center">
                                @csrf @method('PUT')
                                <input type="hidden" name="action" value="move">
                                {{-- Only the current area here; the full list is filled in when the box is opened (page stays light). --}}
                                <select name="zone_id" class="form-select form-select-sm js-zone-pick" style="width: 190px" aria-label="Move {{ $r['name'] }} to another area" required>
                                    <option value="{{ $r['zone_id'] }}" selected>{{ $zoneNames[$r['zone_id']] ?? '—' }}</option>
                                </select>
                                <button class="btn btn-sm btn-outline-primary">Move</button>
                            </form>
                            <form method="POST" action="{{ url('/online-ordering/places/'.$r['origin'].'/'.$r['id']) }}" class="d-inline">
                                @csrf @method('PUT')
                                <input type="hidden" name="action" value="hide">
                                <button class="btn btn-sm btn-outline-secondary" title="The website stops using this place">Hide</button>
                            </form>
                            @if($r['origin'] === 'restaurant')
                                <form method="POST" action="{{ url('/online-ordering/places/restaurant/'.$r['id']) }}" class="d-inline" onsubmit="return confirm('Remove “{{ addslashes($r['name']) }}”?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            @endif
                        @else
                            <form method="POST" action="{{ url('/online-ordering/places/'.$r['origin'].'/'.$r['id']) }}" class="d-inline">
                                @csrf @method('PUT')
                                <input type="hidden" name="action" value="{{ $r['status'] === 'suggested' ? 'approve' : 'show' }}">
                                <button class="btn btn-sm btn-outline-success">{{ $r['status'] === 'suggested' ? 'Approve' : 'Show again' }}</button>
                            </form>
                        @endif
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canEdit ? 6 : 5 }}" class="text-center text-muted py-4">No places match. Add one above, or change the filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($rows->hasPages())
        <div class="card-footer">{{ $rows->links() }}</div>
    @endif
</div>
<template id="zone-options">@foreach($zones->where('is_active', true) as $z)<option value="{{ $z->id }}">{{ $z->area_name }}</option>@endforeach</template>
<script>
(function () {
    var all = document.getElementById('zone-options');
    document.querySelectorAll('.js-zone-pick').forEach(function (sel) {
        function fill() {
            if (sel.dataset.filled) { return; }
            var cur = sel.value;
            sel.innerHTML = all.innerHTML;
            sel.value = cur;
            sel.dataset.filled = '1';
        }
        ['focus', 'mousedown', 'touchstart'].forEach(function (ev) { sel.addEventListener(ev, fill); });
    });
})();
</script>
@endsection
