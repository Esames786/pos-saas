@extends('layouts.app')

@section('title', 'Invoice ' . $invoice->invoice_no)

@php
    $statusBadge = [
        'draft'          => 'bg-secondary',
        'issued'         => 'bg-info text-dark',
        'paid'           => 'bg-success',
        'partially_paid' => 'bg-warning text-dark',
        'void'           => 'bg-dark',
        'overdue'        => 'bg-danger',
    ];
    $canPay = !$invoice->isPaid() && !$invoice->isVoid();
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">{{ $invoice->invoice_no }}</h1>
        <p class="text-muted mb-0">
            {{ $invoice->tenant?->business_name }} —
            <span class="badge {{ $statusBadge[$invoice->status] ?? 'bg-secondary' }}">{{ ucfirst(str_replace('_',' ',$invoice->status)) }}</span>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ url('/invoices') }}" class="btn btn-outline-secondary">Back</a>
        @if(!$invoice->isPaid() && !$invoice->isVoid())
            <form method="POST" action="{{ url('/invoices/' . $invoice->id . '/void') }}"
                  onsubmit="return confirm('Void this invoice?');">
                @csrf
                <button class="btn btn-outline-danger">Void</button>
            </form>
        @endif
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="row">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Invoice Details</h5></div>
            <div class="card-body row g-3">
                <div class="col-md-6"><small class="text-muted d-block">Tenant</small><span class="fw-semibold">{{ $invoice->tenant?->business_name }}</span></div>
                <div class="col-md-6"><small class="text-muted d-block">Plan</small><span class="fw-semibold">{{ $invoice->plan?->name ?? '—' }}</span></div>
                <div class="col-md-6"><small class="text-muted d-block">Type</small><span class="fw-semibold">{{ ucfirst($invoice->invoice_type) }}</span></div>
                <div class="col-md-6"><small class="text-muted d-block">Period</small><span class="fw-semibold">{{ $invoice->period_start?->format('Y-m-d') ?? '—' }} → {{ $invoice->period_end?->format('Y-m-d') ?? '—' }}</span></div>
                <div class="col-md-6"><small class="text-muted d-block">Due Date</small><span class="fw-semibold">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</span></div>
                <div class="col-md-6"><small class="text-muted d-block">Paid At</small><span class="fw-semibold">{{ $invoice->paid_at?->format('Y-m-d H:i') ?? '—' }}</span></div>
                @if($invoice->notes)
                    <div class="col-12"><small class="text-muted d-block">Notes</small>{{ $invoice->notes }}</div>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Payments</h5></div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead>
                        <tr><th>Date</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th><th>Status</th><th>Verified By</th></tr>
                    </thead>
                    <tbody>
                    @forelse($invoice->payments as $payment)
                        <tr>
                            <td>{{ $payment->payment_date?->format('Y-m-d') }}</td>
                            <td>{{ $payment->gateway?->name ?? $payment->payment_method_code ?? '—' }}</td>
                            <td class="small">{{ $payment->reference_no ?? '—' }}</td>
                            <td class="text-end">{{ $payment->currency_code }} {{ number_format((float) $payment->amount, 2) }}</td>
                            <td><span class="badge {{ $payment->status === 'verified' ? 'bg-success' : ($payment->status === 'rejected' ? 'bg-danger' : 'bg-secondary') }}">{{ ucfirst($payment->status) }}</span></td>
                            <td class="small text-muted">{{ $payment->verifiedBy?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No payments recorded.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Amounts</h5></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td>Subtotal</td><td class="text-end">{{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
                    <tr><td>Discount</td><td class="text-end">- {{ number_format((float) $invoice->discount_amount, 2) }}</td></tr>
                    <tr><td>Tax</td><td class="text-end">+ {{ number_format((float) $invoice->tax_amount, 2) }}</td></tr>
                    <tr class="fw-bold"><td>Total</td><td class="text-end">{{ $invoice->currency_code }} {{ number_format((float) $invoice->total_amount, 2) }}</td></tr>
                    <tr class="text-success"><td>Paid</td><td class="text-end">{{ number_format((float) $invoice->paid_amount, 2) }}</td></tr>
                    <tr class="fw-bold {{ (float) $invoice->balance_amount > 0 ? 'text-danger' : 'text-success' }}"><td>Balance</td><td class="text-end">{{ number_format((float) $invoice->balance_amount, 2) }}</td></tr>
                </table>
            </div>
        </div>

        @if($canPay)
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Record Payment</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ url('/invoices/' . $invoice->id . '/payments') }}" class="row g-2">
                    @csrf
                    <div class="col-md-6">
                        <label class="form-label">Gateway</label>
                        <select name="payment_gateway_id" class="form-select form-select-sm">
                            <option value="">— Manual / None —</option>
                            @foreach($gateways as $g)
                                <option value="{{ $g->id }}" @selected(old('payment_gateway_id') == $g->id)>{{ $g->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Method Code</label>
                        <input name="payment_method_code" class="form-control form-control-sm" value="{{ old('payment_method_code') }}" placeholder="bank / jazzcash / cash">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Amount</label>
                        <input name="amount" type="number" step="0.01" min="0.01" class="form-control form-control-sm"
                               value="{{ old('amount', number_format((float) $invoice->balance_amount, 2, '.', '')) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Currency</label>
                        <input name="currency_code" maxlength="3" class="form-control form-control-sm" value="{{ old('currency_code', $invoice->currency_code) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Payment Date</label>
                        <input name="payment_date" type="date" class="form-control form-control-sm" value="{{ old('payment_date', now()->toDateString()) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            @foreach(['verified','pending','rejected'] as $st)
                                <option value="{{ $st }}" @selected(old('status','verified') === $st)>{{ ucfirst($st) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Reference No</label>
                        <input name="reference_no" class="form-control form-control-sm" value="{{ old('reference_no') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <input name="notes" class="form-control form-control-sm" value="{{ old('notes') }}">
                    </div>
                    <div class="col-12 text-end">
                        <button class="btn btn-primary btn-sm">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
