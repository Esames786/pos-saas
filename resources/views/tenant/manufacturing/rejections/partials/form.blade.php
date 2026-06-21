{{-- Shared Rejection form partial (create + edit) --}}
@php
    $productOptions  = $productOptions ?? [];
    $prefill         = $prefill ?? [];
    $productAjaxUrl   = url('/ajax/products');
    $wipAjaxUrl       = url('/ajax/wip-jobs');
    $fgAjaxUrl        = url('/ajax/finished-goods');
    $orderAjaxUrl     = url('/ajax/production-orders');
    $customerAjaxUrl  = url('/ajax/manufacturing-customers');

    $hval = function ($key, $default = null) use ($record, $prefill) {
        return old($key, $record?->{$key} ?? ($prefill[$key] ?? $default));
    };

    if (old('lines')) {
        $rows = collect(old('lines'))->map(fn ($l) => array_merge($l, ['_text' => $productOptions[$l['product_id'] ?? 0] ?? null]));
    } elseif ($record && $record->lines->count()) {
        $rows = $record->lines->map(fn ($l) => [
            'product_id'                     => $l->product_id,
            'unit_id'                        => $l->unit_id,
            'quantity'                       => $l->quantity,
            'rework_quantity'                => $l->rework_quantity,
            'scrap_quantity'                 => $l->scrap_quantity,
            'accepted_after_review_quantity' => $l->accepted_after_review_quantity,
            'disposed_quantity'              => $l->disposed_quantity,
            'estimated_loss_value'           => $l->estimated_loss_value,
            'defect_code'                    => $l->defect_code,
            'batch_no'                       => $l->batch_no,
            'lot_no'                         => $l->lot_no,
            'notes'                          => $l->notes,
            '_text'                          => $productOptions[$l->product_id] ?? ($l->product ? ($l->product->sku . ' — ' . $l->product->name) : null),
        ]);
    } else {
        $rows = collect($prefillLines ?? [])->map(fn ($l) => array_merge($l, ['_text' => $l['_text'] ?? ($productOptions[$l['product_id'] ?? 0] ?? null)]));
    }
    $rows = $rows->values();
    $lineCount = $rows->count();
@endphp

<form method="POST"
      action="{{ $record ? url('/manufacturing/rejections/' . $record->id) : url('/manufacturing/rejections') }}"
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
        <div class="card-header"><h6 class="mb-0">Rejection Details</h6></div>
        <div class="card-body row g-3">

            <div class="col-md-3">
                <label for="rejection_no" class="form-label">Rejection No</label>
                <input id="rejection_no" name="rejection_no" class="form-control @error('rejection_no') is-invalid @enderror"
                       value="{{ old('rejection_no', $record?->rejection_no ?? $nextNo) }}" placeholder="{{ $nextNo ?? 'REJ-000001' }}">
                <div class="form-text">Auto-generated if blank.</div>
                @error('rejection_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="rejection_date" class="form-label required">Rejection Date</label>
                <input id="rejection_date" name="rejection_date" type="date" required
                       class="form-control @error('rejection_date') is-invalid @enderror"
                       value="{{ old('rejection_date', $record?->rejection_date?->toDateString() ?? now()->toDateString()) }}">
                @error('rejection_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
                <label for="finished_good_receipt_id" class="form-label">Finished Goods Receipt</label>
                <select id="finished_good_receipt_id" name="finished_good_receipt_id"
                        class="ajax-select2 form-select @error('finished_good_receipt_id') is-invalid @enderror"
                        data-ajax-url="{{ $fgAjaxUrl }}" data-placeholder="Search FG receipt (optional)…" data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedFg ?? null)<option value="{{ $selectedFg['id'] }}" selected>{{ $selectedFg['text'] }}</option>@endif
                </select>
                @error('finished_good_receipt_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
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

            <div class="col-md-6">
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

            <div class="col-md-3">
                <label for="rejection_type" class="form-label required">Rejection Type</label>
                <select id="rejection_type" name="rejection_type" required class="select form-select @error('rejection_type') is-invalid @enderror">
                    @foreach($rejectionTypes as $rt)
                        <option value="{{ $rt }}" @selected($hval('rejection_type', 'quality_fail') === $rt)>{{ ucfirst(str_replace('_', ' ', $rt)) }}</option>
                    @endforeach
                </select>
                @error('rejection_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" required class="select form-select @error('status') is-invalid @enderror">
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected(old('status', $record?->status ?? 'draft') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="severity" class="form-label">Severity</label>
                <select id="severity" name="severity" class="select form-select @error('severity') is-invalid @enderror">
                    <option value="">— Not set —</option>
                    @foreach($severities as $sv)
                        <option value="{{ $sv }}" @selected($hval('severity') === $sv)>{{ ucfirst($sv) }}</option>
                    @endforeach
                </select>
                @error('severity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="disposition" class="form-label">Disposition</label>
                <select id="disposition" name="disposition" class="select form-select @error('disposition') is-invalid @enderror">
                    <option value="">— Not set —</option>
                    @foreach($dispositions as $d)
                        <option value="{{ $d }}" @selected($hval('disposition') === $d)>{{ ucfirst(str_replace('_', ' ', $d)) }}</option>
                    @endforeach
                </select>
                @error('disposition') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="reason_code" class="form-label">Reason Code</label>
                <select id="reason_code" name="reason_code" class="select form-select @error('reason_code') is-invalid @enderror">
                    <option value="">— Not set —</option>
                    @foreach($reasonCodes as $rc)
                        <option value="{{ $rc }}" @selected($hval('reason_code') === $rc)>{{ ucfirst(str_replace('_', ' ', $rc)) }}</option>
                    @endforeach
                </select>
                @error('reason_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="quality_status" class="form-label">Quality Status</label>
                <select id="quality_status" name="quality_status" class="select form-select @error('quality_status') is-invalid @enderror">
                    <option value="">— Not set —</option>
                    @foreach($qualityStatuses as $qs)
                        <option value="{{ $qs }}" @selected($hval('quality_status') === $qs)>{{ ucfirst(str_replace('_', ' ', $qs)) }}</option>
                    @endforeach
                </select>
                @error('quality_status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="total_quantity" class="form-label required">Total Qty</label>
                <input id="total_quantity" name="total_quantity" type="number" step="0.0001" min="0" required
                       class="form-control @error('total_quantity') is-invalid @enderror"
                       value="{{ old('total_quantity', $record?->total_quantity ?? ($prefill['total_quantity'] ?? 0)) }}">
                <div class="form-text">Auto-calculated from lines if any.</div>
                @error('total_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="rework_quantity" class="form-label">Rework Qty</label>
                <input id="rework_quantity" name="rework_quantity" type="number" step="0.0001" min="0"
                       class="form-control @error('rework_quantity') is-invalid @enderror"
                       value="{{ old('rework_quantity', $record?->rework_quantity ?? 0) }}">
                @error('rework_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="scrap_quantity" class="form-label">Scrap Qty</label>
                <input id="scrap_quantity" name="scrap_quantity" type="number" step="0.0001" min="0"
                       class="form-control @error('scrap_quantity') is-invalid @enderror"
                       value="{{ old('scrap_quantity', $record?->scrap_quantity ?? 0) }}">
                @error('scrap_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="accepted_after_review_quantity" class="form-label">Accepted After Review</label>
                <input id="accepted_after_review_quantity" name="accepted_after_review_quantity" type="number" step="0.0001" min="0"
                       class="form-control @error('accepted_after_review_quantity') is-invalid @enderror"
                       value="{{ old('accepted_after_review_quantity', $record?->accepted_after_review_quantity ?? 0) }}">
                @error('accepted_after_review_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="disposed_quantity" class="form-label">Disposed Qty</label>
                <input id="disposed_quantity" name="disposed_quantity" type="number" step="0.0001" min="0"
                       class="form-control @error('disposed_quantity') is-invalid @enderror"
                       value="{{ old('disposed_quantity', $record?->disposed_quantity ?? 0) }}">
                @error('disposed_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="estimated_loss_value" class="form-label">Estimated Loss Value</label>
                <input id="estimated_loss_value" name="estimated_loss_value" type="number" step="0.0001" min="0"
                       class="form-control @error('estimated_loss_value') is-invalid @enderror"
                       value="{{ old('estimated_loss_value', $record?->estimated_loss_value) }}">
                <div class="form-text">Informational only — no GL impact.</div>
                @error('estimated_loss_value') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $record?->notes) }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

        </div>
    </div>

    {{-- ── Rejection Lines ──────────────────────────────────────────────── --}}
    <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="mb-0">Rejected Items <span class="text-muted small fw-normal">(optional — header totals auto-calc from these)</span></h6>
            <button type="button" id="add-line-btn" class="btn btn-sm btn-outline-primary">
                <i class="ti ti-plus me-1"></i>Add Item
            </button>
        </div>
        <div class="card-body">
            @error('lines') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

            <div class="table-responsive">
                <table class="table table-sm align-middle" id="lines-table">
                    <thead class="thead-light">
                        <tr>
                            <th style="min-width:230px;">Product <span class="text-danger">*</span></th>
                            <th style="min-width:90px;">Unit</th>
                            <th style="min-width:95px;">Qty <span class="text-danger">*</span></th>
                            <th style="min-width:90px;">Rework</th>
                            <th style="min-width:90px;">Scrap</th>
                            <th style="min-width:110px;">Accept-Review</th>
                            <th style="min-width:90px;">Disposed</th>
                            <th style="min-width:110px;">Est. Loss</th>
                            <th style="min-width:100px;">Defect</th>
                            <th style="min-width:95px;">Batch</th>
                            <th style="width:44px;"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                    @foreach($rows as $i => $row)
                        @php $pid = $row['product_id'] ?? null; @endphp
                        <tr class="rej-line-row">
                            <td>
                                <select name="lines[{{ $i }}][product_id]" required
                                        class="ajax-select2 form-select form-select-sm"
                                        data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search product…" data-min-input="1">
                                    @if($pid)<option value="{{ $pid }}" selected>{{ $row['_text'] ?? ($productOptions[$pid] ?? ('#' . $pid)) }}</option>@endif
                                </select>
                            </td>
                            <td>
                                <select name="lines[{{ $i }}][unit_id]" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach($units as $u)
                                        <option value="{{ $u->id }}" @selected($u->id == ($row['unit_id'] ?? ''))>{{ $u->code }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input name="lines[{{ $i }}][quantity]" type="number" step="0.0001" min="0.0001" required value="{{ $row['quantity'] ?? '' }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][rework_quantity]" type="number" step="0.0001" min="0" value="{{ $row['rework_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][scrap_quantity]" type="number" step="0.0001" min="0" value="{{ $row['scrap_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][accepted_after_review_quantity]" type="number" step="0.0001" min="0" value="{{ $row['accepted_after_review_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][disposed_quantity]" type="number" step="0.0001" min="0" value="{{ $row['disposed_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][estimated_loss_value]" type="number" step="0.0001" min="0" value="{{ $row['estimated_loss_value'] ?? '' }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][defect_code]" type="text" value="{{ $row['defect_code'] ?? '' }}" class="form-control form-control-sm" placeholder="defect"></td>
                            <td><input name="lines[{{ $i }}][batch_no]" type="text" value="{{ $row['batch_no'] ?? '' }}" class="form-control form-control-sm" placeholder="batch"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger remove-line-btn" title="Remove"><i class="ti ti-x"></i></button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="text-muted small mb-0 mt-1"><i class="ti ti-info-circle me-1"></i>Rework + Scrap + Accepted-after-review + Disposed cannot exceed Qty per line. Header totals auto-calc from lines. This does NOT deduct stock or create a Scrap record.</p>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary">{{ $record ? 'Update Rejection' : 'Save Rejection' }}</button>
        <a href="{{ url('/manufacturing/rejections' . ($record ? '/' . $record->id : '')) }}" class="btn btn-light ms-2">Cancel</a>
    </div>
</form>

{{-- Row template for new lines (product select is AJAX, initialised on add) --}}
<template id="line-row-template">
    <tr class="rej-line-row">
        <td>
            <select name="lines[__IDX__][product_id]" required
                    class="ajax-select2 form-select form-select-sm"
                    data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search product…" data-min-input="1"></select>
        </td>
        <td>
            <select name="lines[__IDX__][unit_id]" class="form-select form-select-sm">
                <option value="">—</option>
                @foreach($units as $u)
                    <option value="{{ $u->id }}">{{ $u->code }}</option>
                @endforeach
            </select>
        </td>
        <td><input name="lines[__IDX__][quantity]" type="number" step="0.0001" min="0.0001" required class="form-control form-control-sm" placeholder="0.0000"></td>
        <td><input name="lines[__IDX__][rework_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][scrap_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][accepted_after_review_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][disposed_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][estimated_loss_value]" type="number" step="0.0001" min="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][defect_code]" type="text" class="form-control form-control-sm" placeholder="defect"></td>
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
