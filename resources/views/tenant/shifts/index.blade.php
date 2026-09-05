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
            <div class="col-md-4">
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
            <div class="col-md-3">
                <label for="status-filter" class="form-label">Status</label>
                <select id="status-filter" name="status" class="form-select">
                    <option value="">All</option>
                    <option value="open" @selected(request('status') === 'open')>Open</option>
                    <option value="closed" @selected(request('status') === 'closed')>Closed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/shifts') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

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
                    {{-- SHIFT-RECONCILE-1: pehle sirf Opening Cash tha, jo Kashif par har raat 0.00
                         hota hai — yani us column ne kabhi kuch bataya hi nahi. Ab wahi hisab jo
                         daraz band karte waqt saamne hota hai: kitna aana chahiye tha, kitna nikla,
                         aur farq. Cheque ka column jaan-boojh kar nahi: dono tenants par wo
                         aaj tak sifar hai, aur hamesha khali column wohi bemaani cheez ban jata
                         hai jo Opening Cash ban chuka tha. --}}
                    <th scope="col" class="text-end">Opening</th>
                    <th scope="col" class="text-end">Cash Sale</th>
                    <th scope="col" class="text-end">Card</th>
                    <th scope="col" class="text-end">Bank</th>
                    <th scope="col" class="text-end">Refunds</th>
                    <th scope="col" class="text-end">Expected</th>
                    <th scope="col" class="text-end">Counted</th>
                    <th scope="col" class="text-end">Difference</th>
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
                    <td colspan="13">
                        <i class="ti ti-building-store me-1" aria-hidden="true"></i>
                        <strong>{{ $branchName }}</strong>
                        @if($branchOpen > 0)
                            <span class="badge bg-warning text-dark ms-2">{{ $branchOpen }} open</span>
                        @else
                            <span class="badge bg-secondary ms-2">all closed</span>
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
                        @php
                            // Ek hi faisla, har khaane par — wahi AmountVisibility jo Close Shift
                            // aur dashboard poochte hain. Branch na mile to masked (fail closed).
                            $see  = $maySeeAmounts[$shift->branch_id] ?? false;
                            $mny  = fn ($v) => $see ? number_format((float) $v, 2) : '*****';
                            $diff = $shift->cash_variance;
                        @endphp
                        <td class="text-end">{{ $mny($shift->opening_cash) }}</td>
                        <td class="text-end">{{ $mny($shift->total_cash) }}</td>
                        <td class="text-end">{{ $mny($shift->total_card) }}</td>
                        <td class="text-end">{{ $mny($shift->total_bank_transfer) }}</td>
                        <td class="text-end">{{ $mny($shift->total_refunds) }}</td>
                        <td class="text-end fw-semibold">{{ $mny($shift->expected_cash) }}</td>
                        <td class="text-end">{{ $shift->counted_cash === null ? '—' : $mny($shift->counted_cash) }}</td>
                        <td class="text-end">
                            @if(! $see)
                                *****
                            @elseif($diff === null)
                                <span class="text-muted">—</span>
                            @else
                                {{-- Kami surkh, zyadti amber, barabar sabz: rang khud bata deta hai
                                     ke kis daraz ko dekhna hai. --}}
                                <span class="{{ $diff < -0.005 ? 'text-danger fw-semibold' : ($diff > 0.005 ? 'text-warning fw-semibold' : 'text-success') }}">
                                    {{ number_format((float) $diff, 2) }}
                                </span>
                            @endif
                        </td>
                        <td>
                            @if($shift->status === 'open')
                                <span class="badge bg-warning text-dark">Open</span>
                            @else
                                <span class="badge bg-secondary">Closed</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @can('tenant.shifts.show')
                                <a href="{{ url('/shifts/' . $shift->id) }}" class="btn btn-sm btn-dark">View</a>
                            @endcan
                        </td>
                    </tr>
                @php
                    // Sirf IS SAFHE ka jama — list paginated hai, is liye ye poore branch ka
                    // hisab hone ka dawa nahi karta, aur label bhi yehi kehta hai.
                    $see    = $maySeeAmounts[$branchId] ?? false;
                    $sum    = fn (string $col) => $branchShifts->sum(fn ($s) => (float) $s->{$col});
                    $sumVar = $branchShifts->whereNotNull('cash_variance')->sum(fn ($s) => (float) $s->cash_variance);
                    $mny    = fn ($v) => $see ? number_format((float) $v, 2) : '*****';
                @endphp
                <tr class="fw-semibold border-top border-2">
                    <td colspan="5" class="text-end text-muted">Is safhe ka jama —</td>
                    <td class="text-end">{{ $mny($sum('opening_cash')) }}</td>
                    <td class="text-end">{{ $mny($sum('total_cash')) }}</td>
                    <td class="text-end">{{ $mny($sum('total_card')) }}</td>
                    <td class="text-end">{{ $mny($sum('total_bank_transfer')) }}</td>
                    <td class="text-end">{{ $mny($sum('total_refunds')) }}</td>
                    <td class="text-end">{{ $mny($sum('expected_cash')) }}</td>
                    <td class="text-end">{{ $mny($sum('counted_cash')) }}</td>
                    <td class="text-end">
                        @if(! $see)
                            *****
                        @else
                            <span class="{{ $sumVar < -0.005 ? 'text-danger' : ($sumVar > 0.005 ? 'text-warning' : 'text-success') }}">
                                {{ number_format($sumVar, 2) }}
                            </span>
                        @endif
                    </td>
                    <td colspan="2"></td>
                </tr>
                @endforeach
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No shifts found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div class="mt-3">{{ $shifts->links() }}</div>
    </div>
</div>
@endsection
