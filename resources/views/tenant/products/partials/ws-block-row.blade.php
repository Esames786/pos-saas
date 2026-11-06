{{-- One cost block. $k = its key (or "__K__" in the template); $l = its values; $plate = the dish's unit; $q(key, what) = a "?" button.
     COST-BLOCK-CLARITY-1 (5 Nov): what it is comes FIRST (Material / Charge, two picture buttons) and decides the rest of the
     row, its name under it; then material and how much of it goes into one unit of the dish, rate and what the rate is for
     (said in words under it), how it is charged, and what the block adds to one unit of the dish. Under it, one line with only the setting its kind has: a
     material's rate source, a charge's "is this the Making charge?". Every input has a "?" (texts once, in ws-catering).
     Field names and values are the ones the form always posted, so a block left alone posts back exactly what was saved. --}}
@php
    $n = "cost_blocks[lines][{$k}]";
    $e = "cost_blocks.lines.{$k}";
    $isMaterial = ($l['block_type'] ?? 'charge') === \App\Models\Tenant\CateringProductCostBlock::TYPE_MATERIAL;
    $linked = ($l['commercial_rate_source'] ?? null) === 'commercial_book';
    // A saved block always has its basis; a new one is priced per unit of the material (what the writer gives a new material).
    $basis = ($l['rate_basis'] ?? null) ?: 'per_material_unit';
    $making = ($l['charge_role'] ?? null) === 'making';
    $saved = ! empty($l['id']);
@endphp
<tbody class="cb-block" data-ws-row data-block @if($saved) data-saved-type="{{ $isMaterial ? 'material' : 'charge' }}" data-saved-basis="{{ $basis }}" @endif>
<tr class="cb-main">
    <td class="cb-kind" data-label="What is it? · Name">
        <input type="hidden" name="{{ $n }}[id]" value="{{ $l['id'] ?? '' }}">
        <div class="cb-field">
            <div class="cb-kinds" role="radiogroup" aria-label="What is this block?">
                <input type="radio" id="cb-{{ $k }}-t-m" name="{{ $n }}[block_type]" value="material" data-f="type" @checked($isMaterial)>
                <label for="cb-{{ $k }}-t-m"><span class="cb-pic" aria-hidden="true">🥩</span>Material</label>
                <input type="radio" id="cb-{{ $k }}-t-c" name="{{ $n }}[block_type]" value="charge" data-f="type" @checked(! $isMaterial)>
                <label for="cb-{{ $k }}-t-c"><span class="cb-pic" aria-hidden="true">👨‍🍳</span>Charge</label>
            </div>
            {!! $q('kind', 'Material or Charge') !!}
        </div>
        <div class="cb-field mt-2">
            <label class="visually-hidden" for="cb-{{ $k }}-label">Block name</label>
            <input type="text" maxlength="120" class="form-control @error($e . '.label') is-invalid @enderror" id="cb-{{ $k }}-label" name="{{ $n }}[label]" value="{{ $l['label'] ?? '' }}" placeholder="e.g. Chicken, Making" data-f="label">
            {!! $q('name', 'the name') !!}
        </div>
    </td>
    <td class="cb-mat" data-label="Material · how much in 1 {{ $plate }}">
        <div data-only-material @unless($isMaterial) hidden @endunless>
            <div class="cb-field mb-1">
                <label class="visually-hidden" for="cb-{{ $k }}-mat">Material</label>
                <select class="form-select @error($e . '.material_product_id') is-invalid @enderror" id="cb-{{ $k }}-mat" name="{{ $n }}[material_product_id]" data-f="mat">
                    <option value="">Choose a material…</option>
                    @foreach($ws['cost_blocks']['materials'] as $o)
                        <option value="{{ $o->id }}" data-unit="{{ $o->unit?->code }}" data-unit-id="{{ $o->unit_id }}" @selected((string) ($l['material_product_id'] ?? '') === (string) $o->id)>{{ $o->name }}</option>
                    @endforeach
                </select>
                {!! $q('material', 'the material') !!}
            </div>
            <div class="cb-field">
                <label class="visually-hidden" for="cb-{{ $k }}-qty">How much of the material goes into 1 {{ $plate }} of the dish</label>
                <input type="number" step="any" min="0" inputmode="decimal" class="form-control cb-num @error($e . '.quantity_per_unit') is-invalid @enderror" id="cb-{{ $k }}-qty" name="{{ $n }}[quantity_per_unit]" value="{{ $l['quantity_per_unit'] ?? '' }}" placeholder="how much" data-f="qty">
                <label class="visually-hidden" for="cb-{{ $k }}-unit">Unit of the material</label>
                <select class="form-select cb-unit @error($e . '.unit_id') is-invalid @enderror" id="cb-{{ $k }}-unit" name="{{ $n }}[unit_id]" data-f="unit">
                    <option value="">unit</option>
                    @foreach($ws['units'] as $u)<option value="{{ $u->id }}" @selected((string) ($l['unit_id'] ?? '') === (string) $u->id)>{{ $u->code }}</option>@endforeach
                </select>
                {!! $q('qty', 'how much') !!}
            </div>
            <div class="help cb-sub">in 1 {{ $plate }} of the dish</div>
        </div>
        <span class="help" data-only-charge @if($isMaterial) hidden @endif>— no material</span>
    </td>
    <td class="cb-rate" data-label="Rate (Rs)">
        <div class="cb-field">
            <label class="visually-hidden" for="cb-{{ $k }}-rate">Rate (Rs)</label>
            <input type="number" step="any" min="0" inputmode="decimal" class="form-control cb-num @error($e . '.rate') is-invalid @enderror" id="cb-{{ $k }}-rate" name="{{ $n }}[rate]" value="{{ $l['rate'] ?? '' }}" data-f="rate" @readonly($isMaterial && $linked)>
            {!! $q('rate', 'the rate') !!}
        </div>
        <div class="cb-field mt-1" data-only-material @unless($isMaterial) hidden @endunless>
            <label class="visually-hidden" for="cb-{{ $k }}-rate-basis">The rate is for</label>
            <select class="form-select form-select-sm @error($e . '.rate_basis') is-invalid @enderror" id="cb-{{ $k }}-rate-basis" name="{{ $n }}[rate_basis]" data-f="rate-basis">
                <option value="per_material_unit" data-per="material" @selected($basis === 'per_material_unit')>per unit of material</option>
                <option value="per_dish_unit" data-per="dish" @selected($basis === 'per_dish_unit')>per {{ $plate }} of dish</option>
            </select>
            {!! $q('basis', 'what the rate is for') !!}
        </div>
        <div class="help cb-sub cb-words" data-f="rate-words"></div>
        <div class="help cb-sub" data-book-note @unless($isMaterial && $linked) hidden @endunless>From the house rate book (see "Rate from" below).</div>
    </td>
    <td class="cb-charged" data-label="Charged">
        <div class="cb-field">
            <label class="visually-hidden" for="cb-{{ $k }}-basis">Charged</label>
            <select class="form-select form-select-sm" id="cb-{{ $k }}-basis" name="{{ $n }}[charge_basis]" data-f="basis">
                <option value="per_unit" @selected(($l['charge_basis'] ?? 'per_unit') === 'per_unit')>every {{ $plate }}</option>
                <option value="lump_sum" @selected(($l['charge_basis'] ?? '') === 'lump_sum')>once per order</option>
            </select>
            {!! $q('charged', 'how it is charged') !!}
        </div>
    </td>
    <td class="cb-adds" data-label="1 {{ $plate }} adds"><output data-f="adds">—</output></td>
    <td class="ws-rm"><button type="button" class="btn btn-sm btn-outline-danger cb-rm" data-ws-remove title="Remove this block"><i class="ti ti-trash" aria-hidden="true"></i><span class="visually-hidden">Remove this block</span></button></td>
</tr>
<tr class="cb-more">
    <td colspan="6">
        <div class="cb-more-in" data-only-material @unless($isMaterial) hidden @endunless>
            <label class="cb-more-l" for="cb-{{ $k }}-source">Rate from</label>
            <div class="cb-field">
                <select class="form-select form-select-sm w-auto @error($e . '.commercial_rate_source') is-invalid @enderror" id="cb-{{ $k }}-source" name="{{ $n }}[commercial_rate_source]" data-f="rate-source">
                    <option value="manual" @selected(! $linked)>Own rate</option>
                    <option value="commercial_book" @selected($linked)>House rate book</option>
                </select>
                {!! $q('source', 'where the rate comes from') !!}
            </div>
        </div>
        <div class="cb-more-in" data-only-charge @if($isMaterial) hidden @endif>
            <span class="cb-more-l" id="cb-{{ $k }}-role-l">Is this the Making charge?</span>
            <div class="cb-field">
                <div class="yn yn-sm @error($e . '.charge_role') is-invalid @enderror" role="radiogroup" aria-labelledby="cb-{{ $k }}-role-l">
                    <input type="radio" id="cb-{{ $k }}-role-y" name="{{ $n }}[charge_role]" value="making" data-f="role" @checked($making)><label for="cb-{{ $k }}-role-y">Yes</label>
                    <input type="radio" id="cb-{{ $k }}-role-n" name="{{ $n }}[charge_role]" value="" data-f="role" @checked(! $making)><label for="cb-{{ $k }}-role-n">No</label>
                </div>
                {!! $q('making', 'the Making charge') !!}
            </div>
        </div>
    </td>
</tr>
</tbody>
