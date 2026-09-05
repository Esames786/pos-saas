@extends('layouts.app')

@section('title', 'Shifts')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Shifts</h1>
        <p class="fw-medium">View and manage terminal shifts.</p>
    </div>

    <div class="d-flex gap-2">
        @can('tenant.shifts.close')
            <a href="{{ url('/shifts-close-branch') }}" class="btn btn-outline-danger">
                <i class="ti ti-lock me-1" aria-hidden="true"></i>Close Branch
            </a>
        @endcan
        @can('tenant.shifts.create')
            <a href="{{ url('/shifts/open') }}" class="btn btn-primary">
                <i class="ti ti-plus me-1" aria-hidden="true"></i>Open Shift
            </a>
        @endcan
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/shifts') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="branch-filter" class="form-label">Branch</label>
                <select id="branch-filter" name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="status-filter" class="form-label">Status</label>
                <select id="status-filter" name="status" class="form-select">
                    <option value="">All</option>
                    <option value="open" @selected(request('status') === 'open')>Open</option>
                    <option value="closed" @selected(request('status') === 'closed')>Closed</option>
                </select>
            </div>
            {{-- SHIFT-DATE-FILTER-1: din = shift ka business_date (jo khulte waqt jam jaata hai),
                 closed_at nahi — raat 1 baje band hui shift agle din ki nahi hoti. --}}
            <div class="col-md-2">
                <label for="date-from" class="form-label">Din (se)</label>
                <input type="date" id="date-from" name="date_from" class="form-control"
                    value="{{ request('date_from') }}" max="{{ $maxDate }}">
            </div>
            <div class="col-md-2">
                <label for="date-to" class="form-label">Din (tak)</label>
                <input type="date" id="date-to" name="date_to" class="form-control"
                    value="{{ request('date_to') }}" max="{{ $maxDate }}">
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/shifts') }}" class="btn btn-light">Reset</a>
            </div>
            <div class="col-12 d-flex align-items-center gap-2 pt-1">
                <span class="small text-muted">Jaldi se:</span>
                @php
                    // Baaqi filter (branch/status) qaayam rehte hain; page 1 par wapas.
                    $quick = fn (string $d) => request()->fullUrlWithQuery(['date_from' => $d, 'date_to' => $d, 'page' => null]);
                    $isDay = fn (string $d) => request('date_from') === $d && request('date_to') === $d;
                @endphp
                <a href="{{ $quick($today) }}"
                   class="btn btn-sm {{ $isDay($today) ? 'btn-dark' : 'btn-outline-secondary' }}">
                    Today <span class="opacity-75">({{ $today }})</span>
                </a>
                <a href="{{ $quick($yesterday) }}"
                   class="btn btn-sm {{ $isDay($yesterday) ? 'btn-dark' : 'btn-outline-secondary' }}">
                    Yesterday <span class="opacity-75">({{ $yesterday }})</span>
                </a>
            </div>
        </form>
    </div>
</div>

@php $anyCash = collect($maySeeAmounts ?? [])->contains(true); @endphp
@if($anyCash)
@push('styles')
<style>
    /* Chhota, ginne wala panel — labels halke, hindse ek line par, digits ek doosre ke neeche. */
    .cash-grid{display:flex; flex-wrap:wrap; gap:6px 26px; padding:6px 2px}
    .cash-grid > div{display:flex; flex-direction:column; min-width:104px}
    .cash-grid span{font-size:.72rem; letter-spacing:.04em; text-transform:uppercase; opacity:.65}
    .cash-grid b{font-variant-numeric:tabular-nums; font-size:.95rem}
    [data-cash-toggle][aria-expanded="true"]{background:var(--bs-secondary-bg); border-color:var(--bs-secondary)}
</style>
@endpush
@endif

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption>Shift history (grouped by branch)</caption>
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Terminal</th>
                    <th scope="col">Opened By</th>
                    <th scope="col">Opened At</th>
                    <th scope="col">Closed At</th>
                    <th scope="col">Opening Cash</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @php $grouped = collect($shifts->items())->groupBy('branch_id'); @endphp
            @forelse($grouped as $branchId => $branchShifts)
                @php
                    $branchName = $branchShifts->first()->branch?->name ?? ('Branch #' . $branchId);
                    $branchOpen = (int) ($openCounts[$branchId] ?? 0);
                @endphp
                <tr class="table-light">
                    <td colspan="7">
                        <i class="ti ti-building-store me-1" aria-hidden="true"></i>
                        <strong>{{ $branchName }}</strong>
                        @if($branchOpen > 0)
                            <span class="badge bg-warning text-dark ms-2">{{ $branchOpen }} open</span>
                        @else
                            <span class="badge bg-secondary ms-2">all closed</span>
                        @endif
                        {{-- Is safhe ka jama — list paginated hai, is liye ye poore branch ka hisab
                             hone ka dawa nahi karta, aur label bhi yehi kehta hai. --}}
                        @if($maySeeAmounts[$branchId] ?? false)
                            @php
                                $pgExpected = $branchShifts->sum(fn ($s) => (float) $s->expected_cash);
                                $pgVar      = $branchShifts->whereNotNull('cash_variance')->sum(fn ($s) => (float) $s->cash_variance);
                            @endphp
                            <span class="ms-3 small text-muted">
                                is safhe par expected
                                <b class="text-body">{{ number_format($pgExpected, 2) }}</b>
                                @if(abs($pgVar) > 0.005)
                                    · farq
                                    <b class="{{ $pgVar < 0 ? 'text-danger' : 'text-warning' }}">{{ number_format($pgVar, 2) }}</b>
                                @endif
                            </span>
                        @endif
                    </td>
                    <td class="text-end">
                        @if($branchOpen > 0)
                            @can('tenant.shifts.close')
                                <a href="{{ url('/shifts-close-branch?branch_id=' . $branchId) }}" class="btn btn-sm btn-danger">
                                    <i class="ti ti-lock me-1" aria-hidden="true"></i>Close Branch
                                </a>
                            @endcan
                        @endif
                    </td>
                </tr>
                @foreach($branchShifts as $shift)
                    <tr>
                        <td class="text-muted">{{ $shift->id }}</td>
                        <td class="ps-4">{{ $shift->terminal?->name ?? ('Terminal #' . $shift->terminal_id) }}</td>
                        <td>{{ $shift->openedBy?->name }}</td>
                        <td>{{ app(\App\Support\TenantClock::class)->format($shift->opened_at, 'Y-m-d H:i', $shift->timezone_name) }}</td>
                        <td>{{ $shift->closed_at ? app(\App\Support\TenantClock::class)->format($shift->closed_at, 'Y-m-d H:i', $shift->timezone_name) : '—' }}</td>
                        <td>{{ ($maySeeAmounts[$shift->branch_id] ?? false) ? number_format($shift->opening_cash, 2) : '*****' }}</td>
                        <td>
                            @if($shift->status === 'open')
                                <span class="badge bg-warning text-dark">Open</span>
                            @else
                                <span class="badge bg-secondary">Closed</span>
                            @endif
                        </td>
                        <td class="text-end">
                            {{-- SHIFT-RECONCILE-2: hisab yahin, isi satar ke neeche — safha chhora
                                 kiye bagair. Jis ke paas raqam parhne ki ijazat nahi, uske liye ye
                                 button hai hi nahi: ek khali panel khol dena us se bura hai. --}}
                            @if($maySeeAmounts[$shift->branch_id] ?? false)
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                                    data-cash-toggle="{{ $shift->id }}"
                                    aria-expanded="false" aria-controls="cash-{{ $shift->id }}"
                                    title="Cash detail">
                                    <i class="ti ti-cash" aria-hidden="true"></i>
                                </button>
                            @endif
                            @can('tenant.shifts.show')
                                <a href="{{ url('/shifts/' . $shift->id) }}" class="btn btn-sm btn-dark">View</a>
                            @endcan
                        </td>
                    </tr>
                    @if($maySeeAmounts[$shift->branch_id] ?? false)
                        @php
                            $mny  = fn ($v) => number_format((float) $v, 2);
                            $diff = $shift->cash_variance;
                        @endphp
                        <tr id="cash-{{ $shift->id }}" class="cash-detail d-none">
                            <td colspan="8" class="bg-body-tertiary">
                                <div class="cash-grid">
                                    <div><span>Cash sale</span><b>{{ $mny($shift->total_cash) }}</b></div>
                                    <div><span>Card</span><b>{{ $mny($shift->total_card) }}</b></div>
                                    <div><span>Bank</span><b>{{ $mny($shift->total_bank_transfer) }}</b></div>
                                    <div><span>Refunds</span><b>{{ $mny($shift->total_refunds) }}</b></div>
                                    <div><span>Expected</span><b>{{ $mny($shift->expected_cash) }}</b></div>
                                    <div><span>Counted</span><b>{{ $shift->counted_cash === null ? '—' : $mny($shift->counted_cash) }}</b></div>
                                    <div>
                                        <span>Difference</span>
                                        @if($diff === null)
                                            <b class="text-muted">—</b>
                                        @else
                                            {{-- Kami surkh, zyadti amber, barabar sabz — rang khud
                                                 bata deta hai ke kis daraz ko dekhna hai. --}}
                                            <b class="{{ $diff < -0.005 ? 'text-danger' : ($diff > 0.005 ? 'text-warning' : 'text-success') }}">{{ $mny($diff) }}</b>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No shifts found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
@if($anyCash)
@push('scripts')
<script>
    /* Ek hi listener poore table ke liye — har qatar par apna handler lagana paginated list par
       fuzool hai, aur naye rows par khud chalta rehta hai. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-cash-toggle]');
        if (!btn) { return; }
        var row = document.getElementById('cash-' + btn.dataset.cashToggle);
        if (!row) { return; }
        var open = row.classList.toggle('d-none');
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    });
</script>
@endpush
@endif

        <div class="mt-3">{{ $shifts->links() }}</div>
    </div>
</div>
@endsection
