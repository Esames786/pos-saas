@extends('layouts.app')

@section('title', 'Plan Builder')

@section('content')
@php
    $moduleGroups = $modules->groupBy(fn ($module) => $module->category ?: 'Uncategorized');
    $orderedGroups = collect($categoryOrder)
        ->filter(fn ($category) => $moduleGroups->has($category))
        ->mapWithKeys(fn ($category) => [$category => $moduleGroups[$category]]);
    $moduleGroups->each(function ($items, $category) use (&$orderedGroups) {
        if (! $orderedGroups->has($category)) {
            $orderedGroups[$category] = $items;
        }
    });

    $enabledFromOld = old('enabled_modules');
    $enabledIds = $enabledFromOld !== null
        ? collect($enabledFromOld)->map(fn ($id) => (int) $id)->all()
        : $planModules->filter(fn ($pivot) => $pivot->is_enabled)->keys()->map(fn ($id) => (int) $id)->all();

    $globalLimits = [
        'branch_limit' => ['label' => 'Branch limit', 'icon' => 'ti-building-store'],
        'user_limit' => ['label' => 'User limit', 'icon' => 'ti-users'],
        'terminal_limit' => ['label' => 'Terminal limit', 'icon' => 'ti-device-desktop'],
        'product_limit' => ['label' => 'Product limit', 'icon' => 'ti-package'],
    ];
@endphp

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Plan Builder</h1>
        <p class="text-muted mb-0">Build customer access, limits, pricing, and preview for {{ $plan->name }}.</p>
    </div>

    <a href="{{ url('/plans') }}" class="btn btn-outline-secondary">
        <i class="ti ti-arrow-left me-1"></i>Back
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger">Please fix the highlighted fields before saving the plan.</div>
@endif

<form method="POST" action="{{ url('/plans/' . $plan->id) }}" id="planBuilderForm">
    @csrf
    @method('PUT')

    <ul class="nav nav-pills gap-2 mb-3" id="planBuilderTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="basics-tab" data-bs-toggle="pill" data-bs-target="#basics" type="button" role="tab">Plan Basics</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="modules-tab" data-bs-toggle="pill" data-bs-target="#modules" type="button" role="tab">Modules &amp; Features</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="limits-tab" data-bs-toggle="pill" data-bs-target="#limits" type="button" role="tab">Limits</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="preview-tab" data-bs-toggle="pill" data-bs-target="#preview" type="button" role="tab">Customer Preview</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="advanced-tab" data-bs-toggle="pill" data-bs-target="#advanced" type="button" role="tab">Advanced</button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="basics" role="tabpanel" aria-labelledby="basics-tab">
            <div class="alert alert-info d-flex align-items-start gap-2">
                <i class="ti ti-info-circle fs-5"></i>
                <div>Use Plan Basics to control how this plan appears to customers and how billing/trials are presented.</div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0">Plan Basics</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Code</label>
                            <input class="form-control" value="{{ $plan->code }}" disabled>
                            <div class="form-text">Plan code is fixed so existing subscriptions remain linked.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Plan name</label>
                            <input name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $plan->name) }}" required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Billing price</label>
                            <input name="price" type="number" step="0.01" min="0" class="form-control @error('price') is-invalid @enderror" value="{{ old('price', $plan->price) }}" required>
                            @error('price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Currency</label>
                            <input name="currency_code" maxlength="3" class="form-control @error('currency_code') is-invalid @enderror" value="{{ old('currency_code', $plan->currency_code) }}" required>
                            @error('currency_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Billing period</label>
                            <select name="billing_period" class="form-select">
                                @foreach(['monthly', 'yearly'] as $period)
                                    <option value="{{ $period }}" @selected(old('billing_period', $plan->billing_period) === $period)>{{ ucfirst($period) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Monthly price</label>
                            <input name="monthly_price" type="number" step="0.01" min="0" class="form-control @error('monthly_price') is-invalid @enderror" value="{{ old('monthly_price', $plan->monthly_price) }}">
                            @error('monthly_price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Yearly price</label>
                            <input name="yearly_price" type="number" step="0.01" min="0" class="form-control @error('yearly_price') is-invalid @enderror" value="{{ old('yearly_price', $plan->yearly_price) }}">
                            @error('yearly_price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Trial days</label>
                            <input name="trial_days" type="number" min="0" max="365" class="form-control @error('trial_days') is-invalid @enderror" value="{{ old('trial_days', $plan->trial_days) }}">
                            @error('trial_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="is_public" value="1" class="form-check-input" id="is_public" @checked(old('is_public', $plan->is_public))>
                                <label for="is_public" class="form-check-label">Show on public website</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="is_custom" value="1" class="form-check-input" id="is_custom" @checked(old('is_custom', $plan->is_custom))>
                                <label for="is_custom" class="form-check-label">Custom / Contact Sales</label>
                            </div>
                            <div class="form-text">Customers can see this plan but cannot self-start trial unless allowed by your signup flow.</div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active" @checked(old('is_active', $plan->is_active))>
                                <label for="is_active" class="form-check-label">Active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Public description</label>
                            <input name="public_description" maxlength="1000" class="form-control @error('public_description') is-invalid @enderror" value="{{ old('public_description', $plan->public_description) }}" placeholder="Best for small stores, counters, and simple retail checkout.">
                            @error('public_description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="modules" role="tabpanel" aria-labelledby="modules-tab">
            <div class="alert alert-info d-flex align-items-start gap-2">
                <i class="ti ti-layout-grid fs-5"></i>
                <div>Enable the business features this customer should receive. Technical route keys are hidden under Advanced.</div>
            </div>

            @foreach($orderedGroups as $category => $items)
                <section class="mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h5 class="mb-0">{{ $category ?: 'Uncategorized' }}</h5>
                        <span class="badge bg-light text-dark border">{{ $items->count() }} modules</span>
                    </div>

                    <div class="row g-3">
                        @foreach($items as $module)
                            @php
                                $pivot = $planModules[$module->id] ?? null;
                                $enabled = in_array((int) $module->id, $enabledIds, true);
                                $summary = $moduleSummaries[$module->key] ?? [];
                            @endphp
                            <div class="col-xl-4 col-md-6">
                                <div class="border rounded p-3 h-100 bg-white module-card" data-module-card="{{ $module->id }}">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div>
                                            <div class="fw-semibold">{{ $module->name }}</div>
                                            <div class="small text-muted">{{ $module->category ?: 'Uncategorized' }}</div>
                                        </div>
                                        <div class="form-check form-switch m-0">
                                            <input type="checkbox" name="enabled_modules[]" value="{{ $module->id }}" id="module_{{ $module->id }}" class="form-check-input module-toggle" data-module-name="{{ $module->name }}" data-module-category="{{ $module->category ?: 'Uncategorized' }}" @checked($enabled)>
                                        </div>
                                    </div>

                                    <p class="text-muted small mb-3">{{ $summary['description'] ?? ($module->description ?: 'Business feature controlled by this module.') }}</p>

                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <span class="badge {{ $module->is_core ? 'bg-info text-dark' : 'bg-secondary' }}">{{ $module->is_core ? 'Core' : 'Optional' }}</span>
                                        <span class="badge {{ $module->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $module->is_active ? 'Active' : 'Inactive' }}</span>
                                    </div>

                                    <div class="small">
                                        <span class="text-muted">Customer sees:</span>
                                        <span>{{ $summary['sees'] ?? 'Screens mapped to this business module.' }}</span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        <div class="tab-pane fade" id="limits" role="tabpanel" aria-labelledby="limits-tab">
            <div class="alert alert-info d-flex align-items-start gap-2">
                <i class="ti ti-adjustments fs-5"></i>
                <div>Leave blank for unlimited. These friendly fields save into the existing plan feature and module limits storage.</div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0">Global Plan Limits</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        @foreach($globalLimits as $key => $meta)
                            <div class="col-md-3">
                                <label class="form-label"><i class="ti {{ $meta['icon'] }} me-1"></i>{{ $meta['label'] }}</label>
                                <input name="features[{{ $key }}]" type="number" min="0" class="form-control plan-limit-input" data-limit-label="{{ $meta['label'] }}" value="{{ old('features.' . $key, $features[$key] ?? '') }}" placeholder="Unlimited">
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0">Module-Specific Limits</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        @foreach($modules as $module)
                            @php
                                $definitions = $moduleLimitDefinitions[$module->key] ?? [];
                                $pivot = $planModules[$module->id] ?? null;
                                $currentLimits = is_array($pivot?->limits) ? $pivot->limits : [];
                            @endphp
                            @if($definitions)
                                <div class="col-xl-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <div>
                                                <div class="fw-semibold">{{ $module->name }}</div>
                                                <div class="small text-muted">{{ $module->category ?: 'Uncategorized' }}</div>
                                            </div>
                                            <span class="badge bg-light text-dark border">{{ count($definitions) }} limit fields</span>
                                        </div>
                                        <div class="row g-2">
                                            @foreach($definitions as $key => $label)
                                                <div class="col-md-6">
                                                    <label class="form-label small">{{ $label }}</label>
                                                    <input name="module_limit_fields[{{ $module->id }}][{{ $key }}]" class="form-control form-control-sm module-limit-input" data-module-name="{{ $module->name }}" data-limit-label="{{ $label }}" value="{{ old('module_limit_fields.' . $module->id . '.' . $key, $currentLimits[$key] ?? '') }}" placeholder="Unlimited">
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="preview" role="tabpanel" aria-labelledby="preview-tab">
            <div class="alert alert-info d-flex align-items-start gap-2">
                <i class="ti ti-eye fs-5"></i>
                <div>Use this preview to confirm what the customer will see before saving the plan.</div>
            </div>

            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="border rounded p-3 bg-white h-100">
                        <h5 class="mb-3">Public Pricing Preview</h5>
                        <div class="h3 mb-1">{{ $plan->currency_code }} <span id="previewMonthly">{{ old('monthly_price', $plan->monthly_price) ?: old('price', $plan->price) }}</span></div>
                        <div class="text-muted mb-2">per month</div>
                        <div class="small text-muted">Yearly: {{ $plan->currency_code }} <span id="previewYearly">{{ old('yearly_price', $plan->yearly_price) ?: 'Contact sales' }}</span></div>
                        <div class="small text-muted">Trial: <span id="previewTrial">{{ old('trial_days', $plan->trial_days) ?: 0 }}</span> days</div>
                        <hr>
                        <p id="previewDescription" class="mb-0">{{ old('public_description', $plan->public_description) ?: 'No public description yet.' }}</p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded p-3 bg-white h-100">
                        <h5 class="mb-3">Customer Will See</h5>
                        <div id="previewEnabled" class="d-flex flex-column gap-2"></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded p-3 bg-white h-100">
                        <h5 class="mb-3">Customer Will Not See</h5>
                        <div id="previewDisabled" class="d-flex flex-column gap-2"></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="border rounded p-3 bg-white h-100">
                        <h5 class="mb-3">Approx Tenant Sidebar Preview</h5>
                        <div id="previewSidebar" class="d-flex flex-wrap gap-2"></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="border rounded p-3 bg-white h-100">
                        <h5 class="mb-3">Limits Summary</h5>
                        <div id="previewLimits" class="d-flex flex-column gap-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="advanced" role="tabpanel" aria-labelledby="advanced-tab">
            <div class="alert alert-warning d-flex align-items-start gap-2">
                <i class="ti ti-alert-triangle fs-5"></i>
                <div>Changing these values can affect subscription enforcement and tenant access. Use only if you understand route/module mapping.</div>
            </div>

            <div class="accordion" id="advancedPlanAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="rawLimitsHeading">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#rawLimitsPanel" aria-expanded="false" aria-controls="rawLimitsPanel">
                            Raw Plan Module Limits JSON
                        </button>
                    </h2>
                    <div id="rawLimitsPanel" class="accordion-collapse collapse" aria-labelledby="rawLimitsHeading" data-bs-parent="#advancedPlanAccordion">
                        <div class="accordion-body">
                            <p class="text-warning small mb-3">Advanced only. Incorrect JSON can unlock or block access incorrectly.</p>
                            <div class="row g-3">
                                @foreach($modules as $module)
                                    @php
                                        $pivot = $planModules[$module->id] ?? null;
                                        $limits = old('module_limits.' . $module->id, $pivot?->limits ? json_encode($pivot->limits, JSON_PRETTY_PRINT) : '');
                                    @endphp
                                    <div class="col-md-6">
                                        <label class="form-label small">{{ $module->name }} <code>{{ $module->key }}</code></label>
                                        <textarea name="module_limits[{{ $module->id }}]" rows="4" class="form-control form-control-sm font-monospace @error('module_limits.' . $module->id) is-invalid @enderror" placeholder='{"branch_limit": 1}'>{{ $limits }}</textarea>
                                        @error('module_limits.' . $module->id)
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header" id="routeKeysHeading">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#routeKeysPanel" aria-expanded="false" aria-controls="routeKeysPanel">
                            Raw Module Route Keys Summary
                        </button>
                    </h2>
                    <div id="routeKeysPanel" class="accordion-collapse collapse" aria-labelledby="routeKeysHeading" data-bs-parent="#advancedPlanAccordion">
                        <div class="accordion-body">
                            <p class="text-muted small">Route keys are edited from Modules. Verify route ownership from <a href="{{ url('/routes') }}">Route Catalog</a>.</p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Module</th>
                                            <th>Route keys</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($modules as $module)
                                            <tr>
                                                <td>{{ $module->name }}</td>
                                                <td class="small">
                                                    @forelse(($module->route_module_keys ?? []) as $key)
                                                        <span class="badge bg-light text-dark border mb-1">{{ $key }}</span>
                                                    @empty
                                                        <span class="text-muted">No route keys mapped.</span>
                                                    @endforelse
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header" id="notesHeading">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#notesPanel" aria-expanded="false" aria-controls="notesPanel">
                            Developer-only Notes
                        </button>
                    </h2>
                    <div id="notesPanel" class="accordion-collapse collapse" aria-labelledby="notesHeading" data-bs-parent="#advancedPlanAccordion">
                        <div class="accordion-body small text-muted">
                            The form keeps the existing plan update endpoint and storage. Module toggles update plan_modules.is_enabled, friendly global limits update plan_features, and module limit inputs are merged into plan_modules.limits JSON.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a href="{{ url('/plans') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">
            <i class="ti ti-device-floppy me-1"></i>Save Plan
        </button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const emptyText = '<span class="text-muted small">None selected.</span>';

        function badge(label, tone) {
            return `<span class="badge ${tone}">${label}</span>`;
        }

        function updatePreview() {
            const enabled = [];
            const disabled = [];

            document.querySelectorAll('.module-toggle').forEach(function (toggle) {
                const item = {
                    name: toggle.dataset.moduleName,
                    category: toggle.dataset.moduleCategory,
                };
                (toggle.checked ? enabled : disabled).push(item);
            });

            document.getElementById('previewEnabled').innerHTML = enabled.length
                ? enabled.map(item => badge('+ ' + item.name, 'bg-success')).join('')
                : emptyText;

            document.getElementById('previewDisabled').innerHTML = disabled.length
                ? disabled.map(item => badge('x ' + item.name, 'bg-light text-dark border')).join('')
                : emptyText;

            document.getElementById('previewSidebar').innerHTML = enabled.length
                ? enabled.map(item => badge(item.name, 'bg-primary')).join('')
                : emptyText;

            const limits = [];
            document.querySelectorAll('.plan-limit-input').forEach(function (input) {
                limits.push(`${input.dataset.limitLabel}: ${input.value || 'Unlimited'}`);
            });
            document.querySelectorAll('.module-limit-input').forEach(function (input) {
                if (input.value) {
                    limits.push(`${input.dataset.moduleName} - ${input.dataset.limitLabel}: ${input.value}`);
                }
            });

            document.getElementById('previewLimits').innerHTML = limits.length
                ? limits.map(item => `<div class="small border rounded px-2 py-1">${item}</div>`).join('')
                : emptyText;

            const monthly = document.querySelector('[name="monthly_price"]').value || document.querySelector('[name="price"]').value || '0.00';
            const yearly = document.querySelector('[name="yearly_price"]').value || 'Contact sales';
            const trial = document.querySelector('[name="trial_days"]').value || '0';
            const description = document.querySelector('[name="public_description"]').value || 'No public description yet.';

            document.getElementById('previewMonthly').textContent = monthly;
            document.getElementById('previewYearly').textContent = yearly;
            document.getElementById('previewTrial').textContent = trial;
            document.getElementById('previewDescription').textContent = description;
        }

        document.querySelectorAll('.module-toggle, .plan-limit-input, .module-limit-input, [name="price"], [name="monthly_price"], [name="yearly_price"], [name="trial_days"], [name="public_description"]').forEach(function (field) {
            field.addEventListener('input', updatePreview);
            field.addEventListener('change', updatePreview);
        });

        updatePreview();
    });
</script>
@endpush
