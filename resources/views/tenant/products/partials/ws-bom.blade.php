{{-- W3/W4 S3c — the bill of materials, edited inside the product form and saved with it (ProductWorkspace).
     Rows are posted as bom[lines][key][…]; "_was" lets an untouched BOM stay unwritten (material requisitions point at
     its line ids) and refuses one changed on the Bill of materials screen meanwhile. --}}
@php
    $b = $ws['bom'];
    $fromDb = $errors->has('bom.stale') || ! is_array(old('bom'));
    $was = $fromDb ? $b['was'] : old('bom._was', $b['was']);
    $makes = $fromDb ? $b['state']['output_quantity'] : old('bom.output_quantity');
    $lines = $fromDb ? $b['state']['lines'] : array_filter((array) old('bom.lines', []), 'is_array');
    $messages = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'bom.'))->flatten()->unique();
    // Open for a card made in the factory (the page script follows a card change).
    $wsOn = ($cardDef['mfg'] ?? null) === 'finished';
@endphp
<fieldset class="step" data-ws="bom">
    <legend>Bill of materials</legend>
    <div data-ws-off @if($wsOn) hidden @endif>
        <p class="help mb-0">Only a product made in your factory has a bill of materials. @if($b['bom']) Its saved one is kept as it is. @endif</p>
    </div>
    <div data-ws-on data-ws-editor="bom" @unless($wsOn) hidden @endunless>
        <input type="hidden" name="bom[_was]" value="{{ $was }}">
        @if($messages->isNotEmpty())
            <div class="ws-errors" role="alert">
                @foreach($messages as $m)<div class="text-danger small">{{ $m }}</div>@endforeach
            </div>
        @endif
        @if($b['bom'] && $b['bom']->status !== 'active')
            <p class="note mb-2">This bill of materials is <b>{{ $b['bom']->status }}</b> on its own screen. Saving here keeps it {{ $b['bom']->status }}.</p>
        @endif
        <div class="ws-head">
            <div class="ws-make">
                <label class="form-label mb-0" for="bom-makes">These materials make</label>
                <input type="number" step="any" min="0" class="form-control ws-num @error('bom.output_quantity') is-invalid @enderror" id="bom-makes" name="bom[output_quantity]" value="{{ $makes }}">
                <span class="help">{{ $product?->unit?->code ?? 'of this product' }}</span>
            </div>
            @if($b['bom'])<div class="ws-total">{{ $b['bom']->bom_no }} · version {{ $b['bom']->version }}</div>@endif
        </div>
        <div class="table-responsive">
            <table class="table ws-table align-middle mb-2">
                <thead><tr><th scope="col">Material</th><th scope="col">Quantity</th><th scope="col">Unit</th><th scope="col">Usual waste %</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead>
                <tbody data-ws-rows>
                    @foreach($lines as $k => $l)
                        @include('tenant.products.partials.ws-bom-row', ['k' => $k, 'l' => $l])
                    @endforeach
                </tbody>
            </table>
        </div>
        <template data-ws-template>@include('tenant.products.partials.ws-bom-row', ['k' => '__K__', 'l' => []])</template>
        <p class="help mb-2" data-ws-empty @if($lines) hidden @endif>No materials yet.</p>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-outline-primary" data-ws-add>Add a material</button>
            <span class="help">Saved with the product when you press Save — the same rules as the Bill of materials screen. Its number, version, status and notes stay as they are.</span>
        </div>
    </div>
</fieldset>
