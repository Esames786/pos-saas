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
        <div class="card-body">
            <div class="border rounded p-3 mb-3 bg-light stock-transfer-scanner-widget"
                 data-lookup-url="{{ url('/api/catalog/barcode/lookup') }}">
                <label class="form-label small fw-semibold" for="stock-transfer-scanner">
                    Scan Barcode / SKU
                </label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M1 1h2v14H1V1zm3 0h1v14H4V1zm2 0h2v14H6V1zm3 0h1v14H9V1zm2 0h1v14h-1V1zm2 0h2v14h-2V1z"/>
                        </svg>
                    </span>
                    <input type="text"
                           id="stock-transfer-scanner"
                           class="form-control"
                           placeholder="Scan barcode or type SKU then press Enter"
                           autocomplete="off"
                           inputmode="text">
                    <button class="btn btn-outline-primary" type="button" id="stock-transfer-scan-btn">
                        Add
                    </button>
                </div>
                <div class="form-text">
                    Scan product barcode/SKU to add a transfer line. Lookup uses the source branch.
                </div>
            </div>
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
                                        @php
                                            $tfVarData = $product->variants->map(fn($v) => [
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
                                                data-variants="{{ $tfVarData->toJson() }}">
                                            {{ $product->name }}{{ $product->sku ? ' — ' . $product->sku : '' }}
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
    'id'             => (int) $p->id,
    'name'           => $p->name,
    'sku'            => $p->sku,
    'purchase_price' => (string) ($p->default_purchase_price ?? 0),
    'unit_code'      => $p->unit?->code,
    'unit_type'      => $p->unit?->unit_type ?? 'quantity',
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
let tfLineCount = 1;

const tfProductsData = @json($tfProductsJson);

function addTfLine() {
    const idx = tfLineCount++;
    const tbody = document.getElementById('tf-lines-body');

    const productOptions = tfProductsData.map(p =>
        `<option value="${p.id}"
                 data-purchase-price="${p.purchase_price || 0}"
                 data-unit-code="${p.unit_code || ''}"
                 data-unit-type="${p.unit_type || 'quantity'}"
                 data-variants='${JSON.stringify(p.variants || [])}'>${p.name} (${p.sku})</option>`
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

// — Stock Transfer barcode scanner —
(function () {
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        var tokenInput = document.querySelector('input[name="_token"]');
        return tokenInput ? tokenInput.value : '';
    }

    function notifyTf(message, type) {
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

    function tfFromBranchId() {
        var b = document.querySelector('[name="from_branch_id"]');
        return b ? b.value : '';
    }

    function tfRows() {
        return Array.prototype.slice.call(
            document.querySelectorAll('#tf-lines-body tr[id^="tf-line-"]'));
    }

    function tfRowIdx(row) {
        var m = String(row ? row.id : '').match(/tf-line-(\d+)/);
        return m ? Number(m[1]) : null;
    }

    function findFreeTfRow() {
        var rows = tfRows();
        for (var i = 0; i < rows.length; i++) {
            var idx = tfRowIdx(rows[i]);
            var p = document.getElementById('tf_lines_' + idx + '_product');
            if (p && !p.value) return rows[i];
        }
        return null;
    }

    function makeTfRow() {
        if (typeof addTfLine === 'function') addTfLine();
        else throw new Error('addTfLine() not found.');
        var rows = tfRows();
        return rows[rows.length - 1] || null;
    }

    function ensureTfRow() {
        return findFreeTfRow() || makeTfRow();
    }

    function fillTfLine(result) {
        var row = ensureTfRow();
        var idx = tfRowIdx(row);
        if (idx === null) { notifyTf('Unable to find transfer row.', 'error'); return; }

        var product = document.getElementById('tf_lines_' + idx + '_product');
        var variant = document.getElementById('tf_lines_' + idx + '_variant');
        var qty     = document.getElementById('tf_lines_' + idx + '_qty');
        var cost    = document.getElementById('tf_lines_' + idx + '_cost');

        if (!product || !qty || !cost) { notifyTf('Transfer line fields missing.', 'error'); return; }

        product.value = String(result.product_id || '');
        if (typeof loadTfVariants === 'function') loadTfVariants(product, idx);

        // loadTfVariants filters out is_default variants; add the matched variant
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

        var label = result.name || result.sku || 'Product';
        notifyTf(label + (result.unit_code ? ' ' + result.unit_code : '') + ' added. Enter quantity.', 'success');
        setTimeout(function () { qty.focus(); qty.select(); }, 50);
    }

    function lookupTf(code) {
        var widget = document.querySelector('.stock-transfer-scanner-widget');
        var branchId = tfFromBranchId();
        if (!widget) return;
        if (!branchId) { notifyTf('Select source branch before scanning.', 'warning'); return; }

        fetch(widget.dataset.lookupUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ branch_id: branchId, code: code })
        })
        .then(function (r) { if (!r.ok) throw new Error('Lookup failed.'); return r.json(); })
        .then(function (result) {
            if (!result || !result.found) {
                notifyTf(result && result.message ? result.message : 'Barcode not found.', 'warning');
                return;
            }
            fillTfLine(result);
        })
        .catch(function (e) { console.error(e); notifyTf('Barcode lookup failed.', 'error'); });
    }

    function initTfScanner() {
        var scanner = document.getElementById('stock-transfer-scanner');
        var button  = document.getElementById('stock-transfer-scan-btn');
        if (!scanner || !button) return;

        function handleScan() {
            var code = scanner.value.trim();
            if (!code) { scanner.focus(); return; }
            scanner.value = '';
            lookupTf(code);
        }

        scanner.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); handleScan(); }
        });
        button.addEventListener('click', handleScan);
        setTimeout(function () { scanner.focus(); }, 200);
    }

    initTfScanner();
})();
</script>
@endpush
@endsection
