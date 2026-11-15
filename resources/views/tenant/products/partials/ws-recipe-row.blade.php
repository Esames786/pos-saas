{{-- One recipe line. $k = its key (a saved row's position, or "__K__" in the template); $l = its values. --}}
@php
    $n = "recipe[lines][{$k}]";
    $e = "recipe.lines.{$k}";
@endphp
<tr data-ws-row>
    <td class="ws-item" data-label="Ingredient">
        <input type="hidden" name="{{ $n }}[id]" value="{{ $l['id'] ?? '' }}">
        <label class="visually-hidden" for="rl-{{ $k }}-item">Ingredient</label>
        <select class="form-select @error($e . '.product_id') is-invalid @enderror" id="rl-{{ $k }}-item" name="{{ $n }}[product_id]" data-f="item">
            <option value="">Choose an ingredient…</option>
            @foreach($ws['recipe']['options'] as $o)
                <option value="{{ $o->id }}" data-unit="{{ $o->unit?->code }}" data-pack="{{ (float) ($o->purchase_pack_size ?: 1) }}" data-price="{{ round((float) $o->default_purchase_price / ((float) ($o->purchase_pack_size ?: 0) ?: 1.0), 4) }}" @selected((string) ($l['product_id'] ?? '') === (string) $o->id)>{{ $o->name }}</option>
            @endforeach
        </select>
        <details class="ws-advanced mt-2" @if($errors->has($e . '.cost_override') || $errors->has($e . '.applicable_order_types') || $errors->has($e . '.applicable_order_types.*')) open @endif>
            <summary>Ingredient settings</summary>
            <label class="form-label mt-2" for="rl-{{ $k }}-override">Cost override (Rs per purchase pack)</label>
            <input id="rl-{{ $k }}-override" type="number" min="0" step="0.0001" class="form-control"
                name="{{ $n }}[cost_override]" value="{{ $l['cost_override'] ?? '' }}" placeholder="Use purchase cost" data-f="override">
            <div class="help">Leave empty to use the ingredient's purchase cost. Zero is a free ingredient.</div>
            <fieldset class="mt-2">
                <legend class="form-label">Use for these order types</legend>
                <input type="hidden" name="{{ $n }}[applicable_order_types]" value="">
                <div class="d-flex flex-wrap gap-2">
                    @foreach(\App\Models\Tenant\RecipeIngredient::ORDER_TYPES as $type => $label)
                        @if($type !== 'all')
                            <label class="d-inline-flex align-items-center gap-1" for="rl-{{ $k }}-{{ $type }}">
                                <input type="checkbox" id="rl-{{ $k }}-{{ $type }}" name="{{ $n }}[applicable_order_types][]" value="{{ $type }}"
                                    @checked(in_array($type, (array) ($l['applicable_order_types'] ?? []), true))> {{ $label }}
                            </label>
                        @endif
                    @endforeach
                </div>
                <div class="help">None selected = all order types.</div>
            </fieldset>
        </details>
    </td>
    <td class="ws-qty" data-label="Quantity">
        <label class="visually-hidden" for="rl-{{ $k }}-qty">Quantity</label>
        <input type="number" step="any" min="0" inputmode="decimal" class="form-control @error($e . '.quantity') is-invalid @enderror" id="rl-{{ $k }}-qty" name="{{ $n }}[quantity]" value="{{ $l['quantity'] ?? '' }}" data-f="qty">
    </td>
    <td class="ws-unitcell" data-label="Unit">
        <label class="visually-hidden" for="rl-{{ $k }}-unit">Unit</label>
        <select class="form-select @error($e . '.unit_id') is-invalid @enderror" id="rl-{{ $k }}-unit" name="{{ $n }}[unit_id]" data-f="unit">
            <option value="" data-own>its own unit</option>
            @foreach($ws['units'] as $u)<option value="{{ $u->id }}" @selected((string) ($l['unit_id'] ?? '') === (string) $u->id)>{{ $u->code }}</option>@endforeach
        </select>
    </td>
    <td class="ws-part" data-label="Part of the cost">
        <label class="visually-hidden" for="rl-{{ $k }}-part">Part of the cost</label>
        <select class="form-select" id="rl-{{ $k }}-part" name="{{ $n }}[line_section]">
            @foreach($ws['recipe']['sections'] as $sv => $sl)<option value="{{ $sv }}" @selected(($l['line_section'] ?? 'food_cost') === $sv)>{{ $sl }}</option>@endforeach
        </select>
    </td>
    <td class="ws-cost text-end" data-label="Cost" data-f="cost">—</td>
    <td class="ws-rm"><button type="button" class="btn btn-sm btn-outline-danger" data-ws-remove>Remove<span class="visually-hidden"> this ingredient</span></button></td>
</tr>
