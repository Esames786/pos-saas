@extends('layouts.app')

@section('title', 'New Stock Transfer')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">New Stock Transfer</h1>
        <p class="fw-medium text-muted mb-0">Move official stock between branches — batches follow automatically (FEFO).</p>
    </div>
    <a href="{{ url('/stock-transfers') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/stock-transfers') }}" novalidate id="trf-form">
    @csrf

    <div class="card mb-3">
        <div class="card-header"><strong>Transfer Header</strong> <span class="text-muted small ms-2">choose branches first — product lines unlock after</span></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="from_branch_id" class="form-label required">From Branch</label>
                <select id="from_branch_id" name="from_branch_id" tabindex="1"
                        class="form-select @error('from_branch_id') is-invalid @enderror" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('from_branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('from_branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
                <label for="to_branch_id" class="form-label required">To Branch</label>
                <select id="to_branch_id" name="to_branch_id" tabindex="2"
                        class="form-select @error('to_branch_id') is-invalid @enderror" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('to_branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('to_branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
                <label for="transfer_date" class="form-label required">Date</label>
                <input id="transfer_date" type="date" name="transfer_date" tabindex="3" required
                       value="{{ old('transfer_date', now()->toDateString()) }}"
                       class="form-control @error('transfer_date') is-invalid @enderror">
                @error('transfer_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="1" tabindex="4" class="form-control">{{ old('notes') }}</textarea>
            </div>
        </div>
    </div>

    <div class="alert alert-warning py-2 px-3 small" id="trf-gate-warn">
        <i class="ti ti-lock me-1"></i>Select <strong>different From and To branches</strong> above to unlock product lines.
    </div>

    <fieldset id="trf-lines-fieldset" disabled>
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <strong>Product Lines</strong>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light text-muted border d-none d-md-inline">Alt+N = add line · Enter in Qty = next line</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="trf-add-line"><i class="ti ti-plus me-1"></i>Add Line</button>
                </div>
            </div>
            <div class="card-body pb-0">
                <div class="border rounded p-2 mb-3 bg-light">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="ti ti-barcode"></i></span>
                        <input type="text" id="trf-scanner" class="form-control"
                               placeholder="Scan barcode or type SKU then press Enter" autocomplete="off">
                        <button class="btn btn-outline-primary" type="button" id="trf-scan-btn">Add</button>
                    </div>
                    <div class="form-text">Lookup uses the source branch stock.</div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <caption class="visually-hidden">Transfer product lines</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="min-width:260px">Product</th>
                                <th scope="col" style="min-width:130px">Variant</th>
                                <th scope="col" class="text-end">Source Stock</th>
                                <th scope="col" style="width:110px">Qty</th>
                                <th scope="col" style="width:120px">Unit Cost</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody id="trf-lines-body"></tbody>
                    </table>
                </div>
                <div class="px-3 py-2 small text-muted border-top">
                    <i class="ti ti-info-circle me-1"></i>Batches move automatically FEFO (oldest expiry first) — batch numbers and expiry follow the stock into the destination branch.
                </div>
            </div>
        </div>

        @error('lines') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror

        <div class="d-flex gap-2 mb-4">
            <button type="submit" class="btn btn-primary">Post Transfer</button>
            <a href="{{ url('/stock-transfers') }}" class="btn btn-light">Cancel</a>
        </div>
    </fieldset>
</form>

<template id="trf-line-template">
    <tr class="trf-line-row">
        <td><select name="lines[__IDX__][product_id]" class="form-select form-select-sm trf-product-select"></select></td>
        <td>
            <select name="lines[__IDX__][product_variant_id]" class="form-select form-select-sm trf-variant-select">
                <option value="">Default</option>
            </select>
        </td>
        <td class="text-end"><span class="badge bg-light text-dark border trf-stock-badge">—</span></td>
        <td><input type="number" name="lines[__IDX__][quantity]" step="0.001" min="0.001" class="form-control form-control-sm text-end trf-qty"></td>
        <td><input type="number" name="lines[__IDX__][unit_cost]" step="0.0001" min="0" value="0" class="form-control form-control-sm text-end trf-cost"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger trf-remove-line" aria-label="Remove line">&times;</button></td>
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
    function fromBranch() { return document.getElementById('from_branch_id').value; }
    function toBranch() { return document.getElementById('to_branch_id').value; }
    function gateOk() { return fromBranch() && toBranch() && fromBranch() !== toBranch(); }

    function applyGate() {
        document.getElementById('trf-lines-fieldset').disabled = !gateOk();
        document.getElementById('trf-gate-warn').classList.toggle('d-none', gateOk());
    }

    function addLine(focusPicker) {
        var idx = lineIdx++;
        var html = document.getElementById('trf-line-template').innerHTML.replaceAll('__IDX__', idx);
        var tmp = document.createElement('tbody'); tmp.innerHTML = html.trim();
        var row = tmp.firstElementChild;
        document.getElementById('trf-lines-body').appendChild(row);
        initPicker(row.querySelector('.trf-product-select'));
        if (focusPicker && $) { setTimeout(function () { $(row.querySelector('.trf-product-select')).select2('open'); }, 60); }
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
                    return { q: params.term || '', page: params.page || 1, only_active: 1, context: 'inventory', branch_id: fromBranch() };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
                },
            },
        }).on('select2:select', function (e) {
            populateRow($el.closest('tr.trf-line-row')[0], e.params.data);
        });
    }

    function populateRow(row, d) {
        if (!row || !d) return;
        var variant = row.querySelector('.trf-variant-select');
        variant.innerHTML = '';
        (d.variants || []).forEach(function (v) {
            var o = document.createElement('option');
            o.value = v.id; o.textContent = v.is_default ? 'Default' : v.name;
            if (v.is_default) o.selected = true;
            variant.appendChild(o);
        });
        if (!variant.options.length) variant.innerHTML = '<option value="">Default</option>';

        var badge = row.querySelector('.trf-stock-badge');
        badge.textContent = d.stock_label || '0';
        badge.className = 'badge trf-stock-badge ' + ((d.current_stock || 0) > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis');

        var qty = row.querySelector('.trf-qty');
        qty.step = d.allow_decimal ? '0.001' : '1';
        qty.max = d.current_stock || '';
        row.querySelector('.trf-cost').value = Number(d.purchase_price || 0).toFixed(4);

        if ((d.current_stock || 0) <= 0) toast('No source-branch stock for ' + d.name, 'warning');
        setTimeout(function () { qty.focus(); qty.select(); }, 60);
    }

    function scan(code) {
        if (!gateOk()) { toast('Select both branches before scanning', 'warning'); return; }
        fetch(searchUrl + '?context=inventory&only_active=1&branch_id=' + encodeURIComponent(fromBranch()) + '&q=' + encodeURIComponent(code),
            { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var hit = (data.results || [])[0];
                if (!hit) { toast('No product found for "' + code + '"', 'warning'); return; }
                var row = addLine(false);
                if ($) { $(row.querySelector('.trf-product-select')).append(new Option(hit.text, hit.id, true, true)).trigger('change'); }
                populateRow(row, hit);
            })
            .catch(function () { toast('Lookup failed', 'error'); });
    }
    document.getElementById('trf-scan-btn').addEventListener('click', function () {
        var el = document.getElementById('trf-scanner');
        if (el.value.trim()) { scan(el.value.trim()); el.value = ''; }
        el.focus();
    });
    document.getElementById('trf-scanner').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); document.getElementById('trf-scan-btn').click(); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.altKey && (e.key === 'n' || e.key === 'N')) { e.preventDefault(); addLine(true); }
        if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('trf-qty')) {
            e.preventDefault();
            var row = e.target.closest('tr.trf-line-row');
            var next = row.nextElementSibling;
            if (next) { var p = next.querySelector('.trf-product-select'); if ($ && p) $(p).select2('open'); }
            else { addLine(true); }
        }
    });

    document.getElementById('trf-add-line').addEventListener('click', function () { addLine(true); });
    document.addEventListener('click', function (e) {
        if (e.target.closest('.trf-remove-line')) e.target.closest('tr.trf-line-row').remove();
    });

    ['from_branch_id', 'to_branch_id'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', function () {
            applyGate();
            if (id === 'from_branch_id') {
                document.querySelectorAll('#trf-lines-body tr').forEach(function (r) { r.remove(); });
                if (gateOk()) addLine(false);
            }
        });
    });

    document.getElementById('trf-form').addEventListener('submit', function (e) {
        if (this.dataset.confirmed) return;
        e.preventDefault();
        var form = this;
        Swal.fire({
            title: 'Post this transfer?',
            text: 'Stock moves out of the source branch immediately (FEFO batches).',
            icon: 'question', showCancelButton: true, confirmButtonText: 'Post Transfer',
        }).then(function (res) { if (res.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); } });
    });

    applyGate();
    if (gateOk()) addLine(false);
})();
</script>
@endpush
@endsection
