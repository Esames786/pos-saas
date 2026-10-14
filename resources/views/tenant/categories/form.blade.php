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

                {{-- CATEGORY-BRANCH-SCOPE-1: blank = every branch, which is what every category
                     already is. Worth setting only where branches sell different menus. --}}
                <div class="col-md-4">
                    <label for="branch_id" class="form-label">Branch</label>
                    <select id="branch_id" name="branch_id"
                            class="form-select @error('branch_id') is-invalid @enderror">
                        <option value="">— All branches —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}"
                                    @selected(old('branch_id', $category?->branch_id) == $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">Leave on “All branches” unless this menu belongs to one branch only.</div>
                </div>

                {{-- PNL-COST-BY-CATEGORY-1: where this category's purchase-only (no stock) bills land on the P&L.
                     Blank = the parent's account, or 5100 Product COGS — exactly what every category does today. --}}
                <div class="col-md-4">
                    <label for="cogs_account_id" class="form-label">Cost of Sales account</label>
                    <select id="cogs_account_id" name="cogs_account_id"
                            class="form-select @error('cogs_account_id') is-invalid @enderror">
                        <option value="">— Same as parent / 5100 Product COGS —</option>
                        @foreach($costAccounts as $acc)
                            <option value="{{ $acc->id }}"
                                    @selected((string) old('cogs_account_id', $category?->cogs_account_id) === (string) $acc->id)>
                                {{ $acc->code }} — {{ $acc->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('cogs_account_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">Bills for this category's no-stock items post here, so the P&amp;L shows them as their own line (e.g. 5110 Beverages). New bills only.</div>
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

            {{-- ONLINE-ORDERING-1: only on a plan with Online Ordering; the POS does not change. --}}
            @if(\App\Support\OnlineOrdering::enabledForTenant())
                <input type="hidden" name="_website" value="1">
                <h5 class="mb-3 mt-4">Website</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="form-check form-switch mt-4">
                            <input type="checkbox" value="1" name="show_on_website" id="show_on_website" class="form-check-input"
                                   @checked(old('show_on_website', $category?->show_on_website))>
                            <label class="form-check-label" for="show_on_website">Show on website</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="website_name" class="form-label">Name on website</label>
                        <input id="website_name" type="text" name="website_name" maxlength="120" class="form-control"
                               value="{{ old('website_name', $category?->website_name) }}" placeholder="{{ $category?->name ?: 'Same as name' }}">
                    </div>
                    <div class="col-md-2">
                        <label for="website_sort_order" class="form-label">Website order</label>
                        <input id="website_sort_order" type="number" min="0" name="website_sort_order" class="form-control"
                               value="{{ old('website_sort_order', $category?->website_sort_order) }}">
                    </div>
                    <div class="col-md-2">
                        <label for="website_visible_from" class="form-label">Shown from</label>
                        <input id="website_visible_from" type="time" name="website_visible_from" class="form-control @error('website_visible_from') is-invalid @enderror"
                               value="{{ old('website_visible_from', $category?->website_visible_from ? substr($category->website_visible_from, 0, 5) : '') }}">
                        @error('website_visible_from') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label for="website_visible_to" class="form-label">Shown until</label>
                        <input id="website_visible_to" type="time" name="website_visible_to" class="form-control"
                               value="{{ old('website_visible_to', $category?->website_visible_to ? substr($category->website_visible_to, 0, 5) : '') }}">
                    </div>
                    <div class="col-12 form-text mt-0">Leave both times empty to show it all day. 00:00 → 04:00 shows it only after midnight.</div>
                </div>
            @endif

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Category</button>
                <a href="{{ url('/categories') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
