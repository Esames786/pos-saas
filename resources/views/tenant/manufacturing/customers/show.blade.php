@extends('layouts.app')

@section('title', $customer->name . ' — Manufacturing Customer')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $customer->name }}</h1>
        <p class="fw-medium text-muted"><code>{{ $customer->code }}</code>
            &nbsp;<span class="badge bg-{{ $customer->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($customer->status) }}</span>
        </p>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.manufacturing.customers.edit')
            <a href="{{ url('/manufacturing/customers/' . $customer->id . '/edit') }}" class="btn btn-primary">
                <i class="ti ti-pencil me-1"></i>Edit
            </a>
        @endcan
        <a href="{{ url('/manufacturing/customers') }}" class="btn btn-light">
            <i class="ti ti-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif

<div class="alert alert-info d-flex gap-2 align-items-start">
    <i class="ti ti-info-circle fs-18 mt-1 flex-shrink-0"></i>
    <div>
        <strong>Manufacturing Customer</strong> — separate from your POS/Sales customer base.
        This record is for production orders, job-work, costing, and manufacturing reports.
        It does <strong>not</strong> affect POS sales, AR ledger, or customer payments.
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Identity</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Code</dt>
                    <dd class="col-sm-8"><code>{{ $customer->code }}</code></dd>

                    <dt class="col-sm-4 text-muted">Name</dt>
                    <dd class="col-sm-8">{{ $customer->name }}</dd>

                    <dt class="col-sm-4 text-muted">Company</dt>
                    <dd class="col-sm-8">{{ $customer->company_name ?: '—' }}</dd>

                    <dt class="col-sm-4 text-muted">Contact Person</dt>
                    <dd class="col-sm-8">{{ $customer->contact_person ?: '—' }}</dd>

                    <dt class="col-sm-4 text-muted">Tax Number</dt>
                    <dd class="col-sm-8">{{ $customer->tax_number ?: '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Contact Details</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Phone</dt>
                    <dd class="col-sm-8">{{ $customer->phone ?: '—' }}</dd>

                    <dt class="col-sm-4 text-muted">Mobile</dt>
                    <dd class="col-sm-8">{{ $customer->mobile ?: '—' }}</dd>

                    <dt class="col-sm-4 text-muted">Email</dt>
                    <dd class="col-sm-8">{{ $customer->email ?: '—' }}</dd>

                    <dt class="col-sm-4 text-muted">City</dt>
                    <dd class="col-sm-8">{{ $customer->city ?: '—' }}</dd>

                    <dt class="col-sm-4 text-muted">Country</dt>
                    <dd class="col-sm-8">{{ $customer->country ?: '—' }}</dd>

                    <dt class="col-sm-4 text-muted">Address</dt>
                    <dd class="col-sm-8">{{ $customer->address ?: '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    @if($customer->notes)
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Notes</h6></div>
            <div class="card-body">
                <p class="mb-0 text-muted">{{ $customer->notes }}</p>
            </div>
        </div>
    </div>
    @endif
</div>

@can('tenant.manufacturing.customers.destroy')
    @if($customer->status === 'active')
    <div class="mt-4 border-top pt-3">
        <form method="POST" action="{{ url('/manufacturing/customers/' . $customer->id) }}"
              onsubmit="return confirm('Deactivate this manufacturing customer?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm">Deactivate Customer</button>
        </form>
    </div>
    @endif
@endcan
@endsection
