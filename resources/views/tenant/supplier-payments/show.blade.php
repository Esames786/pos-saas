@extends('layouts.app')

@section('title', 'Payment: ' . $supplierPayment->payment_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Supplier Payment
            @if($supplierPayment->isVoided())<span class="badge bg-secondary ms-1 fs-6 align-middle">Voided</span>@endif
        </h1>
        <p class="fw-medium"><code>{{ $supplierPayment->payment_no }}</code></p>
    </div>
    <div class="d-flex gap-2">
        {{-- SUPPLIER-PAYMENT-VOID-1 — ghalat payment ulti karo. Wajah lazmi; cash, supplier ka payable,
             GL aur bill — chaaron ek saath wapas. --}}
        @if(! $supplierPayment->isVoided())
            @can('tenant.supplier-payments.void')
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidPaymentModal">Void</button>
            @endcan
        @endif
        <a href="{{ url('/supplier-payments') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger">@foreach($errors->all() as $err)<div>{{ $err }}</div>@endforeach</div>
@endif

@if($supplierPayment->isVoided())
    <div class="alert alert-warning" id="payment-voided-note">
        Voided on {{ $supplierPayment->voided_at->format('Y-m-d H:i') }}
        by {{ $supplierPayment->voidedBy?->name ?? '—' }} — {{ $supplierPayment->void_reason }}.
        {{ number_format((float) $supplierPayment->amount, 2) }} went back into the cash/bank account and onto the supplier's balance.
    </div>
@endif

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

@if(! $supplierPayment->isVoided())
    @can('tenant.supplier-payments.void')
        <div class="modal fade" id="voidPaymentModal" tabindex="-1" aria-labelledby="voidPaymentTitle" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ url('/supplier-payments/' . $supplierPayment->id . '/void') }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="voidPaymentTitle">Void payment {{ $supplierPayment->payment_no }}?</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning small">
                                {{ number_format((float) $supplierPayment->amount, 2) }} comes back into
                                {{ $supplierPayment->cashBankAccount?->name ?? 'the cash/bank account' }} today,
                                {{ $supplierPayment->supplier?->name }} owes it again, and any bill it paid opens again.
                                The payment stays on record, marked voided.
                            </div>
                            <label for="void-payment-reason" class="form-label">Why is it being voided? <span class="text-danger">*</span></label>
                            <textarea name="reason" id="void-payment-reason" class="form-control" rows="2" required minlength="3" maxlength="500"
                                placeholder="e.g. entered twice, wrong amount, wrong supplier"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Void payment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @include('tenant.catering.partials.submit-guard')
    @endcan
@endif
@endsection
