@extends('layouts.app')

@section('title', 'Billing')

@php
    $statusBadge = [
        'draft' => 'bg-secondary', 'issued' => 'bg-info text-dark', 'paid' => 'bg-success',
        'partially_paid' => 'bg-warning text-dark', 'void' => 'bg-dark', 'overdue' => 'bg-danger',
    ];
    $sub = $tenant->subscription;
@endphp

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Billing</h1>
        <p class="text-muted mb-0">Your subscription invoices and payments.</p>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="card mb-3">
    <div class="card-body row g-3">
        <div class="col-md-3"><small class="text-muted d-block">Plan</small><span class="fw-semibold">{{ $sub?->plan?->name ?? 'No plan' }}</span></div>
        <div class="col-md-3"><small class="text-muted d-block">Subscription</small><span class="fw-semibold">{{ ucfirst(str_replace('_',' ', $sub?->status ?? '-')) }}</span></div>
        <div class="col-md-3"><small class="text-muted d-block">Trial Ends</small><span class="fw-semibold">{{ $sub?->trial_ends_at?->format('Y-m-d') ?? '—' }}</span></div>
        <div class="col-md-3"><small class="text-muted d-block">Current Period Ends</small><span class="fw-semibold">{{ $sub?->current_period_ends_at?->format('Y-m-d') ?? '—' }}</span></div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Invoice #</th><th>Plan</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Due</th><th class="text-end">Action</th></tr>
            </thead>
            <tbody>
            @forelse($invoices as $invoice)
                <tr>
                    <td><code>{{ $invoice->invoice_no }}</code></td>
                    <td>{{ $invoice->plan?->name ?? '—' }}</td>
                    <td><span class="badge {{ $statusBadge[$invoice->status] ?? 'bg-secondary' }}">{{ ucfirst(str_replace('_',' ',$invoice->status)) }}</span></td>
                    <td class="text-end">{{ $invoice->currency_code }} {{ number_format((float) $invoice->total_amount, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $invoice->paid_amount, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $invoice->balance_amount, 2) }}</td>
                    <td class="text-muted small">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="text-end"><a href="{{ url('/billing/invoices/' . $invoice->id) }}" class="btn btn-sm btn-primary">View</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No invoices yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($invoices->hasPages())
        <div class="p-3">{{ $invoices->links() }}</div>
    @endif
</div>
@endsection
