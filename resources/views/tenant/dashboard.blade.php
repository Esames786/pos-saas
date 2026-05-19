@extends('layouts.app')

@section('title', __('dashboard.dashboard'))

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ __('dashboard.tenant_panel') }}</h1>
        <p class="fw-medium">
            Business:
            <span class="text-primary fw-bold">{{ app('tenant')->business_name }}</span>
        </p>
    </div>
</div>

<div class="row">
    <div class="col-lg-3 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6>{{ __('dashboard.today_sales') }}</h6>
                <h3>0.00</h3>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6>{{ __('dashboard.orders') }}</h6>
                <h3>0</h3>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6>{{ __('dashboard.low_stock') }}</h6>
                <h3>0</h3>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-sm-6">
        <div class="card">
            <div class="card-body">
                <h6>{{ __('dashboard.expiry_alerts') }}</h6>
                <h3>0</h3>
            </div>
        </div>
    </div>
</div>
@endsection
