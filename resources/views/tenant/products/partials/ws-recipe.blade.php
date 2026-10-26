{{-- W3/W4 S3c — the recipe, edited inside the product form and saved with it (App\Services\Products\ProductWorkspace).
     Rows are posted as recipe[lines][key][…]; "_was" is the fingerprint of what this tab showed, so an untouched recipe
     is not written and one changed on the Recipes screen meanwhile is refused. --}}
@php
    $r = $ws['recipe'];
    $fromDb = $errors->has('recipe.stale') || ! is_array(old('recipe'));
    $was = $fromDb ? $r['was'] : old('recipe._was', $r['was']);
    $yieldQ = $fromDb ? $r['state']['yield_quantity'] : old('recipe.yield_quantity');
    $yieldU = $fromDb ? $r['state']['yield_unit_id'] : old('recipe.yield_unit_id');
    $lines = $fromDb ? $r['state']['lines'] : array_filter((array) old('recipe.lines', []), 'is_array');
    $messages = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'recipe.'))->flatten()->unique();
    $ownUnit = $product?->unit?->code ?? 'its own unit';
    // Open when the chosen card asks “made from a recipe?” and the answer is Yes (the page script follows later changes).
    $wsOn = ! empty($cardDef['ask']['recipe']) && $ans['recipe'];
@endphp
<fieldset class="step" data-ws="recipe">
    <legend>Recipe</legend>
    <div data-ws-off @if($wsOn) hidden @endif>
        <p class="help mb-0">Only an item made from other items has a recipe. To give this item one, answer <b>Yes</b> to “Is it made from other items?” on <b>Selling &amp; stock</b>.
            @if($r['recipe']) Its saved recipe is kept as it is. @endif</p>
    </div>
    <div data-ws-on data-ws-editor="recipe" @unless($wsOn) hidden @endunless>
        <input type="hidden" name="recipe[_was]" value="{{ $was }}">
        @if($messages->isNotEmpty())
            <div class="ws-errors" role="alert">
                @foreach($messages as $m)<div class="text-danger small">{{ $m }}</div>@endforeach
            </div>
        @endif
        @if($r['recipe'] && ! $r['recipe']->is_active)
            <p class="note mb-2">This recipe is switched off on the Recipes screen. Saving here keeps it switched off.</p>
        @endif
        <div class="ws-head">
            <div class="ws-make">
                <label class="form-label mb-0" for="recipe-yield">This recipe makes</label>
                <input type="number" step="any" min="0" class="form-control ws-num @error('recipe.yield_quantity') is-invalid @enderror" id="recipe-yield" name="recipe[yield_quantity]" value="{{ $yieldQ }}">
                <label class="visually-hidden" for="recipe-yield-unit">Unit it makes</label>
                <select class="form-select ws-unit" id="recipe-yield-unit" name="recipe[yield_unit_id]">
                    <option value="">{{ $ownUnit }}</option>
                    @foreach($ws['units'] as $u)<option value="{{ $u->id }}" @selected((string) $yieldU === (string) $u->id)>{{ $u->code }}</option>@endforeach
                </select>
            </div>
            <div class="ws-total">Recipe cost <b data-ws-cost>—</b></div>
        </div>
        <div class="table-responsive">
            <table class="table ws-table align-middle mb-2">
                <thead><tr><th scope="col">Ingredient</th><th scope="col">Quantity</th><th scope="col">Unit</th><th scope="col">Part of the cost</th><th scope="col" class="text-end">Cost</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead>
                <tbody data-ws-rows>
                    @foreach($lines as $k => $l)
                        @include('tenant.products.partials.ws-recipe-row', ['k' => $k, 'l' => $l])
                    @endforeach
                </tbody>
            </table>
        </div>
        <template data-ws-template>@include('tenant.products.partials.ws-recipe-row', ['k' => '__K__', 'l' => []])</template>
        <p class="help mb-2" data-ws-empty @if($lines) hidden @endif>No ingredients yet.</p>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-outline-primary" data-ws-add>Add an ingredient</button>
            <span class="help">Saved with the product when you press Save — the same rules and cost as the Recipes screen. Cost overrides, order types and report numbers set there stay as they are.</span>
        </div>
        {{-- PRODUCT-NAV-1: each link only for a user who may open that screen. --}}
        @if($r['recipe'] && (\App\Support\ScreenAccess::allows('tenant.recipes.show') || \App\Support\ScreenAccess::allows('tenant.recipes.edit')))
            <p class="help mt-2 mb-0">
                @if(\App\Support\ScreenAccess::allows('tenant.recipes.show'))
                    <a href="{{ url('/recipes/' . $r['recipe']->id) }}" target="_blank" rel="noopener" data-ws-full="recipe-report">Open the full recipe report</a>.
                @endif
                @if(\App\Support\ScreenAccess::allows('tenant.recipes.edit'))
                    Order types, cost overrides and report numbers: <a href="{{ url('/recipes/' . $r['recipe']->id . '/edit') }}" target="_blank" rel="noopener" data-ws-full="recipe-edit">edit them on the Recipes screen</a>.
                @endif
                (A new tab — this page keeps what you typed.)
            </p>
        @endif
    </div>
</fieldset>
