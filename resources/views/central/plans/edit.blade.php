@extends('layouts.app')

@section('title', 'Edit Plan')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Edit Plan</h1>
        <p class="text-muted mb-0">{{ $plan->name }} — <code>{{ $plan->code }}</code></p>
    </div>

    <a href="{{ url('/plans') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ url('/plans/' . $plan->id) }}">
    @csrf
    @method('PUT')

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">Plan Details</h5>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">
                    Please fix the highlighted fields.
                </div>
            @endif

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Code</label>
                    <input type="text" class="form-control" value="{{ $plan->code }}" disabled>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Name</label>
                    <input name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name', $plan->name) }}" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-2">
                    <label class="form-label">Price</label>
                    <input name="price" type="number" step="0.01" min="0"
                           class="form-control @error('price') is-invalid @enderror"
                           value="{{ old('price', $plan->price) }}" required>
                    @error('price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-2">
                    <label class="form-label">Currency</label>
                    <input name="currency_code" maxlength="3"
                           class="form-control @error('currency_code') is-invalid @enderror"
                           value="{{ old('currency_code', $plan->currency_code) }}" required>
                    @error('currency_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Billing Period</label>
                    <select name="billing_period" class="form-select">
                        @foreach(['monthly', 'yearly'] as $period)
                            <option value="{{ $period }}" @selected(old('billing_period', $plan->billing_period) === $period)>
                                {{ ucfirst($period) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input"
                               id="is_active" @checked(old('is_active', $plan->is_active))>
                        <label for="is_active" class="form-check-label">Active</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">Public Listing &amp; Pricing</h5>
            <small class="text-muted">Controls whether this plan appears on the public website and how it is priced there.</small>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Monthly Price</label>
                    <input name="monthly_price" type="number" step="0.01" min="0"
                           class="form-control @error('monthly_price') is-invalid @enderror"
                           value="{{ old('monthly_price', $plan->monthly_price) }}">
                    @error('monthly_price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Yearly Price</label>
                    <input name="yearly_price" type="number" step="0.01" min="0"
                           class="form-control @error('yearly_price') is-invalid @enderror"
                           value="{{ old('yearly_price', $plan->yearly_price) }}">
                    @error('yearly_price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Trial Days</label>
                    <input name="trial_days" type="number" min="0" max="365"
                           class="form-control @error('trial_days') is-invalid @enderror"
                           value="{{ old('trial_days', $plan->trial_days) }}">
                    @error('trial_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3 d-flex align-items-end gap-3">
                    <div class="form-check">
                        <input type="checkbox" name="is_public" value="1" class="form-check-input"
                               id="is_public" @checked(old('is_public', $plan->is_public))>
                        <label for="is_public" class="form-check-label">Show on public website</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_custom" value="1" class="form-check-input"
                               id="is_custom" @checked(old('is_custom', $plan->is_custom))>
                        <label for="is_custom" class="form-check-label">Custom (Contact Sales — no self-trial)</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Public Description</label>
                    <input name="public_description" maxlength="1000"
                           class="form-control @error('public_description') is-invalid @enderror"
                           value="{{ old('public_description', $plan->public_description) }}"
                           placeholder="Best for small stores, counters, and simple retail checkout.">
                    @error('public_description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <p class="text-muted small mb-0 mt-3">
                <i class="ti ti-info-circle me-1"></i>
                The base <strong>Price</strong> above is what billing/invoices use. Keep it equal to the Monthly Price.
                Public pricing cards read Monthly/Yearly Price. Hidden plans stay usable for existing tenants.
            </p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">Plan Limits</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach(['branch_limit' => 'Branch Limit', 'user_limit' => 'User Limit', 'terminal_limit' => 'Terminal Limit', 'product_limit' => 'Product Limit'] as $key => $label)
                    <div class="col-md-4">
                        <label class="form-label">{{ $label }}</label>
                        <input name="features[{{ $key }}]" type="number" min="0"
                               class="form-control"
                               value="{{ old('features.' . $key, $features[$key] ?? '') }}">
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">Modules</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach($modules as $module)
                    @php
                        $pivot = $planModules[$module->id] ?? null;
                        $enabled = old('enabled_modules')
                            ? in_array($module->id, array_map('intval', old('enabled_modules', [])), true)
                            : (bool) ($pivot?->is_enabled);
                        $limits = old('module_limits.' . $module->id, $pivot?->limits ? json_encode($pivot->limits, JSON_PRETTY_PRINT) : '');
                    @endphp

                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="form-check mb-2">
                                <input type="checkbox"
                                       name="enabled_modules[]"
                                       value="{{ $module->id }}"
                                       id="module_{{ $module->id }}"
                                       class="form-check-input"
                                       @checked($enabled)>
                                <label for="module_{{ $module->id }}" class="form-check-label fw-semibold">
                                    {{ $module->name }}
                                </label>
                            </div>

                            <div class="small text-muted mb-2">
                                <code>{{ $module->key }}</code>
                                @if($module->category)
                                    · {{ $module->category }}
                                @endif
                            </div>

                            <label class="form-label small">Limits JSON</label>
                            <textarea name="module_limits[{{ $module->id }}]"
                                      class="form-control form-control-sm @error('module_limits.' . $module->id) is-invalid @enderror"
                                      rows="3"
                                      placeholder='{"branch_limit": 1}'>{{ $limits }}</textarea>
                            @error('module_limits.' . $module->id)
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="text-end">
        <button class="btn btn-primary">Save Plan</button>
    </div>
</form>
@endsection
