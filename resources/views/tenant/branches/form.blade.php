@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $title }}</h1>
        <p class="fw-medium">Branch details and settings.</p>
    </div>
    <a href="{{ url('/branches') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST"
      action="{{ $branch ? url('/branches/' . $branch->id) : url('/branches') }}"
      novalidate>
    @csrf
    @if($branch) @method('PUT') @endif

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Basic Information</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="code" class="form-label">Branch Code</label>
                    <input type="text" id="code" name="code"
                        value="{{ old('code', $branch?->code) }}"
                        class="form-control" maxlength="50" placeholder="MAIN">
                </div>

                <div class="col-md-6">
                    <label for="name" class="form-label required">Branch Name</label>
                    <input type="text" id="name" name="name"
                        value="{{ old('name', $branch?->name) }}"
                        class="form-control" required maxlength="190">
                </div>

                <div class="col-md-3">
                    <label for="business_type" class="form-label required">Business Type</label>
                    <select id="business_type" name="business_type" class="form-select" required>
                        @foreach(['store' => 'Store', 'restaurant' => 'Restaurant', 'hybrid' => 'Hybrid'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('business_type', $branch?->business_type) === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="address" class="form-label">Address</label>
                    <textarea id="address" name="address" class="form-control" rows="2">{{ old('address', $branch?->address) }}</textarea>
                </div>

                <div class="col-md-3">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="text" id="phone" name="phone"
                        value="{{ old('phone', $branch?->phone) }}"
                        class="form-control" maxlength="50">
                </div>

                <div class="col-md-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email"
                        value="{{ old('email', $branch?->email) }}"
                        class="form-control" maxlength="190">
                </div>

                <div class="col-md-4">
                    <label for="timezone" class="form-label required">Timezone</label>
                    <input type="text" id="timezone" name="timezone"
                        value="{{ old('timezone', $branch?->timezone ?? 'Asia/Karachi') }}"
                        class="form-control" required maxlength="100"
                        list="timezone-list">
                    <datalist id="timezone-list">
                        <option value="Asia/Karachi">
                        <option value="Asia/Dubai">
                        <option value="Asia/Riyadh">
                        <option value="UTC">
                        <option value="America/New_York">
                        <option value="Europe/London">
                    </datalist>
                </div>

                <div class="col-md-3">
                    <label for="status" class="form-label required">Status</label>
                    <select id="status" name="status" class="form-select" required>
                        <option value="active" @selected(old('status', $branch?->status ?? 'active') === 'active')>Active</option>
                        <option value="inactive" @selected(old('status', $branch?->status) === 'inactive')>Inactive</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Tax &amp; Invoice Settings</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label for="tax_registration_no" class="form-label">Tax Registration Number</label>
                    <input type="text" id="tax_registration_no" name="tax_registration_no"
                        value="{{ old('tax_registration_no', $branch?->tax_registration_no) }}"
                        class="form-control" maxlength="100">
                </div>

                <div class="col-md-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                            id="is_tax_enabled" name="is_tax_enabled" value="1"
                            @checked(old('is_tax_enabled', $branch?->is_tax_enabled))>
                        <label class="form-check-label" for="is_tax_enabled">Tax Enabled</label>
                    </div>
                </div>

                <div class="col-md-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                            id="show_tax_number_on_invoice" name="show_tax_number_on_invoice" value="1"
                            @checked(old('show_tax_number_on_invoice', $branch?->show_tax_number_on_invoice))>
                        <label class="form-check-label" for="show_tax_number_on_invoice">Show Tax Number on Invoice</label>
                    </div>
                </div>

                <div class="col-md-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                            id="allow_negative_stock" name="allow_negative_stock" value="1"
                            @checked(old('allow_negative_stock', $branch?->allow_negative_stock))>
                        <label class="form-check-label" for="allow_negative_stock">Allow Negative Inventory</label>
                    </div>
                    <div class="form-text text-warning">
                        When enabled, this branch can sell/post stock-out items and official stock may go below zero.
                        Use only when you accept backorder or delayed stock entry workflows.
                        COGS for backorder sales is estimated at last known cost.
                    </div>
                </div>

                <div class="col-md-8">
                    <label for="receipt_footer" class="form-label">Receipt Footer Text</label>
                    <textarea id="receipt_footer" name="receipt_footer" class="form-control" rows="2"
                        placeholder="Thank you for your business!">{{ old('receipt_footer', $branch?->receipt_footer) }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>
            {{ $branch ? 'Update Branch' : 'Create Branch' }}
        </button>
        <a href="{{ url('/branches') }}" class="btn btn-light">Cancel</a>
    </div>
</form>
@endsection
