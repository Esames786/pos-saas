@extends('layouts.app')

@section('title', 'Upgrade Requests')

@section('content')
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

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Upgrade Requests</h1>
        <p class="text-muted mb-0">Tenant-initiated plan change requests.</p>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/subscription-requests') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach(['pending','approved','invoiced','paid','rejected','cancelled'] as $s)
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
                <a href="{{ url('/subscription-requests') }}" class="btn btn-sm btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Tenant</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Invoice</th>
                    <th>Requested</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($requests as $req)
                <tr>
                    <td>{{ $req->id }}</td>
                    <td>{{ $req->tenant?->business_name }}</td>
                    <td>{{ $req->currentPlan?->name ?? '—' }}</td>
                    <td class="fw-semibold">{{ $req->requestedPlan?->name ?? '—' }}</td>
                    <td>{{ ucfirst($req->type) }}</td>
                    <td><span class="badge {{ $statusBadge[$req->status] ?? 'bg-secondary' }}">{{ ucfirst(str_replace('_',' ',$req->status)) }}</span></td>
                    <td>@if($req->invoice)<code>{{ $req->invoice->invoice_no }}</code>@else <span class="text-muted">—</span>@endif</td>
                    <td class="text-muted small">{{ $req->created_at?->format('Y-m-d') }}</td>
                    <td class="text-end">
                        <a href="{{ url('/subscription-requests/' . $req->id) }}" class="btn btn-sm btn-primary">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No upgrade requests yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($requests->hasPages())
        <div class="p-3">{{ $requests->links() }}</div>
    @endif
</div>
@endsection
