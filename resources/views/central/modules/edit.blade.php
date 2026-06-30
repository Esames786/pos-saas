@extends('layouts.app')

@section('title', 'Edit Module')

@section('content')
@php
    $summary = $moduleSummaries[$module->key] ?? [];
@endphp

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Edit Module</h1>
        <p class="text-muted mb-0">{{ $module->name }} - <code>{{ $module->key }}</code></p>
    </div>

    <a href="{{ url('/modules') }}" class="btn btn-outline-secondary">
        <i class="ti ti-arrow-left me-1"></i>Back
    </a>
</div>

<div class="alert alert-info d-flex align-items-start gap-2">
    <i class="ti ti-info-circle fs-5"></i>
    <div>
        Modules group routes into business features that can be enabled or disabled on plans.
        Route keys decide which tenant screens belong to this module.
    </div>
</div>

<form method="POST" action="{{ url('/modules/' . $module->id) }}">
    @csrf
    @method('PUT')

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">Business Module Details</h5>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">Please fix the highlighted fields.</div>
            @endif

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Key</label>
                    <input class="form-control" value="{{ $module->key }}" disabled>
                    <div class="form-text">Module key is fixed to protect enforcement mapping.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Module name</label>
                    <input name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $module->name) }}" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Category</label>
                    <input name="category" class="form-control" value="{{ old('category', $module->category) }}">
                </div>

                <div class="col-md-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" rows="3" class="form-control">{{ old('description', $module->description) }}</textarea>
                    @if($summary)
                        <div class="form-text">Plan Builder summary: {{ $summary['description'] }}</div>
                    @endif
                </div>

                <div class="col-md-4">
                    <label class="form-label">Sort order</label>
                    <input name="sort_order" type="number" min="0" class="form-control" value="{{ old('sort_order', $module->sort_order) }}">
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input type="checkbox" name="is_core" value="1" class="form-check-input" id="is_core" @checked(old('is_core', $module->is_core))>
                        <label for="is_core" class="form-check-label">Core module</label>
                    </div>
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active" @checked(old('is_active', $module->is_active))>
                        <label for="is_active" class="form-check-label">Active</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="accordion mb-3" id="moduleAdvancedAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="routeMappingHeading">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#routeMappingPanel" aria-expanded="false" aria-controls="routeMappingPanel">
                    Advanced Route Mapping
                </button>
            </h2>
            <div id="routeMappingPanel" class="accordion-collapse collapse" aria-labelledby="routeMappingHeading" data-bs-parent="#moduleAdvancedAccordion">
                <div class="accordion-body">
                    <div class="alert alert-warning d-flex align-items-start gap-2">
                        <i class="ti ti-alert-triangle fs-5"></i>
                        <div>Changing route keys can block or unlock screens for tenants. Use Route Catalog to verify route ownership.</div>
                    </div>

                    <label class="form-label">Route module keys</label>
                    <textarea name="route_module_keys_text" rows="8" class="form-control font-monospace">{{ old('route_module_keys_text', implode("\n", $module->route_module_keys ?? [])) }}</textarea>
                    <div class="form-text">
                        One key per line or comma separated. These keys connect route_catalogs.module_key to subscription enforcement.
                    </div>
                    <div class="mt-3">
                        <a href="{{ url('/routes') }}" class="btn btn-sm btn-outline-secondary">
                            <i class="ti ti-route me-1"></i>Open Route Catalog
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-end">
        <button class="btn btn-primary">
            <i class="ti ti-device-floppy me-1"></i>Save Module
        </button>
    </div>
</form>
@endsection
