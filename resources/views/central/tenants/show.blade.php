@extends('layouts.app')

@section('title', 'Tenant Detail')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $tenant->business_name }}</h1>
        <p class="fw-medium">
            Code: <span class="fw-bold">{{ $tenant->tenant_code }}</span>
            &nbsp;|&nbsp;
            Status: @include('central.tenants.partials.status-badge', ['status' => $tenant->status])
        </p>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ url('/tenants') }}" class="btn btn-light">Back</a>

        @can('central.tenants.edit')
            <a href="{{ url('/tenants/' . $tenant->id . '/edit') }}" class="btn btn-primary">Edit</a>
        @endcan
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="row">
    {{-- Left column: info + domains --}}
    <div class="col-lg-8">

        {{-- Tenant info card --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Tenant Information</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <small class="text-muted d-block">Owner Name</small>
                        <span class="fw-semibold">{{ $tenant->owner_name ?? '-' }}</span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Owner Email</small>
                        <span class="fw-semibold">{{ $tenant->owner_email ?? '-' }}</span>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Currency</small>
                        <span class="fw-semibold">{{ $tenant->currency_code }}</span>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Trial Ends</small>
                        <span class="fw-semibold">{{ $tenant->trial_ends_at?->format('Y-m-d') ?? '-' }}</span>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Activated At</small>
                        <span class="fw-semibold">{{ $tenant->activated_at?->format('Y-m-d H:i') ?? '-' }}</span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Plan</small>
                        <span class="fw-semibold">{{ $tenant->subscription?->plan?->name ?? 'No plan' }}</span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Subscription Status</small>
                        <span class="fw-semibold">{{ $tenant->subscription?->status ?? '-' }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Domains card --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Domains</h5></div>
            <div class="card-body">

                @can('central.tenant-domains.store')
                    <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/domains') }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-md-8">
                            <div class="input-group">
                                <input type="text" name="subdomain" class="form-control" placeholder="branch2">
                                <span class="input-group-text">.{{ config('tenancy.tenant_base_domain') }}</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-primary w-100">Add Domain</button>
                        </div>
                    </form>
                @endcan

                <div class="table-responsive">
                    <table class="table table-nowrap align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Primary</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($tenant->domains as $domain)
                            <tr>
                                <td>{{ $domain->domain }}</td>
                                <td>
                                    @if($domain->is_primary)
                                        <span class="badge bg-success">Primary</span>
                                    @else
                                        <span class="badge bg-light text-dark">Secondary</span>
                                    @endif
                                </td>
                                <td>{{ ucfirst($domain->status) }}</td>
                                <td class="text-end">
                                    @if(!$domain->is_primary)
                                        @can('central.tenant-domains.primary')
                                            <form method="POST" action="{{ url('/tenant-domains/' . $domain->id . '/primary') }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-light">Make Primary</button>
                                            </form>
                                        @endcan
                                    @endif

                                    @if($domain->status !== 'active')
                                        @can('central.tenant-domains.activate')
                                            <form method="POST" action="{{ url('/tenant-domains/' . $domain->id . '/activate') }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-success">Activate</button>
                                            </form>
                                        @endcan
                                    @else
                                        @if(!$domain->is_primary)
                                            @can('central.tenant-domains.deactivate')
                                                <form method="POST" action="{{ url('/tenant-domains/' . $domain->id . '/deactivate') }}" class="d-inline">
                                                    @csrf
                                                    <button class="btn btn-sm btn-warning">Deactivate</button>
                                                </form>
                                            @endcan
                                        @endif
                                    @endif

                                    @if(!$domain->is_primary)
                                        @can('central.tenant-domains.destroy')
                                            <form method="POST" action="{{ url('/tenant-domains/' . $domain->id) }}" class="d-inline"
                                                  onsubmit="return confirm('Delete this domain?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-3">No domains configured.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    {{-- Right column: database status + provision + actions --}}
    <div class="col-lg-4">

        {{-- Database status card --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Database</h5></div>
            <div class="card-body">
                @if($tenant->database)
                    <div class="mb-2">
                        <small class="text-muted d-block">Database Name</small>
                        <span class="fw-semibold">{{ $tenant->database->db_database }}</span>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted d-block">Migration Status</small>
                        <span class="badge @if($tenant->database->migration_status === 'completed') bg-success @elseif($tenant->database->migration_status === 'failed') bg-danger @else bg-warning text-dark @endif">
                            {{ ucfirst($tenant->database->migration_status) }}
                        </span>
                    </div>
                @else
                    <p class="text-muted mb-0">No database provisioned yet.</p>
                @endif
            </div>
        </div>

        {{-- Provision card --}}
        @can('central.tenants.provision')
            @if(!$tenant->database || $tenant->database->migration_status !== 'completed')
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">Provision Database</h5></div>
                    <div class="card-body">
                        <p class="text-muted small">
                            Creates the tenant database, runs migrations, and seeds the default owner user.
                        </p>

                        <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/provision') }}" class="row g-3">
                            @csrf

                            <div class="col-12">
                                <label class="form-label">Owner Password <span class="text-danger">*</span></label>
                                <input type="password" name="owner_password" class="form-control" minlength="8" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                <input type="password" name="owner_password_confirmation" class="form-control" required>
                            </div>

                            <div class="col-12">
                                <button class="btn btn-success w-100">
                                    <i class="ti ti-database me-1"></i>Provision &amp; Activate
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endcan

        {{-- Status actions card --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Status Actions</h5></div>
            <div class="card-body d-grid gap-2">

                @if($tenant->status !== 'active')
                    @can('central.tenants.activate')
                        <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/activate') }}">
                            @csrf
                            <button class="btn btn-success w-100">
                                <i class="ti ti-check me-1"></i>Activate Tenant
                            </button>
                        </form>
                    @endcan
                @endif

                @if($tenant->status === 'active')
                    @can('central.tenants.suspend')
                        <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/suspend') }}">
                            @csrf
                            <button class="btn btn-warning w-100">
                                <i class="ti ti-ban me-1"></i>Suspend Tenant
                            </button>
                        </form>
                    @endcan
                @endif

                @if($tenant->status !== 'cancelled')
                    @can('central.tenants.cancel')
                        <form method="POST" action="{{ url('/tenants/' . $tenant->id . '/cancel') }}"
                              onsubmit="return confirm('Cancel this tenant? This cannot be undone easily.')">
                            @csrf
                            <button class="btn btn-danger w-100">
                                <i class="ti ti-x me-1"></i>Cancel Tenant
                            </button>
                        </form>
                    @endcan
                @endif

            </div>
        </div>

    </div>
</div>
@endsection
