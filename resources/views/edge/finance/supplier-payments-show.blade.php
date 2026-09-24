{{-- W4 R8.4 — one SUPPLIER PAYMENT on the Branch Server (Online tenant/supplier-payments/show.blade.php). --}}
@extends('edge.finance.layout')

@section('title', 'Supplier Payment')

@section('nav')
    @if($canIndex)
        <a class="navbtn" id="supplier-payments-link" href="{{ url('/edge/local/pos/supplier-payments') }}">Supplier Payments</a>
    @endif
@endsection

@section('content')
@php $pl = $payment['payload'] ?? []; @endphp
<div class="page-head">
    <div>
        <h2>Supplier Payment</h2>
        <p><code>{{ $payment['sync']['official_reference_no'] ?? ('EDGE-' . substr($payment['event_uuid'], -10)) }}</code>
            <span class="badge {{ $payment['sync']['state'] }}" id="supplier-payment-sync">{{ $payment['sync']['label'] }}</span></p>
    </div>
    @if($canIndex)
        <a class="btn" href="{{ url('/edge/local/pos/supplier-payments') }}">Back</a>
    @endif
</div>

<div class="card">
    <div class="card-h">Payment Details</div>
    <div class="card-b">
        <dl class="kv" id="supplier-payment-details">
            <dt>Supplier</dt><dd>{{ $pl['supplier_name'] ?? '—' }} @if(! empty($pl['supplier_code']))<code>{{ $pl['supplier_code'] }}</code>@endif</dd>
            <dt>Against Bill</dt><dd>{{ $pl['bill_no'] ?? 'No specific bill (general payment)' }}</dd>
            <dt>Branch</dt><dd>{{ $branchName }}</dd>
            <dt>Payment Date</dt><dd>{{ $payment['business_date'] }}</dd>
            <dt>Pay From (Cash/Bank)</dt><dd>{{ trim(($pl['cash_bank_code'] ?? '') . ' ' . ($pl['cash_bank_name'] ?? '')) ?: '—' }}</dd>
            <dt>Method</dt><dd>{{ \App\Services\Edge\EdgeLocalSupplierFinanceService::methodLabel((string) ($pl['payment_method'] ?? '')) }}</dd>
            <dt>Amount</dt><dd><strong>{{ number_format((float) $payment['amount'], 2) }}</strong></dd>
            @if(! empty($pl['reference_no']))<dt>Reference No</dt><dd>{{ $pl['reference_no'] }}</dd>@endif
            @if(! empty($pl['bank_name']))<dt>Bank</dt><dd>{{ $pl['bank_name'] }}</dd>@endif
            @if(! empty($pl['account_no']))<dt>Account No</dt><dd>{{ $pl['account_no'] }}</dd>@endif
            @if(! empty($pl['transaction_ref']))<dt>Transaction Ref</dt><dd>{{ $pl['transaction_ref'] }}</dd>@endif
            @if(! empty($pl['cheque_no']))<dt>Cheque No</dt><dd>{{ $pl['cheque_no'] }}</dd>@endif
            @if(! empty($pl['cheque_date']))<dt>Cheque Date</dt><dd>{{ $pl['cheque_date'] }}</dd>@endif
            <dt>Posted By</dt><dd>{{ $payment['user_name'] ?? '—' }}</dd>
            @if(! empty($pl['notes']))<dt>Notes</dt><dd>{{ $pl['notes'] }}</dd>@endif
            <dt>Recorded</dt><dd>{{ $payment['created_at'] }}</dd>
            <dt>Sync</dt><dd>{{ $payment['sync']['label'] }}</dd>
        </dl>
    </div>
</div>
@endsection
