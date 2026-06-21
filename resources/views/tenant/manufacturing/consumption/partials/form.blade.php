{{-- Shared Consumption form partial (create + edit) --}}
@php
    $productOptions  = $productOptions ?? [];
    $prefill         = $prefill ?? [];
    $productAjaxUrl   = url('/ajax/products');
    $wipAjaxUrl       = url('/ajax/wip-jobs');
    $mrcAjaxUrl       = url('/ajax/material-requisitions');
    $orderAjaxUrl     = url('/ajax/production-orders');
    $customerAjaxUrl  = url('/ajax/manufacturing-customers');

    $hval = function ($key, $default = null) use ($record, $prefill) {
        return old($key, $record?->{$key} ?? ($prefill[$key] ?? $default));
    };

    if (old('lines')) {
        $rows = collect(old('lines'))->map(fn ($l) => array_merge($l, ['_text' => $productOptions[$l['component_product_id'] ?? 0] ?? null]));
    } elseif ($record && $record->lines->count()) {
        $rows = $record->lines->map(fn ($l) => [
            'wip_job_line_id'              => $l->wip_job_line_id,
            'material_requisition_line_id' => $l->material_requisition_line_id,
            'component_product_id'         => $l->component_product_id,
            'unit_id'                      => $l->unit_id,
            'planned_quantity'             => $l->planned_quantity,
            'consumed_quantity'            => $l->consumed_quantity,
            'wastage_quantity'             => $l->wastage_quantity,
            'estimated_unit_cost'          => $l->estimated_unit_cost,
            'estimated_total_value'        => $l->estimated_total_value,
            'batch_no'                     => $l->batch_no,
            'lot_no'                       => $l->lot_no,
            'notes'                        => $l->notes,
            '_text'                        => $productOptions[$l->component_product_id] ?? ($l->componentProduct ? ($l->componentProduct->sku . ' — ' . $l->componentProduct->name) : null),
        ]);
    } else {
        $rows = collect($prefillLines ?? [])->map(fn ($l) => array_merge($l, ['_text' => $l['_text'] ?? ($productOptions[$l['component_product_id'] ?? 0] ?? null)]));
    }
    $rows = $rows->values();
    $lineCount = $rows->count();
@endphp

<form method="POST"
      action="{{ $record ? url('/manufacturing/consumption/' . $record->id) : url('/manufacturing/consumption') }}"
      novalidate>
    @csrf
    @if($record) @method('PUT') @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    @if(!empty($warning))
        <div class="alert alert-warning"><i class="ti ti-alert-triangle me-1"></i>{{ $warning }}</div>
    @endif

    {{-- ── Header card ──────────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-header"><h6 class="mb-0">Consumption Details</h6></div>
        <div class="card-body row g-3">

            <div class="col-md-3">
                <label for="consumption_no" class="form-label">Consumption No</label>
                <input id="consumption_no" name="consumption_no" class="form-control @error('consumption_no') is-invalid @enderror"
                       value="{{ old('consumption_no', $record?->consumption_no ?? $nextNo) }}" placeholder="{{ $nextNo ?? 'CONS-000001' }}">
                <div class="form-text">Auto-generated if blank.</div>
                @error('consumption_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="consumption_date" class="form-label required">Consumption Date</label>
                <input id="consumption_date" name="consumption_date" type="date" required
                       class="form-control @error('consumption_date') is-invalid @enderror"
                       value="{{ old('consumption_date', $record?->consumption_date?->toDateString() ?? now()->toDateString()) }}">
                @error('consumption_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="source_type" class="form-label">Source Type</label>
                <select id="source_type" name="source_type" class="select form-select @error('source_type') is-invalid @enderror">
                    <option value="">— Manual / none —</option>
                    @foreach($sourceTypes as $st)
                        <option value="{{ $st }}" @selected($hval('source_type') === $st)>{{ ucfirst(str_replace('_', ' ', $st)) }}</option>
                    @endforeach
                </select>
                @error('source_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="branch_id" class="form-label required">Branch / Production Unit</label>
                <select id="branch_id" name="branch_id" required class="select form-select @error('branch_id') is-invalid @enderror">
                    <option value="">— Select branch —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($hval('branch_id') == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="wip_job_id" class="form-label">WIP Job</label>
                <select id="wip_job_id" name="wip_job_id"
                        class="ajax-select2 form-select @error('wip_job_id') is-invalid @enderror"
                        data-ajax-url="{{ $wipAjaxUrl }}" data-placeholder="Search WIP job (optional)…" data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedWip ?? null)<option value="{{ $selectedWip['id'] }}" selected>{{ $selectedWip['text'] }}</option>@endif
                </select>
                @error('wip_job_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="material_requisition_id" class="form-label">Material Requisition (MRC)</label>
                <select id="material_requisition_id" name="material_requisition_id"
                        class="ajax-select2 form-select @error('material_requisition_id') is-invalid @enderror"
                        data-ajax-url="{{ $mrcAjaxUrl }}" data-placeholder="Search MRC (optional)…" data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedMrc ?? null)<option value="{{ $selectedMrc['id'] }}" selected>{{ $selectedMrc['text'] }}</option>@endif
                </select>
                @error('material_requisition_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="production_order_id" class="form-label">Production Order</label>
                <select id="production_order_id" name="production_order_id"
                        class="ajax-select2 form-select @error('production_order_id') is-invalid @enderror"
                        data-ajax-url="{{ $orderAjaxUrl }}" data-placeholder="Search production order (optional)…" data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedOrder ?? null)<option value="{{ $selectedOrder['id'] }}" selected>{{ $selectedOrder['text'] }}</option>@endif
                </select>
                @error('production_order_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="manufacturing_customer_id" class="form-label">Manufacturing Customer</label>
                <select id="manufacturing_customer_id" name="manufacturing_customer_id"
                        class="ajax-select2 form-select @error('manufacturing_customer_id') is-invalid @enderror"
                        data-ajax-url="{{ $customerAjaxUrl }}" data-placeholder="Search customer (optional)…" data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedCustomer ?? null)<option value="{{ $selectedCustomer['id'] }}" selected>{{ $selectedCustomer['text'] }}</option>@endif
                </select>
                <div class="form-text">Optional. <strong>Not linked to POS/Sales customers.</strong></div>
                @error('manufacturing_customer_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="consumption_type" class="form-label required">Consumption Type</label>
                <select id="consumption_type" name="consumption_type" required class="select form-select @error('consumption_type') is-invalid @enderror">
                    @foreach($consumptionTypes as $ct)
                        <option value="{{ $ct }}" @selected($hval('consumption_type', 'production_usage') === $ct)>{{ ucfirst(str_replace('_', ' ', $ct)) }}</option>
                    @endforeach
                </select>
                @error('consumption_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" required class="select form-select @error('status') is-invalid @enderror">
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected(old('status', $record?->status ?? 'draft') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="issue_reference" class="form-label">Issue Reference</label>
                <input id="issue_reference" name="issue_reference" class="form-control @error('issue_reference') is-invalid @enderror"
                       value="{{ old('issue_reference', $record?->issue_reference) }}" placeholder="e.g. store issue slip no.">
                @error('issue_reference') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="total_planned_quantity" class="form-label required">Total Planned</label>
                <input id="total_planned_quantity" name="total_planned_quantity" type="number" step="0.0001" min="0" required
                       class="form-control @error('total_planned_quantity') is-invalid @enderror"
                       value="{{ old('total_planned_quantity', $record?->total_planned_quantity ?? 0) }}">
                <div class="form-text">Auto from lines if any.</div>
                @error('total_planned_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="total_consumed_quantity" class="form-label required">Total Consumed</label>
                <input id="total_consumed_quantity" name="total_consumed_quantity" type="number" step="0.0001" min="0" required
                       class="form-control @error('total_consumed_quantity') is-invalid @enderror"
                       value="{{ old('total_consumed_quantity', $record?->total_consumed_quantity ?? 0) }}">
                @error('total_consumed_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="total_wastage_quantity" class="form-label">Total Wastage</label>
                <input id="total_wastage_quantity" name="total_wastage_quantity" type="number" step="0.0001" min="0"
                       class="form-control @error('total_wastage_quantity') is-invalid @enderror"
                       value="{{ old('total_wastage_quantity', $record?->total_wastage_quantity ?? 0) }}">
                @error('total_wastage_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="total_variance_quantity" class="form-label">Total Variance</label>
                <input id="total_variance_quantity" name="total_variance_quantity" type="number" step="0.0001"
                       class="form-control @error('total_variance_quantity') is-invalid @enderror"
                       value="{{ old('total_variance_quantity', $record?->total_variance_quantity ?? 0) }}" readonly>
                <div class="form-text">Consumed − Planned (auto).</div>
                @error('total_variance_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="estimated_consumption_value" class="form-label">Estimated Consumption Value</label>
                <input id="estimated_consumption_value" name="estimated_consumption_value" type="number" step="0.0001" min="0"
                       class="form-control @error('estimated_consumption_value') is-invalid @enderror"
                       value="{{ old('estimated_consumption_value', $record?->estimated_consumption_value) }}">
                <div class="form-text">Informational only — no GL impact.</div>
                @error('estimated_consumption_value') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $record?->notes) }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

        </div>
    </div>

    {{-- ── Consumption Lines ────────────────────────────────────────────── --}}
    <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="mb-0">Consumed Components <span class="text-muted small fw-normal">(header totals auto-calc from these)</span></h6>
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
                            <th style="min-width:240px;">Component Product <span class="text-danger">*</span></th>
                            <th style="min-width:90px;">Unit</th>
                            <th style="min-width:100px;">Planned</th>
                            <th style="min-width:100px;">Consumed <span class="text-danger">*</span></th>
                            <th style="min-width:90px;">Wastage</th>
                            <th style="min-width:110px;">Est. Unit Cost</th>
                            <th style="min-width:110px;">Est. Total</th>
                            <th style="min-width:95px;">Batch</th>
                            <th style="width:44px;"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                    @foreach($rows as $i => $row)
                        @php $pid = $row['component_product_id'] ?? null; @endphp
                        <tr class="cons-line-row">
                            <td>
                                <select name="lines[{{ $i }}][component_product_id]" required
                                        class="ajax-select2 form-select form-select-sm"
                                        data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search component…" data-min-input="1">
                                    @if($pid)<option value="{{ $pid }}" selected>{{ $row['_text'] ?? ($productOptions[$pid] ?? ('#' . $pid)) }}</option>@endif
                                </select>
                                @if(!empty($row['wip_job_line_id']))<input type="hidden" name="lines[{{ $i }}][wip_job_line_id]" value="{{ $row['wip_job_line_id'] }}"><small class="text-success"><i class="ti ti-progress"></i> WIP</small>@endif
                                @if(!empty($row['material_requisition_line_id']))<input type="hidden" name="lines[{{ $i }}][material_requisition_line_id]" value="{{ $row['material_requisition_line_id'] }}"><small class="text-success"><i class="ti ti-clipboard-list"></i> MRC</small>@endif
                            </td>
                            <td>
                                <select name="lines[{{ $i }}][unit_id]" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach($units as $u)
                                        <option value="{{ $u->id }}" @selected($u->id == ($row['unit_id'] ?? ''))>{{ $u->code }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input name="lines[{{ $i }}][planned_quantity]" type="number" step="0.0001" min="0" value="{{ $row['planned_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][consumed_quantity]" type="number" step="0.0001" min="0.0001" required value="{{ $row['consumed_quantity'] ?? '' }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][wastage_quantity]" type="number" step="0.0001" min="0" value="{{ $row['wastage_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][estimated_unit_cost]" type="number" step="0.0001" min="0" value="{{ $row['estimated_unit_cost'] ?? '' }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][estimated_total_value]" type="number" step="0.0001" min="0" value="{{ $row['estimated_total_value'] ?? '' }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][batch_no]" type="text" value="{{ $row['batch_no'] ?? '' }}" class="form-control form-control-sm" placeholder="batch"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger remove-line-btn" title="Remove"><i class="ti ti-x"></i></button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="text-muted small mb-0 mt-1"><i class="ti ti-info-circle me-1"></i>Variance = Consumed − Planned (auto). Wastage cannot exceed Consumed. This does NOT deduct stock or change WIP/MRC quantities.</p>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary">{{ $record ? 'Update Consumption' : 'Save Consumption' }}</button>
        <a href="{{ url('/manufacturing/consumption' . ($record ? '/' . $record->id : '')) }}" class="btn btn-light ms-2">Cancel</a>
    </div>
</form>

{{-- Row template for new lines (product select is AJAX, initialised on add) --}}
<template id="line-row-template">
    <tr class="cons-line-row">
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
        <td><input name="lines[__IDX__][planned_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][consumed_quantity]" type="number" step="0.0001" min="0.0001" required class="form-control form-control-sm" placeholder="0.0000"></td>
        <td><input name="lines[__IDX__][wastage_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][estimated_unit_cost]" type="number" step="0.0001" min="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][estimated_total_value]" type="number" step="0.0001" min="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][batch_no]" type="text" class="form-control form-control-sm" placeholder="batch"></td>
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
