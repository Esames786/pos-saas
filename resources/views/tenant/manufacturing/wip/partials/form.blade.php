{{-- Shared WIP Job form partial (create + edit) --}}
@php
    $componentOptions = $componentOptions ?? [];
    $prefill          = $prefill ?? [];
    $productAjaxUrl    = url('/ajax/products');
    $orderAjaxUrl      = url('/ajax/production-orders');
    $mrcAjaxUrl        = url('/ajax/material-requisitions');
    $customerAjaxUrl   = url('/ajax/manufacturing-customers');

    if (old('lines')) {
        $rows = collect(old('lines'))->map(fn ($l) => [
            'material_requisition_line_id' => $l['material_requisition_line_id'] ?? null,
            'component_product_id'         => $l['component_product_id'] ?? null,
            'unit_id'                      => $l['unit_id'] ?? null,
            'required_quantity'            => $l['required_quantity'] ?? 0,
            'issued_quantity'              => $l['issued_quantity'] ?? 0,
            'consumed_quantity'            => $l['consumed_quantity'] ?? 0,
            'notes'                        => $l['notes'] ?? null,
            '_text'                        => $componentOptions[$l['component_product_id'] ?? 0] ?? null,
        ]);
    } elseif ($job && $job->lines->count()) {
        $rows = $job->lines->map(fn ($l) => [
            'material_requisition_line_id' => $l->material_requisition_line_id,
            'component_product_id'         => $l->component_product_id,
            'unit_id'                      => $l->unit_id,
            'required_quantity'            => $l->required_quantity,
            'issued_quantity'              => $l->issued_quantity,
            'consumed_quantity'            => $l->consumed_quantity,
            'notes'                        => $l->notes,
            '_text'                        => $componentOptions[$l->component_product_id] ?? ($l->componentProduct ? ($l->componentProduct->sku . ' — ' . $l->componentProduct->name) : null),
        ]);
    } else {
        $rows = collect($prefillLines ?? [])->map(fn ($l) => array_merge($l, [
            '_text' => $componentOptions[$l['component_product_id'] ?? 0] ?? null,
        ]));
    }
    $rows = $rows->values();
    $lineCount = $rows->count();
@endphp

<form method="POST"
      action="{{ $job ? url('/manufacturing/wip/' . $job->id) : url('/manufacturing/wip') }}"
      novalidate>
    @csrf
    @if($job) @method('PUT') @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    @if(!empty($warning))
        <div class="alert alert-warning"><i class="ti ti-alert-triangle me-1"></i>{{ $warning }}</div>
    @endif

    {{-- ── Header card ──────────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-header"><h6 class="mb-0">Job Details</h6></div>
        <div class="card-body row g-3">

            <div class="col-md-4">
                <label for="wip_no" class="form-label">WIP No</label>
                <input id="wip_no" name="wip_no"
                       class="form-control @error('wip_no') is-invalid @enderror"
                       value="{{ old('wip_no', $job?->wip_no ?? $nextNo) }}"
                       placeholder="{{ $nextNo ?? 'WIP-000001' }}">
                <div class="form-text">Auto-generated if blank.</div>
                @error('wip_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="production_order_id" class="form-label required">Production Order</label>
                <select id="production_order_id" name="production_order_id" required
                        class="ajax-select2 form-select @error('production_order_id') is-invalid @enderror"
                        data-ajax-url="{{ $orderAjaxUrl }}" data-placeholder="Search production order…" data-min-input="1">
                    @if($selectedOrder ?? null)
                        <option value="{{ $selectedOrder['id'] }}" selected>{{ $selectedOrder['text'] }}</option>
                    @endif
                </select>
                @error('production_order_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="material_requisition_id" class="form-label">Material Requisition (MRC)</label>
                <select id="material_requisition_id" name="material_requisition_id"
                        class="ajax-select2 form-select @error('material_requisition_id') is-invalid @enderror"
                        data-ajax-url="{{ $mrcAjaxUrl }}" data-placeholder="Search MRC (optional)…" data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedMrc ?? null)
                        <option value="{{ $selectedMrc['id'] }}" selected>{{ $selectedMrc['text'] }}</option>
                    @endif
                </select>
                <div class="form-text">Optional link to the source requisition.</div>
                @error('material_requisition_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="finished_product_id" class="form-label required">Finished Product</label>
                <select id="finished_product_id" name="finished_product_id" required
                        class="ajax-select2 form-select @error('finished_product_id') is-invalid @enderror"
                        data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search finished product…" data-min-input="1">
                    @if($selectedFinishedProduct ?? null)
                        <option value="{{ $selectedFinishedProduct['id'] }}" selected>{{ $selectedFinishedProduct['text'] }}</option>
                    @endif
                </select>
                @error('finished_product_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="manufacturing_customer_id" class="form-label">Manufacturing Customer</label>
                <select id="manufacturing_customer_id" name="manufacturing_customer_id"
                        class="ajax-select2 form-select @error('manufacturing_customer_id') is-invalid @enderror"
                        data-ajax-url="{{ $customerAjaxUrl }}" data-placeholder="Search customer (optional)…" data-min-input="1" data-allow-clear="1">
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
                        <option value="{{ $b->id }}" @selected(old('branch_id', $job?->branch_id ?? ($prefill['branch_id'] ?? '')) == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
                <div class="form-text">A WIP job belongs to one branch.</div>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" required class="select form-select @error('status') is-invalid @enderror">
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected(old('status', $job?->status ?? 'draft') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="priority" class="form-label">Priority</label>
                <select id="priority" name="priority" class="select form-select @error('priority') is-invalid @enderror">
                    <option value="">— Not set —</option>
                    @foreach($priorities as $p)
                        <option value="{{ $p }}" @selected(old('priority', $job?->priority ?? ($prefill['priority'] ?? '')) === $p)>{{ ucfirst($p) }}</option>
                    @endforeach
                </select>
                @error('priority') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="planned_quantity" class="form-label required">Planned Qty</label>
                <input id="planned_quantity" name="planned_quantity" type="number" step="0.0001" min="0.0001" required
                       class="form-control @error('planned_quantity') is-invalid @enderror"
                       value="{{ old('planned_quantity', $job?->planned_quantity ?? ($prefill['planned_quantity'] ?? '')) }}">
                @error('planned_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="started_quantity" class="form-label">Started Qty</label>
                <input id="started_quantity" name="started_quantity" type="number" step="0.0001" min="0"
                       class="form-control @error('started_quantity') is-invalid @enderror"
                       value="{{ old('started_quantity', $job?->started_quantity ?? 0) }}">
                @error('started_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="completed_quantity" class="form-label">Completed Qty</label>
                <input id="completed_quantity" name="completed_quantity" type="number" step="0.0001" min="0"
                       class="form-control @error('completed_quantity') is-invalid @enderror"
                       value="{{ old('completed_quantity', $job?->completed_quantity ?? 0) }}">
                <div class="form-text">Progress % is recalculated from completed ÷ planned.</div>
                @error('completed_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="start_date" class="form-label required">Start Date</label>
                <input id="start_date" name="start_date" type="date" required
                       class="form-control @error('start_date') is-invalid @enderror"
                       value="{{ old('start_date', $job?->start_date?->toDateString() ?? now()->toDateString()) }}">
                @error('start_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="target_date" class="form-label">Target Date</label>
                <input id="target_date" name="target_date" type="date"
                       class="form-control @error('target_date') is-invalid @enderror"
                       value="{{ old('target_date', $job?->target_date?->toDateString() ?? ($prefill['target_date'] ?? '')) }}">
                @error('target_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2"
                          class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $job?->notes) }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

        </div>
    </div>

    {{-- ── Material Lines ───────────────────────────────────────────────── --}}
    <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="mb-0">Material Tracking <span class="text-muted small fw-normal">(snapshot from MRC — no stock posting)</span></h6>
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
                            <th style="min-width:110px;">Required <span class="text-danger">*</span></th>
                            <th style="min-width:110px;">Issued</th>
                            <th style="min-width:110px;">Consumed</th>
                            <th style="min-width:140px;">Notes</th>
                            <th style="width:44px;"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                    @foreach($rows as $i => $row)
                        @php $cid = $row['component_product_id'] ?? null; @endphp
                        <tr class="wip-line-row">
                            <td>
                                <select name="lines[{{ $i }}][component_product_id]" required
                                        class="ajax-select2 form-select form-select-sm"
                                        data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search component…" data-min-input="1">
                                    @if($cid)
                                        <option value="{{ $cid }}" selected>{{ $row['_text'] ?? ($componentOptions[$cid] ?? ('#' . $cid)) }}</option>
                                    @endif
                                </select>
                                @if(!empty($row['material_requisition_line_id']))
                                    <input type="hidden" name="lines[{{ $i }}][material_requisition_line_id]" value="{{ $row['material_requisition_line_id'] }}">
                                    <small class="text-success"><i class="ti ti-clipboard-list"></i> from MRC</small>
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
                            <td><input name="lines[{{ $i }}][required_quantity]" type="number" step="0.0001" min="0" required value="{{ $row['required_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][issued_quantity]" type="number" step="0.0001" min="0" value="{{ $row['issued_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][consumed_quantity]" type="number" step="0.0001" min="0" value="{{ $row['consumed_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][notes]" type="text" value="{{ $row['notes'] ?? '' }}" class="form-control form-control-sm" placeholder="optional"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger remove-line-btn" title="Remove"><i class="ti ti-x"></i></button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="text-muted small mb-0 mt-1"><i class="ti ti-info-circle me-1"></i>Material lines are a tracking snapshot. Consumed cannot exceed Issued. This does NOT issue or deduct stock.</p>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary">{{ $job ? 'Update WIP Job' : 'Save WIP Job' }}</button>
        <a href="{{ url('/manufacturing/wip' . ($job ? '/' . $job->id : '')) }}" class="btn btn-light ms-2">Cancel</a>
    </div>
</form>

{{-- Row template for new lines (component select is AJAX, initialised on add) --}}
<template id="line-row-template">
    <tr class="wip-line-row">
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
        <td><input name="lines[__IDX__][required_quantity]" type="number" step="0.0001" min="0" required class="form-control form-control-sm" placeholder="0.0000"></td>
        <td><input name="lines[__IDX__][issued_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][consumed_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
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
