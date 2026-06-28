{{-- UX-POLISH-1: compact per-line "Order Scope" multi-select. A single dropdown
     button whose label summarises the choice; the menu holds the actual
     ingredients[$i][applicable_order_types][] inputs (hidden checkboxes — no visible
     checkbox widgets, selection shown with a check icon). Unchanged backend format.
     Inputs: $i (row index), $otSel (stored applicable_order_types array|null). --}}
@php
    $otSel = array_values(array_filter((array) ($otSel ?? []), fn ($v) => $v !== 'all'));
    $otLabels = \App\Models\Tenant\RecipeIngredient::ORDER_TYPES;
    $otSummary = empty($otSel)
        ? 'All Orders'
        : implode(', ', array_map(fn ($v) => $otLabels[$v] ?? $v, $otSel));
@endphp
<div class="col-12 ot-wrap d-flex align-items-center flex-wrap gap-2">
    <small class="text-muted"><i class="ti ti-receipt me-1"></i>Order Scope:</small>
    <div class="os-scope position-relative">
        <button type="button" class="btn btn-sm btn-outline-secondary os-scope-btn d-inline-flex align-items-center">
            <span class="os-scope-label">{{ $otSummary }}</span>
            <i class="ti ti-chevron-down ms-2"></i>
        </button>
        <div class="os-scope-menu border rounded bg-white shadow-sm p-1" style="display:none; position:absolute; z-index:1050; min-width:190px;">
            @foreach($otLabels as $otv => $otl)
                <label class="os-item d-flex justify-content-between align-items-center px-2 py-1 rounded mb-0">
                    <input type="checkbox" class="ot-check d-none" name="ingredients[{{ $i }}][applicable_order_types][]" value="{{ $otv }}" @checked($otv === 'all' ? empty($otSel) : in_array($otv, $otSel))>
                    <span class="small">{{ $otl }}</span>
                    <i class="ti ti-check os-check ms-3"></i>
                </label>
            @endforeach
        </div>
    </div>
</div>
