@extends('layouts.app')

@section('title', 'New Stock Adjustment')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">New Stock Adjustment</h1>
        <p class="fw-medium text-muted mb-0">Opening / increase / decrease / wastage — posts directly to official branch stock.</p>
    </div>
    <a href="{{ url('/stock-adjustments') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/stock-adjustments') }}" novalidate id="adj-form">
    @csrf

    <div class="card mb-3">
        <div class="card-header"><strong>Adjustment Header</strong> <span class="text-muted small ms-2">choose these first — product lines unlock after</span></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="branch_id" class="form-label required">Branch</label>
                    <select id="branch_id" name="branch_id" tabindex="1"
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
                    <select id="adjustment_type" name="adjustment_type" tabindex="2"
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
                    <input id="adjustment_date" type="date" name="adjustment_date" tabindex="3"
                           value="{{ old('adjustment_date', now()->toDateString()) }}"
                           class="form-control @error('adjustment_date') is-invalid @enderror" required>
                    @error('adjustment_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <label for="notes" class="form-label">Notes</label>
                    <textarea id="notes" name="notes" rows="1" tabindex="4"
                              class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                    @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-warning py-2 px-3 small" id="adj-gate-warn">
        <i class="ti ti-lock me-1"></i>Select the <strong>Branch</strong> above to unlock product lines.
    </div>

    <fieldset id="adj-lines-fieldset" disabled>
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <strong>Product Lines</strong>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light text-muted border d-none d-md-inline">Alt+N = add line · Enter in Qty = next line</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="adj-add-line">
                        <i class="ti ti-plus me-1"></i>Add Line
                    </button>
                </div>
            </div>
            <div class="card-body pb-0">
                <div class="border rounded p-2 mb-3 bg-light">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="ti ti-barcode"></i></span>
                        <input type="text" id="adj-scanner" class="form-control"
                               placeholder="Scan barcode or type SKU then press Enter" autocomplete="off">
                        <button class="btn btn-outline-primary" type="button" id="adj-scan-btn">Add</button>
                    </div>
                    <div class="form-text">Scan to add a line instantly, or use the product search on each row.</div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="adj-lines-table">
                        <caption class="visually-hidden">Adjustment product lines</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="min-width:240px">Product</th>
                                <th scope="col" style="min-width:120px">Variant</th>
                                <th scope="col" class="text-end">Current Stock</th>
                                <th scope="col" style="min-width:150px">Batch</th>
                                <th scope="col" style="width:120px">Expiry</th>
                                <th scope="col" style="width:100px">Qty</th>
                                <th scope="col" style="width:110px">Unit Cost</th>
                                <th scope="col" style="min-width:110px">Notes</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody id="adj-lines-body"></tbody>
                    </table>
                </div>
                <div class="px-3 py-2 small text-muted border-top">
                    <i class="ti ti-info-circle me-1"></i><span id="adj-effect-note">Increase/Opening add stock (new batch allowed); Decrease/Wastage remove stock FEFO from existing batches.</span>
                </div>
            </div>
        </div>

        @error('lines') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror
        @error('stock') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror

        <div class="d-flex gap-2 mb-4">
            <button type="submit" class="btn btn-primary" id="adj-submit">Post Adjustment</button>
            <a href="{{ url('/stock-adjustments') }}" class="btn btn-light">Cancel</a>
        </div>
    </fieldset>
</form>

{{-- Row template --}}
<template id="adj-line-template">
    <tr class="adj-line-row">
        <td>
            <select name="lines[__IDX__][product_id]" class="form-select form-select-sm adj-product-select"></select>
        </td>
        <td>
            <select name="lines[__IDX__][product_variant_id]" class="form-select form-select-sm adj-variant-select">
                <option value="">Default</option>
            </select>
        </td>
        <td class="text-end"><span class="badge bg-light text-dark border adj-stock-badge">—</span></td>
        <td>
            <select class="form-select form-select-sm adj-batch-select">
                <option value="">— No batch —</option>
            </select>
            <input type="text" name="lines[__IDX__][batch_no]" maxlength="100"
                   class="form-control form-control-sm mt-1 adj-batch-new d-none" placeholder="New batch no">
        </td>
        <td><input type="date" name="lines[__IDX__][expiry_date]" class="form-control form-control-sm adj-expiry"></td>
        <td><input type="number" name="lines[__IDX__][quantity]" step="0.001" min="0.001"
                   class="form-control form-control-sm text-end adj-qty"></td>
        <td><input type="number" name="lines[__IDX__][unit_cost]" step="0.0001" min="0" value="0"
                   class="form-control form-control-sm text-end adj-cost"></td>
        <td><input type="text" name="lines[__IDX__][notes]" class="form-control form-control-sm adj-notes"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger adj-remove-line" aria-label="Remove line">&times;</button></td>
    </tr>
</template>

@push('scripts')
<script>
(function () {
    var $ = window.jQuery;
    var searchUrl = @json(url('/ajax/products'));
    var lookupUrl = @json(url('/api/catalog/barcode/lookup'));
    var lineIdx = 0;
    var rowData = {}; // idx -> last picked product payload (batches etc.)

    function toast(msg, type) {
        if (window.Swal) { Swal.fire({ toast: true, position: 'top-end', timer: 2400, showConfirmButton: false, icon: type || 'info', title: msg }); }
    }
    function branchId() { return document.getElementById('branch_id').value; }
    function adjType() { return document.getElementById('adjustment_type').value; }
    function isOutType() { return adjType() === 'decrease' || adjType() === 'wastage'; }

    function applyGate() {
        var ok = !!branchId();
        document.getElementById('adj-lines-fieldset').disabled = !ok;
        document.getElementById('adj-gate-warn').classList.toggle('d-none', ok);
    }

    // ── Row management ───────────────────────────────────────────────────
    function rowIdx(row) { var s = row.querySelector('.adj-product-select'); var m = s ? s.name.match(/lines\[(\d+)\]/) : null; return m ? Number(m[1]) : null; }

    function addLine(focusPicker) {
        var idx = lineIdx++;
        var html = document.getElementById('adj-line-template').innerHTML.replaceAll('__IDX__', idx);
        var tmp = document.createElement('tbody'); tmp.innerHTML = html.trim();
        var row = tmp.firstElementChild;
        document.getElementById('adj-lines-body').appendChild(row);
        initPicker(row.querySelector('.adj-product-select'));
        if (focusPicker && $) { setTimeout(function () { $(row.querySelector('.adj-product-select')).select2('open'); }, 60); }
        return row;
    }

    function initPicker(sel) {
        if (!$ || !$.fn.select2) return;
        var $el = $(sel);
        if ($el.hasClass('select2-hidden-accessible')) return;
        $el.select2({
            width: '100%', placeholder: 'Search product / SKU / barcode…', minimumInputLength: 1,
            ajax: {
                url: searchUrl, dataType: 'json', delay: 200, cache: false,
                data: function (params) {
                    return { q: params.term || '', page: params.page || 1, only_active: 1, context: 'inventory', branch_id: branchId() };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
                },
            },
        }).on('select2:select', function (e) {
            populateRow($el.closest('tr.adj-line-row')[0], e.params.data);
        });
    }

    function populateRow(row, d) {
        if (!row || !d) return;
        var idx = rowIdx(row);
        rowData[idx] = d;

        // variant options (default first)
        var variant = row.querySelector('.adj-variant-select');
        variant.innerHTML = '';
        (d.variants || []).forEach(function (v) {
            var o = document.createElement('option');
            o.value = v.id; o.textContent = v.is_default ? 'Default' : v.name;
            if (v.is_default) o.selected = true;
            variant.appendChild(o);
        });
        if (!variant.options.length) variant.innerHTML = '<option value="">Default</option>';

        // current stock badge
        var badge = row.querySelector('.adj-stock-badge');
        badge.textContent = d.stock_label || '0';
        badge.className = 'badge adj-stock-badge ' + ((d.current_stock || 0) > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis');

        // batch dropdown from SAVED batches
        rebuildBatchOptions(row, d);

        // qty + cost defaults
        var qty = row.querySelector('.adj-qty');
        qty.step = d.allow_decimal ? '0.001' : '1';
        row.querySelector('.adj-cost').value = Number(d.purchase_price || 0).toFixed(4);

        toast((d.sku ? d.sku + ' — ' : '') + d.name + ' added', 'success');
        setTimeout(function () { qty.focus(); qty.select(); }, 60);
    }

    function rebuildBatchOptions(row, d) {
        var sel = row.querySelector('.adj-batch-select');
        var manual = row.querySelector('.adj-batch-new');
        var expiry = row.querySelector('.adj-expiry');
        var batches = (d && d.batches) || [];
        var outType = isOutType();

        sel.innerHTML = '';
        var optNone = document.createElement('option');
        optNone.value = ''; optNone.textContent = outType ? 'Auto (FEFO — oldest expiry first)' : '— No batch —';
        sel.appendChild(optNone);

        batches.forEach(function (b) {
            var o = document.createElement('option');
            o.value = b.batch_no || '';
            o.textContent = (b.batch_no || 'batch') + ' · qty ' + b.qty + (b.expiry ? ' · exp ' + b.expiry : '');
            o.dataset.expiry = b.expiry || '';
            sel.appendChild(o);
        });

        if (!outType) {
            var optNew = document.createElement('option');
            optNew.value = '__new__'; optNew.textContent = '➕ New batch…';
            sel.appendChild(optNew);
        }

        manual.classList.add('d-none'); manual.value = '';
        expiry.readOnly = false;

        sel.onchange = function () {
            if (sel.value === '__new__') {
                manual.classList.remove('d-none'); manual.focus();
                expiry.value = ''; expiry.readOnly = false;
            } else {
                manual.classList.add('d-none'); manual.value = sel.value;
                var opt = sel.options[sel.selectedIndex];
                expiry.value = (opt && opt.dataset.expiry) || '';
                expiry.readOnly = !!sel.value; // existing batch keeps its expiry
            }
        };
        sel.onchange();
    }

    // ── Scanner ──────────────────────────────────────────────────────────
    function csrf() { var i = document.querySelector('input[name="_token"]'); return i ? i.value : ''; }
    function scan(code) {
        if (!branchId()) { toast('Select branch before scanning', 'warning'); return; }
        fetch(searchUrl + '?context=inventory&only_active=1&branch_id=' + encodeURIComponent(branchId()) + '&q=' + encodeURIComponent(code),
            { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var hit = (data.results || [])[0];
                if (!hit) { toast('No product found for "' + code + '"', 'warning'); return; }
                var row = addLine(false);
                if ($) {
                    var $sel = $(row.querySelector('.adj-product-select'));
                    var opt = new Option(hit.text, hit.id, true, true);
                    $sel.append(opt).trigger('change');
                }
                populateRow(row, hit);
            })
            .catch(function () { toast('Lookup failed', 'error'); });
    }
    document.getElementById('adj-scan-btn').addEventListener('click', function () {
        var el = document.getElementById('adj-scanner');
        if (el.value.trim()) { scan(el.value.trim()); el.value = ''; }
        el.focus();
    });
    document.getElementById('adj-scanner').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); document.getElementById('adj-scan-btn').click(); }
    });

    // ── Keyboard: Alt+N add line, Enter in qty → next line's picker ─────
    document.addEventListener('keydown', function (e) {
        if (e.altKey && (e.key === 'n' || e.key === 'N')) { e.preventDefault(); addLine(true); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('adj-qty')) {
            e.preventDefault();
            var row = e.target.closest('tr.adj-line-row');
            var next = row.nextElementSibling;
            if (next) { var p = next.querySelector('.adj-product-select'); if ($ && p) $(p).select2('open'); }
            else { addLine(true); }
        }
    });

    document.getElementById('adj-add-line').addEventListener('click', function () { addLine(true); });
    document.addEventListener('click', function (e) {
        if (e.target.closest('.adj-remove-line')) e.target.closest('tr.adj-line-row').remove();
    });

    // header changes
    document.getElementById('branch_id').addEventListener('change', function () {
        applyGate();
        // stock badges + batches belong to the old branch — reset rows
        document.querySelectorAll('#adj-lines-body tr').forEach(function (r) { r.remove(); });
        rowData = {};
        if (branchId()) addLine(false);
    });
    document.getElementById('adjustment_type').addEventListener('change', function () {
        document.querySelectorAll('#adj-lines-body tr.adj-line-row').forEach(function (row) {
            rebuildBatchOptions(row, rowData[rowIdx(row)] || null);
        });
    });

    // swal confirm on post
    document.getElementById('adj-form').addEventListener('submit', function (e) {
        if (this.dataset.confirmed) return;
        e.preventDefault();
        var form = this;
        var typeLabel = document.getElementById('adjustment_type').selectedOptions[0].textContent;
        Swal.fire({
            title: 'Post ' + typeLabel + '?',
            text: 'This posts directly to official branch stock.',
            icon: 'question', showCancelButton: true, confirmButtonText: 'Post Adjustment',
        }).then(function (res) {
            if (res.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); }
        });
    });

    applyGate();
    if (branchId()) addLine(false);
})();
</script>
@endpush
@endsection
