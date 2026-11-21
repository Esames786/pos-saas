{{-- W4 R1.9 — SHIFT DETAIL / post-close summary on the Branch Server (Online tenant/shifts/show.blade.php). --}}
@extends('edge.finance.layout')

@section('title', 'Shift #' . $shift->id)

@section('nav')
    @if($canIndex)
        <a class="navbtn" id="shift-history-link" href="{{ url('/edge/local/pos/shifts') }}">Shifts</a>
    @endif
@endsection

@section('content')
@php
    $mask = fn ($v) => $maySeeAmounts ? number_format((float) $v, 2) : '*****';
    $fmt = fn ($t) => $t ? $clock->format($t, 'Y-m-d H:i', $shift->timezone_name) : '—';
@endphp
<div class="page-head">
    <div>
        <h2>Shift #{{ $shift->id }}</h2>
        <p>{{ $branchName }} — {{ $shift->terminal?->name ?? ('Terminal #' . $shift->terminal_id) }}
            <span class="badge {{ $shift->status === 'open' ? 'open' : 'closed' }}" id="shift-status-badge">{{ $shift->status === 'open' ? 'Open' : 'Closed' }}</span></p>
    </div>
    <div style="display:flex;gap:.5rem">
        @if($shift->status === 'open' && $canClose)
            <a class="btn danger" id="shift-close-from-detail" href="{{ url('/edge/local/pos') }}" title="Close the shift from the POS of this terminal (Shift → Close shift)">Close Shift (POS)</a>
        @endif
        @if($canIndex)
            <a class="btn" href="{{ url('/edge/local/pos/shifts') }}">Back</a>
        @endif
    </div>
</div>

@if(! $maySeeAmounts)
    <div class="note" id="shift-blind-note">Amounts are hidden for your role on this branch (blind count).</div>
@endif

<div class="grid2">
    <div class="card">
        <div class="card-h">Shift Summary</div>
        <div class="card-b">
            <dl class="kv" id="shift-summary">
                <dt>Branch</dt><dd>{{ $branchName }}</dd>
                <dt>Terminal</dt><dd>{{ $shift->terminal?->name ?? ('Terminal #' . $shift->terminal_id) }}</dd>
                <dt>Business date</dt><dd>{{ $shift->business_date?->toDateString() ?? '—' }}</dd>
                <dt>Opened By</dt><dd>{{ $shift->openedBy?->name ?? '—' }}</dd>
                <dt>Opened At</dt><dd>{{ $fmt($shift->opened_at) }}</dd>
                <dt>Closed By</dt><dd>{{ $shift->closedBy?->name ?? '—' }}</dd>
                <dt>Closed At</dt><dd>{{ $fmt($shift->closed_at) }}</dd>
                <dt>Opening Notes</dt><dd>{{ $shift->opening_notes ?? '—' }}</dd>
                <dt>Closing Notes</dt><dd id="shift-closing-notes">{{ $shift->closing_notes ?? '—' }}</dd>
            </dl>
        </div>
    </div>
    <div class="card">
        <div class="card-h">Cash Summary</div>
        <div class="card-b">
            <dl class="kv" id="shift-cash-summary">
                <dt>Opening Cash</dt><dd>{{ $mask($shift->opening_cash) }}</dd>
                <dt>Total Sales</dt><dd>{{ $mask($shift->total_sales) }}</dd>
                <dt>Total Cash</dt><dd>{{ $mask($shift->total_cash) }}</dd>
                <dt>Total Card</dt><dd>{{ $mask($shift->total_card) }}</dd>
                <dt>Total Bank</dt><dd>{{ $mask($shift->total_bank_transfer) }}</dd>
                @if((float) $shift->total_cheque)
                    <dt>Total Cheque</dt><dd>{{ $mask($shift->total_cheque) }}</dd>
                @endif
                <dt>Total Refunds</dt><dd>{{ $mask($shift->total_refunds) }}</dd>
                <dt>Expected Cash</dt><dd><strong>{{ $mask($shift->expected_cash) }}</strong></dd>
                <dt>Counted Cash</dt><dd>{{ ! $maySeeAmounts ? '*****' : ($shift->counted_cash !== null ? number_format((float) $shift->counted_cash, 2) : '—') }}</dd>
                <dt>Cash Variance</dt>
                <dd id="shift-variance">
                    @if(! $maySeeAmounts)
                        *****
                    @elseif($shift->cash_variance !== null)
                        <span class="{{ $shift->cash_variance < 0 ? 'neg' : ($shift->cash_variance > 0 ? 'pos' : 'zero') }}">{{ number_format((float) $shift->cash_variance, 2) }}</span>
                    @else
                        —
                    @endif
                </dd>
                <dt>Cancelled bills</dt><dd>{{ $breakup['cancelled_bills'] }}{{ $breakup['cancelled_amount'] === null ? '' : ' · ' . number_format($breakup['cancelled_amount'], 2) }}</dd>
                <dt>Voided lines</dt><dd>{{ $breakup['voided_lines'] }} ({{ rtrim(rtrim(number_format($breakup['voided_units'], 3, '.', ''), '0'), '.') }} units)</dd>
            </dl>
        </div>
    </div>
</div>

@if($shift->status === 'closed' && $maySeeAmounts && $shift->cash_variance !== null && (float) $shift->cash_variance < -0.009)
    <div class="note warn" id="shift-shortage-note">
        Cash short by {{ number_format(-(float) $shift->cash_variance, 2) }} — recorded on this shift. On the Online POS a short drawer
        also raises a draft expense voucher for finance; the branch server does not create that finance entry.
    </div>
@endif

@if($shift->cashCountLines->count())
    <div class="card">
        <div class="card-h">Cash Count Breakdown</div>
        <div class="card-b table-wrap">
            <table id="shift-cash-count">
                <thead><tr><th>Denomination</th><th>Type</th><th class="num">Quantity</th><th class="num">Amount</th></tr></thead>
                <tbody>
                @foreach($shift->cashCountLines as $line)
                    <tr>
                        <td>{{ number_format((float) $line->denomination?->denomination_value, 2) }}</td>
                        <td>{{ ucfirst((string) $line->denomination?->denomination_type) }}</td>
                        <td class="num">{{ $line->quantity }}</td>
                        <td class="num">{{ $maySeeAmounts ? number_format((float) $line->amount, 2) : '*****' }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr><td colspan="3">Total</td><td class="num">{{ $maySeeAmounts ? number_format((float) $shift->cashCountLines->sum('amount'), 2) : '*****' }}</td></tr></tfoot>
            </table>
        </div>
    </div>
@endif
@endsection
