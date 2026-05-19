@extends('layouts.app')

@section('title', 'New Stock Transfer')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">New Stock Transfer</h1>
    <a href="{{ url('/stock-transfers') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/stock-transfers') }}" novalidate>
    @csrf

    <div class="card mb-4">
        <div class="card-header"><strong>Transfer Header</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="from_branch_id" class="form-label required">From Branch</label>
                    <select id="from_branch_id" name="from_branch_id"
                            class="form-select @error('from_branch_id') is-invalid @enderror" required>
                        <option value="">— Select —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(old('from_branch_id') == $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('from_branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label for="to_branch_id" class="form-label required">To Branch</label>
                    <select id="to_branch_id" name="to_branch_id"
                            class="form-select @error('to_branch_id') is-invalid @enderror" required>
                        <option value="">— Select —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(old('to_branch_id') == $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('to_branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label for="transfer_date" class="form-label required">Date</label>
                    <input id="transfer_date" type="date" name="transfer_date"
                           value="{{ old('transfer_date', now()->toDateString()) }}"
                           class="form-control @error('transfer_date') is-invalid @enderror" required>
                    @error('transfer_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <label for="tf-notes" class="form-label">Notes</label>
                    <textarea id="tf-notes" name="notes" rows="2"
                              class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                    @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <strong>Product Lines</strong>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addTfLine()">
                <i class="ti ti-plus me-1"></i>Add Line
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-nowrap align-top mb-0">
                    <caption class="visually-hidden">Transfer product lines</caption>
                    <thead>
                        <tr>
                            <th scope="col" style="width:30%">Product</th>
                            <th scope="col" style="width:20%">Variant</th>
                            <th scope="col" style="width:15%">Qty</th>
                            <th scope="col" style="width:15%">Unit Cost</th>
                            <th scope="col" style="width:5%"></th>
                        </tr>
                    </thead>
                    <tbody id="tf-lines-body">
                        <tr id="tf-line-0">
                            <td>
                                <label for="tf_lines_0_product" class="visually-hidden">Product</label>
                                <select id="tf_lines_0_product" name="lines[0][product_id]"
                                        class="form-select form-select-sm" onchange="loadTfVariants(this, 0)">
                                    <option value="">— Select —</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}" data-variants="{{ $product->variants->toJson() }}">
                                            {{ $product->name }} ({{ $product->sku }})
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <label for="tf_lines_0_variant" class="visually-hidden">Variant</label>
                                <select id="tf_lines_0_variant" name="lines[0][product_variant_id]"
                                        class="form-select form-select-sm">
                                    <option value="">Default</option>
                                </select>
                            </td>
                            <td>
                                <label for="tf_lines_0_qty" class="visually-hidden">Quantity</label>
                                <input id="tf_lines_0_qty" type="number" name="lines[0][quantity]"
                                       step="0.001" min="0.001" class="form-control form-control-sm">
                            </td>
                            <td>
                                <label for="tf_lines_0_cost" class="visually-hidden">Unit Cost</label>
                                <input id="tf_lines_0_cost" type="number" name="lines[0][unit_cost]"
                                       step="0.0001" min="0" value="0" class="form-control form-control-sm">
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="removeTfLine(0)" aria-label="Remove line">
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
        <button type="submit" class="btn btn-primary">Post Transfer</button>
        <a href="{{ url('/stock-transfers') }}" class="btn btn-light">Cancel</a>
    </div>
</form>

@push('scripts')
@php
$tfProductsJson = $products->map(fn($p) => [
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
let tfLineCount = 1;

const tfProductsData = @json($tfProductsJson);

function addTfLine() {
    const idx = tfLineCount++;
    const tbody = document.getElementById('tf-lines-body');

    const productOptions = tfProductsData.map(p =>
        `<option value="${p.id}" data-variants='${JSON.stringify(p.variants)}'>${p.name} (${p.sku})</option>`
    ).join('');

    tbody.insertAdjacentHTML('beforeend', `
    <tr id="tf-line-${idx}">
        <td>
            <label for="tf_lines_${idx}_product" class="visually-hidden">Product</label>
            <select id="tf_lines_${idx}_product" name="lines[${idx}][product_id]"
                    class="form-select form-select-sm" onchange="loadTfVariants(this, ${idx})">
                <option value="">— Select —</option>
                ${productOptions}
            </select>
        </td>
        <td>
            <label for="tf_lines_${idx}_variant" class="visually-hidden">Variant</label>
            <select id="tf_lines_${idx}_variant" name="lines[${idx}][product_variant_id]"
                    class="form-select form-select-sm">
                <option value="">Default</option>
            </select>
        </td>
        <td>
            <label for="tf_lines_${idx}_qty" class="visually-hidden">Quantity</label>
            <input id="tf_lines_${idx}_qty" type="number" name="lines[${idx}][quantity]"
                   step="0.001" min="0.001" class="form-control form-control-sm">
        </td>
        <td>
            <label for="tf_lines_${idx}_cost" class="visually-hidden">Unit Cost</label>
            <input id="tf_lines_${idx}_cost" type="number" name="lines[${idx}][unit_cost]"
                   step="0.0001" min="0" value="0" class="form-control form-control-sm">
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="removeTfLine(${idx})" aria-label="Remove line">
                <i class="ti ti-trash"></i>
            </button>
        </td>
    </tr>`);
}

function removeTfLine(idx) {
    const row = document.getElementById('tf-line-' + idx);
    if (row) row.remove();
}

function loadTfVariants(selectEl, idx) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const variantSelect = document.getElementById(`tf_lines_${idx}_variant`);

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
