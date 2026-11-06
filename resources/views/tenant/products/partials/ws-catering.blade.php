{{-- W3/W4 S3c — a dish's event costing (its catering profile and cost blocks), edited inside the product form and saved
     with it (ProductWorkspace). Two parts, each with its own "_was": catering[…] for the profile, cost_blocks[lines][…]
     for the blocks. The dormant pricing mode keeps its saved value; each block's basis, source and role sit in its own
     row (COST-BLOCK-CLARITY-1, below). CATERING-SIMPLIFY-1 (27 Oct): the Catering Products pop-up's fields (on / off, party supply, free item,
     Urdu name, kitchen labels, instructions) are here too, so one Edit edits the whole dish. --}}
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

        {{-- CATERING-SIMPLIFY-1: what the Catering Products pop-up edited, saved through the same writer. --}}
        @php
            $yes = fn (string $f) => filter_var($cv($f), FILTER_VALIDATE_BOOLEAN);
            $switches = [
                'catering_enabled'   => ['Use it at events?', 'No = a new quotation does not fill in its price and cost blocks.', ''],
                'allow_party_supply' => ['Customer may bring own meat?', 'Yes = a quotation line can mark the meat as given by the customer.', ''],
                'is_complimentary'   => ['Free item?', 'Yes = a new quotation line for it starts at Rs 0.', ''],
            ];
        @endphp
        <div class="row g-3 mb-2" data-ws-profile>
            @foreach($switches as $f => [$q, $help, $ur])
                <div class="col-md-4">
                    <span class="form-label d-block" id="cat-{{ $f }}-label">{{ $q }} @if($ur)<span class="ur-inline">{{ $ur }}</span>@endif</span>
                    <div class="yn" role="radiogroup" aria-labelledby="cat-{{ $f }}-label">
                        <input type="radio" name="catering[{{ $f }}]" id="cat-{{ $f }}-y" value="1" @checked($yes($f))><label for="cat-{{ $f }}-y">Yes</label>
                        <input type="radio" name="catering[{{ $f }}]" id="cat-{{ $f }}-n" value="0" @checked(! $yes($f))><label for="cat-{{ $f }}-n">No</label>
                    </div>
                    <div class="help mt-1">{{ $help }}</div>
                </div>
            @endforeach
            <div class="col-md-4">
                <label class="form-label" for="cat-name-ur">Urdu name</label>
                <input type="text" maxlength="255" dir="rtl" lang="ur" class="form-control @error('catering.name_ur') is-invalid @enderror" id="cat-name-ur" name="catering[name_ur]" value="{{ $cv('name_ur') }}">
                <div class="help">On the kitchen sheet and the quotation. Empty = the English name.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="cat-label">Kitchen label (English)</label>
                <input type="text" maxlength="255" class="form-control @error('catering.production_label') is-invalid @enderror" id="cat-label" name="catering[production_label]" value="{{ $cv('production_label') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="cat-label-ur">Kitchen label (Urdu)</label>
                <input type="text" maxlength="255" dir="rtl" lang="ur" class="form-control @error('catering.production_label_ur') is-invalid @enderror" id="cat-label-ur" name="catering[production_label_ur]" value="{{ $cv('production_label_ur') }}">
            </div>
            <div class="col-12">
                <label class="form-label" for="cat-instructions">Default instructions</label>
                <textarea maxlength="2000" rows="2" class="form-control @error('catering.instructions') is-invalid @enderror" id="cat-instructions" name="catering[instructions]">{{ $cv('instructions') }}</textarea>
            </div>
        </div>

        <h3 class="h6 mt-3 mb-1">Cost blocks</h3>
        @if($blocksOn)
            {{-- COST-BLOCK-CLARITY-1 (5 Nov): each block is its own <tbody> (its row + the one setting its kind has), so the
                 table is the rows' container. Every input has a "?" ($q); the texts are written once below and shown in
                 one box. Under the blocks, a sample works out a booking the way the event screen does
                 (CateringCostBlockService::priceLine), live as the blocks are typed. English-only, as the whole form. --}}
            @php
                $q = fn (string $key, string $what) => '<button type="button" class="cb-q" data-cb-help="' . e($key) . '" aria-label="What is ' . e($what) . '?" aria-expanded="false" aria-controls="cb-pop">?</button>';
                $helps = [
                    'kind' => 'Choose this first. Material = something you buy and put in the dish, like chicken, rice or oil. Charge = money for work or a service, like making, a live counter or transport.',
                    'name' => 'A short name you will recognise on the booking and the quotation. Example: Chicken, Making, Live counter.',
                    'material' => 'The item from your materials list that goes into this dish.',
                    'qty' => "How much of the material goes into 1 {$plate} of this dish, and in which unit. Example: 0.5 KG chicken in 1 KG biryani. The event's material needs are worked out from this too.",
                    'rate' => 'The price in rupees. Never leave it 0 — the booking will show 0 for it too. If the price is not fixed yet, put 1 and type the customer\'s rate on the booking.',
                    'basis' => "What the rate is the price of. Per unit of material = the material's own price (chicken Rs 700 a KG), and the dish pays rate × how much. Per {$plate} of dish = what this material adds to 1 {$plate} of the dish. The line under it says it in full words. Changing it changes the price — check the sample below.",
                    'charged' => "Every {$plate} = multiplied by the booking quantity. Once per order = the same amount whatever the quantity, like a live counter. A once-per-order charge is not in the dish rate; it is added to the booking total one time.",
                    'source' => 'Own rate = the number typed in Rate. House rate book = the rate comes from your materials rate list; the current rate is taken when you Save. Later changes to the rate list are not applied by themselves — they are offered in Rate Impact.',
                    'making' => 'Yes = this is the dish\'s making (cooking) charge. The dish then also shows in Making Adjustment, where you change the making rate of many dishes at once. A dish can have only one Making charge.',
                    'adds' => "What this block adds to the rate of 1 {$plate} of the dish. Once-per-order charges are not in it; they are added to the booking once.",
                    'sample' => 'Type a quantity to see what a booking of that size charges, worked out the same way as the event screen. Nothing here is saved.',
                ];
            @endphp
            <div class="cb-editor" data-ws-editor="cost_blocks" data-plate="{{ $plate }}">
                <input type="hidden" name="cost_blocks[_was]" value="{{ $bWas }}">
                <div class="table-responsive">
                    <table class="table ws-table ws-blocks align-middle mb-2" data-ws-rows>
                        <thead><tr><th scope="col">What is it? · Name</th><th scope="col">Material · how much in 1 {{ $plate }}</th><th scope="col">Rate (Rs)</th><th scope="col">Charged</th><th scope="col">1 {{ $plate }} adds {!! $q('adds', '1 ' . $plate . ' adds') !!}</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead>
                        @foreach($blines as $k => $l)
                            @include('tenant.products.partials.ws-block-row', ['k' => $k, 'l' => $l])
                        @endforeach
                    </table>
                </div>
                <template data-ws-template>@include('tenant.products.partials.ws-block-row', ['k' => '__K__', 'l' => []])</template>
                <p class="help mb-2" data-ws-empty @if($blines) hidden @endif>No cost blocks yet.</p>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <button type="button" class="btn btn-outline-primary" data-ws-add>Add a cost block</button>
                    <span class="help">Saved with the dish when you press Save. Tap <span class="cb-q cb-q-static" aria-hidden="true">?</span> next to any box to see what it means.</span>
                </div>

                <section class="cb-sample" data-cb-sample aria-labelledby="cb-sample-h">
                    <div class="cb-sample-head">
                        <h4 class="cb-sample-title" id="cb-sample-h">How the event screen will charge it</h4>
                        <div class="cb-field">
                            <label for="cb-sample-qty">Booking of</label>
                            <input type="number" min="0" step="any" inputmode="decimal" class="form-control form-control-sm cb-num" id="cb-sample-qty" value="10" data-cb-sample-qty>
                            <span>{{ $plate }}</span>
                            {!! $q('sample', 'this sample') !!}
                        </div>
                    </div>
                    <table class="cb-sample-lines"><tbody data-cb-sample-lines></tbody></table>
                    <div class="cb-sample-total" data-cb-sample-total aria-live="polite"></div>
                    <ul class="cb-warn" data-cb-warn></ul>
                    <p class="help mb-0">A sample only — nothing here is saved. Meat the customer brings and a rate typed on the booking itself are not shown.</p>
                </section>

                <div class="cb-pop" id="cb-pop" role="status" hidden>
                    <button type="button" class="cb-pop-x" data-cb-pop-close aria-label="Close">×</button>
                    <p class="mb-0" data-cb-pop-text></p>
                </div>
                <div hidden data-cb-helps>
                    @foreach($helps as $key => $text)<p data-key="{{ $key }}">{{ $text }}</p>@endforeach
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
        {{-- PRODUCT-NAV-1: what this tab does not change, on its own screen — only for a user who may open it.
             (CATERING-SIMPLIFY-1: on / off, labels and instructions are on this tab now; only the blocks' roles are not.) --}}
        @php
            $cProfile = $c['profile'] ?? null;
            $cBlocksOk = $cProfile && \App\Support\ScreenAccess::allows('tenant.catering.cost-blocks.edit');
        @endphp
        @if($cBlocksOk)
            <p class="help mt-2 mb-0">
                <a href="{{ url('/catering/profiles/' . $cProfile->id . '/blocks') }}" target="_blank" rel="noopener" data-ws-full="cost-blocks">Open the standalone cost-block view</a>.
                (A new tab — this page keeps what you typed.)
            </p>
        @endif
    </div>
</fieldset>
