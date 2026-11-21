{{-- W4 R8.6 — MANUAL JOURNALS list on the Branch Server (Online tenant/finance/manual-journals/index.blade.php). --}}
@extends('edge.finance.layout')

@section('title', 'Manual Journals')

@section('nav')
    @if($canJournal)
        <a class="navbtn" id="journal-link" href="{{ url('/edge/local/pos/finance/journal') }}">General Journal</a>
    @endif
@endsection

@section('content')
<div class="page-head">
    <div>
        <h2>Manual Journals</h2>
        <p>Supplier / Accounts Payable journals posted on this branch server (pending sync until the Cloud posts them officially).</p>
    </div>
    @if($canJournal)
        <a class="btn primary" id="manual-journal-new" href="{{ url('/edge/local/pos/finance/journal') }}">New Journal</a>
    @endif
</div>

<div class="card">
    <div class="card-b">
        <form method="GET" action="{{ url('/edge/local/pos/finance/manual-journals') }}" class="filters" id="manual-journal-filters">
            <div class="f"><label for="mj-date-from">From</label><input type="date" id="mj-date-from" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="f"><label for="mj-date-to">To</label><input type="date" id="mj-date-to" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
            <div class="f" style="min-width:240px"><label for="mj-q">Search</label><input type="text" id="mj-q" name="q" placeholder="Entry no / ref / description" value="{{ $filters['q'] ?? '' }}"></div>
            <div class="f" style="flex-direction:row;gap:.4rem">
                <button class="btn primary" type="submit">Filter</button>
                <a class="btn" href="{{ url('/edge/local/pos/finance/manual-journals') }}">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-b table-wrap">
        <table id="manual-journal-table">
            <thead><tr><th>Entry #</th><th>Date</th><th>Reference</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th>Status</th><th class="num"></th></tr></thead>
            <tbody>
            @forelse($journals as $j)
                @php $t = $j['payload']['totals'] ?? ['debit' => $j['amount'], 'credit' => $j['amount']]; @endphp
                <tr>
                    <td><code>{{ $j['sync']['official_reference_no'] ?? ('EDGE-' . substr($j['event_uuid'], -10)) }}</code></td>
                    <td>{{ $j['business_date'] }}</td>
                    <td style="color:var(--muted)">{{ $j['reference_no'] ?: '—' }}</td>
                    <td class="wrap">{{ $j['description'] }}</td>
                    <td class="num">{{ number_format((float) $t['debit'], 2) }}</td>
                    <td class="num">{{ number_format((float) $t['credit'], 2) }}</td>
                    <td><span class="badge {{ $j['sync']['state'] }}" title="{{ $j['sync']['label'] }}">{{ strtoupper(str_replace('_', ' ', $j['sync']['state'])) }}</span></td>
                    <td class="num">
                        @if($canShow)
                            <a class="btn sm" id="manual-journal-view-{{ $j['event_uuid'] }}" href="{{ url('/edge/local/pos/finance/manual-journals/' . $j['event_uuid']) }}">View</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No manual journal entries on this branch server yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Page {{ $journals->currentPage() }} of {{ max(1, $journals->lastPage()) }} · {{ $journals->total() }} journal(s)</span>
            @if($journals->previousPageUrl())<a class="btn sm" href="{{ $journals->previousPageUrl() }}">Previous</a>@endif
            @if($journals->nextPageUrl())<a class="btn sm" href="{{ $journals->nextPageUrl() }}">Next</a>@endif
        </div>
    </div>
</div>
@endsection
