@extends('layouts.app')

@section('title', 'Manufacturing Customers')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Manufacturing Customers</h1>
        <p class="fw-medium text-muted">Client/project parties for production orders and job-work. <strong>Separate from POS/Sales customers.</strong></p>
    </div>
    @can('tenant.manufacturing.customers.create')
        <a href="{{ url('/manufacturing/customers/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Add Customer
        </a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="alert alert-info d-flex gap-2 align-items-start mb-4">
    <i class="ti ti-info-circle fs-18 mt-1 flex-shrink-0"></i>
    <div>
        <strong>These are Manufacturing Customers</strong> — separate from your POS/Sales customer base.
        They are used for production orders, job-work, costing, and manufacturing reports.
        They do <strong>not</strong> affect POS sales, AR ledger, or customer payments.
    </div>
</div>

{{-- Filters --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ url('/manufacturing/customers') }}" class="row g-2 align-items-end">
            <div class="col-sm-6 col-md-5">
                <input type="text" name="q" class="form-control" placeholder="Search code, name, company, contact, phone…"
                       value="{{ $filters['q'] ?? '' }}">
            </div>
            <div class="col-sm-3 col-md-2">
                <select name="status" class="form-select">
                    <option value="">All status</option>
                    <option value="active"   {{ ($filters['status'] ?? '') === 'active'   ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <div class="col-sm-3 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                @if(!empty(array_filter($filters)))
                    <a href="{{ url('/manufacturing/customers') }}" class="btn btn-outline-secondary" title="Clear"><i class="ti ti-x"></i></a>
                @endif
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Manufacturing customer list</caption>
            <thead class="thead-light">
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Company</th>
                    <th scope="col">Contact Person</th>
                    <th scope="col">Phone</th>
                    <th scope="col">Email</th>
                    <th scope="col">City</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($customers as $customer)
                <tr>
                    <td><code>{{ $customer->code }}</code></td>
                    <td><strong>{{ $customer->name }}</strong></td>
                    <td>{{ $customer->company_name ?: '—' }}</td>
                    <td>{{ $customer->contact_person ?: '—' }}</td>
                    <td>{{ $customer->phone ?: ($customer->mobile ?: '—') }}</td>
                    <td>{{ $customer->email ?: '—' }}</td>
                    <td>{{ $customer->city ?: '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $customer->status === 'active' ? 'success' : 'secondary' }}">
                            {{ ucfirst($customer->status) }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.manufacturing.customers.show')
                            <a href="{{ url('/manufacturing/customers/' . $customer->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @can('tenant.manufacturing.customers.edit')
                            <a href="{{ url('/manufacturing/customers/' . $customer->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                        @can('tenant.manufacturing.customers.destroy')
                            @if($customer->status === 'active')
                            <form method="POST" action="{{ url('/manufacturing/customers/' . $customer->id) }}"
                                  class="d-inline" onsubmit="return confirm('Deactivate this customer?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                            </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-5">
                        No manufacturing customers found.
                        @can('tenant.manufacturing.customers.create')
                            <a href="{{ url('/manufacturing/customers/create') }}">Add the first one.</a>
                        @endcan
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($customers->hasPages())
    <div class="card-footer">{{ $customers->links() }}</div>
    @endif
</div>
@endsection
