{{-- Shared Finished Goods receipt form partial (create + edit) --}}
@php
    $productOptions  = $productOptions ?? [];
    $prefill         = $prefill ?? [];
    $productAjaxUrl   = url('/ajax/products');
    $wipAjaxUrl       = url('/ajax/wip-jobs');
    $orderAjaxUrl     = url('/ajax/production-orders');
    $customerAjaxUrl  = url('/ajax/manufacturing-customers');

    if (old('lines')) {
        $rows = collect(old('lines'))->map(fn ($l) => [
            'finished_product_id' => $l['finished_product_id'] ?? null,
            'unit_id'             => $l['unit_id'] ?? null,
            'batch_no'            => $l['batch_no'] ?? null,
            'lot_no'              => $l['lot_no'] ?? null,
            'received_quantity'   => $l['received_quantity'] ?? '',
            'accepted_quantity'   => $l['accepted_quantity'] ?? 0,
            'rejected_quantity'   => $l['rejected_quantity'] ?? 0,
            'scrap_quantity'      => $l['scrap_quantity'] ?? 0,
            'expiry_date'         => $l['expiry_date'] ?? null,
            'notes'               => $l['notes'] ?? null,
            '_text'               => $productOptions[$l['finished_product_id'] ?? 0] ?? null,
        ]);
    } elseif ($receipt && $receipt->lines->count()) {
        $rows = $receipt->lines->map(fn ($l) => [
            'finished_product_id' => $l->finished_product_id,
            'unit_id'             => $l->unit_id,
            'batch_no'            => $l->batch_no,
            'lot_no'              => $l->lot_no,
            'received_quantity'   => $l->received_quantity,
            'accepted_quantity'   => $l->accepted_quantity,
            'rejected_quantity'   => $l->rejected_quantity,
            'scrap_quantity'      => $l->scrap_quantity,
            'expiry_date'         => $l->expiry_date?->toDateString(),
            'notes'               => $l->notes,
            '_text'               => $productOptions[$l->finished_product_id] ?? ($l->finishedProduct ? ($l->finishedProduct->sku . ' — ' . $l->finishedProduct->name) : null),
        ]);
    } else {
        $rows = collect($prefillLines ?? [])->map(fn ($l) => array_merge($l, [
            '_text' => $l['_text'] ?? ($productOptions[$l['finished_product_id'] ?? 0] ?? null),
        ]));
    }
    $rows = $rows->values();
    $lineCount = $rows->count();
@endphp

<form method="POST"
      action="{{ $receipt ? url('/manufacturing/finished-goods/' . $receipt->id) : url('/manufacturing/finished-goods') }}"
      novalidate>
    @csrf
    @if($receipt) @method('PUT') @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    {{-- ── Header card ──────────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-header"><h6 class="mb-0">Receipt Details</h6></div>
        <div class="card-body row g-3">

            <div class="col-md-4">
                <label for="fg_no" class="form-label">FG No</label>
                <input id="fg_no" name="fg_no" class="form-control @error('fg_no') is-invalid @enderror"
                       value="{{ old('fg_no', $receipt?->fg_no ?? $nextNo) }}" placeholder="{{ $nextNo ?? 'FG-000001' }}">
                <div class="form-text">Auto-generated if blank.</div>
                @error('fg_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="wip_job_id" class="form-label required">WIP Job</label>
                <select id="wip_job_id" name="wip_job_id" required
                        class="ajax-select2 form-select @error('wip_job_id') is-invalid @enderror"
                        data-ajax-url="{{ $wipAjaxUrl }}" data-placeholder="Search WIP job…" data-min-input="1">
                    @if($selectedWip ?? null)
                        <option value="{{ $selectedWip['id'] }}" selected>{{ $selectedWip['text'] }}</option>
                    @endif
                </select>
                @error('wip_job_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
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

            <div class="col-md-3">
                <label for="branch_id" class="form-label required">Branch / Production Unit</label>
                <select id="branch_id" name="branch_id" required class="select form-select @error('branch_id') is-invalid @enderror">
                    <option value="">— Select branch —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(old('branch_id', $receipt?->branch_id ?? ($prefill['branch_id'] ?? '')) == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="receipt_date" class="form-label required">Receipt Date</label>
                <input id="receipt_date" name="receipt_date" type="date" required
                       class="form-control @error('receipt_date') is-invalid @enderror"
                       value="{{ old('receipt_date', $receipt?->receipt_date?->toDateString() ?? now()->toDateString()) }}">
                @error('receipt_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" required class="select form-select @error('status') is-invalid @enderror">
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected(old('status', $receipt?->status ?? 'draft') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="quality_status" class="form-label">Quality Status</label>
                <select id="quality_status" name="quality_status" class="select form-select @error('quality_status') is-invalid @enderror">
                    <option value="">— Not set —</option>
                    @foreach($qualityStatuses as $qs)
                        <option value="{{ $qs }}" @selected(old('quality_status', $receipt?->quality_status) === $qs)>{{ ucfirst(str_replace('_', ' ', $qs)) }}</option>
                    @endforeach
                </select>
                @error('quality_status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="priority" class="form-label">Priority</label>
                <select id="priority" name="priority" class="select form-select @error('priority') is-invalid @enderror">
                    <option value="">— Not set —</option>
                    @foreach($priorities as $p)
                        <option value="{{ $p }}" @selected(old('priority', $receipt?->priority ?? ($prefill['priority'] ?? '')) === $p)>{{ ucfirst($p) }}</option>
                    @endforeach
                </select>
                @error('priority') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="planned_quantity" class="form-label required">Planned Qty</label>
                <input id="planned_quantity" name="planned_quantity" type="number" step="0.0001" min="0.0001" required
                       class="form-control @error('planned_quantity') is-invalid @enderror"
                       value="{{ old('planned_quantity', $receipt?->planned_quantity ?? ($prefill['planned_quantity'] ?? '')) }}">
                @error('planned_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="received_quantity" class="form-label required">Received Qty</label>
                <input id="received_quantity" name="received_quantity" type="number" step="0.0001" min="0.0001" required
                       class="form-control @error('received_quantity') is-invalid @enderror"
                       value="{{ old('received_quantity', $receipt?->received_quantity ?? ($prefill['received_quantity'] ?? '')) }}">
                <div class="form-text">Cannot exceed WIP planned qty.</div>
                @error('received_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-2">
                <label for="accepted_quantity" class="form-label">Accepted</label>
                <input id="accepted_quantity" name="accepted_quantity" type="number" step="0.0001" min="0"
                       class="form-control @error('accepted_quantity') is-invalid @enderror"
                       value="{{ old('accepted_quantity', $receipt?->accepted_quantity ?? 0) }}">
                @error('accepted_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-2">
                <label for="rejected_quantity" class="form-label">Rejected</label>
                <input id="rejected_quantity" name="rejected_quantity" type="number" step="0.0001" min="0"
                       class="form-control @error('rejected_quantity') is-invalid @enderror"
                       value="{{ old('rejected_quantity', $receipt?->rejected_quantity ?? 0) }}">
                @error('rejected_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-2">
                <label for="scrap_quantity" class="form-label">Scrap</label>
                <input id="scrap_quantity" name="scrap_quantity" type="number" step="0.0001" min="0"
                       class="form-control @error('scrap_quantity') is-invalid @enderror"
                       value="{{ old('scrap_quantity', $receipt?->scrap_quantity ?? 0) }}">
                @error('scrap_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $receipt?->notes) }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

        </div>
    </div>

    {{-- ── Output Lines (batches / lots) ────────────────────────────────── --}}
    <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="mb-0">Output Batches / Lots <span class="text-muted small fw-normal">(optional — tracking only)</span></h6>
            <button type="button" id="add-line-btn" class="btn btn-sm btn-outline-primary">
                <i class="ti ti-plus me-1"></i>Add Line
            </button>
        </div>
        <div class="card-body">
            @error('lines') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

            <div class="table-responsive">
                <table class="table table-sm align-middle" id="lines-table">
                    <thead class="thead-light">
                        <tr>
                            <th style="min-width:240px;">Finished Product <span class="text-danger">*</span></th>
                            <th style="min-width:100px;">Unit</th>
                            <th style="min-width:110px;">Batch</th>
                            <th style="min-width:110px;">Lot</th>
                            <th style="min-width:100px;">Received <span class="text-danger">*</span></th>
                            <th style="min-width:90px;">Accepted</th>
                            <th style="min-width:90px;">Rejected</th>
                            <th style="min-width:90px;">Scrap</th>
                            <th style="min-width:140px;">Expiry</th>
                            <th style="width:44px;"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                    @foreach($rows as $i => $row)
                        @php $fpid = $row['finished_product_id'] ?? null; @endphp
                        <tr class="fg-line-row">
                            <td>
                                <select name="lines[{{ $i }}][finished_product_id]" required
                                        class="ajax-select2 form-select form-select-sm"
                                        data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search product…" data-min-input="1">
                                    @if($fpid)
                                        <option value="{{ $fpid }}" selected>{{ $row['_text'] ?? ($productOptions[$fpid] ?? ('#' . $fpid)) }}</option>
                                    @endif
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
                            <td><input name="lines[{{ $i }}][batch_no]" type="text" value="{{ $row['batch_no'] ?? '' }}" class="form-control form-control-sm" placeholder="batch"></td>
                            <td><input name="lines[{{ $i }}][lot_no]" type="text" value="{{ $row['lot_no'] ?? '' }}" class="form-control form-control-sm" placeholder="lot"></td>
                            <td><input name="lines[{{ $i }}][received_quantity]" type="number" step="0.0001" min="0.0001" required value="{{ $row['received_quantity'] ?? '' }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][accepted_quantity]" type="number" step="0.0001" min="0" value="{{ $row['accepted_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][rejected_quantity]" type="number" step="0.0001" min="0" value="{{ $row['rejected_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][scrap_quantity]" type="number" step="0.0001" min="0" value="{{ $row['scrap_quantity'] ?? 0 }}" class="form-control form-control-sm"></td>
                            <td><input name="lines[{{ $i }}][expiry_date]" type="date" value="{{ $row['expiry_date'] ?? '' }}" class="form-control form-control-sm"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger remove-line-btn" title="Remove"><i class="ti ti-x"></i></button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="text-muted small mb-0 mt-1"><i class="ti ti-info-circle me-1"></i>Output lines are optional tracking detail. Accepted + Rejected + Scrap cannot exceed Received. This does NOT increase inventory.</p>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary">{{ $receipt ? 'Update Finished Goods' : 'Save Finished Goods' }}</button>
        <a href="{{ url('/manufacturing/finished-goods' . ($receipt ? '/' . $receipt->id : '')) }}" class="btn btn-light ms-2">Cancel</a>
    </div>
</form>

{{-- Row template for new lines (product select is AJAX, initialised on add) --}}
<template id="line-row-template">
    <tr class="fg-line-row">
        <td>
            <select name="lines[__IDX__][finished_product_id]" required
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
        <td><input name="lines[__IDX__][batch_no]" type="text" class="form-control form-control-sm" placeholder="batch"></td>
        <td><input name="lines[__IDX__][lot_no]" type="text" class="form-control form-control-sm" placeholder="lot"></td>
        <td><input name="lines[__IDX__][received_quantity]" type="number" step="0.0001" min="0.0001" required class="form-control form-control-sm" placeholder="0.0000"></td>
        <td><input name="lines[__IDX__][accepted_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][rejected_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][scrap_quantity]" type="number" step="0.0001" min="0" value="0" class="form-control form-control-sm"></td>
        <td><input name="lines[__IDX__][expiry_date]" type="date" class="form-control form-control-sm"></td>
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
