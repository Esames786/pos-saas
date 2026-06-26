@extends('layouts.app')

@section('title', $title)

@section('content')
@php
    $existingModifiers = old('modifiers');
    if ($existingModifiers === null) {
        $existingModifiers = $group->exists
            ? $group->modifiers->map(fn ($modifier) => [
                'id'                => $modifier->id,
                'name'              => $modifier->name,
                'price_delta'       => $modifier->price_delta,
                'linked_product_id' => $modifier->linked_product_id,
                'is_default'        => $modifier->is_default ? 1 : 0,
                'sort_order'        => $modifier->sort_order,
                'status'            => $modifier->status,
            ])->values()->all()
            : [
                ['name' => '', 'price_delta' => 0, 'linked_product_id' => '', 'is_default' => 0, 'sort_order' => 0, 'status' => 'active'],
            ];
    }
@endphp

<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h3 class="page-title">{{ $title }}</h3>
                    </div>
                    <div class="col-auto">
                        <a href="{{ url('/modifier-groups') }}" class="btn btn-light">Back</a>
                    </div>
                </div>
            </div>

            @if(session('status'))
                <div class="alert alert-success alert-dismissible">
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ $group->exists ? url('/modifier-groups/' . $group->id) : url('/modifier-groups') }}">
                @csrf
                @if($group->exists)
                    @method('PUT')
                @endif

                <div class="card mb-4">
                    <div class="card-header"><strong>Group Rules</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" maxlength="190" required value="{{ old('name', $group->name) }}" placeholder="Crust, Toppings, Sauces">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Branch</label>
                                <select name="branch_id" class="form-select">
                                    <option value="">All Branches</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected(old('branch_id', $group->branch_id) == $branch->id)>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Min Select</label>
                                <input type="number" name="min_select" min="0" step="1" class="form-control" value="{{ old('min_select', $group->min_select ?? 0) }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Max Select</label>
                                <input type="number" name="max_select" min="1" step="1" class="form-control" value="{{ old('max_select', $group->max_select) }}" placeholder="Any">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">Sort</label>
                                <input type="number" name="sort_order" min="0" step="1" class="form-control" value="{{ old('sort_order', $group->sort_order ?? 0) }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="active" @selected(old('status', $group->status ?? 'active') === 'active')>Active</option>
                                    <option value="inactive" @selected(old('status', $group->status) === 'inactive')>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="form-check mb-2">
                                    <input type="checkbox" name="is_required" value="1" class="form-check-input" id="is-required" @checked(old('is_required', $group->is_required))>
                                    <label for="is-required" class="form-check-label">Required group</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <strong>Modifier Options</strong>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="add-modifier-row">Add Option</button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" id="modifier-options-table">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 24%">Name</th>
                                        <th style="width: 14%">Price Delta</th>
                                        <th style="width: 28%">Linked Product</th>
                                        <th style="width: 10%">Default</th>
                                        <th style="width: 10%">Sort</th>
                                        <th style="width: 10%">Status</th>
                                        <th style="width: 4%"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($existingModifiers as $idx => $modifier)
                                    <tr>
                                        <td>
                                            <input type="hidden" name="modifiers[{{ $idx }}][id]" value="{{ $modifier['id'] ?? '' }}">
                                            <input type="hidden" name="modifiers[{{ $idx }}][_delete]" value="0" class="delete-flag">
                                            <input type="text" name="modifiers[{{ $idx }}][name]" class="form-control form-control-sm" maxlength="190" value="{{ $modifier['name'] ?? '' }}" placeholder="Extra Cheese">
                                        </td>
                                        <td>
                                            <input type="number" name="modifiers[{{ $idx }}][price_delta]" class="form-control form-control-sm" step="0.01" value="{{ $modifier['price_delta'] ?? 0 }}">
                                        </td>
                                        <td>
                                            <select name="modifiers[{{ $idx }}][linked_product_id]" class="form-select form-select-sm">
                                                <option value="">None</option>
                                                @foreach($products as $product)
                                                    <option value="{{ $product->id }}" @selected(($modifier['linked_product_id'] ?? '') == $product->id)>{{ $product->name }} ({{ $product->sku }})</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" name="modifiers[{{ $idx }}][is_default]" value="1" class="form-check-input" @checked(!empty($modifier['is_default']))>
                                        </td>
                                        <td>
                                            <input type="number" name="modifiers[{{ $idx }}][sort_order]" min="0" step="1" class="form-control form-control-sm" value="{{ $modifier['sort_order'] ?? $idx }}">
                                        </td>
                                        <td>
                                            <select name="modifiers[{{ $idx }}][status]" class="form-select form-select-sm">
                                                <option value="active" @selected(($modifier['status'] ?? 'active') === 'active')>Active</option>
                                                <option value="inactive" @selected(($modifier['status'] ?? '') === 'inactive')>Inactive</option>
                                            </select>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-modifier-row">Remove</button>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary">Save Modifier Group</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<template id="modifier-row-template">
    <tr>
        <td>
            <input type="hidden" name="modifiers[__INDEX__][id]" value="">
            <input type="hidden" name="modifiers[__INDEX__][_delete]" value="0" class="delete-flag">
            <input type="text" name="modifiers[__INDEX__][name]" class="form-control form-control-sm" maxlength="190" placeholder="Extra Cheese">
        </td>
        <td><input type="number" name="modifiers[__INDEX__][price_delta]" class="form-control form-control-sm" step="0.01" value="0"></td>
        <td>
            <select name="modifiers[__INDEX__][linked_product_id]" class="form-select form-select-sm">
                <option value="">None</option>
                @foreach($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->sku }})</option>
                @endforeach
            </select>
        </td>
        <td class="text-center"><input type="checkbox" name="modifiers[__INDEX__][is_default]" value="1" class="form-check-input"></td>
        <td><input type="number" name="modifiers[__INDEX__][sort_order]" min="0" step="1" class="form-control form-control-sm" value="0"></td>
        <td>
            <select name="modifiers[__INDEX__][status]" class="form-select form-select-sm">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-modifier-row">Remove</button></td>
    </tr>
</template>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var tableBody = document.querySelector('#modifier-options-table tbody');
    var template = document.getElementById('modifier-row-template');
    var nextIndex = {{ count($existingModifiers) }};

    document.getElementById('add-modifier-row').addEventListener('click', function () {
        var html = template.innerHTML.replaceAll('__INDEX__', nextIndex++);
        tableBody.insertAdjacentHTML('beforeend', html);
    });

    tableBody.addEventListener('click', function (event) {
        if (!event.target.classList.contains('remove-modifier-row')) return;
        var row = event.target.closest('tr');
        var idInput = row.querySelector('input[name$="[id]"]');
        var deleteFlag = row.querySelector('.delete-flag');

        if (idInput && idInput.value) {
            deleteFlag.value = '1';
            row.classList.add('d-none');
            return;
        }

        row.remove();
    });
});
</script>
@endpush
@endsection
