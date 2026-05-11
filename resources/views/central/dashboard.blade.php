@extends('layouts.app')

@section('title', __('dashboard.dashboard'))

@section('content')
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1">Central Dashboard</h1>
            <p class="fw-medium">Manage tenants, subscriptions, domains, and route permissions.</p>
        </div>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row">
        <div class="col-lg-4 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <h6>Total Tenants</h6>
                    <h3>{{ $tenantCount }}</h3>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <h6>Active Tenants</h6>
                    <h3>{{ $activeTenantCount }}</h3>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <h6>Pending Tenants</h6>
                    <h3>{{ $pendingTenantCount }}</h3>
                </div>
            </div>
        </div>
    </div>
@endsection
