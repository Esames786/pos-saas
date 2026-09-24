{{-- W4 R8.4 — SUPPLIER PAYMENTS list on the Branch Server (Online tenant/supplier-payments/index.blade.php). --}}
@extends('edge.finance.layout')

@section('title', 'Supplier Payments')

@section('nav')
    @if($canViewLedger)
        <a class="navbtn" id="suppliers-link" href="{{ url('/edge/local/pos/suppliers') }}">Suppliers</a>
    @endif
@endsection

@section('content')
<div class="page-head">
    <div>
        <h2>Supplier Payments</h2>
        <p>Payments recorded on this branch server. The Cloud posts each one officially when it syncs; payments made on the Online POS show in the Supplier Ledger.</p>
    </div>
    @if($canPay)
        <a class="btn primary" id="supplier-payment-new" href="{{ url('/edge/local/pos/suppliers') }}">Record Payment</a>
    @endif
</div>

<div class="card">
    <div class="card-b">
        <form method="GET" action="{{ url('/edge/local/pos/supplier-payments') }}" class="filters" id="supplier-payment-filters">
            <div class="f">
                <label for="pay-supplier">Supplier</label>
                <select id="pay-supplier" name="supplier_id">
                    <option value="">All suppliers</option>
                    @foreach($suppliers as $s)
                        <option value="{{ $s['cloud_supplier_id'] }}" @selected((int) ($filters['supplier_id'] ?? 0) === (int) $s['cloud_supplier_id'])>{{ $s['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="f">
                <label for="pay-date-from">From</label>
                <input type="date" id="pay-date-from" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="f">
                <label for="pay-date-to">To</label>
                <input type="date" id="pay-date-to" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            </div>
            <div class="f" style="flex-direction:row;gap:.4rem">
                <button class="btn primary" type="submit">Filter</button>
                <a class="btn" href="{{ url('/edge/local/pos/supplier-payments') }}">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-b table-wrap">
        <table id="supplier-payment-table">
            <thead>
            <tr><th>Payment No</th><th>Supplier</th><th>Bill</th><th>Date</th><th>Method</th><th class="num">Amount</th><th>Status</th><th class="num">Action</th></tr>
            </thead>
            <tbody>
            @php $pageTotal = 0; @endphp
            @forelse($payments as $p)
                @php $pl = $p['payload'] ?? []; $pageTotal += (float) $p['amount']; @endphp
                <tr>
                    <td><code>{{ $p['sync']['official_reference_no'] ?? ('EDGE-' . substr($p['event_uuid'], -10)) }}</code></td>
                    <td>{{ $pl['supplier_name'] ?? '—' }}</td>
                    <td>{{ $pl['bill_no'] ?? '—' }}</td>
                    <td>{{ $p['business_date'] }}</td>
                    <td>{{ \App\Services\Edge\EdgeLocalSupplierFinanceService::methodLabel((string) ($pl['payment_method'] ?? '')) }}</td>
                    <td class="num">{{ number_format((float) $p['amount'], 2) }}</td>
                    <td><span class="badge {{ $p['sync']['state'] }}" title="{{ $p['sync']['label'] }}">{{ strtoupper(str_replace('_', ' ', $p['sync']['state'])) }}</span></td>
                    <td class="num">
                        @if($canShow)
                            <a class="btn sm" id="supplier-payment-view-{{ $p['event_uuid'] }}" href="{{ url('/edge/local/pos/supplier-payments/' . $p['event_uuid']) }}">View</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No supplier payments recorded on this branch server.</td></tr>
            @endforelse
            </tbody>
            @if($payments->count())
                <tfoot><tr><td colspan="5">This page</td><td class="num">{{ number_format($pageTotal, 2) }}</td><td colspan="2"></td></tr></tfoot>
            @endif
        </table>
        <div class="pager">
            <span>Page {{ $payments->currentPage() }} of {{ max(1, $payments->lastPage()) }} · {{ $payments->total() }} payment(s)</span>
            @if($payments->previousPageUrl())<a class="btn sm" href="{{ $payments->previousPageUrl() }}">Previous</a>@endif
            @if($payments->nextPageUrl())<a class="btn sm" href="{{ $payments->nextPageUrl() }}">Next</a>@endif
        </div>
    </div>
</div>
@endsection
