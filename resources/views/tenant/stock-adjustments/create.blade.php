@extends('layouts.app')

@section('title', 'New Stock Adjustment')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">New Stock Adjustment</h1>
    <a href="{{ url('/stock-adjustments') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/stock-adjustments') }}" novalidate id="adj-form">
    @csrf

    <div class="card mb-4">
        <div class="card-header"><strong>Adjustment Header</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="branch_id" class="form-label required">Branch</label>
                    <select id="branch_id" name="branch_id"
                            class="form-select @error('branch_id') is-invalid @enderror" required>
                        <option value="">— Select Branch —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label for="adjustment_type" class="form-label required">Type</label>
                    <select id="adjustment_type" name="adjustment_type"
                            class="form-select @error('adjustment_type') is-invalid @enderror" required>
                        <option value="opening"  @selected(old('adjustment_type') === 'opening')>Opening Stock</option>
                        <option value="increase" @selected(old('adjustment_type','increase') === 'increase')>Increase</option>
                        <option value="decrease" @selected(old('adjustment_type') === 'decrease')>Decrease</option>
                        <option value="wastage"  @selected(old('adjustment_type') === 'wastage')>Wastage / Damage</option>
                    </select>
                    @error('adjustment_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label for="adjustment_date" class="form-label required">Date</label>
                    <input id="adjustment_date" type="date" name="adjustment_date"
                           value="{{ old('adjustment_date', now()->toDateString()) }}"
                           class="form-control @error('adjustment_date') is-invalid @enderror" required>
                    @error('adjustment_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <label for="notes" class="form-label">Notes</label>
                    <textarea id="notes" name="notes" rows="2"
                              class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                    @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>
    </div>

    {{-- Lines --}}
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <strong>Product Lines</strong>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addLine()">
                <i class="ti ti-plus me-1"></i>Add Line
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-nowrap align-top mb-0" id="lines-table">
                    <caption class="visually-hidden">Adjustment product lines</caption>
                    <thead>
                        <tr>
                            <th scope="col" style="width:25%">Product</th>
                            <th scope="col" style="width:15%">Variant</th>
                            <th scope="col" style="width:12%">Batch No</th>
                            <th scope="col" style="width:12%">Expiry Date</th>
                            <th scope="col" style="width:10%">Qty</th>
                            <th scope="col" style="width:10%">Unit Cost</th>
                            <th scope="col" style="width:12%">Notes</th>
                            <th scope="col" style="width:4%"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                        <tr id="line-0" class="line-row">
                            <td>
                                <label for="lines_0_product" class="visually-hidden">Product</label>
                                <select id="lines_0_product" name="lines[0][product_id]"
                                        class="form-select form-select-sm product-select" onchange="loadVariants(this, 0)">
                                    <option value="">— Select —</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}" data-variants="{{ $product->variants->toJson() }}">
                                            {{ $product->name }} ({{ $product->sku }})
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <label for="lines_0_variant" class="visually-hidden">Variant</label>
                                <select id="lines_0_variant" name="lines[0][product_variant_id]"
                                        class="form-select form-select-sm variant-select">
                                    <option value="">Default</option>
                                </select>
                            </td>
                            <td>
                                <label for="lines_0_batch" class="visually-hidden">Batch No</label>
                                <input id="lines_0_batch" type="text" name="lines[0][batch_no]"
                                       class="form-control form-control-sm" maxlength="100"
                                       value="{{ old('lines.0.batch_no') }}">
                            </td>
                            <td>
                                <label for="lines_0_expiry" class="visually-hidden">Expiry Date</label>
                                <input id="lines_0_expiry" type="date" name="lines[0][expiry_date]"
                                       class="form-control form-control-sm"
                                       value="{{ old('lines.0.expiry_date') }}">
                            </td>
                            <td>
                                <label for="lines_0_qty" class="visually-hidden">Quantity</label>
                                <input id="lines_0_qty" type="number" name="lines[0][quantity]"
                                       step="0.001" min="0.001"
                                       class="form-control form-control-sm"
                                       value="{{ old('lines.0.quantity') }}">
                            </td>
                            <td>
                                <label for="lines_0_cost" class="visually-hidden">Unit Cost</label>
                                <input id="lines_0_cost" type="number" name="lines[0][unit_cost]"
                                       step="0.0001" min="0" value="{{ old('lines.0.unit_cost', 0) }}"
                                       class="form-control form-control-sm">
                            </td>
                            <td>
                                <label for="lines_0_notes" class="visually-hidden">Notes</label>
                                <input id="lines_0_notes" type="text" name="lines[0][notes]"
                                       class="form-control form-control-sm"
                                       value="{{ old('lines.0.notes') }}">
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="removeLine(0)" aria-label="Remove line">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @error('lines') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror
    @error('stock') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Post Adjustment</button>
        <a href="{{ url('/stock-adjustments') }}" class="btn btn-light">Cancel</a>
    </div>
</form>

@push('scripts')
@php
$adjProductsJson = $products->map(fn($p) => [
    'id'       => $p->id,
    'name'     => $p->name,
    'sku'      => $p->sku,
    'variants' => $p->variants->map(fn($v) => [
        'id'         => $v->id,
        'name'       => $v->name,
        'is_default' => $v->is_default,
    ])->values(),
]);
@endphp
<script>
let lineCount = 1;

const productsData = @json($adjProductsJson);

function addLine() {
    const idx = lineCount++;
    const tbody = document.getElementById('lines-body');

    const productOptions = productsData.map(p =>
        `<option value="${p.id}" data-variants='${JSON.stringify(p.variants)}'>${p.name} (${p.sku})</option>`
    ).join('');

    const row = `
    <tr id="line-${idx}" class="line-row">
        <td>
            <label for="lines_${idx}_product" class="visually-hidden">Product</label>
            <select id="lines_${idx}_product" name="lines[${idx}][product_id]"
                    class="form-select form-select-sm product-select" onchange="loadVariants(this, ${idx})">
                <option value="">— Select —</option>
                ${productOptions}
            </select>
        </td>
        <td>
            <label for="lines_${idx}_variant" class="visually-hidden">Variant</label>
            <select id="lines_${idx}_variant" name="lines[${idx}][product_variant_id]"
                    class="form-select form-select-sm variant-select">
                <option value="">Default</option>
            </select>
        </td>
        <td>
            <label for="lines_${idx}_batch" class="visually-hidden">Batch No</label>
            <input id="lines_${idx}_batch" type="text" name="lines[${idx}][batch_no]"
                   class="form-control form-control-sm" maxlength="100">
        </td>
        <td>
            <label for="lines_${idx}_expiry" class="visually-hidden">Expiry Date</label>
            <input id="lines_${idx}_expiry" type="date" name="lines[${idx}][expiry_date]"
                   class="form-control form-control-sm">
        </td>
        <td>
            <label for="lines_${idx}_qty" class="visually-hidden">Quantity</label>
            <input id="lines_${idx}_qty" type="number" name="lines[${idx}][quantity]"
                   step="0.001" min="0.001" class="form-control form-control-sm">
        </td>
        <td>
            <label for="lines_${idx}_cost" class="visually-hidden">Unit Cost</label>
            <input id="lines_${idx}_cost" type="number" name="lines[${idx}][unit_cost]"
                   step="0.0001" min="0" value="0" class="form-control form-control-sm">
        </td>
        <td>
            <label for="lines_${idx}_notes" class="visually-hidden">Notes</label>
            <input id="lines_${idx}_notes" type="text" name="lines[${idx}][notes]"
                   class="form-control form-control-sm">
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="removeLine(${idx})" aria-label="Remove line">
                <i class="ti ti-trash"></i>
            </button>
        </td>
    </tr>`;

    tbody.insertAdjacentHTML('beforeend', row);
}

function removeLine(idx) {
    const row = document.getElementById('line-' + idx);
    if (row) row.remove();
}

function loadVariants(selectEl, idx) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const variantSelect = document.getElementById(`lines_${idx}_variant`);

    let variants = [];
    try { variants = JSON.parse(opt.dataset.variants || '[]'); } catch {}

    variantSelect.innerHTML = '<option value="">Default</option>';
    variants.filter(v => !v.is_default).forEach(v => {
        const o = document.createElement('option');
        o.value = v.id;
        o.textContent = v.name;
        variantSelect.appendChild(o);
    });
}
</script>
@endpush
@endsection
