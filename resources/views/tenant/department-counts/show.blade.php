@extends('layouts.app')

@section('title', 'Count ' . $session->count_no)

@php
    $expectedValue = $session->lines->sum(fn ($l) => (float) $l->expected_qty * (float) $l->average_cost);
    $countedValue  = $session->lines->sum(fn ($l) => (float) $l->counted_qty * (float) $l->average_cost);
    $positive      = $session->lines->where('variance_qty', '>', 0)->count();
    $negative      = $session->lines->where('variance_qty', '<', 0)->count();
@endphp

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">
            {{ $session->count_no }}
            @if($session->status === 'approved')
                <span class="badge bg-success align-middle">Approved</span>
            @elseif($session->status === 'submitted')
                <span class="badge bg-info align-middle">Submitted</span>
            @elseif($session->status === 'rejected')
                <span class="badge bg-danger align-middle">Rejected</span>
            @elseif($session->status === 'cancelled')
                <span class="badge bg-secondary align-middle">Cancelled</span>
            @else
                <span class="badge bg-warning text-dark align-middle">Draft</span>
            @endif
        </h1>
        <p class="fw-medium text-muted mb-0">{{ $session->department?->name }} · {{ $session->branch?->name }} · {{ $session->count_date?->format('Y-m-d') }}</p>
    </div>
    <div class="d-flex gap-2">
        @if($session->isDraft())
            @can('tenant.department-counts.edit')
                <a href="{{ url('/department-counts/' . $session->id . '/edit') }}" class="btn btn-primary">Continue Counting</a>
            @endcan
        @endif
        <a href="{{ url('/department-counts') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

{{-- Summary cards --}}
<div class="card mb-3">
    <div class="card-body row text-center g-3 small">
        <div class="col-6 col-md-2"><div class="text-muted">Expected Value</div><div class="fw-bold fs-6">{{ number_format($expectedValue, 2) }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Counted Value</div><div class="fw-bold fs-6">{{ number_format($countedValue, 2) }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Variance Value</div><div class="fw-bold fs-6 {{ $session->totalVarianceValue() < 0 ? 'text-danger' : 'text-success' }}">{{ number_format($session->totalVarianceValue(), 2) }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Positive Lines</div><div class="fw-bold fs-6 text-success">{{ $positive }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Negative Lines</div><div class="fw-bold fs-6 text-danger">{{ $negative }}</div></div>
        <div class="col-6 col-md-2"><div class="text-muted">Lines</div><div class="fw-bold fs-6">{{ $session->lines->count() }}</div></div>
    </div>
</div>

{{-- Status timeline --}}
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-4 small">
        <span><i class="ti ti-pencil me-1"></i>Created: <strong>{{ $session->created_at?->format('Y-m-d H:i') }}</strong> {{ $session->createdBy?->name ? 'by ' . $session->createdBy->name : '' }}</span>
        @if($session->submitted_at)
            <span><i class="ti ti-send me-1"></i>Submitted: <strong>{{ $session->submitted_at->format('Y-m-d H:i') }}</strong> by {{ $session->submittedBy?->name }}</span>
        @endif
        @if($session->approved_at)
            <span class="text-success"><i class="ti ti-check me-1"></i>Approved: <strong>{{ $session->approved_at->format('Y-m-d H:i') }}</strong> by {{ $session->approvedBy?->name }}</span>
        @endif
        @if($session->rejected_at)
            <span class="text-danger"><i class="ti ti-x me-1"></i>Rejected: <strong>{{ $session->rejected_at->format('Y-m-d H:i') }}</strong> by {{ $session->rejectedBy?->name }} — {{ $session->rejection_reason }}</span>
        @endif
        @if($session->cancelled_at)
            <span class="text-muted"><i class="ti ti-ban me-1"></i>Cancelled: <strong>{{ $session->cancelled_at->format('Y-m-d H:i') }}</strong> by {{ $session->cancelledBy?->name }}</span>
        @endif
    </div>
</div>

{{-- Approval section --}}
@if($session->isSubmitted())
    <div class="card mb-3 border-info-subtle">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="small flex-grow-1">
                <strong>Awaiting approval.</strong> Approving will adjust <strong>department custody stock</strong> to match the counted quantities
                (custody adjustments only — official branch stock and accounting stay unchanged).
            </div>
            @can('tenant.department-counts.approve')
                <form method="POST" action="{{ url('/department-counts/' . $session->id . '/approve') }}"
                      onsubmit="return confirm('Approve this count? Department custody stock will be adjusted to the counted quantities.')">
                    @csrf
                    <button class="btn btn-success"><i class="ti ti-check me-1"></i>Approve</button>
                </form>
            @endcan
            @can('tenant.department-counts.reject')
                <form method="POST" action="{{ url('/department-counts/' . $session->id . '/reject') }}" class="d-flex gap-2">
                    @csrf
                    <input name="rejection_reason" class="form-control form-control-sm" placeholder="Rejection reason (required)" required>
                    <button class="btn btn-outline-danger">Reject</button>
                </form>
            @endcan
        </div>
    </div>
@endif

{{-- Lines --}}
<div class="card">
    <div class="card-header"><strong>Count Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Product</th><th>SKU</th>
                    <th class="text-end">Expected</th><th class="text-end">Counted</th>
                    <th class="text-end">Variance Qty</th><th class="text-end">Avg Cost</th><th class="text-end">Variance Value</th>
                    <th>Reason</th><th>Notes</th>
                </tr>
            </thead>
            <tbody>
            @foreach($session->lines as $line)
                <tr>
                    <td>{{ $line->product?->name }}</td>
                    <td><code>{{ $line->product?->sku }}</code></td>
                    <td class="text-end">{{ number_format($line->expected_qty, 3) }}</td>
                    <td class="text-end fw-semibold">{{ number_format($line->counted_qty, 3) }}</td>
                    <td class="text-end {{ (float) $line->variance_qty < 0 ? 'text-danger fw-semibold' : ((float) $line->variance_qty > 0 ? 'text-success fw-semibold' : 'text-muted') }}">
                        {{ number_format($line->variance_qty, 3) }}
                    </td>
                    <td class="text-end">{{ number_format($line->average_cost, 4) }}</td>
                    <td class="text-end {{ (float) $line->variance_value < 0 ? 'text-danger' : '' }}">{{ number_format($line->variance_value, 2) }}</td>
                    <td>
                        @if($line->reason_code)
                            <span class="badge bg-light text-dark border">{{ ucwords(str_replace('_', ' ', $line->reason_code)) }}</span>
                        @else — @endif
                    </td>
                    <td class="small text-muted">{{ $line->notes ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if($session->isApproved() && $session->adjustments->count())
        <div class="card-footer small text-muted">
            <i class="ti ti-check me-1"></i>{{ $session->adjustments->count() }} custody adjustment(s) posted to the department stock ledger (reference {{ $session->count_no }}).
        </div>
    @endif
</div>
@endsection
