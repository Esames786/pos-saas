{{-- One BOM line. $k = its key (or "__K__" in the template); $l = its values. --}}
@php
    $n = "bom[lines][{$k}]";
    $e = "bom.lines.{$k}";
@endphp
<tr data-ws-row>
    <td class="ws-item" data-label="Material">
        <input type="hidden" name="{{ $n }}[id]" value="{{ $l['id'] ?? '' }}">
        <label class="visually-hidden" for="bl-{{ $k }}-item">Material</label>
        <select class="form-select @error($e . '.component_product_id') is-invalid @enderror" id="bl-{{ $k }}-item" name="{{ $n }}[component_product_id]" data-f="item">
            <option value="">Choose a material…</option>
            @foreach($ws['bom']['options'] as $o)
                <option value="{{ $o->id }}" data-unit="{{ $o->unit?->code }}" @selected((string) ($l['component_product_id'] ?? '') === (string) $o->id)>{{ $o->name }}</option>
            @endforeach
        </select>
    </td>
    <td class="ws-qty" data-label="Quantity">
        <label class="visually-hidden" for="bl-{{ $k }}-qty">Quantity</label>
        <input type="number" step="any" min="0" inputmode="decimal" class="form-control @error($e . '.quantity') is-invalid @enderror" id="bl-{{ $k }}-qty" name="{{ $n }}[quantity]" value="{{ $l['quantity'] ?? '' }}">
    </td>
    <td class="ws-unitcell" data-label="Unit">
        <label class="visually-hidden" for="bl-{{ $k }}-unit">Unit</label>
        <select class="form-select @error($e . '.unit_id') is-invalid @enderror" id="bl-{{ $k }}-unit" name="{{ $n }}[unit_id]" data-f="unit">
            <option value="" data-own>its own unit</option>
            @foreach($ws['units'] as $u)<option value="{{ $u->id }}" @selected((string) ($l['unit_id'] ?? '') === (string) $u->id)>{{ $u->code }}</option>@endforeach
        </select>
    </td>
    <td class="ws-qty" data-label="Usual waste %">
        <label class="visually-hidden" for="bl-{{ $k }}-waste">Usual waste %</label>
        <input type="number" step="any" min="0" max="100" inputmode="decimal" class="form-control @error($e . '.wastage_percent') is-invalid @enderror" id="bl-{{ $k }}-waste" name="{{ $n }}[wastage_percent]" value="{{ $l['wastage_percent'] ?? '0' }}">
    </td>
    <td class="ws-rm"><button type="button" class="btn btn-sm btn-outline-danger" data-ws-remove>Remove<span class="visually-hidden"> this material</span></button></td>
</tr>
