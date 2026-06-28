{{-- UX-POLISH-1: compact per-line "Order Scope" control. Drives the existing
     ingredients[$i][applicable_order_types][] checkboxes (kept for the same backend
     format). Presets cover the common cases; "Custom…" reveals the raw pills.
     Inputs: $i (row index), $otSel (stored applicable_order_types array|null). --}}
@php
    $otSel = array_values(array_filter((array) ($otSel ?? []), fn ($v) => $v !== 'all'));
    sort($otSel);
    $otPreset = match (true) {
        $otSel === ['dine_in']             => 'dine_in',
        $otSel === ['delivery', 'takeaway'] => 'takeaway_delivery',
        $otSel === ['takeaway']            => 'takeaway',
        $otSel === ['delivery']            => 'delivery',
        $otSel === ['quick_sale']          => 'quick_sale',
        $otSel === []                      => 'all',
        default                            => 'custom',
    };
    $otPresets = [
        'all'               => 'All Orders',
        'dine_in'           => 'Dine In only',
        'takeaway_delivery' => 'Takeaway + Delivery',
        'takeaway'          => 'Takeaway only',
        'delivery'          => 'Delivery only',
        'quick_sale'        => 'Quick Sale only',
        'custom'            => 'Custom…',
    ];
@endphp
<div class="col-12 ot-wrap d-flex align-items-center flex-wrap gap-2">
    <small class="text-muted"><i class="ti ti-receipt me-1"></i>Order Scope:</small>
    <select class="form-select form-select-sm os-preset" style="width:auto">
        @foreach($otPresets as $pv => $pl)
            <option value="{{ $pv }}" @selected($otPreset === $pv)>{{ $pl }}</option>
        @endforeach
    </select>
    <span class="os-detail d-flex flex-wrap gap-2" style="{{ $otPreset !== 'custom' ? 'display:none' : '' }}">
        @foreach(\App\Models\Tenant\RecipeIngredient::ORDER_TYPES as $otv => $otl)
            <label class="me-2 small mb-0"><input type="checkbox" class="form-check-input ot-check me-1" name="ingredients[{{ $i }}][applicable_order_types][]" value="{{ $otv }}" @checked($otv === 'all' ? empty($otSel) : in_array($otv, $otSel))>{{ $otl }}</label>
        @endforeach
    </span>
</div>
