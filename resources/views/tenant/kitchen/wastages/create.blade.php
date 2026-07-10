@extends('layouts.app')

@section('title', 'Record Wastage')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Record Wastage</h1>
        <p class="fw-medium text-muted mb-0">Spoilage, spillage, prep loss — deducts official branch stock (FEFO) and posts wastage COGS.</p>
    </div>
    <a href="{{ url('/kitchen/wastages') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/kitchen/wastages') }}" novalidate id="wst-form">
    @csrf

    <div class="card mb-3">
        <div class="card-header"><strong>Wastage Header</strong> <span class="text-muted small ms-2">choose branch first — product lines unlock after</span></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id" tabindex="1"
                        class="form-select @error('branch_id') is-invalid @enderror" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-3">
                <label for="wastage_date" class="form-label required">Wastage Date</label>
                <input id="wastage_date" type="date" name="wastage_date" tabindex="2" required
                       value="{{ old('wastage_date', now()->toDateString()) }}"
                       class="form-control @error('wastage_date') is-invalid @enderror">
                @error('wastage_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="alert alert-warning py-2 px-3 small" id="wst-gate-warn">
        <i class="ti ti-lock me-1"></i>Select the <strong>Branch</strong> above to unlock product lines.
    </div>

    <fieldset id="wst-lines-fieldset" disabled>
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <strong>Wasted Products</strong>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light text-muted border d-none d-md-inline">Alt+N = add line · Enter in Qty = next line</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="wst-add-line"><i class="ti ti-plus me-1"></i>Add Line</button>
                </div>
            </div>
            <div class="card-body pb-0">
                <div class="border rounded p-2 mb-3 bg-light">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="ti ti-barcode"></i></span>
                        <input type="text" id="wst-scanner" class="form-control"
                               placeholder="Scan barcode or type SKU then press Enter" autocomplete="off">
                        <button class="btn btn-outline-primary" type="button" id="wst-scan-btn">Add</button>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <caption class="visually-hidden">Wastage product lines</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="min-width:260px">Product</th>
                                <th scope="col" style="min-width:120px">Variant</th>
                                <th scope="col" class="text-end">Current Stock</th>
                                <th scope="col" style="width:110px">Waste Qty</th>
                                <th scope="col" class="text-end">After</th>
                                <th scope="col" style="min-width:180px">Reason</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody id="wst-lines-body"></tbody>
                    </table>
                </div>
                <div class="px-3 py-2 small text-muted border-top">
                    <i class="ti ti-info-circle me-1"></i>Posting deducts official branch stock immediately (oldest-expiry batches first) and records the wastage cost.
                </div>
            </div>
        </div>

        @error('wastage') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror

        <div class="d-flex gap-2 mb-4">
            <button type="submit" class="btn btn-primary">Record Wastage</button>
            <a href="{{ url('/kitchen/wastages') }}" class="btn btn-light">Cancel</a>
        </div>
    </fieldset>
</form>

<datalist id="wst-reasons">
    <option value="Spoilage"></option>
    <option value="Spillage"></option>
    <option value="Prep loss"></option>
    <option value="Over-production"></option>
    <option value="Expired"></option>
    <option value="Burnt / cooking error"></option>
    <option value="Damaged packaging"></option>
</datalist>

<template id="wst-line-template">
    <tr class="wst-line-row" data-stock="0">
        <td>
            <select name="lines[__IDX__][product_id]" class="form-select form-select-sm wst-product-select"></select>
            <div class="small text-muted wst-info mt-1"></div>
        </td>
        <td>
            <select name="lines[__IDX__][product_variant_id]" class="form-select form-select-sm wst-variant-select">
                <option value="">Default</option>
            </select>
        </td>
        <td class="text-end"><span class="badge bg-light text-dark border wst-stock-badge">—</span></td>
        <td><input type="number" name="lines[__IDX__][quantity]" step="0.001" min="0.001" class="form-control form-control-sm text-end wst-qty"></td>
        <td class="text-end"><span class="badge bg-light text-dark border wst-after-badge">—</span></td>
        <td><input type="text" name="lines[__IDX__][reason]" maxlength="255" list="wst-reasons"
                   class="form-control form-control-sm wst-reason" placeholder="e.g. spoilage, prep loss"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger wst-remove-line" aria-label="Remove line">&times;</button></td>
    </tr>
</template>

@push('scripts')
<script>
(function () {
    var $ = window.jQuery;
    var searchUrl = @json(url('/ajax/products'));
    var lineIdx = 0;

    function toast(msg, type) {
        if (window.Swal) { Swal.fire({ toast: true, position: 'top-end', timer: 2400, showConfirmButton: false, icon: type || 'info', title: msg }); }
    }
    function branchId() { return document.getElementById('branch_id').value; }

    function applyGate() {
        var ok = !!branchId();
        document.getElementById('wst-lines-fieldset').disabled = !ok;
        document.getElementById('wst-gate-warn').classList.toggle('d-none', ok);
    }

    function addLine(focusPicker) {
        var idx = lineIdx++;
        var html = document.getElementById('wst-line-template').innerHTML.replaceAll('__IDX__', idx);
        var tmp = document.createElement('tbody'); tmp.innerHTML = html.trim();
        var row = tmp.firstElementChild;
        document.getElementById('wst-lines-body').appendChild(row);
        initPicker(row.querySelector('.wst-product-select'));
        if (focusPicker && $) { setTimeout(function () { $(row.querySelector('.wst-product-select')).select2('open'); }, 60); }
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
            populateRow($el.closest('tr.wst-line-row')[0], e.params.data);
        });
    }

    function populateRow(row, d) {
        if (!row || !d) return;
        row.dataset.stock = d.current_stock || 0;
        row.dataset.unit = d.unit_code || '';

        var variant = row.querySelector('.wst-variant-select');
        variant.innerHTML = '';
        (d.variants || []).forEach(function (v) {
            var o = document.createElement('option');
            o.value = v.id; o.textContent = v.is_default ? 'Default' : v.name;
            if (v.is_default) o.selected = true;
            variant.appendChild(o);
        });
        if (!variant.options.length) variant.innerHTML = '<option value="">Default</option>';

        var badge = row.querySelector('.wst-stock-badge');
        badge.textContent = d.stock_label || '0';
        badge.className = 'badge wst-stock-badge ' + ((d.current_stock || 0) > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis');
        badge.title = (d.sku ? 'SKU ' + d.sku + ' · ' : '') + 'Unit ' + (d.unit_code || '-') + ' · Avg cost ' + Number(d.purchase_price || 0).toFixed(2)
            + ((d.batches || []).length ? ' · ' + d.batches.length + ' batch(es)' : '');

        row.querySelector('.wst-info').textContent = (d.sku || '') + (d.unit_code ? ' · ' + d.unit_code : '') + ' · avg cost ' + Number(d.purchase_price || 0).toFixed(2);

        var qty = row.querySelector('.wst-qty');
        qty.step = d.allow_decimal ? '0.001' : '1';
        qty.max = d.current_stock || '';

        recalcAfter(row);
        if ((d.current_stock || 0) <= 0) toast('No stock to waste for ' + d.name, 'warning');
        setTimeout(function () { qty.focus(); qty.select(); }, 60);
    }

    function recalcAfter(row) {
        var stock = parseFloat(row.dataset.stock || 0);
        var qty = parseFloat(row.querySelector('.wst-qty').value || 0);
        var after = stock - qty;
        var badge = row.querySelector('.wst-after-badge');
        badge.textContent = (Math.round(after * 1000) / 1000) + (row.dataset.unit ? ' ' + row.dataset.unit : '');
        badge.className = 'badge wst-after-badge ' + (after < 0 ? 'bg-danger text-white' : (after === 0 ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-dark border'));
    }

    function scan(code) {
        if (!branchId()) { toast('Select branch before scanning', 'warning'); return; }
        fetch(searchUrl + '?context=inventory&only_active=1&branch_id=' + encodeURIComponent(branchId()) + '&q=' + encodeURIComponent(code),
            { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var hit = (data.results || [])[0];
                if (!hit) { toast('No product found for "' + code + '"', 'warning'); return; }
                var row = addLine(false);
                if ($) { $(row.querySelector('.wst-product-select')).append(new Option(hit.text, hit.id, true, true)).trigger('change'); }
                populateRow(row, hit);
            })
            .catch(function () { toast('Lookup failed', 'error'); });
    }
    document.getElementById('wst-scan-btn').addEventListener('click', function () {
        var el = document.getElementById('wst-scanner');
        if (el.value.trim()) { scan(el.value.trim()); el.value = ''; }
        el.focus();
    });
    document.getElementById('wst-scanner').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); document.getElementById('wst-scan-btn').click(); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.altKey && (e.key === 'n' || e.key === 'N')) { e.preventDefault(); addLine(true); }
        if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('wst-qty')) {
            e.preventDefault();
            e.target.closest('tr.wst-line-row').querySelector('.wst-reason').focus();
        }
        if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('wst-reason')) {
            e.preventDefault();
            var row = e.target.closest('tr.wst-line-row');
            var next = row.nextElementSibling;
            if (next) { var p = next.querySelector('.wst-product-select'); if ($ && p) $(p).select2('open'); }
            else { addLine(true); }
        }
    });
    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('wst-qty')) {
            recalcAfter(e.target.closest('tr.wst-line-row'));
        }
    });

    document.getElementById('wst-add-line').addEventListener('click', function () { addLine(true); });
    document.addEventListener('click', function (e) {
        if (e.target.closest('.wst-remove-line')) e.target.closest('tr.wst-line-row').remove();
    });

    document.getElementById('branch_id').addEventListener('change', function () {
        applyGate();
        document.querySelectorAll('#wst-lines-body tr').forEach(function (r) { r.remove(); });
        if (branchId()) addLine(false);
    });

    document.getElementById('wst-form').addEventListener('submit', function (e) {
        if (this.dataset.confirmed) return;
        e.preventDefault();
        var form = this;
        var over = Array.prototype.some.call(document.querySelectorAll('#wst-lines-body tr.wst-line-row'), function (row) {
            return parseFloat(row.querySelector('.wst-qty').value || 0) > parseFloat(row.dataset.stock || 0);
        });
        Swal.fire({
            title: 'Record wastage?',
            html: 'Official branch stock will be <strong>deducted immediately</strong>.' + (over ? '<br><span class="text-danger">One or more lines exceed current stock — posting will fail on those.</span>' : ''),
            icon: over ? 'warning' : 'question', showCancelButton: true, confirmButtonText: 'Record Wastage',
        }).then(function (res) { if (res.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); } });
    });

    applyGate();
    if (branchId()) addLine(false);
})();
</script>
@endpush
@endsection
