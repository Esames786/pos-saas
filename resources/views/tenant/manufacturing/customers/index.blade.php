@extends('layouts.app')

@section('title', 'Manufacturing Customers')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Manufacturing Customers</h4>
                <h6>Client/project parties for production orders and job-work — separate from POS/Sales customers</h6>
            </div>
            @can('tenant.manufacturing.customers.create')
            <div class="page-btn">
                <a href="{{ url('/manufacturing/customers/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Add Customer
                </a>
            </div>
            @endcan
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif

        <div class="alert alert-info">
            <i class="ti ti-info-circle me-1"></i>
            <strong>These are Manufacturing Customers</strong> — separate from your POS/Sales customer base. Used for production orders, job-work and manufacturing reports. They do not affect POS sales, AR ledger or customer payments.
        </div>

        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ url('/manufacturing/customers') }}" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-md-5">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="Code, name, company, contact, phone…"
                               value="{{ $filters['q'] ?? '' }}">
                    </div>
                    <div class="col-sm-3 col-md-3">
                        <label class="form-label mb-1">Status</label>
                        <select name="status" class="select form-select">
                            <option value="">All status</option>
                            <option value="active"   {{ ($filters['status'] ?? '') === 'active'   ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>
                    <div class="col-sm-3 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        @if(!empty(array_filter($filters)))
                            <a href="{{ url('/manufacturing/customers') }}" class="btn btn-light" title="Clear"><i class="ti ti-x"></i></a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
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
                                <td><a href="{{ url('/manufacturing/customers/' . $customer->id) }}" class="fw-semibold">{{ $customer->code }}</a></td>
                                <td>{{ $customer->name }}</td>
                                <td>{{ $customer->company_name ?: '—' }}</td>
                                <td>{{ $customer->contact_person ?: '—' }}</td>
                                <td>{{ $customer->phone ?: ($customer->mobile ?: '—') }}</td>
                                <td>{{ $customer->email ?: '—' }}</td>
                                <td>{{ $customer->city ?: '—' }}</td>
                                <td>
                                    <span class="badge {{ $customer->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                                        {{ ucfirst($customer->status) }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    @can('tenant.manufacturing.customers.show')
                                        <a href="{{ url('/manufacturing/customers/' . $customer->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @endcan
                                    @can('tenant.manufacturing.customers.edit')
                                        <a href="{{ url('/manufacturing/customers/' . $customer->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1" title="Edit"><i class="ti ti-pencil"></i></a>
                                    @endcan
                                    @can('tenant.manufacturing.customers.destroy')
                                        @if($customer->status === 'active')
                                        <form method="POST" action="{{ url('/manufacturing/customers/' . $customer->id) }}"
                                              class="d-inline" onsubmit="return confirm('Deactivate this customer?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate"><i class="ti ti-ban"></i></button>
                                        </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
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
                    <div class="mt-3">{{ $customers->links() }}</div>
                @endif
            </div>
        </div>
@endsection
