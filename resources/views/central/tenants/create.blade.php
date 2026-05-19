@extends('layouts.app')

@section('title', 'Create Tenant')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Create Tenant</h1>
        <p class="fw-medium">Create a pending customer account and primary subdomain.</p>
    </div>
    <a href="{{ url('/tenants') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ url('/tenants') }}" class="row g-3">
            @csrf

            <div class="col-md-4">
                <label class="form-label">Tenant Code <span class="text-danger">*</span></label>
                <input type="text" name="tenant_code" value="{{ old('tenant_code') }}" class="form-control" placeholder="restaurant01" required>
                <small class="text-muted">Lowercase, no spaces. Used for the database name.</small>
            </div>

            <div class="col-md-8">
                <label class="form-label">Business Name <span class="text-danger">*</span></label>
                <input type="text" name="business_name" value="{{ old('business_name') }}" class="form-control" required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Owner Name</label>
                <input type="text" name="owner_name" value="{{ old('owner_name') }}" class="form-control">
            </div>

            <div class="col-md-6">
                <label class="form-label">Owner Email</label>
                <input type="email" name="owner_email" value="{{ old('owner_email') }}" class="form-control">
                <small class="text-muted">Used as the tenant portal login email.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label">Subdomain <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="text" name="subdomain" value="{{ old('subdomain') }}" class="form-control" placeholder="restaurant01" required>
                    <span class="input-group-text">.{{ config('tenancy.tenant_base_domain') }}</span>
                </div>
            </div>

            <div class="col-md-4">
                <label class="form-label">Currency <span class="text-danger">*</span></label>
                <input type="text" name="currency_code" value="{{ old('currency_code', 'PKR') }}" class="form-control" maxlength="3" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Trial Days</label>
                <input type="number" name="trial_days" value="{{ old('trial_days', 60) }}" class="form-control" min="0" max="365">
            </div>

            <div class="col-md-6">
                <label class="form-label">Plan</label>
                <select name="plan_id" class="form-select">
                    <option value="">No Plan</option>
                    @foreach($plans as $plan)
                        <option value="{{ $plan->id }}" @selected(old('plan_id') == $plan->id)>
                            {{ $plan->name }} — {{ $plan->currency_code }} {{ number_format($plan->price, 2) }}/{{ $plan->billing_period }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-12">
                <button class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i>Create Tenant
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
