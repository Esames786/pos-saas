@extends('layouts.app')

@section('title', 'Delivery Areas')

@section('content')
@php
    $single = $settings->branch_mode === 'single';
    $singleBranch = $single ? $branches->firstWhere('id', $settings->default_branch_id) : null;
    $servedCount = $zones->filter(fn ($z) => $z->is_active && $z->city === $city)->count();
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Delivery Areas</h1>
        <p class="text-muted mb-0">Tick the areas you deliver to and set the fee for each. The website shows only these areas, with their fee and delivery time.</p>
    </div>
    <div class="d-flex gap-2">
        @if($cities->count() > 1)
            <form method="GET" action="{{ url('/online-ordering/areas') }}">
                <select name="city" class="form-select" onchange="this.form.submit()">
                    @foreach($cities as $c)
                        <option value="{{ $c }}" @selected($c === $city)>{{ $c }}</option>
                    @endforeach
                </select>
            </form>
        @endif
        @can('tenant.online-ordering.settings.edit')
            <a href="{{ url('/online-ordering/settings') }}" class="btn btn-outline-primary"><i class="ti ti-settings me-1"></i>Website settings</a>
        @endcan
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

@if($single)
    <div class="alert alert-info">
        All areas are served by <strong>{{ $singleBranch?->name ?? 'the website branch (not chosen yet)' }}</strong> — "One branch for everything" is set in Website settings.
    </div>
@endif

<form method="POST" action="{{ url('/online-ordering/areas') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="city" value="{{ $city }}">

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><strong>{{ $city }}</strong> · {{ $areas->count() }} areas · <span id="served-count">{{ $servedCount }}</span> served</span>
            <label class="form-check mb-0">
                <input type="checkbox" class="form-check-input" id="serve-all">
                <span class="form-check-label">Tick all</span>
            </label>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th style="width: 60px">Serve</th>
                    <th>Area</th>
                    @unless($single)<th>Branch</th>@endunless
                    <th class="text-end">Fee</th>
                    <th class="text-end">Min. order</th>
                    <th class="text-end">Free above</th>
                    <th class="text-end">Time (min)</th>
                    <th class="text-end">Places</th>
                </tr>
                </thead>
                <tbody>
                @foreach($areas as $area)
                    @php
                        $z = $zones->get($area->id);
                        $key = "zones.{$area->id}";
                        $serving = (bool) old("$key.serve", $z?->is_active);
                    @endphp
                    <tr>
                        <td><input type="checkbox" value="1" name="zones[{{ $area->id }}][serve]" class="form-check-input js-serve" {{ $serving ? 'checked' : '' }}></td>
                        <td>{{ $area->name }}</td>
                        @unless($single)
                            <td>
                                <select name="zones[{{ $area->id }}][branch_id]" class="form-select form-select-sm">
                                    @foreach($branches as $b)
                                        <option value="{{ $b->id }}" @selected((int) old("$key.branch_id", $z?->branch_id ?? $branches->first()?->id) === $b->id)>{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                        @endunless
                        <td><input type="number" step="1" min="0" name="zones[{{ $area->id }}][fee]" class="form-control form-control-sm text-end" style="width: 100px; margin-left: auto;" value="{{ old("$key.fee", $z ? (float) $z->fee : '') }}"></td>
                        <td><input type="number" step="1" min="0" name="zones[{{ $area->id }}][min_order]" class="form-control form-control-sm text-end" style="width: 110px; margin-left: auto;" value="{{ old("$key.min_order", $z?->min_order !== null ? (float) $z->min_order : '') }}"></td>
                        <td><input type="number" step="1" min="0" name="zones[{{ $area->id }}][free_delivery_above]" class="form-control form-control-sm text-end" style="width: 110px; margin-left: auto;" value="{{ old("$key.free_delivery_above", $z?->free_delivery_above !== null ? (float) $z->free_delivery_above : '') }}"></td>
                        <td><input type="number" step="1" min="5" max="240" name="zones[{{ $area->id }}][eta_minutes]" class="form-control form-control-sm text-end" style="width: 90px; margin-left: auto;" value="{{ old("$key.eta_minutes", $z?->eta_minutes) }}" placeholder="{{ $settings->default_eta_minutes }}"></td>
                        <td class="text-end text-nowrap">
                            {{-- Societies / neighbourhoods inside this area live on the Delivery Places screen. --}}
                            @if($z)
                                <a href="{{ url('/online-ordering/places?zone='.$z->id) }}" class="small">{{ (int) ($placeCounts[$z->id] ?? 0) }} {{ (int) ($placeCounts[$z->id] ?? 0) === 1 ? 'place' : 'places' }}</a>
                            @else
                                <span class="small text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @can('tenant.online-ordering.areas.update')
            <div class="card-footer">
                <button class="btn btn-primary">Save delivery areas</button>
                <span class="text-muted small ms-2">Unticking an area stops deliveries there but keeps its fee for later. Societies inside each area: <a href="{{ url('/online-ordering/places') }}">Delivery Places</a>.</span>
            </div>
        @endcan
    </div>
</form>

<script>
(function () {
    var all = document.getElementById('serve-all');
    var count = document.getElementById('served-count');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.js-serve'));
    function recount() {
        count.textContent = boxes.filter(function (b) { return b.checked; }).length;
    }
    boxes.forEach(function (b) { b.addEventListener('change', recount); });
    if (all) {
        all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
            recount();
        });
    }
})();
</script>
@endsection
