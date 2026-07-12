@extends('layouts.app')

@section('title', 'Invoice ' . $invoice->invoice_no)

@php
    $statusBadge = [
        'draft' => 'bg-secondary', 'issued' => 'bg-info text-dark', 'paid' => 'bg-success',
        'partially_paid' => 'bg-warning text-dark', 'void' => 'bg-dark', 'overdue' => 'bg-danger',
    ];
    $canPay = !$invoice->isPaid() && !$invoice->isVoid();
@endphp

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $invoice->invoice_no }}</h1>
        <p class="text-muted mb-0">
            {{ $invoice->plan?->name ?? '—' }} —
            <span class="badge {{ $statusBadge[$invoice->status] ?? 'bg-secondary' }}">{{ ucfirst(str_replace('_',' ',$invoice->status)) }}</span>
        </p>
    </div>
    <a href="{{ url('/billing') }}" class="btn btn-outline-secondary">Back</a>
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
            <div class="card-header"><h5 class="mb-0">Invoice</h5></div>
            <div class="card-body row g-3">
                <div class="col-md-6"><small class="text-muted d-block">Period</small><span class="fw-semibold">{{ $invoice->period_start?->format('Y-m-d') ?? '—' }} → {{ $invoice->period_end?->format('Y-m-d') ?? '—' }}</span></div>
                <div class="col-md-6"><small class="text-muted d-block">Due Date</small><span class="fw-semibold">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</span></div>
                <div class="col-md-3"><small class="text-muted d-block">Subtotal</small>{{ number_format((float) $invoice->subtotal, 2) }}</div>
                <div class="col-md-3"><small class="text-muted d-block">Discount</small>{{ number_format((float) $invoice->discount_amount, 2) }}</div>
                <div class="col-md-3"><small class="text-muted d-block">Tax</small>{{ number_format((float) $invoice->tax_amount, 2) }}</div>
                <div class="col-md-3"><small class="text-muted d-block">Total</small><span class="fw-bold">{{ $invoice->currency_code }} {{ number_format((float) $invoice->total_amount, 2) }}</span></div>
                <div class="col-md-3"><small class="text-muted d-block">Paid</small><span class="text-success">{{ number_format((float) $invoice->paid_amount, 2) }}</span></div>
                <div class="col-md-3"><small class="text-muted d-block">Balance</small><span class="fw-bold {{ (float) $invoice->balance_amount > 0 ? 'text-danger' : 'text-success' }}">{{ number_format((float) $invoice->balance_amount, 2) }}</span></div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Payments</h5></div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th><th>Status</th><th>Proof</th></tr></thead>
                    <tbody>
                    @forelse($invoice->payments as $payment)
                        <tr>
                            <td>{{ $payment->payment_date?->format('Y-m-d') }}</td>
                            <td>{{ $payment->gateway?->name ?? $payment->payment_method_code ?? '—' }}</td>
                            <td class="small">{{ $payment->reference_no ?? '—' }}</td>
                            <td class="text-end">{{ $payment->currency_code }} {{ number_format((float) $payment->amount, 2) }}</td>
                            <td><span class="badge {{ $payment->status === 'verified' ? 'bg-success' : ($payment->status === 'rejected' ? 'bg-danger' : 'bg-secondary') }}">{{ ucfirst($payment->status) }}</span></td>
                            <td>
                                @if($payment->proof_path)
                                    <a href="{{ url('/billing/invoices/' . $invoice->id . '/payments/' . $payment->id . '/proof') }}" class="btn btn-sm btn-outline-secondary">Download</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No payments yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        @if($canPay)
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Upload Payment Proof</h5></div>
            <div class="card-body">
                <p class="small text-muted">Pay via bank transfer / JazzCash / EasyPaisa, then upload your proof here. The provider will verify it.</p>
                <form method="POST" action="{{ url('/billing/invoices/' . $invoice->id . '/payments') }}" enctype="multipart/form-data" class="row g-2">
                    @csrf
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
                        <label class="form-label">Method</label>
                        <select name="payment_method_code" class="form-select form-select-sm">
                            @foreach(['bank' => 'Bank Transfer', 'jazzcash' => 'JazzCash', 'easypaisa' => 'EasyPaisa', 'cash' => 'Cash', 'manual' => 'Other'] as $code => $label)
                                <option value="{{ $code }}" @selected(old('payment_method_code') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Payment Date</label>
                        <input name="payment_date" type="date" class="form-control form-control-sm" value="{{ old('payment_date', now()->toDateString()) }}" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Reference No</label>
                        <input name="reference_no" class="form-control form-control-sm" value="{{ old('reference_no') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Payment Proof</label>
                        <input name="proof" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="form-control form-control-sm" required>
                        <small class="text-muted d-block mt-1">
                            Upload your bank receipt, transaction screenshot, or PDF proof.
                            Allowed: JPG, PNG, WEBP, PDF · max 5 MB.
                            <strong>Do not upload passwords or card PINs.</strong>
                        </small>
                        @error('proof') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <input name="notes" class="form-control form-control-sm" value="{{ old('notes') }}">
                    </div>
                    <div class="col-12 text-end">
                        <button class="btn btn-primary btn-sm">Upload Proof</button>
                    </div>
                </form>
            </div>
        </div>
        @else
            <div class="alert alert-secondary">This invoice is {{ $invoice->status }} and no longer accepts payment proofs.</div>
        @endif
    </div>
</div>
@endsection
