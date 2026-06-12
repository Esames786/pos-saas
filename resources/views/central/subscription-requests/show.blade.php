@extends('layouts.app')

@section('title', 'Upgrade Request')

@php
    $statusBadge = [
        'pending'   => 'bg-info text-dark',
        'approved'  => 'bg-primary',
        'invoiced'  => 'bg-warning text-dark',
        'paid'      => 'bg-success',
        'rejected'  => 'bg-danger',
        'cancelled' => 'bg-secondary',
    ];
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Upgrade Request #{{ $changeRequest->id }}</h1>
        <p class="text-muted mb-0">{{ $changeRequest->tenant?->business_name }} — submitted {{ $changeRequest->created_at?->format('Y-m-d H:i') }}</p>
    </div>
    <div>
        <a href="{{ url('/subscription-requests') }}" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="card mb-3">
    <div class="card-body row g-3">
        <div class="col-md-3">
            <small class="text-muted d-block">Status</small>
            <span class="badge {{ $statusBadge[$changeRequest->status] ?? 'bg-secondary' }}">{{ ucfirst(str_replace('_',' ',$changeRequest->status)) }}</span>
        </div>
        <div class="col-md-3"><small class="text-muted d-block">From Plan</small><span class="fw-semibold">{{ $changeRequest->currentPlan?->name ?? '—' }}</span></div>
        <div class="col-md-3">
            <small class="text-muted d-block">To Plan</small>
            <span class="fw-semibold">{{ $changeRequest->requestedPlan?->name ?? '—' }}</span>
            @if($changeRequest->requestedPlan)
                <div class="text-muted small">{{ $changeRequest->requestedPlan->currency_code }} {{ number_format((float) $changeRequest->requestedPlan->price, 2) }}</div>
            @endif
        </div>
        <div class="col-md-3"><small class="text-muted d-block">Type</small><span class="fw-semibold">{{ ucfirst($changeRequest->type) }}</span></div>
    </div>
</div>

@if($changeRequest->customer_notes)
    <div class="card mb-3"><div class="card-body">
        <small class="text-muted d-block">Customer Notes</small>{{ $changeRequest->customer_notes }}
    </div></div>
@endif

@if($changeRequest->invoice)
    <div class="card mb-3">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <small class="text-muted d-block">Linked Invoice</small>
                <code>{{ $changeRequest->invoice->invoice_no }}</code>
                — {{ $changeRequest->invoice->currency_code }} {{ number_format((float) $changeRequest->invoice->total_amount, 2) }}
                <span class="badge bg-secondary ms-1">{{ ucfirst(str_replace('_',' ',$changeRequest->invoice->status)) }}</span>
            </div>
            <a href="{{ url('/invoices/' . $changeRequest->invoice->id) }}" class="btn btn-sm btn-primary">Manage Invoice</a>
        </div>
    </div>
@endif

@if($changeRequest->admin_notes)
    <div class="card mb-3"><div class="card-body">
        <small class="text-muted d-block">Admin Notes</small>{{ $changeRequest->admin_notes }}
    </div></div>
@endif

@if($changeRequest->status === 'pending')
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card border-success">
                <div class="card-body">
                    <h5 class="card-title">Approve</h5>
                    <p class="text-muted small">Creates an upgrade invoice for the requested plan. Once paid &amp; verified, the subscription upgrades automatically.</p>
                    <form method="POST" action="{{ url('/subscription-requests/' . $changeRequest->id . '/approve') }}">
                        @csrf
                        <textarea class="form-control mb-2" name="admin_notes" rows="2" placeholder="Notes (optional)"></textarea>
                        <button type="submit" class="btn btn-success">Approve &amp; Create Invoice</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-danger">
                <div class="card-body">
                    <h5 class="card-title">Reject</h5>
                    <p class="text-muted small">Decline this upgrade request.</p>
                    <form method="POST" action="{{ url('/subscription-requests/' . $changeRequest->id . '/reject') }}">
                        @csrf
                        <textarea class="form-control mb-2" name="admin_notes" rows="2" placeholder="Reason (optional)"></textarea>
                        <button type="submit" class="btn btn-outline-danger">Reject Request</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection
