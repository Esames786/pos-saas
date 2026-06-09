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
        <div class="card-body">
            <div class="border rounded p-3 mb-3 bg-light stock-adjustment-scanner-widget"
                 data-lookup-url="{{ url('/api/catalog/barcode/lookup') }}">
                <label class="form-label small fw-semibold" for="stock-adjustment-scanner">
                    Scan Barcode / SKU
                </label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M1 1h2v14H1V1zm3 0h1v14H4V1zm2 0h2v14H6V1zm3 0h1v14H9V1zm2 0h1v14h-1V1zm2 0h2v14h-2V1z"/>
                        </svg>
                    </span>
                    <input type="text"
                           id="stock-adjustment-scanner"
                           class="form-control"
                           placeholder="Scan barcode or type SKU then press Enter"
                           autocomplete="off"
                           inputmode="text">
                    <button class="btn btn-outline-primary" type="button" id="stock-adjustment-scan-btn">
                        Add
                    </button>
                </div>
                <div class="form-text">
                    Scan product barcode/SKU to add an adjustment line, then enter counted adjustment quantity.
                </div>
            </div>
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
                                        @php
                                            $adjVarData = $product->variants->map(fn($v) => [
                                                'id'             => (int) $v->id,
                                                'name'           => $v->name,
                                                'sku'            => $v->sku,
                                                'barcode'        => $v->barcode,
                                                'purchase_price' => (string) ($v->purchase_price ?? 0),
                                                'selling_price'  => (string) ($v->selling_price ?? 0),
                                                'is_default'     => (bool) $v->is_default,
                                                'is_active'      => (bool) $v->is_active,
                                            ])->values();
                                        @endphp
                                        <option value="{{ $product->id }}"
                                                data-purchase-price="{{ $product->default_purchase_price ?? 0 }}"
                                                data-unit-code="{{ $product->unit?->code }}"
                                                data-unit-type="{{ $product->unit?->unit_type ?? 'quantity' }}"
                                                data-requires-batch="{{ $product->requires_batch ? 1 : 0 }}"
                                                data-has-expiry="{{ $product->has_expiry ? 1 : 0 }}"
                                                data-variants="{{ $adjVarData->toJson() }}">
                                            {{ $product->name }}{{ $product->sku ? ' — ' . $product->sku : '' }}
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
    'id'             => (int) $p->id,
    'name'           => $p->name,
    'sku'            => $p->sku,
    'purchase_price' => (string) ($p->default_purchase_price ?? 0),
    'unit_code'      => $p->unit?->code,
    'unit_type'      => $p->unit?->unit_type ?? 'quantity',
    'requires_batch' => (bool) $p->requires_batch,
    'has_expiry'     => (bool) $p->has_expiry,
    'variants'       => $p->variants->map(fn($v) => [
        'id'             => (int) $v->id,
        'name'           => $v->name,
        'sku'            => $v->sku,
        'barcode'        => $v->barcode,
        'purchase_price' => (string) ($v->purchase_price ?? 0),
        'selling_price'  => (string) ($v->selling_price ?? 0),
        'is_default'     => (bool) $v->is_default,
        'is_active'      => (bool) $v->is_active,
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
        `<option value="${p.id}"
                 data-purchase-price="${p.purchase_price || 0}"
                 data-unit-code="${p.unit_code || ''}"
                 data-unit-type="${p.unit_type || 'quantity'}"
                 data-requires-batch="${p.requires_batch ? 1 : 0}"
                 data-has-expiry="${p.has_expiry ? 1 : 0}"
                 data-variants='${JSON.stringify(p.variants || [])}'>${p.name} (${p.sku})</option>`
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

// — Stock Adjustment barcode scanner —
(function () {
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        var tokenInput = document.querySelector('input[name="_token"]');
        return tokenInput ? tokenInput.value : '';
    }

    function notifyAdj(message, type) {
        if (window.Swal) {
            Swal.fire({ toast: true, position: 'top-end', timer: 2200,
                showConfirmButton: false, icon: type || 'info', title: message });
            return;
        }
        alert(message);
    }

    function moneyVal(value) {
        var num = Number(value || 0);
        return Number.isFinite(num) ? num : 0;
    }

    function adjBranchId() {
        var b = document.querySelector('[name="branch_id"]');
        return b ? b.value : '';
    }

    function adjRows() {
        return Array.prototype.slice.call(
            document.querySelectorAll('#lines-body tr[id^="line-"]'));
    }

    function adjRowIdx(row) {
        var m = String(row ? row.id : '').match(/line-(\d+)/);
        return m ? Number(m[1]) : null;
    }

    function findFreeAdjRow() {
        var rows = adjRows();
        for (var i = 0; i < rows.length; i++) {
            var idx = adjRowIdx(rows[i]);
            var p = document.getElementById('lines_' + idx + '_product');
            if (p && !p.value) return rows[i];
        }
        return null;
    }

    function makeAdjRow() {
        if (typeof addLine === 'function') addLine();
        else throw new Error('addLine() not found.');
        var rows = adjRows();
        return rows[rows.length - 1] || null;
    }

    function ensureAdjRow() {
        return findFreeAdjRow() || makeAdjRow();
    }

    function fillAdjLine(result) {
        var row = ensureAdjRow();
        var idx = adjRowIdx(row);
        if (idx === null) { notifyAdj('Unable to find adjustment row.', 'error'); return; }

        var product = document.getElementById('lines_' + idx + '_product');
        var variant = document.getElementById('lines_' + idx + '_variant');
        var qty     = document.getElementById('lines_' + idx + '_qty');
        var cost    = document.getElementById('lines_' + idx + '_cost');
        var batch   = document.getElementById('lines_' + idx + '_batch');
        var expiry  = document.getElementById('lines_' + idx + '_expiry');

        if (!product || !qty || !cost) { notifyAdj('Adjustment line fields missing.', 'error'); return; }

        product.value = String(result.product_id || '');
        if (typeof loadVariants === 'function') loadVariants(product, idx);

        // loadVariants filters out is_default variants; add the matched variant
        // explicitly so it appears selected even if it is the default variant
        if (variant && result.variant_id) {
            if (!variant.querySelector('option[value="' + result.variant_id + '"]')) {
                var opt = document.createElement('option');
                opt.value = result.variant_id;
                opt.textContent = result.variant_name || result.name || ('Variant #' + result.variant_id);
                variant.insertBefore(opt, variant.options[1] || null);
            }
            variant.value = String(result.variant_id);
        }

        qty.value = result.allow_decimal ? '1.000' : '1';
        qty.step  = result.allow_decimal ? '0.001' : '1';
        qty.min   = '0.001';
        cost.value = moneyVal(result.purchase_price).toFixed(4);

        if (batch && result.requires_batch)  batch.placeholder = 'Required';
        if (expiry && result.has_expiry)     expiry.classList.add('border-warning');

        var label = result.name || result.sku || 'Product';
        notifyAdj(label + (result.unit_code ? ' ' + result.unit_code : '') + ' added. Enter quantity.', 'success');
        setTimeout(function () { qty.focus(); qty.select(); }, 50);
    }

    function lookupAdj(code) {
        var widget = document.querySelector('.stock-adjustment-scanner-widget');
        var branchId = adjBranchId();
        if (!widget) return;
        if (!branchId) { notifyAdj('Select branch before scanning.', 'warning'); return; }

        fetch(widget.dataset.lookupUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ branch_id: branchId, code: code })
        })
        .then(function (r) { if (!r.ok) throw new Error('Lookup failed.'); return r.json(); })
        .then(function (result) {
            if (!result || !result.found) {
                notifyAdj(result && result.message ? result.message : 'Barcode not found.', 'warning');
                return;
            }
            fillAdjLine(result);
        })
        .catch(function (e) { console.error(e); notifyAdj('Barcode lookup failed.', 'error'); });
    }

    function initAdjScanner() {
        var scanner = document.getElementById('stock-adjustment-scanner');
        var button  = document.getElementById('stock-adjustment-scan-btn');
        if (!scanner || !button) return;

        function handleScan() {
            var code = scanner.value.trim();
            if (!code) { scanner.focus(); return; }
            scanner.value = '';
            lookupAdj(code);
        }

        scanner.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); handleScan(); }
        });
        button.addEventListener('click', handleScan);
        setTimeout(function () { scanner.focus(); }, 200);
    }

    initAdjScanner();
})();
</script>
@endpush
@endsection
