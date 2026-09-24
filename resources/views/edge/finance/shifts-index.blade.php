{{-- W4 R1.8 — SHIFT HISTORY on the Branch Server (Online tenant/shifts/index.blade.php): this branch's LOCAL shifts. --}}
@extends('edge.finance.layout')

@section('title', 'Shifts')

@section('content')
@php
    $mny = fn ($v) => number_format((float) $v, 2);
    $quick = fn (string $d) => request()->fullUrlWithQuery(['date_from' => $d, 'date_to' => $d, 'page' => null]);
    $isDay = fn (string $d) => ($filters['date_from'] ?? null) === $d && ($filters['date_to'] ?? null) === $d;
@endphp
<div class="page-head">
    <div>
        <h2>Shifts</h2>
        <p>Shifts opened on this branch server ({{ $branchName }}). Shifts run on the Online POS are in the Cloud shift history.</p>
    </div>
    <div>
        <a class="btn primary" id="shift-open-from-history" href="{{ url('/edge/local/pos') }}">Open / close my shift (POS)</a>
    </div>
</div>

<div class="note" id="close-branch-note">
    Close Branch (every terminal at once) and the Daily Closing are not run on the branch server — each counter closes its
    own shift from its POS (Shift → Close shift). {{ $openCount }} shift(s) are open on this branch now.
</div>

<div class="card">
    <div class="card-b">
        <form method="GET" action="{{ url('/edge/local/pos/shifts') }}" class="filters" id="shift-filters">
            <div class="f">
                <label for="status-filter">Status</label>
                <select id="status-filter" name="status">
                    <option value="">All</option>
                    <option value="open" @selected(($filters['status'] ?? '') === 'open')>Open</option>
                    <option value="closed" @selected(($filters['status'] ?? '') === 'closed')>Closed</option>
                </select>
            </div>
            <div class="f">
                <label for="date-from">Business date (from)</label>
                <input type="date" id="date-from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" max="{{ $maxDate }}">
            </div>
            <div class="f">
                <label for="date-to">Business date (to)</label>
                <input type="date" id="date-to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" max="{{ $maxDate }}">
            </div>
            <div class="f" style="flex-direction:row;gap:.4rem">
                <button class="btn primary" type="submit">Filter</button>
                <a class="btn" href="{{ url('/edge/local/pos/shifts') }}">Reset</a>
            </div>
        </form>
        <div class="quick">
            <span>Quick:</span>
            <a class="btn sm {{ $isDay($today) ? 'on' : '' }}" id="shift-filter-today" href="{{ $quick($today) }}">Today ({{ $today }})</a>
            <a class="btn sm {{ $isDay($yesterday) ? 'on' : '' }}" id="shift-filter-yesterday" href="{{ $quick($yesterday) }}">Yesterday ({{ $yesterday }})</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-b table-wrap">
        <table id="shift-history-table">
            <caption style="text-align:left;color:var(--muted);font-size:.8rem;padding-bottom:.4rem">Shift history — {{ $branchName }}</caption>
            <thead>
            <tr>
                <th>#</th><th>Terminal</th><th>Opened By</th><th>Business date</th><th>Opened At</th><th>Closed At</th>
                <th class="num">Opening Cash</th><th>Status</th><th class="num">Action</th>
            </tr>
            </thead>
            <tbody>
            @if($maySeeAmounts && $shifts->count())
                @php
                    $pgExpected = collect($shifts->items())->sum(fn ($s) => (float) $s->expected_cash);
                    $pgVar = collect($shifts->items())->whereNotNull('cash_variance')->sum(fn ($s) => (float) $s->cash_variance);
                @endphp
                <tr class="group"><td colspan="9">
                    <strong>{{ $branchName }}</strong>
                    <span class="badge {{ $openCount > 0 ? 'open' : 'closed' }}">{{ $openCount > 0 ? $openCount . ' open' : 'all closed' }}</span>
                    <span style="color:var(--muted);font-size:.8rem;margin-left:.8rem">this page: expected <b style="color:var(--ink)">{{ $mny($pgExpected) }}</b>
                        @if(abs($pgVar) > 0.005) · difference <b class="{{ $pgVar < 0 ? 'neg' : 'pos' }}">{{ $mny($pgVar) }}</b>@endif</span>
                </td></tr>
            @endif
            @forelse($shifts as $shift)
                <tr>
                    <td style="color:var(--muted)">{{ $shift->id }}</td>
                    <td>{{ $shift->terminal?->name ?? ('Terminal #' . $shift->terminal_id) }}</td>
                    <td>{{ $shift->openedBy?->name }}</td>
                    <td>{{ $shift->business_date?->toDateString() }}</td>
                    <td>{{ $clock->format($shift->opened_at, 'Y-m-d H:i', $shift->timezone_name) }}</td>
                    <td>{{ $shift->closed_at ? $clock->format($shift->closed_at, 'Y-m-d H:i', $shift->timezone_name) : '—' }}</td>
                    <td class="num">{{ $maySeeAmounts ? $mny($shift->opening_cash) : '*****' }}</td>
                    <td><span class="badge {{ $shift->status === 'open' ? 'open' : 'closed' }}">{{ $shift->status === 'open' ? 'Open' : 'Closed' }}</span></td>
                    <td class="num">
                        @if($maySeeAmounts)
                            <button type="button" class="btn sm" data-cash-toggle="{{ $shift->id }}" aria-expanded="false" aria-controls="cash-{{ $shift->id }}" title="Cash detail">Cash</button>
                        @endif
                        @if($canShow)
                            <a class="btn sm primary" id="shift-view-{{ $shift->id }}" href="{{ url('/edge/local/pos/shifts/' . $shift->id) }}">View</a>
                        @endif
                    </td>
                </tr>
                @if($maySeeAmounts)
                    <tr id="cash-{{ $shift->id }}" class="cash-detail" hidden>
                        <td colspan="9">
                            <div class="cash-grid">
                                <div><span>Cash sale</span><b>{{ $mny($shift->total_cash) }}</b></div>
                                <div><span>Card</span><b>{{ $mny($shift->total_card) }}</b></div>
                                <div><span>Bank</span><b>{{ $mny($shift->total_bank_transfer) }}</b></div>
                                <div><span>Refunds</span><b>{{ $mny($shift->total_refunds) }}</b></div>
                                <div><span>Expected</span><b>{{ $mny($shift->expected_cash) }}</b></div>
                                <div><span>Counted</span><b>{{ $shift->counted_cash === null ? '—' : $mny($shift->counted_cash) }}</b></div>
                                <div><span>Difference</span>
                                    @if($shift->cash_variance === null)<b>—</b>
                                    @else<b class="{{ $shift->cash_variance < 0 ? 'neg' : ($shift->cash_variance > 0 ? 'pos' : 'zero') }}">{{ $mny($shift->cash_variance) }}</b>@endif
                                </div>
                            </div>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="9" class="empty">No shifts found on this branch server.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Page {{ $shifts->currentPage() }} of {{ max(1, $shifts->lastPage()) }} · {{ $shifts->total() }} shift(s)</span>
            @if($shifts->previousPageUrl())<a class="btn sm" href="{{ $shifts->previousPageUrl() }}">Previous</a>@endif
            @if($shifts->nextPageUrl())<a class="btn sm" href="{{ $shifts->nextPageUrl() }}">Next</a>@endif
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.querySelectorAll('[data-cash-toggle]').forEach(function (b) {
        b.addEventListener('click', function () {
            var row = document.getElementById('cash-' + b.getAttribute('data-cash-toggle'));
            if (!row) { return; }
            row.hidden = !row.hidden;
            b.setAttribute('aria-expanded', row.hidden ? 'false' : 'true');
        });
    });
</script>
@endsection
