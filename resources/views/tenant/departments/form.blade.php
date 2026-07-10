@extends('layouts.app')

@section('title', $title)

@php
    $selectedCategoryIds = collect(old('category_ids', $department?->categoryMaps->pluck('category_id')->all() ?? []))->map(fn ($v) => (int) $v)->all();
    $childrenSelected    = collect(old('category_include_children', $department?->categoryMaps->where('include_children', true)->pluck('category_id')->all() ?? []))->map(fn ($v) => (int) $v)->all();
    $includeOverrides    = $department?->productOverrides->where('mapping_type', 'include') ?? collect();
    $excludeOverrides    = $department?->productOverrides->where('mapping_type', 'exclude') ?? collect();
@endphp

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $title }}</h1>
        <p class="fw-medium text-muted mb-0">A department is an internal responsibility area inside one branch.</p>
    </div>
    <a href="{{ url('/departments') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ $department ? url('/departments/' . $department->id) : url('/departments') }}" novalidate>
    @csrf
    @if($department) @method('PUT') @endif

    {{-- 1. Department Details --}}
    <div class="card mb-3">
        <div class="card-header"><strong>1. Department Details</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id', $department?->branch_id) == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                <small class="text-muted">Departments live inside a branch — Kitchen at Main Branch is separate from Kitchen at City Branch.</small>
            </div>
            <div class="col-md-2">
                <label for="code" class="form-label required">Code</label>
                <input id="code" name="code" class="form-control text-uppercase @error('code') is-invalid @enderror"
                       value="{{ old('code', $department?->code) }}" placeholder="KITCHEN" required>
                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-3">
                <label for="name" class="form-label required">Name</label>
                <input id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $department?->name) }}" placeholder="Kitchen" required>
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-2">
                <label for="manager_user_id" class="form-label">Manager</label>
                <select id="manager_user_id" name="manager_user_id" class="form-select @error('manager_user_id') is-invalid @enderror">
                    <option value="">— Optional —</option>
                    @foreach($managers as $manager)
                        <option value="{{ $manager->id }}" @selected(old('manager_user_id', $department?->manager_user_id) == $manager->id)>{{ $manager->name }}</option>
                    @endforeach
                </select>
                @error('manager_user_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                    <option value="active"   @selected(old('status', $department?->status ?? 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $department?->status) === 'inactive')>Inactive</option>
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-9">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description', $department?->description) }}</textarea>
                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-3">
                <label for="sort_order" class="form-label">Sort Order</label>
                <input id="sort_order" type="number" min="0" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror"
                       value="{{ old('sort_order', $department?->sort_order ?? 0) }}">
                @error('sort_order') <div class="invalid-feedback">{{ $message }}</div> @enderror
                <small class="text-muted">When a product matches multiple departments, the lowest sort order wins in reports.</small>
            </div>
        </div>
    </div>

    {{-- 2. Category Responsibility --}}
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <strong>2. Category Responsibility</strong>
            <span class="small text-muted">Assign categories this department is responsible for.</span>
        </div>
        <div class="card-body">
            <div class="alert alert-light border small mb-3">
                <i class="ti ti-info-circle me-1"></i>
                If a parent category is selected, child categories can be included automatically.
                Example: <strong>Kitchen</strong> can own <em>Raw Materials</em>; <strong>Packing</strong> can own <em>Packing Material</em>.
            </div>
            <div class="row g-2">
                @forelse($categories as $root)
                    <div class="col-md-4">
                        <div class="border rounded p-2 h-100">
                            <div class="form-check">
                                <input class="form-check-input dept-cat-check" type="checkbox" name="category_ids[]"
                                       value="{{ $root->id }}" id="cat-{{ $root->id }}"
                                       @checked(in_array($root->id, $selectedCategoryIds, true))>
                                <label class="form-check-label fw-semibold" for="cat-{{ $root->id }}">{{ $root->name }}</label>
                            </div>
                            @if($root->children->count())
                                <div class="form-check ms-3">
                                    <input class="form-check-input" type="checkbox" name="category_include_children[]"
                                           value="{{ $root->id }}" id="cat-children-{{ $root->id }}"
                                           @checked(in_array($root->id, $childrenSelected, true) || (!$department && !old('_token')))>
                                    <label class="form-check-label small text-muted" for="cat-children-{{ $root->id }}">
                                        Include child categories ({{ $root->children->pluck('name')->take(4)->implode(', ') }}{{ $root->children->count() > 4 ? '…' : '' }})
                                    </label>
                                </div>
                                <div class="ms-3 mt-1 border-top pt-1">
                                    @foreach($root->children as $child)
                                        <div class="form-check">
                                            <input class="form-check-input dept-cat-check" type="checkbox" name="category_ids[]"
                                                   value="{{ $child->id }}" id="cat-{{ $child->id }}"
                                                   @checked(in_array($child->id, $selectedCategoryIds, true))>
                                            <label class="form-check-label small" for="cat-{{ $child->id }}">{{ $child->name }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="col-12 text-muted">No active categories found. Create categories first.</div>
                @endforelse
            </div>
            @error('category_ids') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
        </div>
    </div>

    {{-- 3. Product Overrides --}}
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <strong>3. Product Overrides</strong>
            <span class="small text-muted">Optional — only when a product does not follow its category.</span>
        </div>
        <div class="card-body row g-3">
            <div class="alert alert-light border small mb-1 col-12">
                <i class="ti ti-info-circle me-1"></i>
                Use overrides only when a product does not follow its category.
                Example: <em>Mineral Water</em> is usually <strong>Bar</strong>, but can be excluded from <strong>Kitchen</strong> even if Grocery is mapped.
            </div>
            <div class="col-md-6">
                <label for="include_product_ids" class="form-label">Include specific products</label>
                <select id="include_product_ids" name="include_product_ids[]" multiple
                        class="ajax-select2 form-select @error('include_product_ids') is-invalid @enderror"
                        data-ajax-url="{{ url('/ajax/products') }}"
                        data-placeholder="Search product to include…" data-min-input="1">
                    @foreach($includeOverrides as $override)
                        <option value="{{ $override->product_id }}" selected>
                            {{ $override->product?->sku ? $override->product->sku . ' — ' : '' }}{{ $override->product?->name ?? ('#' . $override->product_id) }}
                        </option>
                    @endforeach
                </select>
                <small class="text-muted">Assign this specific product even if its category is not mapped.</small>
            </div>
            <div class="col-md-6">
                <label for="exclude_product_ids" class="form-label">Exclude specific products</label>
                <select id="exclude_product_ids" name="exclude_product_ids[]" multiple
                        class="ajax-select2 form-select @error('exclude_product_ids') is-invalid @enderror"
                        data-ajax-url="{{ url('/ajax/products') }}"
                        data-placeholder="Search product to exclude…" data-min-input="1">
                    @foreach($excludeOverrides as $override)
                        <option value="{{ $override->product_id }}" selected>
                            {{ $override->product?->sku ? $override->product->sku . ' — ' : '' }}{{ $override->product?->name ?? ('#' . $override->product_id) }}
                        </option>
                    @endforeach
                </select>
                <small class="text-muted">Exclude this product even if its category is mapped. Exclude always wins.</small>
            </div>
        </div>
    </div>

    {{-- 4. Reporting Behavior --}}
    <div class="card mb-3">
        <div class="card-header"><strong>4. Reporting Behavior</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="allow_stock_issue" value="1" id="allow_stock_issue"
                           @checked(old('allow_stock_issue', $department?->allow_stock_issue ?? true))>
                    <label class="form-check-label" for="allow_stock_issue">Allow stock issue</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="require_end_day_count" value="1" id="require_end_day_count"
                           @checked(old('require_end_day_count', $department?->require_end_day_count ?? false))>
                    <label class="form-check-label" for="require_end_day_count">Require end-day count</label>
                </div>
            </div>
            <div class="col-12">
                <div class="alert alert-light border small mb-0">
                    <i class="ti ti-flag me-1"></i>
                    <strong>Allow stock issue</strong> — when off, Issue documents cannot target this department.
                    <strong>Require end-day count</strong> — the Department Dashboard flags this department as
                    <em>Count due</em> until today's count is approved.
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-primary" type="submit">{{ $department ? 'Update Department' : 'Create Department' }}</button>
        <a href="{{ url('/departments') }}" class="btn btn-light">Cancel</a>
    </div>
</form>

@include('tenant.partials.ajax-select2-scripts')
@endsection
