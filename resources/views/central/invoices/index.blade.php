@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
@php
    $statusBadge = [
        'draft'          => 'bg-secondary',
        'issued'         => 'bg-info text-dark',
        'paid'           => 'bg-success',
        'partially_paid' => 'bg-warning text-dark',
        'void'           => 'bg-dark',
        'overdue'        => 'bg-danger',
    ];
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Invoices</h1>
        <p class="text-muted mb-0">Subscription billing and manual payments.</p>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/invoices') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach(['draft','issued','paid','partially_paid','void','overdue'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tenant</label>
                <select name="tenant_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach($tenants as $t)
                        <option value="{{ $t->id }}" @selected((string) request('tenant_id') === (string) $t->id)>{{ $t->business_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-dark">Filter</button>
                <a href="{{ url('/invoices') }}" class="btn btn-sm btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Tenant</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance</th>
                    <th>Due</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($invoices as $invoice)
                <tr>
                    <td><code>{{ $invoice->invoice_no }}</code></td>
                    <td>{{ $invoice->tenant?->business_name }}</td>
                    <td>{{ $invoice->plan?->name ?? '—' }}</td>
                    <td><span class="badge {{ $statusBadge[$invoice->status] ?? 'bg-secondary' }}">{{ ucfirst(str_replace('_',' ',$invoice->status)) }}</span></td>
                    <td class="text-end">{{ $invoice->currency_code }} {{ number_format((float) $invoice->total_amount, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $invoice->paid_amount, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $invoice->balance_amount, 2) }}</td>
                    <td class="text-muted small">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="text-end">
                        <a href="{{ url('/invoices/' . $invoice->id) }}" class="btn btn-sm btn-primary">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No invoices yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($invoices->hasPages())
        <div class="p-3">{{ $invoices->links() }}</div>
    @endif
</div>
@endsection
