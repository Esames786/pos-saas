@extends('layouts.app')

@section('title', 'Edit Module')

@section('content')
@php
    $summary = $moduleSummaries[$module->key] ?? [];
@endphp

<div class="mu-head">
    <div>
        <p class="mu-eyebrow">Modules</p>
        <h1>Edit Module</h1>
        <p class="mu-sub">{{ $module->name }} - <code>{{ $module->key }}</code></p>
    </div>

    <a href="{{ url('/modules') }}" class="btn btn-outline-secondary">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Back
    </a>
</div>

@if($stats)
    <section class="mu-panel">
        <div class="d-flex flex-wrap gap-3 align-items-start">
            @if($stats['image'])
                <img src="{{ $stats['image'] }}" alt="{{ $module->name }} screen" style="width:220px;height:130px;object-fit:cover;object-position:top left;border-radius:10px;border:1px solid var(--mu-border-soft)">
            @endif
            <div style="flex:1;min-width:240px">
                <h2>What this module gives</h2>
                <p class="mu-panel-sub mb-2">{{ $stats['description'] }}</p>
                <div class="mu-module-meta mb-2">
                    <span>{{ $stats['screens'] }} screens · {{ $stats['permissions'] }} permissions</span>
                    <span>{{ $stats['plans'] }} plans</span>
                    <span>{{ $stats['tenants'] }} tenants</span>
                </div>
                @if($stats['main_screens'] !== [])
                    <p class="mu-module-screens mb-0"><b>Turning this on gives these screens in the sidebar:</b> {{ implode(', ', $stats['main_screens']) }}</p>
                @endif
            </div>
        </div>
    </section>
@endif

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
                    <label class="form-label" for="module-name">Module name</label>
                    <input name="name" id="module-name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $module->name) }}" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="module-category">Category</label>
                    <input name="category" id="module-category" class="form-control" value="{{ old('category', $module->category) }}">
                </div>

                <div class="col-md-4 d-flex align-items-end gap-4">
                    <div class="form-check form-switch">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active" @checked(old('is_active', $module->is_active))>
                        <label for="is_active" class="form-check-label">Active</label>
                    </div>
                    <div class="form-check form-switch">
                        <input type="checkbox" name="is_core" value="1" class="form-check-input" id="is_core" @checked(old('is_core', $module->is_core))>
                        <label for="is_core" class="form-check-label">Core module</label>
                    </div>
                </div>

                <div class="col-md-12">
                    <label class="form-label" for="module-description">Description</label>
                    <textarea name="description" id="module-description" rows="3" class="form-control">{{ old('description', $module->description) }}</textarea>
                    @if($summary)
                        <div class="form-text">Plan Builder summary: {{ $summary['description'] }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- MASTER-UNIVERSE-1: everything technical, folded away. Still part of the form — saving keeps it. --}}
    <div class="accordion mb-3" id="moduleAdvancedAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="routeMappingHeading">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#routeMappingPanel" aria-expanded="false" aria-controls="routeMappingPanel">
                    Technical details (for developers)
                </button>
            </h2>
            <div id="routeMappingPanel" class="accordion-collapse collapse" aria-labelledby="routeMappingHeading" data-bs-parent="#moduleAdvancedAccordion">
                <div class="accordion-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" for="module-key">Key</label>
                            <input class="form-control" id="module-key" value="{{ $module->key }}" disabled>
                            <div class="form-text">Module key is fixed to protect enforcement mapping.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="module-sort">Sort order</label>
                            <input name="sort_order" id="module-sort" type="number" min="0" class="form-control" value="{{ old('sort_order', $module->sort_order) }}">
                        </div>
                    </div>

                    <div class="alert alert-warning d-flex align-items-start gap-2">
                        <i class="ti ti-alert-triangle fs-5" aria-hidden="true"></i>
                        <div>Changing route keys can block or unlock screens for tenants. Use Route Catalog to verify route ownership.</div>
                    </div>

                    <label class="form-label" for="route_module_keys_text">Route module keys</label>
                    <textarea name="route_module_keys_text" id="route_module_keys_text" rows="8" class="form-control font-monospace">{{ old('route_module_keys_text', implode("\n", $module->route_module_keys ?? [])) }}</textarea>
                    <div class="form-text">
                        One key per line or comma separated. These keys connect route_catalogs.module_key to subscription enforcement.
                    </div>
                    <div class="mt-3">
                        <a href="{{ url('/routes') }}" class="btn btn-sm btn-outline-secondary">
                            <i class="ti ti-route me-1" aria-hidden="true"></i>Open Route Catalog
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-end">
        <button class="btn btn-primary">
            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Save Module
        </button>
    </div>
</form>
@endsection
