@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $title }}</h1>
        <p class="fw-medium text-muted mb-0">Custody movement only — official branch stock and accounting are not changed.</p>
    </div>
    <a href="{{ url('/department-stock/transfers') }}" class="btn btn-light">Back</a>
</div>

<div class="card border-primary-subtle mb-3">
    <div class="card-body d-flex flex-wrap align-items-start gap-3 py-2">
        <span class="badge bg-primary-subtle text-primary-emphasis mt-1"><i class="ti ti-building-warehouse me-1"></i>Custody only</span>
        <div class="small">
            <strong>Issue</strong> — branch pool → department · <strong>Return</strong> — department → branch pool ·
            <strong>Transfer</strong> — department → department (same branch).
            <div class="text-muted mt-1">No official branch stock will be changed. Posting updates the department custody sub-ledger only.</div>
        </div>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST"
      action="{{ $transfer ? url('/department-stock/transfers/' . $transfer->id) : url('/department-stock/transfers') }}"
      id="dept-transfer-form" novalidate>
    @csrf
    @if($transfer) @method('PUT') @endif

    {{-- Header --}}
    <div class="card mb-3">
        <div class="card-header"><strong>Document Header</strong> <span class="text-muted small ms-2">choose these first — product lines unlock after</span></div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label for="transfer_type" class="form-label required">Type</label>
                <select id="transfer_type" name="transfer_type" class="form-select" required>
                    <option value="">— Select —</option>
                    <option value="issue"    @selected(old('transfer_type', $transfer?->transfer_type) === 'issue')>Issue to Department</option>
                    <option value="return"   @selected(old('transfer_type', $transfer?->transfer_type) === 'return')>Return from Department</option>
                    <option value="transfer" @selected(old('transfer_type', $transfer?->transfer_type) === 'transfer')>Department to Department</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id', $transfer?->branch_id) == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2" id="wrap-from-dept">
                <label for="from_department_id" class="form-label required">From Department</label>
                <select id="from_department_id" name="from_department_id" class="form-select">
                    <option value="">— Select —</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" data-branch="{{ $dept->branch_id }}"
                                @selected(old('from_department_id', $transfer?->from_department_id) == $dept->id)>{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2" id="wrap-to-dept">
                <label for="to_department_id" class="form-label required">To Department</label>
                <select id="to_department_id" name="to_department_id" class="form-select">
                    <option value="">— Select —</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" data-branch="{{ $dept->branch_id }}"
                                @selected(old('to_department_id', $transfer?->to_department_id) == $dept->id)>{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="transfer_date" class="form-label required">Date</label>
                <input id="transfer_date" type="date" name="transfer_date" class="form-control" required
                       value="{{ old('transfer_date', $transfer?->transfer_date?->format('Y-m-d') ?? now()->toDateString()) }}">
            </div>
            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="1" class="form-control">{{ old('notes', $transfer?->notes) }}</textarea>
            </div>
        </div>
    </div>

    {{-- Lines --}}
    <div class="alert alert-warning py-2 px-3 small" id="dept-gate-warn">
        <i class="ti ti-lock me-1"></i>Select the <strong>Type, Branch, and Department(s)</strong> above to unlock product lines.
    </div>

    <fieldset id="dept-lines-fieldset" disabled>
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <strong>Product Lines</strong>
                <button type="button" class="btn btn-sm btn-outline-primary" id="add-line-btn"><i class="ti ti-plus me-1"></i>Add Product Line</button>
            </div>
            <div class="card-body table-responsive">
                <table class="table align-middle" id="dept-lines-table">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" style="min-width:260px">Product</th>
                            <th scope="col" class="text-end">Branch On Hand</th>
                            <th scope="col" class="text-end">Allocated</th>
                            <th scope="col" class="text-end">Available to Issue</th>
                            <th scope="col" class="text-end">Source Dept Stock</th>
                            <th scope="col" style="width:110px">Qty</th>
                            <th scope="col" style="width:120px">Unit Cost</th>
                            <th scope="col" class="text-end">Line Total</th>
                            <th scope="col" style="width:140px">Notes</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody id="dept-lines-body">
                        @php $oldLines = old('lines', $transfer?->lines?->toArray() ?? []); @endphp
                        @forelse($oldLines as $i => $line)
                            <tr class="dept-line-row">
                                <td>
                                    <select name="lines[{{ $i }}][product_id]" class="form-select dept-product-select" required>
                                        @php
                                            $lp = \App\Models\Tenant\Product::find($line['product_id'] ?? null);
                                        @endphp
                                        @if($lp)
                                            <option value="{{ $lp->id }}" selected>{{ $lp->sku ? $lp->sku . ' — ' : '' }}{{ $lp->name }}</option>
                                        @endif
                                    </select>
                                    <input type="hidden" name="lines[{{ $i }}][product_variant_id]" class="dept-variant-id" value="{{ $line['product_variant_id'] ?? '' }}">
                                    <div class="small text-warning dept-map-warn d-none"><i class="ti ti-alert-triangle me-1"></i>Not mapped to destination department (allowed).</div>
                                </td>
                                <td class="text-end"><span class="badge bg-light text-dark border dept-b-onhand">—</span></td>
                                <td class="text-end"><span class="badge bg-light text-dark border dept-b-alloc">—</span></td>
                                <td class="text-end"><span class="badge bg-light text-dark border dept-b-avail">—</span></td>
                                <td class="text-end"><span class="badge bg-light text-dark border dept-b-src">—</span></td>
                                <td><input type="number" step="0.001" min="0.001" name="lines[{{ $i }}][quantity]" class="form-control form-control-sm text-end dept-qty" value="{{ $line['quantity'] ?? '' }}" required></td>
                                <td><input type="number" step="0.0001" min="0" name="lines[{{ $i }}][unit_cost]" class="form-control form-control-sm text-end dept-cost" value="{{ $line['unit_cost'] ?? '' }}"></td>
                                <td class="text-end dept-line-total">0.00</td>
                                <td><input name="lines[{{ $i }}][notes]" class="form-control form-control-sm" value="{{ $line['notes'] ?? '' }}"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger dept-remove-line">&times;</button></td>
                            </tr>
                        @empty
                        @endforelse
                    </tbody>
                </table>
                <div class="small text-muted">
                    <i class="ti ti-info-circle me-1"></i>Quantity is validated against <strong>Available to Issue</strong> (issue) or <strong>Source Dept Stock</strong> (return/transfer) when posting.
                    Unit cost defaults to the branch average cost when left empty.
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mb-4">
            <button class="btn btn-primary" type="submit">{{ $transfer ? 'Update Draft' : 'Save Draft' }}</button>
            <a href="{{ url('/department-stock/transfers') }}" class="btn btn-light">Cancel</a>
        </div>
    </fieldset>
</form>

{{-- Row template for Add Product Line --}}
<template id="dept-line-template">
    <tr class="dept-line-row">
        <td>
            <select name="lines[__IDX__][product_id]" class="form-select dept-product-select" required></select>
            <input type="hidden" name="lines[__IDX__][product_variant_id]" class="dept-variant-id" value="">
            <div class="small text-warning dept-map-warn d-none"><i class="ti ti-alert-triangle me-1"></i>Not mapped to destination department (allowed).</div>
        </td>
        <td class="text-end"><span class="badge bg-light text-dark border dept-b-onhand">—</span></td>
        <td class="text-end"><span class="badge bg-light text-dark border dept-b-alloc">—</span></td>
        <td class="text-end"><span class="badge bg-light text-dark border dept-b-avail">—</span></td>
        <td class="text-end"><span class="badge bg-light text-dark border dept-b-src">—</span></td>
        <td><input type="number" step="0.001" min="0.001" name="lines[__IDX__][quantity]" class="form-control form-control-sm text-end dept-qty" required></td>
        <td><input type="number" step="0.0001" min="0" name="lines[__IDX__][unit_cost]" class="form-control form-control-sm text-end dept-cost"></td>
        <td class="text-end dept-line-total">0.00</td>
        <td><input name="lines[__IDX__][notes]" class="form-control form-control-sm"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger dept-remove-line">&times;</button></td>
    </tr>
</template>

@push('scripts')
<script>
(function () {
    var $ = window.jQuery;
    var searchUrl = @json(url('/ajax/products'));
    var lineIdx = {{ count($oldLines ?? []) }};

    function el(id) { return document.getElementById(id); }
    function headerVals() {
        return {
            type:   el('transfer_type').value,
            branch: el('branch_id').value,
            from:   el('from_department_id').value,
            to:     el('to_department_id').value,
        };
    }

    // ── Department selects follow the chosen branch ─────────────────────────
    function filterDeptOptions() {
        var branch = el('branch_id').value;
        ['from_department_id', 'to_department_id'].forEach(function (id) {
            var sel = el(id);
            Array.prototype.forEach.call(sel.options, function (opt) {
                if (!opt.value) return;
                var show = !branch || opt.getAttribute('data-branch') === branch;
                opt.hidden = !show;
                if (!show && opt.selected) sel.value = '';
            });
        });
    }

    // ── Type controls which department fields apply ─────────────────────────
    function applyTypeShape() {
        var type = el('transfer_type').value;
        var fromWrap = el('wrap-from-dept'), toWrap = el('wrap-to-dept');
        fromWrap.style.opacity = (type === 'issue') ? 0.4 : 1;
        toWrap.style.opacity   = (type === 'return') ? 0.4 : 1;
        el('from_department_id').disabled = (type === 'issue');
        el('to_department_id').disabled   = (type === 'return');
        if (type === 'issue')  el('from_department_id').value = '';
        if (type === 'return') el('to_department_id').value = '';
    }

    // ── Header-first gate ────────────────────────────────────────────────────
    function gateOk() {
        var h = headerVals();
        if (!h.type || !h.branch) return false;
        if (h.type === 'issue')    return !!h.to;
        if (h.type === 'return')   return !!h.from;
        return !!h.from && !!h.to && h.from !== h.to;
    }
    function applyGate() {
        var ok = gateOk();
        el('dept-lines-fieldset').disabled = !ok;
        el('dept-gate-warn').classList.toggle('d-none', ok);
    }

    // ── Product picker (custody-aware select2) ───────────────────────────────
    function initPicker(selectEl) {
        if (!$ || !$.fn.select2) return;
        var $el = $(selectEl);
        if ($el.hasClass('select2-hidden-accessible')) return;
        $el.select2({
            width: '100%',
            placeholder: 'Search product…',
            minimumInputLength: 1,
            ajax: {
                url: searchUrl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    var h = headerVals();
                    return {
                        q: params.term || '', page: params.page || 1, only_active: 1,
                        context: 'department_stock', branch_id: h.branch,
                        from_department_id: h.from || 0, to_department_id: h.to || 0,
                        transfer_type: h.type,
                    };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
                },
                cache: false,
            },
        }).on('select2:select', function (e) {
            populateRow($el.closest('tr.dept-line-row')[0], e.params.data);
        });
    }

    function fmt(n) { return (n === null || n === undefined) ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 3 }); }

    function populateRow(row, d) {
        if (!row || !d) return;
        row.querySelector('.dept-variant-id').value = d.variant_id || '';
        row.querySelector('.dept-b-onhand').textContent = fmt(d.branch_on_hand);
        row.querySelector('.dept-b-alloc').textContent  = fmt(d.department_allocated);
        var availEl = row.querySelector('.dept-b-avail');
        availEl.textContent = fmt(d.available_to_issue);
        availEl.className = 'badge dept-b-avail ' + ((d.available_to_issue || 0) > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis');
        row.querySelector('.dept-b-src').textContent = fmt(d.source_department_on_hand);
        var cost = row.querySelector('.dept-cost');
        cost.value = Number(d.branch_average_cost || 0).toFixed(4);
        var warn = row.querySelector('.dept-map-warn');
        warn.classList.toggle('d-none', d.destination_department_match !== false);
        // Max-quantity hint for over-issue / over-move (server re-validates on post).
        var h = headerVals();
        var qty = row.querySelector('.dept-qty');
        qty.max = (h.type === 'issue') ? (d.available_to_issue || 0) : (d.source_department_on_hand ?? '');
        recalcRow(row);
    }

    function recalcRow(row) {
        var qty = parseFloat(row.querySelector('.dept-qty').value || 0);
        var cost = parseFloat(row.querySelector('.dept-cost').value || 0);
        row.querySelector('.dept-line-total').textContent = (qty * cost).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Refresh custody figures for already-selected rows when header changes.
    function refreshRows() {
        var h = headerVals();
        if (!h.branch) return;
        document.querySelectorAll('#dept-lines-body .dept-line-row').forEach(function (row) {
            var ps = row.querySelector('.dept-product-select');
            if (!ps || !ps.value) return;
            fetch(searchUrl + '?context=department_stock&product_id=' + encodeURIComponent(ps.value)
                    + '&branch_id=' + encodeURIComponent(h.branch)
                    + '&from_department_id=' + encodeURIComponent(h.from || 0)
                    + '&to_department_id=' + encodeURIComponent(h.to || 0),
                { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if ((d.results || [])[0]) populateRow(row, d.results[0]); })
                .catch(function () {});
        });
    }

    // ── Wiring ───────────────────────────────────────────────────────────────
    el('add-line-btn').addEventListener('click', function () {
        var tpl = el('dept-line-template').innerHTML.replaceAll('__IDX__', lineIdx++);
        var tbody = el('dept-lines-body');
        var tmp = document.createElement('tbody');
        tmp.innerHTML = tpl.trim();
        var row = tmp.firstElementChild;
        tbody.appendChild(row);
        initPicker(row.querySelector('.dept-product-select'));
    });

    document.addEventListener('click', function (e) {
        if (e.target.closest('.dept-remove-line')) {
            e.target.closest('tr.dept-line-row').remove();
        }
    });
    document.addEventListener('input', function (e) {
        if (e.target.classList.contains('dept-qty') || e.target.classList.contains('dept-cost')) {
            recalcRow(e.target.closest('tr.dept-line-row'));
        }
    });

    ['transfer_type', 'branch_id', 'from_department_id', 'to_department_id'].forEach(function (id) {
        el(id).addEventListener('change', function () {
            if (id === 'transfer_type') applyTypeShape();
            if (id === 'branch_id') filterDeptOptions();
            applyGate();
            refreshRows();
        });
    });

    // Init existing rows (edit / validation-return) + initial state.
    document.querySelectorAll('#dept-lines-body .dept-product-select').forEach(initPicker);
    applyTypeShape();
    filterDeptOptions();
    applyGate();
    refreshRows();

    // Start with one empty line on a fresh form.
    if (document.querySelectorAll('#dept-lines-body .dept-line-row').length === 0) {
        el('add-line-btn').click();
    }
})();
</script>
@endpush
@endsection
