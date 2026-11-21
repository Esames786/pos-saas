{{-- W4 R3.6 — SALES RETURNS list on the Branch Server (Online tenant/sales-returns/index.blade.php). --}}
@extends('edge.finance.layout')

@section('title', 'Sales Returns')

@section('content')
<div class="page-head">
    <div>
        <h2>Sales Returns</h2>
        <p>Returns posted against paid sales — on this branch server, plus the Online returns mirrored here for recent sales.</p>
    </div>
    @if($canCreate)
        <a class="btn primary" id="sales-return-new" href="{{ url('/edge/local/pos') }}" title="New returns are posted from the POS (Returns)">New Return (POS)</a>
    @endif
</div>

<div class="card">
    <div class="card-b">
        <form method="GET" action="{{ url('/edge/local/pos/sales-returns') }}" class="filters" id="sales-return-filters">
            <div class="f">
                <label for="date_from">From</label>
                <input type="date" id="date_from" name="date_from" value="{{ $dateFrom }}">
            </div>
            <div class="f">
                <label for="date_to">To</label>
                <input type="date" id="date_to" name="date_to" value="{{ $dateTo }}">
            </div>
            <div class="f" style="flex-direction:row;gap:.4rem">
                <button class="btn primary" type="submit">Filter</button>
                <a class="btn" href="{{ url('/edge/local/pos/sales-returns') }}">Reset</a>
            </div>
        </form>
        <div class="quick">
            <span>Quick:</span>
            <a class="btn sm {{ $dateFrom === $today && $dateTo === $today ? 'on' : '' }}" id="sales-return-today" href="{{ url('/edge/local/pos/sales-returns?range=today') }}">Today</a>
            <a class="btn sm" id="sales-return-yesterday" href="{{ url('/edge/local/pos/sales-returns?range=yesterday') }}">Yesterday</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-b table-wrap">
        <table id="sales-return-table">
            <thead>
            <tr>
                <th>Return No</th><th>Sale No</th><th>Branch</th><th>Return Date</th><th class="num">Grand Total</th>
                <th>Refund Method</th><th>Status</th><th>Sync</th><th class="num">Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse($returns as $return)
                <tr>
                    <td><code>{{ $return->return_no }}</code></td>
                    <td><code>{{ $return->order?->sale_no }}</code></td>
                    <td>{{ $branchName }}</td>
                    <td>{{ $clock->format($return->return_date, 'Y-m-d H:i', $tz) }}</td>
                    <td class="num">{{ number_format((float) $return->grand_total, 2) }}</td>
                    <td>{{ $return->refund_method ? str_replace('_', ' ', ucfirst($return->refund_method)) : '—' }}</td>
                    <td><span class="badge {{ in_array($return->status, ['posted', 'cloud_mirror'], true) ? 'posted' : 'closed' }}">{{ $return->status === 'cloud_mirror' ? 'Posted' : ucfirst($return->status) }}</span></td>
                    <td><span class="badge {{ $return->edge_sync_label['state'] }}">{{ $return->edge_sync_label['label'] }}</span></td>
                    <td class="num">
                        @if($canShow)
                            <a class="btn sm" id="sales-return-view-{{ $return->id }}" href="{{ url('/edge/local/pos/sales-returns/' . $return->id) }}">View</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">No sales returns found.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Page {{ $returns->currentPage() }} of {{ max(1, $returns->lastPage()) }} · {{ $returns->total() }} return(s)</span>
            @if($returns->previousPageUrl())<a class="btn sm" href="{{ $returns->previousPageUrl() }}">Previous</a>@endif
            @if($returns->nextPageUrl())<a class="btn sm" href="{{ $returns->nextPageUrl() }}">Next</a>@endif
        </div>
    </div>
</div>
@endsection
