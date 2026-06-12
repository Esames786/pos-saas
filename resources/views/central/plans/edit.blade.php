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
            <h5 class="mb-0">Plan Limits</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach(['branch_limit' => 'Branch Limit', 'user_limit' => 'User Limit', 'terminal_limit' => 'Terminal Limit'] as $key => $label)
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
