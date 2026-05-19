@extends('layouts.app')

@section('title', 'Customer: ' . $customer->name)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Customer</h1>
        <p class="fw-medium">{{ $customer->name }}</p>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.customers.edit')
            <a href="{{ url('/customers/' . $customer->id . '/edit') }}" class="btn btn-primary">Edit</a>
        @endcan
        <a href="{{ url('/customers') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

<div class="card mb-3">
    <div class="card-header"><strong>Customer Details</strong></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Code</dt>
            <dd class="col-sm-9"><code>{{ $customer->code ?? '—' }}</code></dd>

            <dt class="col-sm-3">Name</dt>
            <dd class="col-sm-9">{{ $customer->name }}</dd>

            <dt class="col-sm-3">Phone</dt>
            <dd class="col-sm-9">{{ $customer->phone ?? '—' }}</dd>

            <dt class="col-sm-3">Email</dt>
            <dd class="col-sm-9">{{ $customer->email ?? '—' }}</dd>

            @if($customer->tax_number)
                <dt class="col-sm-3">Tax Number</dt>
                <dd class="col-sm-9">{{ $customer->tax_number }}</dd>
            @endif

            @if($customer->gender)
                <dt class="col-sm-3">Gender</dt>
                <dd class="col-sm-9">{{ ucfirst($customer->gender) }}</dd>
            @endif

            @if($customer->date_of_birth)
                <dt class="col-sm-3">Date of Birth</dt>
                <dd class="col-sm-9">{{ $customer->date_of_birth->format('Y-m-d') }}</dd>
            @endif

            @if($customer->address)
                <dt class="col-sm-3">Address</dt>
                <dd class="col-sm-9">{{ $customer->address }}</dd>
            @endif

            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <span class="badge bg-{{ $customer->status === 'active' ? 'success' : 'secondary' }}">
                    {{ ucfirst($customer->status) }}
                </span>
            </dd>

            <dt class="col-sm-3">Total Sales</dt>
            <dd class="col-sm-9">{{ number_format($customer->sales_orders_count) }}</dd>
        </dl>
    </div>
</div>
@endsection
