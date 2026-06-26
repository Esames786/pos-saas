@php
    $editing = isset($combo);
    $componentRows = old('components', $editing ? $combo->components->map(fn ($component) => [
        'product_id' => $component->product_id,
        'product_variant_id' => $component->product_variant_id,
        'quantity' => $component->quantity,
        'sort_order' => $component->sort_order,
    ])->all() : [['product_id' => '', 'product_variant_id' => '', 'quantity' => 1, 'sort_order' => 10]]);
@endphp
@extends('layouts.app')

@section('title', $editing ? 'Edit Combo' : 'Create Combo')

@section('content')
    <div class="page-header">
        <div class="page-title">
            <h4>{{ $editing ? 'Edit Combo' : 'New Combo' }}</h4>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ $editing ? url('/combos/' . $combo->id) : url('/combos') }}">
                @csrf
                @if($editing) @method('PUT') @endif

                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required" for="name">Name</label>
                        <input id="name" name="name" class="form-control" value="{{ old('name', $combo->name ?? '') }}" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="code">Code</label>
                        <input id="code" name="code" class="form-control" value="{{ old('code', $combo->code ?? '') }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label required" for="price">Combo Price</label>
                        <input id="price" type="number" step="0.01" min="0" name="price" class="form-control" value="{{ old('price', $combo->price ?? '') }}" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="branch_id">Branch</label>
                        <select id="branch_id" name="branch_id" class="form-select">
                            <option value="">All branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('branch_id', $combo->branch_id ?? '') === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label required" for="status">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="active" @selected(old('status', $combo->status ?? 'active') === 'active')>Active</option>
                            <option value="inactive" @selected(old('status', $combo->status ?? '') === 'inactive')>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="sort_order">Sort Order</label>
                        <input id="sort_order" type="number" min="0" name="sort_order" class="form-control" value="{{ old('sort_order', $combo->sort_order ?? 0) }}">
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea id="description" name="description" rows="2" class="form-control">{{ old('description', $combo->description ?? '') }}</textarea>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="mb-0">Components</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="add-component-row">
                        <i class="ti ti-plus me-1"></i> Add Component
                    </button>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th style="min-width:260px">Product</th>
                            <th style="min-width:220px">Variant</th>
                            <th style="width:140px">Quantity</th>
                            <th style="width:130px">Sort</th>
                            <th style="width:80px"></th>
                        </tr>
                        </thead>
                        <tbody id="component-rows">
                        @foreach($componentRows as $i => $row)
                            @include('tenant.combos.partials.component-row', ['index' => $i, 'row' => $row])
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-primary">{{ $editing ? 'Update Combo' : 'Create Combo' }}</button>
                    <a href="{{ url('/combos') }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <template id="component-row-template">
        @include('tenant.combos.partials.component-row', ['index' => '__INDEX__', 'row' => ['product_id' => '', 'product_variant_id' => '', 'quantity' => 1, 'sort_order' => '']])
    </template>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const rows = document.getElementById('component-rows');
    const template = document.getElementById('component-row-template');

    document.getElementById('add-component-row')?.addEventListener('click', function () {
        const index = rows.querySelectorAll('[data-component-row]').length;
        rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
    });

    rows.addEventListener('click', function (event) {
        const button = event.target.closest('[data-remove-component]');
        if (!button) return;
        if (rows.querySelectorAll('[data-component-row]').length <= 1) return;
        button.closest('[data-component-row]').remove();
    });
});
</script>
@endpush
