@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">{{ $title }}</h1>
    <a href="{{ url('/categories') }}" class="btn btn-light">Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST"
              action="{{ $category ? url('/categories/' . $category->id) : url('/categories') }}"
              novalidate>
            @csrf
            @if($category) @method('PUT') @endif

            @if($errors->any())
                <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
            @endif

            <div class="row g-3">
                <div class="col-md-3">
                    <label for="code" class="form-label required">Code</label>
                    <input id="code" type="text" name="code"
                           value="{{ old('code', $category?->code) }}"
                           class="form-control @error('code') is-invalid @enderror"
                           maxlength="50" required>
                    @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-help">Will be uppercased automatically.</div>
                </div>

                <div class="col-md-5">
                    <label for="name" class="form-label required">Name</label>
                    <input id="name" type="text" name="name"
                           value="{{ old('name', $category?->name) }}"
                           class="form-control @error('name') is-invalid @enderror"
                           maxlength="190" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label for="parent_id" class="form-label">Parent Category</label>
                    <select id="parent_id" name="parent_id"
                            class="form-select @error('parent_id') is-invalid @enderror">
                        <option value="">— None (Top Level) —</option>
                        @foreach($parents as $parent)
                            <option value="{{ $parent->id }}"
                                    @selected(old('parent_id', $category?->parent_id) == $parent->id)>
                                {{ $parent->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('parent_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-8">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" rows="3"
                              class="form-control @error('description') is-invalid @enderror"
                              maxlength="500">{{ old('description', $category?->description) }}</textarea>
                    @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-2">
                    <label for="sort_order" class="form-label required">Sort Order</label>
                    <input id="sort_order" type="number" name="sort_order" min="0"
                           value="{{ old('sort_order', $category?->sort_order ?? 0) }}"
                           class="form-control @error('sort_order') is-invalid @enderror" required>
                    @error('sort_order') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input id="is_active" type="checkbox" name="is_active" value="1"
                               class="form-check-input"
                               @checked(old('is_active', $category?->is_active ?? true))>
                        <label for="is_active" class="form-check-label">Active</label>
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Category</button>
                <a href="{{ url('/categories') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
