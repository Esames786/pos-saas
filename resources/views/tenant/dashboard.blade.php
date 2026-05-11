@extends('layouts.app')

@section('title', __('dashboard.dashboard'))

@section('content')
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1">Tenant Dashboard</h1>
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
                    <h6>Today Sales</h6>
                    <h3>0.00</h3>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <h6>Orders</h6>
                    <h3>0</h3>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <h6>Low Stock</h6>
                    <h3>0</h3>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <h6>Expiry Alerts</h6>
                    <h3>0</h3>
                </div>
            </div>
        </div>
    </div>
@endsection
