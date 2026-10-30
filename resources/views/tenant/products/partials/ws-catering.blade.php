{{-- W3/W4 S3c — a dish's event costing (its catering profile and cost blocks), edited inside the product form and saved
     with it (ProductWorkspace). Two parts, each with its own "_was": catering[…] for the profile, cost_blocks[lines][…]
     for the blocks. What is not shown here (profile switches, labels, pricing; each block's rate basis, rate source and
     role) keeps its saved value. --}}
@php
    $c = $ws['catering'];
    $cFromDb = $errors->has('catering.stale') || ! is_array(old('catering'));
    $cWas = $cFromDb ? $c['was'] : old('catering._was', $c['was']);
    $cv = fn (string $f) => $cFromDb ? $c['state'][$f] : old('catering.' . $f);
    $mode = $cv('costing_mode') ?: 'recipe';
    $blocksOn = isset($ws['cost_blocks']);
    if ($blocksOn) {
        $bk = $ws['cost_blocks'];
        $bFromDb = $errors->has('cost_blocks.stale') || ! is_array(old('cost_blocks'));
        $bWas = $bFromDb ? $bk['was'] : old('cost_blocks._was', $bk['was']);
        $blines = $bFromDb ? $bk['state']['lines'] : array_filter((array) old('cost_blocks.lines', []), 'is_array');
    }
    $messages = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'catering.') || str_starts_with($k, 'cost_blocks.'))->flatten()->unique();
    // Open for a dish — a sold item (the page script follows a card change).
    $wsOn = ($cardDef['kind'] ?? null) === \App\Models\Tenant\Product::KIND_SALE_ITEM;
    $plate = $product?->unit?->code ?? 'unit';
@endphp
<fieldset class="step" data-ws="dish">
    <legend>Event costing</legend>
    <div data-ws-off @if($wsOn) hidden @endif>
        <p class="help mb-0">Only a dish sold at events has event costing. @if($c['profile']) Its saved costing is kept as it is. @endif</p>
    </div>
    <div data-ws-on @unless($wsOn) hidden @endunless>
        <input type="hidden" name="catering[_was]" value="{{ $cWas }}">
        @if($messages->isNotEmpty())
            <div class="ws-errors" role="alert">
                @foreach($messages as $m)<div class="text-danger small">{{ $m }}</div>@endforeach
            </div>
        @endif
        <div class="row g-3 mb-2">
            <div class="col-12">
                <span class="form-label d-block" id="cat-mode-label">Priced by</span>
                <div class="yn" role="radiogroup" aria-labelledby="cat-mode-label">
                    <input type="radio" name="catering[costing_mode]" id="cat-mode-recipe" value="recipe" @checked($mode === 'recipe')><label for="cat-mode-recipe">Recipe</label>
                    <input type="radio" name="catering[costing_mode]" id="cat-mode-blocks" value="blocks" @checked($mode === 'blocks')><label for="cat-mode-blocks">Cost blocks</label>
                </div>
                <div class="help mt-1">Switching is refused while the other one is not ready to cost the dish, as on the Catering screen.</div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="cat-rate">Catering rate (Rs)</label>
                <input type="number" step="any" min="0" inputmode="decimal" class="form-control @error('catering.default_catering_rate') is-invalid @enderror" id="cat-rate" name="catering[default_catering_rate]" value="{{ $cv('default_catering_rate') }}">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="cat-unit">Quoted in</label>
                <select class="form-select" id="cat-unit" name="catering[default_quote_unit_id]">
                    <option value="">{{ $plate }} (the dish's unit)</option>
                    @foreach($ws['units'] as $u)<option value="{{ $u->id }}" @selected((string) $cv('default_quote_unit_id') === (string) $u->id)>{{ $u->code }}</option>@endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="cat-min">Smallest order</label>
                <input type="number" step="any" min="0" inputmode="decimal" class="form-control @error('catering.minimum_qty') is-invalid @enderror" id="cat-min" name="catering[minimum_qty]" value="{{ $cv('minimum_qty') }}">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="cat-station">Kitchen station</label>
                <input type="text" maxlength="50" class="form-control @error('catering.production_station') is-invalid @enderror" id="cat-station" name="catering[production_station]" value="{{ $cv('production_station') }}" placeholder="e.g. Rice, BBQ">
            </div>
        </div>

        <h3 class="h6 mt-3 mb-1">Cost blocks</h3>
        @if($blocksOn)
            <div data-ws-editor="cost_blocks">
                <input type="hidden" name="cost_blocks[_was]" value="{{ $bWas }}">
                <div class="table-responsive">
                    <table class="table ws-table align-middle mb-2">
                        <thead><tr><th scope="col">Block</th><th scope="col">Kind</th><th scope="col">Material · per {{ $plate }}</th><th scope="col">Rate (Rs)</th><th scope="col">Charged</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead>
                        <tbody data-ws-rows>
                            @foreach($blines as $k => $l)
                                @include('tenant.products.partials.ws-block-row', ['k' => $k, 'l' => $l])
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <template data-ws-template>@include('tenant.products.partials.ws-block-row', ['k' => '__K__', 'l' => []])</template>
                <p class="help mb-2" data-ws-empty @if($blines) hidden @endif>No cost blocks yet.</p>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <button type="button" class="btn btn-outline-primary" data-ws-add>Add a cost block</button>
                    <span class="help">Saved with the dish — the same rules as the Cost blocks screen. A block that follows the house rate book keeps that rate; link or unlink it on the Cost blocks screen.</span>
                </div>
            </div>
        @else
            @php $ro = $ws['cost_blocks_readonly'] ?? collect(); @endphp
            @if($ro->isEmpty())
                <p class="help mb-0">No cost blocks. Changing cost blocks needs the Cost blocks permission.</p>
            @else
                <ul class="small mb-1">@foreach($ro as $blk)<li>{{ $blk->label }} — Rs {{ rtrim(rtrim(number_format((float) $blk->rate, 2), '0'), '.') }}</li>@endforeach</ul>
                <p class="help mb-0">Kept as saved — changing cost blocks needs the Cost blocks permission.</p>
            @endif
        @endif
    </div>
</fieldset>
