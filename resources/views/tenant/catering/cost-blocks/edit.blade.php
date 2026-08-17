@extends('layouts.app')

@section('title', 'Cost Blocks — ' . $profile->product->name)

@section('content')
@php
    $unitCode = $profile->defaultQuoteUnit?->code ?? $profile->product->unit?->code ?? 'unit';
    $isActiveSource = $profile->usesBlocks();
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-0">Cost Blocks</h1>
        <div class="text-muted">
            {{ $profile->product->name }}
            <span class="fs-12">· {{ $profile->product->sku }}</span>
        </div>
    </div>
    <a href="{{ url('/catering/profiles') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1"></i>Back to Catering Products
    </a>
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', [
    'manages' => 'What a dish is made of, and what each part adds to its price.',
    'managesUr' => 'ایک ڈش کن حصوں سے بنتی ہے اور ہر حصہ قیمت میں کیا جوڑتا ہے۔',
    'reversible' => 'safe',
    'note' => 'Editing blocks does not reprice a quotation that has already been sent. Nothing is posted and no stock moves.',
    'noteUr' => 'بلاک میں تبدیلی سے بھیجا ہوا تخمینہ دوبارہ قیمت نہیں لگاتا۔',
])

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

@unless($isActiveSource)
    {{-- Blocks may be configured while the recipe is still the authority. Saying
         so prevents the opposite belief, which is the dangerous one: an operator
         carefully editing blocks that decide nothing. --}}
    <div class="alert alert-secondary d-flex align-items-start gap-2">
        <i class="ti ti-info-circle mt-1"></i>
        <div>
            This dish is currently costed from its <strong>recipe</strong>, so these blocks are stored but
            do not decide anything yet.
            <span class="d-block fs-12 mt-1">
                Configure them here, then change <strong>Costing Source</strong> to Cost Blocks on the
                Catering Products screen. The recipe stays stored either way.
            </span>
        </div>
    </div>
@endunless

@if($isActiveSource && ! $readiness['ready'])
    <div class="alert alert-danger">
        <strong><i class="ti ti-alert-triangle me-1"></i>This dish cannot be quoted yet.</strong>
        <ul class="mb-0 mt-2">
            @foreach($readiness['blockers'] as $blocker)
                <li>{{ $blocker }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(! empty($readiness['warnings']))
    <div class="alert alert-warning">
        <ul class="mb-0">
            @foreach($readiness['warnings'] as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ url('/catering/profiles/' . $profile->id . '/blocks') }}">
    @csrf
    @method('PUT')

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h5 class="mb-0">Parts of this dish</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" id="add-material">
                    <i class="ti ti-plus me-1"></i>Add material
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="add-charge">
                    <i class="ti ti-plus me-1"></i>Add charge
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="blocks-table">
                    <thead>
                        <tr>
                            <th style="min-width:150px">Part</th>
                            <th style="min-width:190px">Material</th>
                            <th class="text-end" style="min-width:120px">
                                Charged per {{ $unitCode }}
                                <i class="ti ti-help-circle text-muted" data-bs-toggle="tooltip"
                                   title="What the customer pays for this part, for each unit of the finished dish."></i>
                            </th>
                            <th style="min-width:120px">Basis</th>
                            <th class="text-end" style="min-width:140px">
                                Material per {{ $unitCode }}
                                <i class="ti ti-help-circle text-muted" data-bs-toggle="tooltip"
                                   title="How much of the material one unit of the dish actually uses. 0.5 means a 10 KG order draws 5 KG."></i>
                            </th>
                            <th style="min-width:100px">Unit</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="blocks-body">
                        @foreach($blocks as $block)
                        <tr class="block-row" data-type="{{ $block->block_type }}">
                            <td>
                                <input type="hidden" name="blocks[{{ $loop->index }}][id]" value="{{ $block->id }}">
                                <input type="hidden" name="blocks[{{ $loop->index }}][block_type]" value="{{ $block->block_type }}" class="f-type">
                                <input type="text" name="blocks[{{ $loop->index }}][label]" value="{{ $block->label }}"
                                       class="form-control form-control-sm" required maxlength="120">
                                <span class="badge bg-{{ $block->isMaterial() ? 'info' : 'secondary' }}-subtle text-{{ $block->isMaterial() ? 'info' : 'secondary' }}-emphasis fs-12 mt-1">
                                    {{ $block->isMaterial() ? 'Material' : 'Charge' }}
                                </span>
                            </td>
                            <td>
                                @if($block->isMaterial())
                                    <select name="blocks[{{ $loop->index }}][material_product_id]" class="form-select form-select-sm" required>
                                        <option value="">Choose a material…</option>
                                        @foreach($materials as $material)
                                            <option value="{{ $material->id }}" @selected($material->id === $block->material_product_id)>{{ $material->name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="text-muted fs-12">Not a material — money only</span>
                                @endif
                            </td>
                            <td>
                                <input type="number" step="0.0001" min="0" name="blocks[{{ $loop->index }}][rate]"
                                       value="{{ (float) $block->rate }}" class="form-control form-control-sm text-end f-rate" required>
                            </td>
                            <td>
                                <select name="blocks[{{ $loop->index }}][charge_basis]" class="form-select form-select-sm f-basis">
                                    <option value="per_unit" @selected($block->charge_basis === 'per_unit')>Per {{ $unitCode }}</option>
                                    <option value="lump_sum" @selected($block->charge_basis === 'lump_sum')>Lump sum</option>
                                </select>
                            </td>
                            <td>
                                @if($block->isMaterial())
                                    <input type="number" step="0.0001" min="0" name="blocks[{{ $loop->index }}][quantity_per_unit]"
                                           value="{{ $block->quantity_per_unit !== null ? (float) $block->quantity_per_unit : '' }}"
                                           class="form-control form-control-sm text-end" placeholder="e.g. 0.5">
                                @else
                                    <span class="text-muted fs-12">—</span>
                                @endif
                            </td>
                            <td>
                                @if($block->isMaterial())
                                    <select name="blocks[{{ $loop->index }}][unit_id]" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        @foreach($units as $unit)
                                            <option value="{{ $unit->id }}" @selected($unit->id === $block->unit_id)>{{ $unit->code }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="text-muted fs-12">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-light remove-block" title="Remove this part">
                                    <i class="ti ti-x"></i>
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div id="no-blocks" class="text-center text-muted py-4 {{ $blocks->isNotEmpty() ? 'd-none' : '' }}">
                No parts yet. Add the materials this dish uses and the charges on top of them.
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="text-muted fs-12">
                Removing a part keeps it on record as inactive, so quotations that already used it still make sense.
            </div>
            <button type="submit" class="btn btn-primary">Save cost blocks</button>
        </div>
    </div>
</form>

{{-- ── What this dish costs and what it charges ──────────────────────────
     Two numbers that look alike and are not. Showing them apart is the only
     way an operator sees that a 10 KG order bills 2,000 of chicken while five
     kilos leave the store. --}}
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0">Preview</h5>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 fs-12 text-muted">Order size</label>
            <input type="number" step="0.001" min="0" value="10" id="preview-qty" class="form-control form-control-sm" style="width:100px">
            <span class="text-muted fs-12">{{ $unitCode }}</span>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-7">
                <table class="table table-sm mb-0" id="preview-table">
                    <thead>
                        <tr>
                            <th>Part</th>
                            <th class="text-end">Charged</th>
                            <th class="text-end">Material drawn</th>
                        </tr>
                    </thead>
                    <tbody id="preview-body"></tbody>
                    <tfoot>
                        <tr class="fw-bold border-top">
                            <td>Customer pays</td>
                            <td class="text-end" id="preview-total">0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="col-md-5">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted fs-12">Selling rate</div>
                    <div class="fs-3 fw-bold" id="preview-rate">0.00</div>
                    <div class="text-muted fs-12">per {{ $unitCode }} — lump sums are excluded, since they do not scale</div>

                    <hr>

                    <div class="text-muted fs-12">
                        <i class="ti ti-alert-circle"></i>
                        <strong>Charged is not cost.</strong> What the customer pays for a material and how much
                        of it the kitchen draws are separate numbers. The material's real cost comes from the
                        <a href="{{ url('/catering/material-rates') }}">Material Rate Book</a>, and the gap
                        between the two is the margin.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Row template, kept out of the form so its inputs are never submitted. --}}
<template id="block-template">
    <tr class="block-row">
        <td>
            <input type="hidden" name="__i__[id]" value="">
            <input type="hidden" name="__i__[block_type]" value="__TYPE__" class="f-type">
            <input type="text" name="__i__[label]" class="form-control form-control-sm" required maxlength="120" placeholder="e.g. chicken">
            <span class="badge fs-12 mt-1 __BADGE__">__TYPELABEL__</span>
        </td>
        <td class="cell-material"></td>
        <td>
            <input type="number" step="0.0001" min="0" name="__i__[rate]" value="0" class="form-control form-control-sm text-end f-rate" required>
        </td>
        <td>
            <select name="__i__[charge_basis]" class="form-select form-select-sm f-basis">
                <option value="per_unit">Per {{ $unitCode }}</option>
                <option value="lump_sum">Lump sum</option>
            </select>
        </td>
        <td class="cell-qty"></td>
        <td class="cell-unit"></td>
        <td class="text-end">
            <button type="button" class="btn btn-sm btn-light remove-block" title="Remove this part"><i class="ti ti-x"></i></button>
        </td>
    </tr>
</template>
@endsection

@push('scripts')
<script>
$(function () {
    @php
        $materialOptions = '<option value="">Choose a material…</option>';
        foreach ($materials as $material) {
            $materialOptions .= '<option value="'.$material->id.'">'.e($material->name).'</option>';
        }
        $unitOptions = '<option value="">—</option>';
        foreach ($units as $unit) {
            $unitOptions .= '<option value="'.$unit->id.'">'.e($unit->code).'</option>';
        }
    @endphp
    const materialOptions = @json($materialOptions);
    const unitOptions = @json($unitOptions);

    function addRow(type) {
        const index = $('#blocks-body .block-row').length;
        const prefix = 'blocks[' + index + ']';
        const isMaterial = type === 'material';

        let html = $('#block-template').html()
            .replace(/__i__/g, prefix)
            .replace(/__TYPE__/g, type)
            .replace(/__TYPELABEL__/g, isMaterial ? 'Material' : 'Charge')
            .replace(/__BADGE__/g, isMaterial
                ? 'bg-info-subtle text-info-emphasis'
                : 'bg-secondary-subtle text-secondary-emphasis');

        const $row = $(html);
        $row.attr('data-type', type);

        if (isMaterial) {
            $row.find('.cell-material').html(
                '<select name="' + prefix + '[material_product_id]" class="form-select form-select-sm" required>' + materialOptions + '</select>');
            $row.find('.cell-qty').html(
                '<input type="number" step="0.0001" min="0" name="' + prefix + '[quantity_per_unit]" class="form-control form-control-sm text-end" placeholder="e.g. 0.5">');
            $row.find('.cell-unit').html(
                '<select name="' + prefix + '[unit_id]" class="form-select form-select-sm">' + unitOptions + '</select>');
        } else {
            $row.find('.cell-material').html('<span class="text-muted fs-12">Not a material — money only</span>');
            $row.find('.cell-qty').html('<span class="text-muted fs-12">—</span>');
            $row.find('.cell-unit').html('<span class="text-muted fs-12">—</span>');
        }

        $('#blocks-body').append($row);
        $('#no-blocks').addClass('d-none');
        preview();
    }

    $('#add-material').on('click', () => addRow('material'));
    $('#add-charge').on('click', () => addRow('charge'));

    $(document).on('click', '.remove-block', function () {
        $(this).closest('tr').remove();
        reindex();
        if ($('#blocks-body .block-row').length === 0) $('#no-blocks').removeClass('d-none');
        preview();
    });

    // Names must stay contiguous or Laravel's blocks.*.field rules skip a row.
    function reindex() {
        $('#blocks-body .block-row').each(function (i) {
            $(this).find('[name]').each(function () {
                this.name = this.name.replace(/blocks\[\d+\]/, 'blocks[' + i + ']');
            });
        });
    }

    function preview() {
        const qty = parseFloat($('#preview-qty').val()) || 0;
        let total = 0, perUnit = 0;
        const rows = [];

        $('#blocks-body .block-row').each(function () {
            const $r = $(this);
            const label = $r.find('[name$="[label]"]').val() || '(unnamed)';
            const rate = parseFloat($r.find('.f-rate').val()) || 0;
            const lump = $r.find('.f-basis').val() === 'lump_sum';
            const ratio = parseFloat($r.find('[name$="[quantity_per_unit]"]').val()) || 0;

            // A lump sum ignores quantity entirely — that is the whole point of
            // it — and never enters the per-unit rate, where it would be wrong
            // at every size except the one it was divided by.
            const amount = lump ? rate : rate * qty;
            total += amount;
            if (!lump) perUnit += rate;

            rows.push({
                label: label,
                amount: amount,
                drawn: ratio > 0 ? (ratio * qty) : null,
                lump: lump,
            });
        });

        $('#preview-body').html(rows.length ? rows.map(r =>
            '<tr><td>' + $('<div>').text(r.label).html() +
            (r.lump ? ' <span class="badge bg-light text-muted fs-12">once</span>' : '') +
            '</td><td class="text-end">' + r.amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) +
            '</td><td class="text-end text-muted">' +
            (r.drawn === null ? '—' : r.drawn.toLocaleString(undefined, {maximumFractionDigits: 4})) +
            '</td></tr>').join('') : '<tr><td colspan="3" class="text-center text-muted py-3">Nothing to preview yet.</td></tr>');

        $('#preview-total').text(total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#preview-rate').text(perUnit.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    }

    $(document).on('input change', '#blocks-body input, #blocks-body select, #preview-qty', preview);
    preview();
});
</script>
@endpush
