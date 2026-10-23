{{-- One recipe line. $k = its key (a saved row's position, or "__K__" in the template); $l = its values. --}}
@php
    $n = "recipe[lines][{$k}]";
    $e = "recipe.lines.{$k}";
    $override = ($l['id'] ?? null) ? ($ws['recipe']['overrides'][$l['id']] ?? null) : null;
@endphp
<tr data-ws-row @if($override !== null) data-override="{{ $override }}" data-override-item="{{ $l['product_id'] ?? '' }}" @endif>
    <td class="ws-item" data-label="Ingredient">
        <input type="hidden" name="{{ $n }}[id]" value="{{ $l['id'] ?? '' }}">
        <label class="visually-hidden" for="rl-{{ $k }}-item">Ingredient</label>
        <select class="form-select @error($e . '.product_id') is-invalid @enderror" id="rl-{{ $k }}-item" name="{{ $n }}[product_id]" data-f="item">
            <option value="">Choose an ingredient…</option>
            @foreach($ws['recipe']['options'] as $o)
                <option value="{{ $o->id }}" data-unit="{{ $o->unit?->code }}" data-price="{{ round((float) $o->default_purchase_price / ((float) ($o->purchase_pack_size ?: 0) ?: 1.0), 4) }}" @selected((string) ($l['product_id'] ?? '') === (string) $o->id)>{{ $o->name }}</option>
            @endforeach
        </select>
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
