@extends('layouts.app')

@section('title', 'Edit Tenant')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Edit Tenant</h1>
        <p class="fw-medium">{{ $tenant->business_name }}</p>
    </div>
    <a href="{{ url('/tenants/' . $tenant->id) }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ url('/tenants/' . $tenant->id) }}" class="row g-3">
            @csrf
            @method('PUT')

            <div class="col-md-4">
                <label class="form-label">Tenant Code <span class="text-danger">*</span></label>
                <input type="text" name="tenant_code" value="{{ old('tenant_code', $tenant->tenant_code) }}" class="form-control" required>
            </div>

            <div class="col-md-8">
                <label class="form-label">Business Name <span class="text-danger">*</span></label>
                <input type="text" name="business_name" value="{{ old('business_name', $tenant->business_name) }}" class="form-control" required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Owner Name</label>
                <input type="text" name="owner_name" value="{{ old('owner_name', $tenant->owner_name) }}" class="form-control">
            </div>

            <div class="col-md-6">
                <label class="form-label">Owner Email</label>
                <input type="email" name="owner_email" value="{{ old('owner_email', $tenant->owner_email) }}" class="form-control">
            </div>

            <div class="col-md-4">
                <label class="form-label">Currency <span class="text-danger">*</span></label>
                <input type="text" name="currency_code" value="{{ old('currency_code', $tenant->currency_code) }}" class="form-control" maxlength="3" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Trial Ends At</label>
                <input type="date" name="trial_ends_at" value="{{ old('trial_ends_at', $tenant->trial_ends_at?->format('Y-m-d')) }}" class="form-control">
            </div>

            <div class="col-md-4">
                <label class="form-label">Plan</label>
                <select name="plan_id" class="form-select">
                    <option value="">No Plan</option>
                    @foreach($plans as $plan)
                        <option value="{{ $plan->id }}" @selected(old('plan_id', $tenant->subscription?->plan_id) == $plan->id)>
                            {{ $plan->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-12">
                <button class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i>Update Tenant
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
