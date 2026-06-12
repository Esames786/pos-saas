@extends('layouts.app')

@section('title', 'Create Invoice')

@php
    $currentPlan = $tenant->subscription?->plan;
    $defaultPlanId = old('plan_id', $currentPlan?->id);
    $defaultCurrency = old('currency_code', $currentPlan?->currency_code ?? 'PKR');
    $defaultSubtotal = old('subtotal', $currentPlan?->price ?? 0);
    $periodStart = old('period_start', now()->toDateString());
    $periodEnd = old('period_end', ($currentPlan?->billing_period === 'yearly'
        ? now()->addYear() : now()->addMonth())->toDateString());
    $dueDate = old('due_date', now()->addDays(7)->toDateString());
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Create Invoice</h1>
        <p class="text-muted mb-0">{{ $tenant->business_name }} — <code>{{ $tenant->tenant_code }}</code></p>
    </div>
    <a href="{{ url('/tenants/' . $tenant->id) }}" class="btn btn-outline-secondary">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/tenants/' . $tenant->id . '/invoices') }}" class="card">
    @csrf
    <div class="card-body row g-3">
        <div class="col-md-4">
            <label class="form-label">Tenant</label>
            <input type="text" class="form-control" value="{{ $tenant->business_name }}" disabled>
        </div>

        <div class="col-md-4">
            <label class="form-label">Plan</label>
            <select name="plan_id" class="form-select">
                <option value="">No plan</option>
                @foreach($plans as $plan)
                    <option value="{{ $plan->id }}" @selected((string) $defaultPlanId === (string) $plan->id)>
                        {{ $plan->name }} ({{ $plan->currency_code }} {{ number_format((float) $plan->price, 2) }})
                    </option>
                @endforeach
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label">Invoice Type</label>
            <select name="invoice_type" class="form-select">
                @foreach(['subscription','upgrade','addon','manual'] as $type)
                    <option value="{{ $type }}" @selected(old('invoice_type','subscription') === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">Currency</label>
            <input name="currency_code" maxlength="3" class="form-control" value="{{ $defaultCurrency }}" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Subtotal</label>
            <input name="subtotal" type="number" step="0.01" min="0" class="form-control" value="{{ $defaultSubtotal }}" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Discount</label>
            <input name="discount_amount" type="number" step="0.01" min="0" class="form-control" value="{{ old('discount_amount', 0) }}">
        </div>
        <div class="col-md-3">
            <label class="form-label">Tax</label>
            <input name="tax_amount" type="number" step="0.01" min="0" class="form-control" value="{{ old('tax_amount', 0) }}">
        </div>

        <div class="col-md-4">
            <label class="form-label">Period Start</label>
            <input name="period_start" type="date" class="form-control" value="{{ $periodStart }}">
        </div>
        <div class="col-md-4">
            <label class="form-label">Period End</label>
            <input name="period_end" type="date" class="form-control" value="{{ $periodEnd }}">
        </div>
        <div class="col-md-4">
            <label class="form-label">Due Date</label>
            <input name="due_date" type="date" class="form-control" value="{{ $dueDate }}">
        </div>

        <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea name="notes" rows="2" class="form-control">{{ old('notes') }}</textarea>
        </div>
    </div>
    <div class="card-footer text-end">
        <a href="{{ url('/tenants/' . $tenant->id) }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">Create Invoice</button>
    </div>
</form>
@endsection
