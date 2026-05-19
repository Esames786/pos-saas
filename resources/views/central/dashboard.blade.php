@extends('layouts.app')

@section('title', __('dashboard.dashboard'))

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ __('dashboard.central_admin') }}</h1>
        <p class="fw-medium">Manage tenants, subscriptions, domains, databases, and permissions.</p>
    </div>
</div>

<div class="row">
    <div class="col-lg-4 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6>{{ __('dashboard.total_tenants') }}</h6>
                <h3>{{ $tenantCount ?? 0 }}</h3>
            </div>
        </div>
    </div>

    <div class="col-lg-4 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6>{{ __('dashboard.active_tenants') }}</h6>
                <h3>{{ $activeTenantCount ?? 0 }}</h3>
            </div>
        </div>
    </div>

    <div class="col-lg-4 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6>{{ __('dashboard.pending_tenants') }}</h6>
                <h3>{{ $pendingTenantCount ?? 0 }}</h3>
            </div>
        </div>
    </div>
</div>
@endsection
