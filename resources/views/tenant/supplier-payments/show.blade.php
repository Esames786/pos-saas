@extends('layouts.app')

@section('title', 'Payment: ' . $supplierPayment->payment_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Supplier Payment</h1>
        <p class="fw-medium"><code>{{ $supplierPayment->payment_no }}</code></p>
    </div>
    <a href="{{ url('/supplier-payments') }}" class="btn btn-light">Back</a>
</div>

<div class="card">
    <div class="card-header"><strong>Payment Details</strong></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Supplier</dt>
            <dd class="col-sm-9">{{ $supplierPayment->supplier?->name }}</dd>

            <dt class="col-sm-3">Against Bill</dt>
            <dd class="col-sm-9">
                @if($supplierPayment->bill)
                    <a href="{{ url('/purchase-bills/' . $supplierPayment->bill->id) }}">
                        {{ $supplierPayment->bill->bill_no }}
                    </a>
                @else
                    — (general payment)
                @endif
            </dd>

            <dt class="col-sm-3">Branch</dt>
            <dd class="col-sm-9">{{ $supplierPayment->branch?->name ?? '—' }}</dd>

            <dt class="col-sm-3">Payment Date</dt>
            <dd class="col-sm-9">{{ $supplierPayment->payment_date?->format('Y-m-d') }}</dd>

            <dt class="col-sm-3">Method</dt>
            <dd class="col-sm-9">{{ str_replace('_', ' ', ucfirst($supplierPayment->payment_method)) }}</dd>

            <dt class="col-sm-3">Amount</dt>
            <dd class="col-sm-9"><strong>{{ number_format($supplierPayment->amount, 2) }}</strong></dd>

            @if($supplierPayment->reference_no)
                <dt class="col-sm-3">Reference No</dt>
                <dd class="col-sm-9">{{ $supplierPayment->reference_no }}</dd>
            @endif

            @if($supplierPayment->bank_name)
                <dt class="col-sm-3">Bank</dt>
                <dd class="col-sm-9">{{ $supplierPayment->bank_name }}</dd>
            @endif

            @if($supplierPayment->account_no)
                <dt class="col-sm-3">Account No</dt>
                <dd class="col-sm-9">{{ $supplierPayment->account_no }}</dd>
            @endif

            @if($supplierPayment->transaction_ref)
                <dt class="col-sm-3">Transaction Ref</dt>
                <dd class="col-sm-9">{{ $supplierPayment->transaction_ref }}</dd>
            @endif

            @if($supplierPayment->cheque_no)
                <dt class="col-sm-3">Cheque No</dt>
                <dd class="col-sm-9">{{ $supplierPayment->cheque_no }}</dd>
            @endif

            @if($supplierPayment->cheque_date)
                <dt class="col-sm-3">Cheque Date</dt>
                <dd class="col-sm-9">{{ $supplierPayment->cheque_date->format('Y-m-d') }}</dd>
            @endif

            <dt class="col-sm-3">Posted By</dt>
            <dd class="col-sm-9">{{ $supplierPayment->postedBy?->name ?? '—' }}</dd>

            @if($supplierPayment->notes)
                <dt class="col-sm-3">Notes</dt>
                <dd class="col-sm-9">{{ $supplierPayment->notes }}</dd>
            @endif
        </dl>
    </div>
</div>
@endsection
