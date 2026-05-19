@extends('layouts.app')

@section('title', 'Tenants')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Tenants</h1>
        <p class="fw-medium">Manage customer accounts, domains, databases, and activation status.</p>
    </div>

    @can('central.tenants.create')
        <a href="{{ url('/tenants/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1"></i>Create Tenant
        </a>
    @endcan
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/tenants') }}" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Business, owner, email, code">
            </div>

            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    @foreach(['pending','active','suspended','cancelled'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-dark">
                    <i class="ti ti-search me-1"></i>Filter
                </button>
                <a href="{{ url('/tenants') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Business</th>
                    <th>Primary Domain</th>
                    <th>Plan</th>
                    <th>DB</th>
                    <th>Status</th>
                    <th>Trial Ends</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>

            <tbody>
            @forelse($tenants as $tenant)
                @php
                    $primaryDomain = $tenant->domains->where('is_primary', true)->first();
                    $dbStatus = $tenant->database?->migration_status ?: 'not created';
                @endphp

                <tr>
                    <td><span class="fw-bold">{{ $tenant->tenant_code }}</span></td>
                    <td>
                        <div class="fw-semibold">{{ $tenant->business_name }}</div>
                        <small class="text-muted">{{ $tenant->owner_email }}</small>
                    </td>
                    <td>
                        @if($primaryDomain)
                            <span>{{ $primaryDomain->domain }}</span>
                            <small class="d-block text-muted">{{ $primaryDomain->status }}</small>
                        @else
                            <span class="text-muted">No domain</span>
                        @endif
                    </td>
                    <td>{{ $tenant->subscription?->plan?->name ?? 'No plan' }}</td>
                    <td><span class="badge bg-light text-dark">{{ $dbStatus }}</span></td>
                    <td>@include('central.tenants.partials.status-badge', ['status' => $tenant->status])</td>
                    <td>{{ $tenant->trial_ends_at?->format('Y-m-d') ?? '-' }}</td>
                    <td class="text-end">
                        @can('central.tenants.show')
                            <a href="{{ url('/tenants/' . $tenant->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @can('central.tenants.edit')
                            <a href="{{ url('/tenants/' . $tenant->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No tenants found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div class="mt-3">{{ $tenants->links() }}</div>
    </div>
</div>
@endsection
