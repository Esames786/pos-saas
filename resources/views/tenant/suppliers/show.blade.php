@extends('layouts.app')

@section('title', 'Supplier: ' . $supplier->name)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $supplier->name }}</h1>
        <p class="fw-medium">Code: <code>{{ $supplier->code }}</code></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ url('/suppliers') }}" class="btn btn-light">Back</a>
        @can('tenant.suppliers.ledger')
            <a href="{{ url('/suppliers/' . $supplier->id . '/ledger') }}" class="btn btn-dark">Ledger</a>
        @endcan
        @can('tenant.suppliers.edit')
            <a href="{{ url('/suppliers/' . $supplier->id . '/edit') }}" class="btn btn-primary">Edit</a>
        @endcan
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Details</strong></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Contact Person</dt>
            <dd class="col-sm-9">{{ $supplier->contact_person ?: '—' }}</dd>

            <dt class="col-sm-3">Phone</dt>
            <dd class="col-sm-9">{{ $supplier->phone ?: '—' }}</dd>

            <dt class="col-sm-3">Email</dt>
            <dd class="col-sm-9">{{ $supplier->email ?: '—' }}</dd>

            <dt class="col-sm-3">Tax Number</dt>
            <dd class="col-sm-9">{{ $supplier->tax_number ?: '—' }}</dd>

            <dt class="col-sm-3">Payment Terms</dt>
            <dd class="col-sm-9">{{ $supplier->payment_terms_days }} days</dd>

            <dt class="col-sm-3">Opening Balance</dt>
            <dd class="col-sm-9">{{ number_format($supplier->opening_balance, 2) }}</dd>

            <dt class="col-sm-3">Current Balance</dt>
            <dd class="col-sm-9"><strong>{{ number_format($supplier->current_balance, 2) }}</strong></dd>

            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <span class="badge bg-{{ $supplier->status === 'active' ? 'success' : 'secondary' }}">
                    {{ ucfirst($supplier->status) }}
                </span>
            </dd>

            @if($supplier->address)
                <dt class="col-sm-3">Address</dt>
                <dd class="col-sm-9">{{ $supplier->address }}</dd>
            @endif
        </dl>
    </div>
</div>
@endsection
