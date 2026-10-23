{{-- One cost block. $k = its key (or "__K__" in the template); $l = its values. --}}
@php
    $n = "cost_blocks[lines][{$k}]";
    $e = "cost_blocks.lines.{$k}";
    $saved = ($l['id'] ?? null) ? ($ws['cost_blocks']['blocks'][$l['id']] ?? null) : null;
    $linked = $saved && $saved->followsCommercialBook();
    $isMaterial = ($l['block_type'] ?? 'charge') === \App\Models\Tenant\CateringProductCostBlock::TYPE_MATERIAL;
    $perDish = $saved && $saved->rateBasis() === \App\Models\Tenant\CateringProductCostBlock::RATE_PER_DISH_UNIT;
@endphp
<tr data-ws-row data-block>
    <td class="ws-label" data-label="Block">
        <input type="hidden" name="{{ $n }}[id]" value="{{ $l['id'] ?? '' }}">
        <label class="visually-hidden" for="cb-{{ $k }}-label">Block name</label>
        <input type="text" maxlength="120" class="form-control @error($e . '.label') is-invalid @enderror" id="cb-{{ $k }}-label" name="{{ $n }}[label]" value="{{ $l['label'] ?? '' }}" placeholder="e.g. Chicken, Making">
    </td>
    <td class="ws-part" data-label="Kind">
        <label class="visually-hidden" for="cb-{{ $k }}-type">Kind</label>
        <select class="form-select" id="cb-{{ $k }}-type" name="{{ $n }}[block_type]" data-f="type">
            <option value="charge" @selected(! $isMaterial)>Charge (work, gas…)</option>
            <option value="material" @selected($isMaterial)>Material</option>
        </select>
    </td>
    <td class="ws-mat" data-label="Material">
        <div data-only-material @unless($isMaterial) hidden @endunless>
            <label class="visually-hidden" for="cb-{{ $k }}-mat">Material</label>
            <select class="form-select mb-1 @error($e . '.material_product_id') is-invalid @enderror" id="cb-{{ $k }}-mat" name="{{ $n }}[material_product_id]">
                <option value="">Choose a material…</option>
                @foreach($ws['cost_blocks']['materials'] as $o)
                    <option value="{{ $o->id }}" @selected((string) ($l['material_product_id'] ?? '') === (string) $o->id)>{{ $o->name }}</option>
                @endforeach
            </select>
            <div class="d-flex gap-1">
                <label class="visually-hidden" for="cb-{{ $k }}-qty">Material used per dish unit</label>
                <input type="number" step="any" min="0" inputmode="decimal" class="form-control @error($e . '.quantity_per_unit') is-invalid @enderror" id="cb-{{ $k }}-qty" name="{{ $n }}[quantity_per_unit]" value="{{ $l['quantity_per_unit'] ?? '' }}" placeholder="used">
                <label class="visually-hidden" for="cb-{{ $k }}-unit">Unit</label>
                <select class="form-select @error($e . '.unit_id') is-invalid @enderror" id="cb-{{ $k }}-unit" name="{{ $n }}[unit_id]">
                    <option value="">unit</option>
                    @foreach($ws['units'] as $u)<option value="{{ $u->id }}" @selected((string) ($l['unit_id'] ?? '') === (string) $u->id)>{{ $u->code }}</option>@endforeach
                </select>
            </div>
        </div>
        <span class="help" data-only-charge @if($isMaterial) hidden @endif>—</span>
    </td>
    <td class="ws-qty" data-label="Rate (Rs)">
        <label class="visually-hidden" for="cb-{{ $k }}-rate">Rate (Rs)</label>
        @if($linked)
            <input type="hidden" name="{{ $n }}[rate]" value="{{ $l['rate'] ?? '' }}">
            <input type="text" class="form-control" id="cb-{{ $k }}-rate" value="{{ $l['rate'] ?? '' }}" readonly aria-describedby="cb-{{ $k }}-rate-says">
            <div class="help" id="cb-{{ $k }}-rate-says">follows the house rate book</div>
        @else
            <input type="number" step="any" min="0" inputmode="decimal" class="form-control @error($e . '.rate') is-invalid @enderror" id="cb-{{ $k }}-rate" name="{{ $n }}[rate]" value="{{ $l['rate'] ?? '' }}">
            <div class="help" data-only-material @unless($isMaterial) hidden @endunless>{{ $perDish ? 'per dish unit' : 'per unit of the material' }}</div>
        @endif
    </td>
    <td class="ws-part" data-label="Charged">
        <label class="visually-hidden" for="cb-{{ $k }}-basis">Charged</label>
        <select class="form-select" id="cb-{{ $k }}-basis" name="{{ $n }}[charge_basis]">
            <option value="per_unit" @selected(($l['charge_basis'] ?? 'per_unit') === 'per_unit')>for every unit</option>
            <option value="lump_sum" @selected(($l['charge_basis'] ?? '') === 'lump_sum')>once per order</option>
        </select>
    </td>
    <td class="ws-rm"><button type="button" class="btn btn-sm btn-outline-danger" data-ws-remove>Remove<span class="visually-hidden"> this block</span></button></td>
</tr>
