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
        <p class="text-muted mb-0">Switch on the areas you deliver to. The delivery fee comes from each area, or from the distance to your branch — you choose below.</p>
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

    {{-- DELIVERY-RADIUS-1 (owner, 16 Oct): the fee by distance from the branch; beyond the last band = no delivery. --}}
    <div class="card mb-3" id="delivery-fee">
        <div class="card-body">
            <h5 class="mb-1">Delivery fee</h5>
            <p class="text-muted small mb-3">By distance, the website measures a straight line from your branch to the customer's map pin (or to the middle of their area when they don't share it), and the checkout charges the same.</p>
            <div class="d-flex flex-wrap gap-4 mb-3">
                <label class="form-check">
                    <input class="form-check-input" type="radio" name="fee_mode" value="area" @checked(old('fee_mode', $feeMode) === 'area')>
                    <span class="form-check-label"><strong>Each area's own fee</strong><br><span class="small text-muted">The Fee column in the table below.</span></span>
                </label>
                <label class="form-check">
                    <input class="form-check-input" type="radio" name="fee_mode" value="distance" @checked(old('fee_mode', $feeMode) === 'distance')>
                    <span class="form-check-label"><strong>By distance from the branch</strong><br><span class="small text-muted">The bands below. Farther than the last band: no delivery.</span></span>
                </label>
            </div>
            @foreach($branches as $b)
                @php
                    $loc = $branchLocs->get($b->id);
                    $rows = ($bands->get($b->id) ?? collect())->map(fn ($x) => ['up_to_km' => rtrim(rtrim(number_format($x->up_to_km, 2, '.', ''), '0'), '.'), 'fee' => (int) $x->fee])->values()->all();
                    $rows = old("bands.{$b->id}", $rows);
                    $rows = array_values($rows);
                    $target = max(3, count($rows) + 1);   // always one empty row to add a band
                    while (count($rows) < $target) { $rows[] = ['up_to_km' => '', 'fee' => '']; }
                @endphp
                <div class="border rounded p-3 mb-2">
                    <div class="fw-semibold mb-2">{{ $b->name }}{{ $b->address ? ' — '.$b->address : '' }}</div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small" for="loc-{{ $b->id }}">Branch location on the map</label>
                            <input type="text" class="form-control form-control-sm" id="loc-{{ $b->id }}" name="branches[{{ $b->id }}][location]" maxlength="500"
                                   value="{{ old("branches.{$b->id}.location", $loc?->hasPoint() ? $loc->lat.', '.$loc->lng : '') }}" placeholder="24.9230, 67.0893 — or a Google Maps link that shows the numbers">
                            @if($loc?->hasPoint())
                                <a class="small" href="https://www.google.com/maps?q={{ $loc->lat }},{{ $loc->lng }}" target="_blank" rel="noopener">Check it on the map ↗</a>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" for="dir-{{ $b->id }}">"Get directions" link for pick-up <span class="text-muted">(optional)</span></label>
                            <input type="url" class="form-control form-control-sm" id="dir-{{ $b->id }}" name="branches[{{ $b->id }}][map_url]" maxlength="500"
                                   value="{{ old("branches.{$b->id}.map_url", $loc?->map_url) }}" placeholder="Your Google Maps share link">
                        </div>
                    </div>
                    <div class="small text-muted mb-1">Distance bands</div>
                    <div class="d-flex flex-column gap-1 js-bands" data-branch="{{ $b->id }}">
                        @foreach($rows as $i => $r)
                            <div class="d-flex align-items-center gap-2 small">
                                <span>Up to</span>
                                <input type="number" step="0.1" min="0.1" max="100" class="form-control form-control-sm text-end" style="width: 90px" name="bands[{{ $b->id }}][{{ $i }}][up_to_km]" value="{{ $r['up_to_km'] ?? '' }}" aria-label="Up to km">
                                <span>km</span>
                                <span class="text-muted">→ Rs</span>
                                <input type="number" step="1" min="0" class="form-control form-control-sm text-end" style="width: 100px" name="bands[{{ $b->id }}][{{ $i }}][fee]" value="{{ $r['fee'] ?? '' }}" aria-label="Fee">
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="btn btn-link btn-sm px-0 js-add-band" data-branch="{{ $b->id }}">+ Add a band</button>
                    <span class="small text-muted ms-2">Leave a row empty to drop it.</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><strong>{{ $city }}</strong> · {{ $areas->count() }} areas · <span id="served-count">{{ $servedCount }}</span> active</span>
            <label class="form-check form-switch mb-0">
                <input type="checkbox" class="form-check-input" id="serve-all">
                <span class="form-check-label">All on</span>
            </label>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th style="width: 70px">Active</th>
                    <th>Area</th>
                    @unless($single)<th>Branch</th>@endunless
                    <th class="text-end">{{ $feeMode === 'distance' ? 'Area fee (not used)' : 'Fee' }}</th>
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
                        <td><div class="form-check form-switch mb-0"><input type="checkbox" role="switch" value="1" name="zones[{{ $area->id }}][serve]" class="form-check-input js-serve" aria-label="Deliver to {{ $area->name }}" {{ $serving ? 'checked' : '' }}></div></td>
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
                        <td class="text-end">
                            <input type="number" step="1" min="0" name="zones[{{ $area->id }}][fee]" class="form-control form-control-sm text-end" style="width: 100px; margin-left: auto;" value="{{ old("$key.fee", $z ? (float) $z->fee : '') }}">
                            @if($z && ($q = $byDistance->get($z->id)) && $q['km'] !== null)
                                <div class="small {{ $q['ok'] ? 'text-muted' : 'text-danger' }} text-nowrap" title="From the middle of the area — each customer pays by their own pin">{{ $q['ok'] ? 'by distance Rs '.number_format($q['fee']) : 'too far' }} · {{ $q['km'] }} km</div>
                            @endif
                        </td>
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
                <span class="text-muted small ms-2">Switching an area off stops deliveries there (the website says so) but keeps its fee for later. Societies inside each area: <a href="{{ url('/online-ordering/places') }}">Delivery Places</a>.</span>
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
    // Distance bands: one more empty row.
    document.querySelectorAll('.js-add-band').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var box = document.querySelector('.js-bands[data-branch="' + btn.dataset.branch + '"]');
            var row = box.lastElementChild.cloneNode(true);
            var i = box.children.length;
            row.querySelectorAll('input').forEach(function (inp) {
                inp.value = '';
                inp.name = inp.name.replace(/\[\d+\](\[(up_to_km|fee)\])$/, '[' + i + ']$1');
            });
            box.appendChild(row);
            row.querySelector('input').focus();
        });
    });
})();
</script>
@endsection
