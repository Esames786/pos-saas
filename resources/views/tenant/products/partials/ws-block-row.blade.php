{{-- One cost block. $k = its key (or "__K__" in the template); $l = its values. --}}
@php
    $n = "cost_blocks[lines][{$k}]";
    $e = "cost_blocks.lines.{$k}";
    $linked = ($l['commercial_rate_source'] ?? null) === 'commercial_book';
    $isMaterial = ($l['block_type'] ?? 'charge') === \App\Models\Tenant\CateringProductCostBlock::TYPE_MATERIAL;
@endphp
<tr data-ws-row data-block>
    <td class="ws-label" data-label="Block">
        <input type="hidden" name="{{ $n }}[id]" value="{{ $l['id'] ?? '' }}">
        <label class="visually-hidden" for="cb-{{ $k }}-label">Block name</label>
        <input type="text" maxlength="120" class="form-control @error($e . '.label') is-invalid @enderror" id="cb-{{ $k }}-label" name="{{ $n }}[label]" value="{{ $l['label'] ?? '' }}" placeholder="e.g. Chicken, Making">
        <details class="ws-advanced mt-2" @if($errors->has('cost_blocks.lines') || $errors->has($e . '.rate_basis') || $errors->has($e . '.commercial_rate_source') || $errors->has($e . '.charge_role')) open @endif>
            <summary>Pricing settings</summary>
            <div data-only-material @unless($isMaterial) hidden @endunless>
                <label class="form-label mt-2" for="cb-{{ $k }}-rate-basis">Rate is per</label>
                <select id="cb-{{ $k }}-rate-basis" class="form-select" name="{{ $n }}[rate_basis]" data-f="rate-basis">
                    <option value="" @selected(empty($l['rate_basis']))>Keep default basis</option>
                    <option value="per_material_unit" @selected(($l['rate_basis'] ?? '') === 'per_material_unit')>Unit of material</option>
                    <option value="per_dish_unit" @selected(($l['rate_basis'] ?? '') === 'per_dish_unit')>Unit of finished dish</option>
                </select>
                <div class="help">Changing the basis changes how the rate is calculated. Check the amount before saving.</div>
                <label class="form-label mt-2" for="cb-{{ $k }}-source">Rate source</label>
                <select id="cb-{{ $k }}-source" class="form-select" name="{{ $n }}[commercial_rate_source]" data-f="rate-source">
                    <option value="" @selected(empty($l['commercial_rate_source']))>Keep current source (new: manual)</option>
                    <option value="manual" @selected(($l['commercial_rate_source'] ?? '') === 'manual')>Manual rate</option>
                    <option value="commercial_book" @selected($linked)>House rate book</option>
                </select>
                <div class="help">Linking adopts the current house rate on Save. Later book changes require a separate rate application.</div>
            </div>
            <div data-only-charge @if($isMaterial) hidden @endif>
                <label class="form-label mt-2" for="cb-{{ $k }}-role">Charge role</label>
                <select id="cb-{{ $k }}-role" class="form-select" name="{{ $n }}[charge_role]">
                    <option value="" @selected(empty($l['charge_role']))>General charge</option>
                    <option value="making" @selected(($l['charge_role'] ?? '') === 'making')>Making</option>
                </select>
                <div class="help">Only one Making charge is allowed per dish.</div>
            </div>
        </details>
    </td>
    <td class="ws-part" data-label="Kind">
        <label class="visually-hidden" for="cb-{{ $k }}-type">Kind</label>
        <select class="form-select" id="cb-{{ $k }}-type" name="{{ $n }}[block_type]" data-f="type">
            <option value="charge" @selected(! $isMaterial)>Charge</option>
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
        <input type="number" step="any" min="0" inputmode="decimal" class="form-control @error($e . '.rate') is-invalid @enderror" id="cb-{{ $k }}-rate" name="{{ $n }}[rate]" value="{{ $l['rate'] ?? '' }}" data-f="rate" @readonly($linked)>
        <div class="help" data-book-note @unless($linked) hidden @endunless>House rate book; choose Manual rate to unlink.</div>
    </td>
    <td class="ws-part" data-label="Charged">
        <label class="visually-hidden" for="cb-{{ $k }}-basis">Charged</label>
        <select class="form-select" id="cb-{{ $k }}-basis" name="{{ $n }}[charge_basis]">
            <option value="per_unit" @selected(($l['charge_basis'] ?? 'per_unit') === 'per_unit')>per unit</option>
            <option value="lump_sum" @selected(($l['charge_basis'] ?? '') === 'lump_sum')>once per order</option>
        </select>
    </td>
    <td class="ws-rm"><button type="button" class="btn btn-sm btn-outline-danger" data-ws-remove>Remove<span class="visually-hidden"> this block</span></button></td>
</tr>
