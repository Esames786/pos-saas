@extends('layouts.app')

@section('title', 'Customer Payment ' . $customerPayment->payment_no)

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Customer Payment {{ $customerPayment->payment_no }}</h4>
                <h6>Finance — Customer Payment</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/finance/customer-payments') }}" class="btn btn-secondary">Back</a>
            </div>
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4"><small class="text-muted d-block">Customer</small>{{ $customerPayment->customer->name ?? '—' }}</div>
                            <div class="col-md-4"><small class="text-muted d-block">Payment Date</small>{{ optional($customerPayment->payment_date)->format('Y-m-d') }}</div>
                            <div class="col-md-4"><small class="text-muted d-block">Amount</small><strong>{{ number_format((float) $customerPayment->amount, 2) }}</strong></div>
                            <div class="col-md-4"><small class="text-muted d-block">Branch</small>{{ $customerPayment->branch->name ?? '—' }}</div>
                            <div class="col-md-4"><small class="text-muted d-block">Against Sale</small>{{ $customerPayment->salesOrder->sale_no ?? 'On account' }}</div>
                            <div class="col-md-4"><small class="text-muted d-block">Method</small>{{ $customerPayment->payment_method ?: '—' }}</div>
                            <div class="col-md-4"><small class="text-muted d-block">Deposited To</small>{{ $customerPayment->cashBankAccount->name ?? '— (no cash/bank effect)' }}</div>
                            <div class="col-md-4"><small class="text-muted d-block">Reference</small>{{ $customerPayment->reference_no ?: '—' }}</div>
                            <div class="col-md-4"><small class="text-muted d-block">Recorded By</small>{{ $customerPayment->postedBy->name ?? '—' }}</div>
                            @if($customerPayment->notes)
                            <div class="col-12"><small class="text-muted d-block">Notes</small>{{ $customerPayment->notes }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body text-center">
                        <small class="text-muted d-block">Payment Amount</small>
                        <h3 class="fw-bold text-success">{{ number_format((float) $customerPayment->amount, 2) }}</h3>
                        @if($customerPayment->customer)
                            <small class="text-muted d-block mt-2">Customer balance now</small>
                            <div class="fw-semibold">{{ number_format((float) $customerPayment->customer->current_balance, 2) }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
@endsection
