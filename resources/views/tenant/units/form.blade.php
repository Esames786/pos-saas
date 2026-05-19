@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">{{ $title }}</h1>
    <a href="{{ url('/units') }}" class="btn btn-light">Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST"
              action="{{ $unit ? url('/units/' . $unit->id) : url('/units') }}"
              novalidate>
            @csrf
            @if($unit) @method('PUT') @endif

            @if($errors->any())
                <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
            @endif

            <div class="row g-3">
                <div class="col-md-3">
                    <label for="code" class="form-label required">Code</label>
                    <input id="code" type="text" name="code"
                           value="{{ old('code', $unit?->code) }}"
                           class="form-control @error('code') is-invalid @enderror"
                           maxlength="50" required>
                    @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-help">Will be uppercased automatically.</div>
                </div>

                <div class="col-md-5">
                    <label for="name" class="form-label required">Name</label>
                    <input id="name" type="text" name="name"
                           value="{{ old('name', $unit?->name) }}"
                           class="form-control @error('name') is-invalid @enderror"
                           maxlength="190" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label for="unit_type" class="form-label required">Type</label>
                    <select id="unit_type" name="unit_type"
                            class="form-select @error('unit_type') is-invalid @enderror" required>
                        @foreach(['quantity', 'weight', 'volume', 'length'] as $type)
                            <option value="{{ $type }}" @selected(old('unit_type', $unit?->unit_type) === $type)>
                                {{ ucfirst($type) }}
                            </option>
                        @endforeach
                    </select>
                    @error('unit_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label for="base_factor" class="form-label required">Base Conversion Factor</label>
                    <input id="base_factor" type="number" name="base_factor" step="0.000001" min="0.000001"
                           value="{{ old('base_factor', $unit?->base_factor ?? 1) }}"
                           class="form-control @error('base_factor') is-invalid @enderror" required>
                    @error('base_factor') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-help">e.g. 1000 for kg→g, 1 for base unit.</div>
                </div>

                <div class="col-md-4 d-flex align-items-end gap-4">
                    <div class="form-check mb-2">
                        <input id="is_base" type="checkbox" name="is_base" value="1"
                               class="form-check-input"
                               @checked(old('is_base', $unit?->is_base))>
                        <label for="is_base" class="form-check-label">Is Base Unit</label>
                    </div>
                    <div class="form-check mb-2">
                        <input id="is_active" type="checkbox" name="is_active" value="1"
                               class="form-check-input"
                               @checked(old('is_active', $unit?->is_active ?? true))>
                        <label for="is_active" class="form-check-label">Active</label>
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Unit</button>
                <a href="{{ url('/units') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
