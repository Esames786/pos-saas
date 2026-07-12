@extends('layouts.app')

@section('title', 'Create Purchase Return')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Create Purchase Return</h1>
        <p class="fw-medium text-muted mb-0">Return goods to the supplier — saved as a draft first; nothing moves until you Post.</p>
    </div>
    <a href="{{ url('/purchase-returns') }}" class="btn btn-light">Back</a>
</div>

<div class="card border-warning-subtle mb-3">
    <div class="card-body py-2 small">
        <i class="ti ti-alert-triangle me-1"></i>
        Posting this return will <strong>reduce official branch stock</strong> and <strong>reduce the supplier payable</strong>.
        It cannot be edited after posting. If the supplier is already fully paid, the return creates a <strong>supplier credit</strong>.
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/purchase-returns') }}" novalidate id="pret-form">
    @csrf

    {{-- Header --}}
    <div class="card mb-3">
        <div class="card-header"><strong>Return Header</strong> <span class="text-muted small ms-2">choose these first — lines unlock after</span></div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select" required {{ $goodsReceipt ? 'disabled' : '' }}>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id', $goodsReceipt?->branch_id) == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                @if($goodsReceipt)<input type="hidden" name="branch_id" value="{{ $goodsReceipt->branch_id }}">@endif
            </div>
            <div class="col-md-3">
                <label for="supplier_id" class="form-label required">Supplier</label>
                <select id="supplier_id" name="supplier_id" class="form-select" required {{ $goodsReceipt ? 'disabled' : '' }}>
                    <option value="">— Select —</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id', $goodsReceipt?->supplier_id) == $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
                @if($goodsReceipt)<input type="hidden" name="supplier_id" value="{{ $goodsReceipt->supplier_id }}">@endif
            </div>
            <div class="col-md-3">
                <label for="grn-picker" class="form-label">Source GRN <span class="text-muted small">(recommended)</span></label>
                <select id="grn-picker" class="form-select">
                    @if($goodsReceipt)
                        <option value="{{ $goodsReceipt->id }}" selected>{{ $goodsReceipt->grn_no }} — {{ $goodsReceipt->supplier?->name }}</option>
                    @endif
                </select>
                @if($goodsReceipt)<input type="hidden" name="goods_receipt_id" value="{{ $goodsReceipt->id }}">@endif
                <div class="form-text">Pick the receipt being returned against — lines and returnable quantities load automatically.</div>
            </div>
            <div class="col-md-3">
                <label for="return_date" class="form-label required">Return Date</label>
                <input id="return_date" type="date" name="return_date" class="form-control" required
                       value="{{ old('return_date', now()->toDateString()) }}">
            </div>
            <div class="col-md-3">
                <label for="reason_code" class="form-label required">Reason</label>
                <select id="reason_code" name="reason_code" class="form-select">
                    <option value="">— Select —</option>
                    @foreach($reasonCodes as $code)
                        <option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ ucwords(str_replace('_', ' ', $code)) }}</option>
                    @endforeach
                </select>
                @error('reason_code') <div class="text-danger small">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-9">
                <label for="notes" class="form-label">Notes</label>
                <input id="notes" name="notes" class="form-control" value="{{ old('notes') }}">
            </div>
        </div>
    </div>

    @if($goodsReceipt)
        {{-- GRN-sourced lines: returnable quantities per received line --}}
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span><strong>Received Lines</strong> <span class="text-muted small ms-2">{{ $goodsReceipt->grn_no }} · {{ $goodsReceipt->receipt_date?->format('Y-m-d') }}</span></span>
                <span class="small text-muted">Enter the quantity to return per line — 0 = not returned.</span>
            </div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th><th>Variant</th><th>Batch</th>
                            <th class="text-end">Received</th><th class="text-end">Already Returned</th><th class="text-end">Returnable</th>
                            <th style="width:120px">Return Qty</th><th class="text-end">Unit Cost</th><th class="text-end">Line Total</th>
                            <th style="width:150px">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($goodsReceipt->lines as $i => $line)
                        @php
                            $canReturn = max($returnable[$line->id] ?? 0, 0);
                            $already   = (float) $line->quantity_received - $canReturn;
                        @endphp
                        <tr class="pret-line" data-cost="{{ (float) $line->unit_cost }}">
                            <td>
                                {{ $line->product?->name }}
                                <input type="hidden" name="lines[{{ $i }}][product_id]" value="{{ $line->product_id }}">
                                <input type="hidden" name="lines[{{ $i }}][product_variant_id]" value="{{ $line->product_variant_id }}">
                                <input type="hidden" name="lines[{{ $i }}][source_line_id]" value="{{ $line->id }}">
                                <input type="hidden" name="lines[{{ $i }}][unit_cost]" value="{{ (float) $line->unit_cost }}">
                            </td>
                            <td>{{ $line->variant?->name ?? 'Default' }}</td>
                            <td>{{ $line->batch_no ?? '—' }}</td>
                            <td class="text-end">{{ number_format($line->quantity_received, 3) }}</td>
                            <td class="text-end">{{ number_format($already, 3) }}</td>
                            <td class="text-end">
                                <span class="badge {{ $canReturn > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' }}">{{ number_format($canReturn, 3) }}</span>
                            </td>
                            <td>
                                <input type="number" step="0.001" min="0" max="{{ $canReturn }}"
                                       name="lines[{{ $i }}][quantity]" value="0"
                                       class="form-control form-control-sm text-end pret-qty"
                                       {{ $canReturn <= 0 ? 'disabled' : '' }}>
                            </td>
                            <td class="text-end">{{ number_format($line->unit_cost, 4) }}</td>
                            <td class="text-end pret-line-total">0.00</td>
                            <td>
                                <select name="lines[{{ $i }}][reason_code]" class="form-select form-select-sm">
                                    <option value="">Header reason</option>
                                    @foreach($reasonCodes as $code)
                                        <option value="{{ $code }}">{{ ucwords(str_replace('_', ' ', $code)) }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="table-light fw-semibold">
                        <tr><td colspan="8" class="text-end">Return Total</td><td class="text-end" id="pret-grand">0.00</td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @else
        {{-- Manual lines (no GRN): AJAX product picker, validated against stock --}}
        <div class="alert alert-warning py-2 px-3 small" id="pret-gate-warn">
            <i class="ti ti-lock me-1"></i>Select the <strong>Branch and Supplier</strong> above (or pick a Source GRN) to unlock product lines.
        </div>
        <fieldset id="pret-lines-fieldset" disabled>
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <strong>Return Lines (no source receipt)</strong>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-light text-muted border d-none d-md-inline">Alt+N = add line</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="pret-add-line"><i class="ti ti-plus me-1"></i>Add Line</button>
                    </div>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:260px">Product</th><th>Variant</th>
                                <th class="text-end">Current Stock</th>
                                <th style="width:110px">Return Qty</th><th style="width:120px">Unit Cost</th>
                                <th class="text-end">Line Total</th><th style="width:150px">Reason</th><th></th>
                            </tr>
                        </thead>
                        <tbody id="pret-lines-body"></tbody>
                        <tfoot class="table-light fw-semibold">
                            <tr><td colspan="5" class="text-end">Return Total</td><td class="text-end" id="pret-grand">0.00</td><td colspan="2"></td></tr>
                        </tfoot>
                    </table>
                </div>
                <div class="card-footer small text-muted">
                    <i class="ti ti-info-circle me-1"></i>Without a source receipt, quantities are validated against current branch stock only. Prefer selecting the Source GRN for received/returned tracking.
                </div>
            </div>
        </fieldset>
    @endif

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-primary" type="submit" {{ !$goodsReceipt ? 'form=pret-form' : '' }}>Save Draft</button>
        <a href="{{ url('/purchase-returns') }}" class="btn btn-light">Cancel</a>
    </div>
</form>

<template id="pret-line-template">
    <tr class="pret-line" data-cost="0">
        <td><select name="lines[__IDX__][product_id]" class="form-select form-select-sm pret-product-select"></select>
            <input type="hidden" name="lines[__IDX__][product_variant_id]" class="pret-variant-id" value=""></td>
        <td class="pret-variant-label small text-muted">Default</td>
        <td class="text-end"><span class="badge bg-light text-dark border pret-stock-badge">—</span></td>
        <td><input type="number" step="0.001" min="0.001" name="lines[__IDX__][quantity]" class="form-control form-control-sm text-end pret-qty"></td>
        <td><input type="number" step="0.0001" min="0" name="lines[__IDX__][unit_cost]" value="0" class="form-control form-control-sm text-end pret-cost"></td>
        <td class="text-end pret-line-total">0.00</td>
        <td>
            <select name="lines[__IDX__][reason_code]" class="form-select form-select-sm">
                <option value="">Header reason</option>
                @foreach($reasonCodes as $code)
                    <option value="{{ $code }}">{{ ucwords(str_replace('_', ' ', $code)) }}</option>
                @endforeach
            </select>
        </td>
        <td><button type="button" class="btn btn-sm btn-outline-danger pret-remove-line" aria-label="Remove line">&times;</button></td>
    </tr>
</template>

@push('scripts')
<script>
(function () {
    var $ = window.jQuery;
    var productsUrl = @json(url('/ajax/products'));
    var grnUrl = @json(url('/ajax/goods-receipts'));
    var hasGrn = @json((bool) $goodsReceipt);
    var lineIdx = 0;

    function branchId() { var el = document.querySelector('select#branch_id'); return el && !el.disabled ? el.value : @json($goodsReceipt?->branch_id); }
    function supplierId() { var el = document.querySelector('select#supplier_id'); return el && !el.disabled ? el.value : @json($goodsReceipt?->supplier_id); }

    // GRN picker — choosing a receipt reloads with its returnable lines.
    if ($ && $.fn.select2) {
        $('#grn-picker').select2({
            width: '100%', placeholder: 'Search GRN no…', allowClear: true, minimumInputLength: 0,
            ajax: {
                url: grnUrl, dataType: 'json', delay: 200, cache: false,
                data: function (params) {
                    return { q: params.term || '', page: params.page || 1, supplier_id: supplierId() || '', branch_id: branchId() || '' };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
                },
            },
        }).on('select2:select', function (e) {
            window.location = @json(url('/purchase-returns/create')) + '?goods_receipt_id=' + e.params.data.id;
        }).on('select2:clear', function () {
            if (hasGrn) window.location = @json(url('/purchase-returns/create'));
        });
    }

    function recalc() {
        var grand = 0;
        document.querySelectorAll('tr.pret-line').forEach(function (row) {
            var qty = parseFloat((row.querySelector('.pret-qty') || {}).value || 0);
            var costInput = row.querySelector('.pret-cost');
            var cost = costInput ? parseFloat(costInput.value || 0) : parseFloat(row.dataset.cost || 0);
            var qtyEl = row.querySelector('.pret-qty');
            if (qtyEl && qtyEl.max) qtyEl.classList.toggle('is-invalid', qty > parseFloat(qtyEl.max));
            var total = qty * cost;
            var cell = row.querySelector('.pret-line-total');
            if (cell) cell.textContent = total.toFixed(2);
            grand += total;
        });
        var g = document.getElementById('pret-grand');
        if (g) g.textContent = grand.toFixed(2);
        return grand;
    }
    document.addEventListener('input', function (e) {
        if (e.target.classList && (e.target.classList.contains('pret-qty') || e.target.classList.contains('pret-cost'))) recalc();
    });

    // Manual mode (no GRN)
    if (!hasGrn) {
        function gateOk() { return !!branchId() && !!supplierId(); }
        function applyGate() {
            document.getElementById('pret-lines-fieldset').disabled = !gateOk();
            document.getElementById('pret-gate-warn').classList.toggle('d-none', gateOk());
        }
        function addLine(focus) {
            var idx = lineIdx++;
            var html = document.getElementById('pret-line-template').innerHTML.replaceAll('__IDX__', idx);
            var tmp = document.createElement('tbody'); tmp.innerHTML = html.trim();
            var row = tmp.firstElementChild;
            document.getElementById('pret-lines-body').appendChild(row);
            initPicker(row.querySelector('.pret-product-select'));
            if (focus && $) setTimeout(function () { $(row.querySelector('.pret-product-select')).select2('open'); }, 60);
            return row;
        }
        function initPicker(sel) {
            if (!$ || !$.fn.select2) return;
            var $el = $(sel);
            $el.select2({
                width: '100%', placeholder: 'Search product / SKU / barcode…', minimumInputLength: 1,
                ajax: {
                    url: productsUrl, dataType: 'json', delay: 200, cache: false,
                    data: function (params) { return { q: params.term || '', page: params.page || 1, only_active: 1, context: 'inventory', branch_id: branchId() }; },
                    processResults: function (data, params) {
                        params.page = params.page || 1;
                        return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
                    },
                },
            }).on('select2:select', function (e) {
                var row = $el.closest('tr.pret-line')[0];
                var d = e.params.data;
                row.querySelector('.pret-variant-id').value = d.variant_id || '';
                var badge = row.querySelector('.pret-stock-badge');
                badge.textContent = d.stock_label || '0';
                badge.className = 'badge pret-stock-badge ' + ((d.current_stock || 0) > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis');
                var qty = row.querySelector('.pret-qty');
                qty.max = d.current_stock || '';
                qty.step = d.allow_decimal ? '0.001' : '1';
                row.querySelector('.pret-cost').value = Number(d.purchase_price || 0).toFixed(4);
                setTimeout(function () { qty.focus(); qty.select(); }, 60);
                recalc();
            });
        }
        document.getElementById('pret-add-line').addEventListener('click', function () { addLine(true); });
        document.addEventListener('click', function (e) {
            if (e.target.closest('.pret-remove-line')) { e.target.closest('tr.pret-line').remove(); recalc(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.altKey && (e.key === 'n' || e.key === 'N')) { e.preventDefault(); addLine(true); }
        });
        ['branch_id', 'supplier_id'].forEach(function (id) {
            document.getElementById(id).addEventListener('change', function () {
                applyGate();
                if (id === 'branch_id') { document.querySelectorAll('#pret-lines-body tr').forEach(function (r) { r.remove(); }); if (gateOk()) addLine(false); }
            });
        });
        applyGate();
        if (gateOk()) addLine(false);
    }

    // Swal confirm on save (draft only — real warning shows on Post)
    document.getElementById('pret-form').addEventListener('submit', function (e) {
        var totalQty = 0;
        document.querySelectorAll('tr.pret-line .pret-qty').forEach(function (el) { totalQty += parseFloat(el.value || 0); });
        if (totalQty <= 0) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Nothing to return', text: 'Enter a return quantity on at least one line.' });
        }
    });

    recalc();
})();
</script>
@endpush
@endsection
