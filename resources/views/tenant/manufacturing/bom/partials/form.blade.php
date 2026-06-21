{{-- Shared BOM form partial (create + edit) --}}
@php
    $existingLines           = $bom?->lines ?? collect();
    $lineCount               = $existingLines->count();
    $componentOptionsById    = $componentOptionsById ?? [];
    $selectedFinishedProduct = $selectedFinishedProduct ?? null;
    $productAjaxUrl          = url('/ajax/products');
@endphp

<form method="POST"
      action="{{ $bom ? url('/manufacturing/bom/' . $bom->id) : url('/manufacturing/bom') }}"
      novalidate>
    @csrf
    @if($bom) @method('PUT') @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    {{-- ── Header card ──────────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-header"><h6 class="mb-0">BOM Details</h6></div>
        <div class="card-body row g-3">

            <div class="col-md-4">
                <label for="bom_no" class="form-label">BOM No</label>
                <input id="bom_no" name="bom_no"
                       class="form-control @error('bom_no') is-invalid @enderror"
                       value="{{ old('bom_no', $bom?->bom_no ?? $nextNo) }}"
                       placeholder="{{ $nextNo ?? 'BOM-000001' }}">
                <div class="form-text">Auto-generated if blank.</div>
                @error('bom_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="version" class="form-label required">Version</label>
                <input id="version" name="version" required
                       class="form-control @error('version') is-invalid @enderror"
                       value="{{ old('version', $bom?->version ?? '1.0') }}">
                @error('version') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" required
                        class="select form-select @error('status') is-invalid @enderror">
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected(old('status', $bom?->status ?? 'draft') === $s)>
                            {{ ucfirst($s) }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text">Setting Active deactivates other BOMs for the same product.</div>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="finished_product_id" class="form-label required">Finished Product</label>
                <select id="finished_product_id" name="finished_product_id" required
                        class="ajax-select2 form-select @error('finished_product_id') is-invalid @enderror"
                        data-ajax-url="{{ $productAjaxUrl }}"
                        data-placeholder="Search finished product…"
                        data-min-input="1">
                    @if($selectedFinishedProduct)
                        <option value="{{ $selectedFinishedProduct['id'] }}" selected>{{ $selectedFinishedProduct['text'] }}</option>
                    @endif
                </select>
                <div class="form-text">Type to search by SKU or name.</div>
                @error('finished_product_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="output_quantity" class="form-label required">Output Qty (batch size)</label>
                <input id="output_quantity" name="output_quantity" type="number"
                       step="0.0001" min="0.0001" required
                       class="form-control @error('output_quantity') is-invalid @enderror"
                       value="{{ old('output_quantity', $bom?->output_quantity ?? 1) }}">
                <div class="form-text">Units produced per one BOM run.</div>
                @error('output_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="effective_from" class="form-label">Effective From</label>
                <input id="effective_from" name="effective_from" type="date"
                       class="form-control @error('effective_from') is-invalid @enderror"
                       value="{{ old('effective_from', $bom?->effective_from?->toDateString()) }}">
                @error('effective_from') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-8">
                <label for="name" class="form-label">BOM Name (optional label)</label>
                <input id="name" name="name"
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $bom?->name) }}"
                       placeholder="e.g. Standard formula, Summer batch…">
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2"
                          class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $bom?->notes) }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

        </div>
    </div>

    {{-- ── Component Lines ──────────────────────────────────────────────── --}}
    <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="mb-0">Component Lines <span class="text-muted small fw-normal">(raw materials / consumables)</span></h6>
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
                            <th style="min-width:120px;">Unit</th>
                            <th style="min-width:110px;">Qty <span class="text-danger">*</span></th>
                            <th style="min-width:110px;">Wastage %</th>
                            <th style="min-width:160px;">Notes</th>
                            <th style="width:44px;"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                    @if($existingLines->count())
                        @foreach($existingLines as $i => $line)
                        @php $cid = $line->component_product_id; @endphp
                        <tr class="bom-line-row">
                            <td>
                                <select name="lines[{{ $i }}][component_product_id]" required
                                        class="ajax-select2 form-select form-select-sm"
                                        data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search component…" data-min-input="1">
                                    @if($cid)
                                        <option value="{{ $cid }}" selected>{{ $componentOptionsById[$cid] ?? ($line->componentProduct?->sku . ' — ' . $line->componentProduct?->name) }}</option>
                                    @endif
                                </select>
                            </td>
                            <td>
                                <select name="lines[{{ $i }}][unit_id]" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach($units as $u)
                                        <option value="{{ $u->id }}" @selected($u->id == $line->unit_id)>
                                            {{ $u->code }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input name="lines[{{ $i }}][quantity]" type="number"
                                       step="0.0001" min="0.0001" required
                                       value="{{ old('lines.' . $i . '.quantity', $line->quantity) }}"
                                       class="form-control form-control-sm">
                            </td>
                            <td>
                                <input name="lines[{{ $i }}][wastage_percent]" type="number"
                                       step="0.01" min="0" max="100"
                                       value="{{ old('lines.' . $i . '.wastage_percent', $line->wastage_percent) }}"
                                       class="form-control form-control-sm"
                                       placeholder="0">
                            </td>
                            <td>
                                <input name="lines[{{ $i }}][notes]" type="text"
                                       value="{{ old('lines.' . $i . '.notes', $line->notes) }}"
                                       class="form-control form-control-sm" placeholder="optional">
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger remove-line-btn"
                                        title="Remove"><i class="ti ti-x"></i></button>
                            </td>
                        </tr>
                        @endforeach
                    @elseif(old('lines'))
                        @foreach(old('lines') as $i => $line)
                        @php $cid = $line['component_product_id'] ?? null; @endphp
                        <tr class="bom-line-row">
                            <td>
                                <select name="lines[{{ $i }}][component_product_id]" required
                                        class="ajax-select2 form-select form-select-sm"
                                        data-ajax-url="{{ $productAjaxUrl }}" data-placeholder="Search component…" data-min-input="1">
                                    @if($cid)
                                        <option value="{{ $cid }}" selected>{{ $componentOptionsById[$cid] ?? ('#' . $cid) }}</option>
                                    @endif
                                </select>
                            </td>
                            <td>
                                <select name="lines[{{ $i }}][unit_id]" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach($units as $u)
                                        <option value="{{ $u->id }}" @selected($u->id == ($line['unit_id'] ?? ''))>
                                            {{ $u->code }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input name="lines[{{ $i }}][quantity]" type="number" step="0.0001" min="0.0001" required
                                       value="{{ $line['quantity'] ?? '' }}" class="form-control form-control-sm">
                            </td>
                            <td>
                                <input name="lines[{{ $i }}][wastage_percent]" type="number" step="0.01" min="0" max="100"
                                       value="{{ $line['wastage_percent'] ?? 0 }}" class="form-control form-control-sm" placeholder="0">
                            </td>
                            <td>
                                <input name="lines[{{ $i }}][notes]" type="text" value="{{ $line['notes'] ?? '' }}"
                                       class="form-control form-control-sm" placeholder="optional">
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger remove-line-btn" title="Remove"><i class="ti ti-x"></i></button>
                            </td>
                        </tr>
                        @endforeach
                    @endif
                    </tbody>
                </table>
            </div>

            <p class="text-muted small mb-0 mt-1"><i class="ti ti-info-circle me-1"></i>At least 1 component required. A component cannot be the same as the finished product. Type to search products.</p>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary">{{ $bom ? 'Update BOM' : 'Save BOM' }}</button>
        <a href="{{ url('/manufacturing/bom' . ($bom ? '/' . $bom->id : '')) }}" class="btn btn-light ms-2">Cancel</a>
    </div>
</form>

{{-- Row template for new lines (component select is AJAX, initialised on add) --}}
<template id="line-row-template">
    <tr class="bom-line-row">
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
        <td>
            <input name="lines[__IDX__][quantity]" type="number" step="0.0001" min="0.0001" required
                   class="form-control form-control-sm" placeholder="0.0000">
        </td>
        <td>
            <input name="lines[__IDX__][wastage_percent]" type="number" step="0.01" min="0" max="100"
                   class="form-control form-control-sm" placeholder="0">
        </td>
        <td>
            <input name="lines[__IDX__][notes]" type="text"
                   class="form-control form-control-sm" placeholder="optional">
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger remove-line-btn" title="Remove">
                <i class="ti ti-x"></i>
            </button>
        </td>
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
        if (window.initAjaxSelect2) window.initAjaxSelect2(row); // init AJAX Select2 on the new row
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
