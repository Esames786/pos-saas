{{-- W4 R9.4 — PURCHASE RETURNS list on the Branch Server (Online tenant/purchase-returns/index.blade.php). --}}
@extends('edge.finance.layout')

@section('title', 'Purchase Returns')

@section('nav')
    @if($canCreate)
        <a class="navbtn" id="purchase-returns-link" href="{{ url('/edge/local/pos/purchase-returns') }}">New Return</a>
    @endif
@endsection

@section('content')
<div class="page-head">
    <div>
        <h2>Purchase Returns</h2>
        <p>Returns to suppliers posted on this branch server. Each one is posted at once (pending sync) and the Cloud posts it officially; there are no drafts on the branch server.</p>
    </div>
    @if($canCreate)
        <a class="btn primary" id="purchase-return-new" href="{{ url('/edge/local/pos/purchase-returns') }}">New Return</a>
    @endif
</div>

<div class="card">
    <div class="card-b">
        <form method="GET" action="{{ url('/edge/local/pos/purchase-return-list') }}" class="filters" id="purchase-return-filters">
            <div class="f">
                <label for="supplier_id">Supplier</label>
                <select id="supplier_id" name="supplier_id">
                    <option value="">All suppliers</option>
                    @foreach($suppliers as $s)
                        <option value="{{ $s['cloud_supplier_id'] }}" @selected((int) ($filters['supplier_id'] ?? 0) === (int) $s['cloud_supplier_id'])>{{ $s['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="f"><label for="pr-date-from">From</label><input type="date" id="pr-date-from" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="f"><label for="pr-date-to">To</label><input type="date" id="pr-date-to" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
            <div class="f" style="flex-direction:row;gap:.4rem">
                <button class="btn primary" type="submit">Filter</button>
                <a class="btn" href="{{ url('/edge/local/pos/purchase-return-list') }}">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-b table-wrap">
        <table id="purchase-return-table">
            <thead><tr><th>Return No</th><th>Date</th><th>Branch</th><th>Supplier</th><th>Source GRN</th><th class="num">Lines</th><th class="num">Total</th><th>Status</th><th>Posted</th><th class="num">Action</th></tr></thead>
            <tbody>
            @forelse($returns as $r)
                @php $pl = $r['payload'] ?? []; @endphp
                <tr>
                    <td><code>{{ $r['sync']['official_reference_no'] ?? ('EDGE-' . substr($r['event_uuid'], -10)) }}</code></td>
                    <td>{{ $r['return_date'] }}</td>
                    <td>{{ $branchName }}</td>
                    <td>{{ $pl['supplier_name'] ?? ('#' . $r['cloud_supplier_id']) }}</td>
                    <td>{{ $pl['grn_no'] ?? ('#' . $r['cloud_grn_id']) }}</td>
                    <td class="num">{{ count($r['lines']) }}</td>
                    <td class="num">{{ number_format((float) $r['grand_total'], 2) }}</td>
                    <td><span class="badge {{ $r['sync']['state'] }}" title="{{ $r['sync']['label'] }}">{{ strtoupper(str_replace('_', ' ', $r['sync']['state'])) }}</span></td>
                    <td>{{ $r['created_at'] }}{{ $r['user_name'] ? ' · ' . $r['user_name'] : '' }}</td>
                    <td class="num">
                        @if($canShow)
                            <a class="btn sm" id="purchase-return-view-{{ $r['event_uuid'] }}" href="{{ url('/edge/local/pos/purchase-return-list/' . $r['event_uuid']) }}">View</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="empty">No purchase returns posted on this branch server.</td></tr>
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
