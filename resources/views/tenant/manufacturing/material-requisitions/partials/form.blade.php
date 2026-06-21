{{-- Shared Material Requisition form partial (create + edit) --}}
@php
    $componentOptions = $componentOptions ?? [];
    $prefill          = $prefill ?? [];
    $productAjaxUrl    = url('/ajax/products');
    $orderAjaxUrl      = url('/ajax/production-orders');
    $customerAjaxUrl   = url('/ajax/manufacturing-customers');

    // Normalise line rows from: submitted (validation redirect) -> existing (edit)
    // -> BOM prefill (generate-from-order) -> none.
    if (old('lines')) {
        $rows = collect(old('lines'))->map(fn ($l) => [
            'component_product_id' => $l['component_product_id'] ?? null,
            'unit_id'              => $l['unit_id'] ?? null,
            'required_quantity'    => $l['required_quantity'] ?? '',
            'issued_quantity'      => $l['issued_quantity'] ?? 0,
            'wastage_percent'      => $l['wastage_percent'] ?? 0,
            'source_bom_line_id'   => $l['source_bom_line_id'] ?? null,
            'notes'                => $l['notes'] ?? null,
            '_text'                => $componentOptions[$l['component_product_id'] ?? 0] ?? null,
        ]);
    } elseif ($requisition && $requisition->lines->count()) {
        $rows = $requisition->lines->map(fn ($l) => [
            'component_product_id' => $l->component_product_id,
            'unit_id'              => $l->unit_id,
            'required_quantity'    => $l->required_quantity,
            'issued_quantity'      => $l->issued_quantity,
            'wastage_percent'      => $l->wastage_percent,
            'source_bom_line_id'   => $l->source_bom_line_id,
            'notes'                => $l->notes,
            '_text'                => $componentOptions[$l->component_product_id] ?? ($l->componentProduct ? ($l->componentProduct->sku . ' — ' . $l->componentProduct->name) : null),
        ]);
    } else {
        $rows = collect($prefillLines ?? [])->map(fn ($l) => array_merge($l, [
            '_text' => $componentOptions[$l['component_product_id'] ?? 0] ?? null,
        ]));
    }
    $rows = $rows->values();
    $lineCount = $rows->count() ?: 1;
@endphp

<form method="POST"
      action="{{ $requisition ? url('/manufacturing/material-requisitions/' . $requisition->id) : url('/manufacturing/material-requisitions') }}"
      novalidate>
    @csrf
    @if($requisition) @method('PUT') @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    @if(!empty($bomWarning))
        <div class="alert alert-warning"><i class="ti ti-alert-triangle me-1"></i>{{ $bomWarning }}</div>
    @endif

    {{-- ── Header card ──────────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-header"><h6 class="mb-0">Requisition Details</h6></div>
        <div class="card-body row g-3">

            <div class="col-md-4">
                <label for="mrc_no" class="form-label">MRC No</label>
                <input id="mrc_no" name="mrc_no"
                       class="form-control @error('mrc_no') is-invalid @enderror"
                       value="{{ old('mrc_no', $requisition?->mrc_no ?? $nextNo) }}"
                       placeholder="{{ $nextNo ?? 'MRC-000001' }}">
                <div class="form-text">Auto-generated if blank.</div>
                @error('mrc_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="request_date" class="form-label required">Request Date</label>
                <input id="request_date" name="request_date" type="date" required
                       class="form-control @error('request_date') is-invalid @enderror"
                       value="{{ old('request_date', $requisition?->request_date?->toDateString() ?? now()->toDateString()) }}">
                @error('request_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="required_date" class="form-label">Required Date</label>
                <input id="required_date" name="required_date" type="date"
                       class="form-control @error('required_date') is-invalid @enderror"
                       value="{{ old('required_date', $requisition?->required_date?->toDateString() ?? ($prefill['required_date'] ?? '')) }}">
                @error('required_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="production_order_id" class="form-label">Production Order</label>
                <select id="production_order_id" name="production_order_id"
                        class="ajax-select2 form-select @error('production_order_id') is-invalid @enderror"
                        data-ajax-url="{{ $orderAjaxUrl }}" data-placeholder="Search production order (optional)…"
                        data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedOrder ?? null)
                        <option value="{{ $selectedOrder['id'] }}" selected>{{ $selectedOrder['text'] }}</option>
                    @endif
                </select>
                <div class="form-text">Optional. Use <em>Generate Material Requisition</em> on a production order to prefill from its BOM.</div>
                @error('production_order_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="manufacturing_customer_id" class="form-label">Manufacturing Customer</label>
                <select id="manufacturing_customer_id" name="manufacturing_customer_id"
                        class="ajax-select2 form-select @error('manufacturing_customer_id') is-invalid @enderror"
                        data-ajax-url="{{ $customerAjaxUrl }}" data-placeholder="Search customer (optional)…"
                        data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedCustomer ?? null)
                        <option value="{{ $selectedCustomer['id'] }}" selected>{{ $selectedCustomer['text'] }}</option>
                    @endif
                </select>
                <div class="form-text">Optional. <strong>Not linked to POS/Sales customers.</strong></div>
                @error('manufacturing_customer_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="branch_id" class="form-label required">Branch / Production Unit</label>
                <select id="branch_id" name="branch_id" required
                        class="select form-select @error('branch_id') is-invalid @enderror">
                    <option value="">— Select branch —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(old('branch_id', $requisition?->branch_id ?? ($prefill['branch_id'] ?? '')) == $b->id)>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text">A requisition belongs to one branch.</div>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" required
                        class="select form-select @error('status') is-invalid @enderror">
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected(old('status', $requisition?->status ?? 'draft') === $s)>
                            {{ ucfirst(str_replace('_', ' ', $s)) }}
                        </option>
                    @endforeach
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="priority" class="form-label">Priority</label>
                <select id="priority" name="priority"
                        class="select form-select @error('priority') is-invalid @enderror">
                    <option value="">— Not set —</option>
                    @foreach($priorities as $p)
                        <option value="{{ $p }}" @selected(old('priority', $requisition?->priority ?? ($prefill['priority'] ?? '')) === $p)>
                            {{ ucfirst($p) }}
                        </option>
                    @endforeach
                </select>
                @error('priority') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2"
                          class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $requisition?->notes) }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

        </div>
    </div>

    {{-- ── Requisition Lines ────────────────────────────────────────────── --}}
    <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="mb-0">Required Components <span class="text-muted small fw-normal">(raw materials / consumables)</span></h6>
            <button type="button" id="add-line-btn" class="btn btn-sm btn-outline-primary">
                <i class="ti ti-plus me-1"></i>Add Component
            </button>
        </div>
        <div class="card-body">
            @error('lines') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

            <div class="table-responsive">
                <table class="table table-sm align-middle" id="lines-table">
                    <thead class="thead-light">
                        <tr>
                            <th style="min-width:260px;">Component Product <span class="text-danger">*</span></th>
                            <th style="min-width:110px;">Unit</th>
                            <th style="min-width:120px;">Required Qty <span class="text-danger">*</span></th>
                            <th style="min-width:110px;">Issued Qty</th>
                            <th style="min-width:100px;">Wastage %</th>
                            <th style="min-width:150px;">Notes</th>
                            <th style="width:44px;"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                    @forelse($rows as $i => $row)
                        @php $cid = $row['component_product_id'] ?? null; @endphp
                        <tr class="mrc-line-row">
                            <td>
                                <select name="lines[{{ $i }}][component_product_id]" required
                                        class="ajax-select2 form-select form-select-sm"
                                        data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search component…" data-min-input="1">
                                    @if($cid)
                                        <option value="{{ $cid }}" selected>{{ $row['_text'] ?? ($componentOptions[$cid] ?? ('#' . $cid)) }}</option>
                                    @endif
                                </select>
                                @if(!empty($row['source_bom_line_id']))
                                    <input type="hidden" name="lines[{{ $i }}][source_bom_line_id]" value="{{ $row['source_bom_line_id'] }}">
                                    <small class="text-success"><i class="ti ti-sitemap"></i> from BOM</small>
                                @endif
                            </td>
                            <td>
                                <select name="lines[{{ $i }}][unit_id]" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach($units as $u)
                                        <option value="{{ $u->id }}" @selected($u->id == ($row['unit_id'] ?? ''))>{{ $u->code }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input name="lines[{{ $i }}][required_quantity]" type="number" step="0.0001" min="0.0001" required
                                       value="{{ $row['required_quantity'] ?? '' }}" class="form-control form-control-sm">
                            </td>
                            <td>
                                <input name="lines[{{ $i }}][issued_quantity]" type="number" step="0.0001" min="0"
                                       value="{{ $row['issued_quantity'] ?? 0 }}" class="form-control form-control-sm">
                            </td>
                            <td>
                                <input name="lines[{{ $i }}][wastage_percent]" type="number" step="0.01" min="0" max="100"
                                       value="{{ $row['wastage_percent'] ?? 0 }}" class="form-control form-control-sm" placeholder="0">
                            </td>
                            <td>
                                <input name="lines[{{ $i }}][notes]" type="text" value="{{ $row['notes'] ?? '' }}"
                                       class="form-control form-control-sm" placeholder="optional">
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger remove-line-btn" title="Remove"><i class="ti ti-x"></i></button>
                            </td>
                        </tr>
                    @empty
                        <tr class="mrc-line-row">
                            <td>
                                <select name="lines[0][component_product_id]" required
                                        class="ajax-select2 form-select form-select-sm"
                                        data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search component…" data-min-input="1"></select>
                            </td>
                            <td>
                                <select name="lines[0][unit_id]" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach($units as $u)
                                        <option value="{{ $u->id }}">{{ $u->code }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input name="lines[0][required_quantity]" type="number" step="0.0001" min="0.0001" required class="form-control form-control-sm" placeholder="0.0000"></td>
                            <td><input name="lines[0][issued_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
                            <td><input name="lines[0][wastage_percent]" type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" placeholder="0"></td>
                            <td><input name="lines[0][notes]" type="text" class="form-control form-control-sm" placeholder="optional"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger remove-line-btn" title="Remove"><i class="ti ti-x"></i></button></td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <p class="text-muted small mb-0 mt-1"><i class="ti ti-info-circle me-1"></i>At least 1 component required. Issued cannot exceed required. Type to search products. This does not issue stock.</p>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary">{{ $requisition ? 'Update MRC' : 'Save MRC' }}</button>
        <a href="{{ url('/manufacturing/material-requisitions' . ($requisition ? '/' . $requisition->id : '')) }}" class="btn btn-light ms-2">Cancel</a>
    </div>
</form>

{{-- Row template for new lines (component select is AJAX, initialised on add) --}}
<template id="line-row-template">
    <tr class="mrc-line-row">
        <td>
            <select name="lines[__IDX__][component_product_id]" required
                    class="ajax-select2 form-select form-select-sm"
                    data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search component…" data-min-input="1"></select>
        </td>
        <td>
            <select name="lines[__IDX__][unit_id]" class="form-select form-select-sm">
                <option value="">—</option>
                @foreach($units as $u)
                    <option value="{{ $u->id }}">{{ $u->code }}</option>
                @endforeach
            </select>
        </td>
        <td><input name="lines[__IDX__][required_quantity]" type="number" step="0.0001" min="0.0001" required class="form-control form-control-sm" placeholder="0.0000"></td>
        <td><input name="lines[__IDX__][issued_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][wastage_percent]" type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" placeholder="0"></td>
        <td><input name="lines[__IDX__][notes]" type="text" class="form-control form-control-sm" placeholder="optional"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-line-btn" title="Remove"><i class="ti ti-x"></i></button></td>
    </tr>
</template>

@include('tenant.partials.ajax-select2-scripts')

@push('scripts')
<script>
(function () {
    let idx = {{ $lineCount }};
    const body = document.getElementById('lines-body');
    const tpl  = document.getElementById('line-row-template');

    document.getElementById('add-line-btn').addEventListener('click', function () {
        const html = tpl.innerHTML.replaceAll('__IDX__', idx++);
        const tr   = document.createElement('tbody');
        tr.innerHTML = html;
        const row = tr.firstElementChild;
        body.appendChild(row);
        if (window.initAjaxSelect2) window.initAjaxSelect2(row);
    });

    body.addEventListener('click', function (e) {
        const btn = e.target.closest('.remove-line-btn');
        if (!btn) return;
        const row = btn.closest('tr');
        if (typeof window.jQuery !== 'undefined') {
            window.jQuery(row).find('.select2-hidden-accessible').each(function () {
                window.jQuery(this).select2('destroy');
            });
        }
        row.remove();
    });
})();
</script>
@endpush
