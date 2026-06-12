@extends('layouts.app')

@section('title', 'Edit Module')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Edit Module</h1>
        <p class="text-muted mb-0">{{ $module->name }} — <code>{{ $module->key }}</code></p>
    </div>

    <a href="{{ url('/modules') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ url('/modules/' . $module->id) }}">
    @csrf
    @method('PUT')

    <div class="card">
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">
                    Please fix the highlighted fields.
                </div>
            @endif

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Key</label>
                    <input class="form-control" value="{{ $module->key }}" disabled>
                    <div class="form-text">Module key is fixed to protect enforcement mapping.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Name</label>
                    <input name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name', $module->name) }}" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Category</label>
                    <input name="category" class="form-control"
                           value="{{ old('category', $module->category) }}">
                </div>

                <div class="col-md-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" rows="3" class="form-control">{{ old('description', $module->description) }}</textarea>
                </div>

                <div class="col-md-12">
                    <label class="form-label">Route Module Keys</label>
                    <textarea name="route_module_keys_text" rows="6" class="form-control font-monospace">{{ old('route_module_keys_text', implode("\n", $module->route_module_keys ?? [])) }}</textarea>
                    <div class="form-text text-warning">
                        Advanced: these keys connect route_catalogs.module_key to subscription enforcement.
                        Wrong values can block or unlock modules incorrectly.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Sort Order</label>
                    <input name="sort_order" type="number" min="0" class="form-control"
                           value="{{ old('sort_order', $module->sort_order) }}">
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" name="is_core" value="1" class="form-check-input"
                               id="is_core" @checked(old('is_core', $module->is_core))>
                        <label for="is_core" class="form-check-label">Core Module</label>
                    </div>
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input"
                               id="is_active" @checked(old('is_active', $module->is_active))>
                        <label for="is_active" class="form-check-label">Active</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-end mt-3">
        <button class="btn btn-primary">Save Module</button>
    </div>
</form>
@endsection
