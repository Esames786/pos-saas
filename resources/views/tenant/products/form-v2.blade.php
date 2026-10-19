@extends('layouts.app')

@section('title', $title)

@php
    /*
     * PRODUCT-SCREEN-V2 — one simple screen to add AND edit a product (owner, 19 Oct).
     * Pictures first ("What are you adding?"), then a few yes/no questions in plain English with Urdu
     * under each; every answer opens only its own part. The server (App\Support\ProductCards::resolve)
     * turns the card + answers into the real fields, so this page's script only shows and hides —
     * correctness never depends on it.
     */
    $isEdit = (bool) $product;
    $formUrl = $isEdit ? url('/products/' . $product->id) : url('/products');
    $card = old('_card', $currentCard);
    $cardDef = $cards[$card] ?? reset($cards);
    $saved = fn (string $field, $default = null) => old($field, $product?->{$field} ?? $default);
    // The starting answer of a question: what was posted back, else the saved product, else the card's default.
    $answer = function (string $q, ?bool $fromProduct) use ($cardDef) {
        $rule = $cardDef['ask'][$q] ?? null;
        if ($rule === null) return false;
        if (isset($rule['lock'])) return (bool) $rule['default'];
        $old = old('_' . $q);
        if ($old !== null) return (bool) (int) $old;
        return $fromProduct ?? (bool) $rule['default'];
    };
    $ans = [
        'sell'   => $answer('sell', $isEdit ? (bool) $product->is_sellable : null),
        'buy'    => $answer('buy', $isEdit ? (bool) $product->is_purchasable : null),
        'stock'  => $answer('stock', $isEdit ? (bool) $product->is_stock_tracked : null),
        'expiry' => $answer('expiry', $isEdit ? (bool) $product->has_expiry : null),
        'recipe' => $answer('recipe', $isEdit ? $product->inventory_consumption_method === 'recipe' : null),
    ];
    $tax = (bool) (int) old('_tax', $isEdit && $product->is_taxable ? 1 : 0);
    $stockLocked = $isEdit && $hasStockHistory && $product->is_stock_tracked;
    $hasBarcode = (bool) (int) old('_barcode', $barcode || ($cardDef['barcode'] ?? false) ? 1 : 0);
    $nextCodes = collect($cards)->mapWithKeys(fn ($c) => [$c['key'] => \App\Support\ProductCards::nextSku($c['sku_prefix'])])->all();
    $weightUnitId = $units->first(fn ($u) => in_array($u->unit_type, ['weight', 'volume'], true))?->id;
    $questions = [
        'sell'   => ['Do you sell it to customers?', 'کیا آپ یہ گاہک کو بیچتے ہیں؟'],
        'buy'    => ['Do you buy it from a supplier?', 'کیا آپ یہ سپلائر سے خریدتے ہیں؟'],
        'stock'  => ['Do you want to count its stock?', 'کیا اس کا اسٹاک گننا ہے؟'],
        'expiry' => ['Does it have a batch and an expiry date?', 'کیا اس کا بیچ اور ایکسپائری ہوتی ہے؟'],
        'recipe' => ['Is it made from other items (a recipe)?', 'کیا یہ دوسری چیزوں سے بنتا ہے؟'],
    ];
@endphp

@push('styles')
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;600&display=swap">
<style>
    .pv2 { --navy:#092C4C; --gold:#B8902E; --goldbg:#FFF8E6; --line:#E3E8EE; --ink2:#4E5B6B; --ok:#1E7A50; --bad:#B42318; max-width:1240px; }
    .pv2 .ur { font-family:"Noto Nastaliq Urdu","Jameel Noori Nastaleeq",serif; direction:rtl; display:block; color:var(--ink2); font-size:.92rem; line-height:2.1; }
    .pv2 .ur-inline { font-family:"Noto Nastaliq Urdu","Jameel Noori Nastaleeq",serif; direction:rtl; unicode-bidi:isolate; color:var(--ink2); font-size:.85rem; }
    .pv2 .step { background:#fff; border:1px solid var(--line); border-radius:14px; padding:18px 20px; margin-bottom:14px; }
    .pv2 .step > legend, .pv2 .step-title { font-size:1.1rem; font-weight:800; color:var(--navy); float:none; width:auto; margin:0 0 4px; padding:0; }
    .pv2 .cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:12px; margin-top:10px; }
    .pv2 .pcard { position:relative; display:flex; flex-direction:column; align-items:center; gap:4px; text-align:center; border:2px solid var(--line); border-radius:14px; padding:14px 10px; cursor:pointer; background:#fff; min-height:180px; }
    .pv2 .pcard input { position:absolute; opacity:0; inset:0; cursor:pointer; }
    .pv2 .pcard img { width:72px; height:72px; }
    .pv2 .pcard b { font-size:1rem; color:#212B36; }
    .pv2 .pcard small { color:var(--ink2); }
    .pv2 .pcard .eg { color:#8A6A12; font-size:.8rem; }
    .pv2 .pcard:has(input:checked) { border-color:var(--gold); background:var(--goldbg); box-shadow:0 6px 18px rgba(184,144,46,.18); }
    .pv2 .pcard:has(input:focus-visible) { outline:3px solid var(--navy); outline-offset:2px; }
    .pv2 .q { border-top:1px solid var(--line); padding:14px 0 4px; }
    .pv2 .q:first-of-type { border-top:0; }
    .pv2 .q-head { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .pv2 .q-head .q-text { font-weight:700; font-size:1rem; }
    .pv2 .yn { display:inline-flex; border:1px solid #CBD3DE; border-radius:10px; overflow:hidden; }
    .pv2 .yn label { margin:0; padding:9px 18px; min-width:84px; text-align:center; cursor:pointer; font-weight:700; background:#fff; }
    .pv2 .yn input { position:absolute; opacity:0; width:1px; height:1px; }
    .pv2 .yn input:checked + label { background:var(--navy); color:#fff; }
    .pv2 .yn input:focus-visible + label { outline:3px solid var(--gold); outline-offset:-3px; }
    .pv2 .lock { background:#FFF4D6; color:#7A5300; border-radius:8px; padding:6px 10px; font-size:.88rem; }
    .pv2 .q-body { padding:12px 0 6px; }
    .pv2 .form-label { font-weight:700; }
    .pv2 .help { color:var(--ink2); font-size:.85rem; }
    .pv2 .what { position:sticky; top:12px; background:var(--navy); color:#fff; border-radius:14px; padding:16px 18px; }
    .pv2 .what h2 { font-size:1rem; color:#F2DFA8; margin:0 0 8px; font-weight:800; }
    .pv2 .what li { margin-bottom:6px; }
    .pv2 .note { background:var(--goldbg); border:1px solid #F2DFA8; border-radius:10px; padding:10px 12px; color:#6B5310; }
    .pv2 .btn-big { min-height:46px; padding:0 22px; font-weight:800; }
    .pv2 .input-group-lg .btn { font-size:1rem; font-weight:700; }
    .pv2 .bc-wrap svg { max-width:100%; height:70px; }
    .pv2 [hidden] { display:none !important; }
    @media (max-width: 991px) { .pv2 .what { position:static; } }
</style>
@endpush

@section('content')
<div class="pv2">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
        <div>
            <h1 class="mb-0">{{ $title }}</h1>
            <span class="ur">{{ $isEdit ? 'چیز میں تبدیلی' : 'نئی چیز شامل کریں' }}</span>
        </div>
        <a href="{{ $isEdit ? url('/products/' . $product->id) : url('/products') }}" class="btn btn-light">Back</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger" role="alert" tabindex="-1" id="pv2-errors">
            <b>Please check these:</b>
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    @if($isEdit && ($salesCount > 0 || $onHand != 0))
        <div class="note mb-3">This item has <b>{{ number_format($salesCount) }}</b> sale lines and <b>{{ rtrim(rtrim(number_format($onHand, 3), '0'), '.') }}</b> in stock. Saving here never changes past sales or stock.
            <span class="ur">اس چیز کی پرانی سیل اور اسٹاک ویسے ہی رہیں گے۔</span></div>
    @endif

    <form method="POST" action="{{ $formUrl }}" enctype="multipart/form-data" novalidate id="pv2-form">
        @csrf
        @if($isEdit) @method('PUT') @endif
        <input type="hidden" name="_form_v2" value="1">
        <input type="hidden" name="_barcode_made" id="_barcode_made" value="{{ old('_barcode_made', 0) }}">

        <div class="row g-3">
            <div class="col-lg-8">

                <fieldset class="step">
                    <legend>1. What are you adding?</legend>
                    <span class="ur">آپ کیا شامل کر رہے ہیں؟</span>
                    @if($businessInfo)
                        <p class="help mb-0">Showing {{ $businessInfo['label'] }} items only.</p>
                    @endif
                    <div class="cards" role="radiogroup" aria-label="What are you adding?">
                        @foreach($cards as $c)
                            <label class="pcard">
                                <input type="radio" name="_card" value="{{ $c['key'] }}" @checked($card === $c['key']) aria-describedby="card-{{ $c['key'] }}-says">
                                <img src="{{ asset($c['image']) }}" alt="" aria-hidden="true">
                                <b>{{ $c['label'] }}</b>
                                <span class="ur-inline">{{ $c['label_ur'] }}</span>
                                <small id="card-{{ $c['key'] }}-says">{{ $c['says'] }}</small>
                                <span class="eg">e.g. {{ $c['example'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if($isEdit)
                        <p class="help mt-2 mb-0" id="pv2-card-warn" hidden>You are changing the type of a saved item. Past sales and stock stay as they are.</p>
                    @endif
                </fieldset>

                <fieldset class="step">
                    <legend>2. Basic details</legend>
                    <span class="ur">بنیادی معلومات</span>
                    <div class="row g-3 mt-1">
                        <div class="col-md-7">
                            <label class="form-label" for="name">Name <span class="text-danger" aria-hidden="true">*</span></label>
                            <input id="name" name="name" class="form-control form-control-lg @error('name') is-invalid @enderror" value="{{ $saved('name') }}" required aria-required="true" aria-describedby="name-help" autocomplete="off">
                            <div id="name-help" class="help">The name your staff and customers know it by. <span class="ur-inline">وہ نام جس سے سب جانتے ہیں</span></div>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="category_id">Group (category)</label>
                            <select id="category_id" name="category_id" class="form-select form-select-lg">
                                <option value="">— None —</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}" @selected((string) $saved('category_id') === (string) $cat->id)>{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="unit_id">Sold and counted in</label>
                            <select id="unit_id" name="unit_id" class="form-select form-select-lg" aria-describedby="unit-help" data-weight-unit="{{ $weightUnitId }}">
                                <option value="">— Piece (default) —</option>
                                @foreach($units as $u)
                                    <option value="{{ $u->id }}" @selected((string) $saved('unit_id') === (string) $u->id)>{{ $u->name }}{{ in_array($u->unit_type, ['weight', 'volume', 'length'], true) ? ' — can sell 1.250' : '' }}</option>
                                @endforeach
                            </select>
                            <div id="unit-help" class="help">Piece, tablet, bottle — or kg / litre to sell part quantities. <span class="ur-inline">کس حساب سے بکتا ہے</span></div>
                        </div>
                        <div class="col-md-6">
                            <span class="form-label d-block">Is it in use?</span>
                            <div class="yn" role="radiogroup" aria-label="Is it in use?">
                                <input type="radio" id="st-a" name="status" value="active" @checked($saved('status', 'active') === 'active')><label for="st-a">Yes</label>
                                <input type="radio" id="st-i" name="status" value="inactive" @checked($saved('status', 'active') === 'inactive')><label for="st-i">No, hide it</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="image">Picture</label>
                            <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control" aria-describedby="image-help">
                            <div id="image-help" class="help">Shown on the counter screen. JPG / PNG, up to 2 MB.</div>
                            @if($isEdit && $product->image_path)
                                <div class="d-flex align-items-center gap-2 mt-2">
                                    <img src="{{ asset('storage/' . $product->image_path) }}" alt="Current picture of {{ $product->name }}" style="width:56px;height:56px;object-fit:cover;border-radius:8px">
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="remove_image" value="1" id="remove_image"><label class="form-check-label" for="remove_image">Remove picture</label></div>
                                </div>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="description">Notes <span class="help">(optional)</span></label>
                            <textarea id="description" name="description" rows="2" class="form-control">{{ $saved('description') }}</textarea>
                        </div>
                    </div>
                </fieldset>

                @if($hasMedicineCols)
                <fieldset class="step" data-only="medicine" @if(! $cardDef['medicine']) hidden @endif>
                    <legend>Medicine details</legend>
                    <span class="ur">دوائی کی تفصیل</span>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label" for="generic_name">Salt / formula</label>
                            <input id="generic_name" name="generic_name" class="form-control form-control-lg" value="{{ $saved('generic_name') }}" aria-describedby="gen-help" autocomplete="off">
                            <div id="gen-help" class="help">e.g. Paracetamol. The counter finds every brand by its salt. <span class="ur-inline">سالٹ / فارمولا</span></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="strength">Strength</label>
                            <input id="strength" name="strength" class="form-control form-control-lg" value="{{ $saved('strength') }}" placeholder="500 mg">
                        </div>
                        <div class="col-md-3">
                            <span class="form-label d-block">Needs a prescription?</span>
                            <div class="yn" role="radiogroup" aria-label="Needs a doctor's prescription?">
                                <input type="radio" id="rx-n" name="_rx" value="0" @checked(! (bool) (int) old('_rx', $product?->needs_prescription ? 1 : 0))><label for="rx-n">No</label>
                                <input type="radio" id="rx-y" name="_rx" value="1" @checked((bool) (int) old('_rx', $product?->needs_prescription ? 1 : 0))><label for="rx-y">Yes</label>
                            </div>
                            <span class="ur-inline">ڈاکٹر کا نسخہ</span>
                        </div>
                    </div>
                </fieldset>
                @endif

                <fieldset class="step">
                    <legend>3. A few questions</legend>
                    <span class="ur">چند سوال — جواب "ہاں" ہو تو اس کا حصہ کھلتا ہے</span>

                    @foreach($questions as $q => [$en, $ur])
                        @php $rule = $cardDef['ask'][$q] ?? null; $locked = $rule !== null && isset($rule['lock']) || ($q === 'stock' && $stockLocked); $on = $q === 'stock' && $stockLocked ? true : $ans[$q]; @endphp
                        <div class="q" data-q="{{ $q }}" @if($rule === null) hidden @endif>
                            <div class="q-head">
                                <div><span class="q-text" id="q-{{ $q }}-label">{{ $en }}</span><span class="ur">{{ $ur }}</span></div>
                                <div class="yn" role="radiogroup" aria-labelledby="q-{{ $q }}-label" data-yn="{{ $q }}" @if($locked) hidden @endif>
                                    <input type="radio" id="q-{{ $q }}-y" name="_{{ $q }}" value="1" @checked($on) @disabled($locked)><label for="q-{{ $q }}-y">Yes</label>
                                    <input type="radio" id="q-{{ $q }}-n" name="_{{ $q }}" value="0" @checked(! $on) @disabled($locked)><label for="q-{{ $q }}-n">No</label>
                                </div>
                                <div class="lock" data-lock="{{ $q }}" @if(! $locked) hidden @endif>
                                    🔒 <span data-lock-text>{{ $q === 'stock' && $stockLocked ? 'Stock of this item has already moved, so counting stays on.' : (($on ? 'Yes — ' : 'No — ') . ($rule['lock'][0] ?? '')) }}</span>
                                </div>
                            </div>
                            @error('_' . $q)<div class="text-danger small mt-1">{{ $message }}</div>@enderror

                            <div class="q-body" data-body="{{ $q }}" @if(! $on) hidden @endif>
                                @if($q === 'sell')
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="default_selling_price">Selling price (Rs) <span class="text-danger" aria-hidden="true">*</span></label>
                                            <input id="default_selling_price" name="default_selling_price" inputmode="decimal" class="form-control form-control-lg @error('default_selling_price') is-invalid @enderror" value="{{ $saved('default_selling_price') }}" aria-describedby="sp-help">
                                            <div id="sp-help" class="help">The price for one {{ $cardDef['weight'] ? 'kg / litre' : 'piece' }}. <span class="ur-inline">بیچنے کی قیمت</span></div>
                                            @error('default_selling_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                    </div>
                                @elseif($q === 'buy')
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="default_purchase_price">Buying price (Rs)</label>
                                            <input id="default_purchase_price" name="default_purchase_price" inputmode="decimal" class="form-control form-control-lg" value="{{ $saved('default_purchase_price') }}" aria-describedby="bp-help">
                                            <div id="bp-help" class="help">What you pay the supplier for one. Goods receipts (GRN) fill this in later. <span class="ur-inline">خرید کی قیمت</span></div>
                                        </div>
                                    </div>
                                @elseif($q === 'stock')
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="reorder_level">Tell me when stock goes below</label>
                                            <input id="reorder_level" name="reorder_level" inputmode="decimal" class="form-control form-control-lg" value="{{ old('reorder_level', $reorderLevel ?? 0) }}" aria-describedby="rl-help">
                                            <div id="rl-help" class="help">Shown on the low-stock list. <span class="ur-inline">جب اتنا رہ جائے تو بتائیں</span></div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="reorder_quantity">How much to order then</label>
                                            <input id="reorder_quantity" name="reorder_quantity" inputmode="decimal" class="form-control form-control-lg" value="{{ old('reorder_quantity', $reorderQuantity ?? 0) }}">
                                        </div>
                                        <div class="col-12 help">Stock comes in with a goods receipt (GRN) or a stock adjustment, and goes out when it is sold or used.</div>
                                    </div>
                                @elseif($q === 'expiry')
                                    <p class="help mb-0">Every goods receipt will ask for the batch number and expiry date, and the counter sells the batch that expires first. <span class="ur-inline">ہر خرید پر بیچ اور ایکسپائری</span></p>
                                @elseif($q === 'recipe')
                                    <p class="help mb-0">After saving, add what it is made from on the <a href="{{ url('/recipes/create') }}">Recipes</a> screen. When it sells, those items are taken out of stock. <span class="ur-inline">ترکیب بعد میں شامل کریں</span></p>
                                @endif
                            </div>
                            @if($locked)<input type="hidden" name="_{{ $q }}" value="{{ $on ? 1 : 0 }}" data-locked-input="{{ $q }}">@endif
                        </div>
                    @endforeach

                    <div class="q" data-q="tax">
                        <div class="q-head">
                            <div><span class="q-text" id="q-tax-label">Is there tax on it?</span><span class="ur">کیا اس پر ٹیکس ہے؟</span></div>
                            <div class="yn" role="radiogroup" aria-labelledby="q-tax-label">
                                <input type="radio" id="q-tax-y" name="_tax" value="1" @checked($tax)><label for="q-tax-y">Yes</label>
                                <input type="radio" id="q-tax-n" name="_tax" value="0" @checked(! $tax)><label for="q-tax-n">No</label>
                            </div>
                        </div>
                        <div class="q-body" data-body="tax" @if(! $tax) hidden @endif>
                            <label class="form-label" for="tax_rate_percent">Tax %</label>
                            <input id="tax_rate_percent" name="tax_rate_percent" inputmode="decimal" class="form-control form-control-lg" style="max-width:200px" value="{{ $saved('tax_rate_percent') !== null ? rtrim(rtrim((string) $saved('tax_rate_percent'), '0'), '.') : '' }}">
                        </div>
                    </div>
                </fieldset>

                @if($packsOn)
                    @include('tenant.products.partials.packs-step')
                @endif

                <fieldset class="step">
                    <legend>{{ $packsOn ? '5' : '4' }}. Code and barcode</legend>
                    <span class="ur">کوڈ اور بارکوڈ</span>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label" for="sku">Item code</label>
                            <div class="input-group input-group-lg">
                                <input id="sku" name="sku" class="form-control @error('sku') is-invalid @enderror" value="{{ $saved('sku') }}" aria-describedby="sku-help" autocomplete="off" style="text-transform:uppercase">
                                <button type="button" class="btn btn-outline-secondary" id="make-sku">Make code</button>
                            </div>
                            <div id="sku-help" class="help">Leave it empty and Bingoo makes the next code (e.g. <span id="sku-next">{{ $nextCodes[$card] ?? '' }}</span>). <span class="ur-inline">خالی چھوڑیں تو خود بن جاتا ہے</span></div>
                            @error('sku')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <span class="form-label d-block" id="q-bc-label">Does it have a barcode?</span>
                            <div class="yn" role="radiogroup" aria-labelledby="q-bc-label">
                                <input type="radio" id="bc-y" name="_barcode" value="1" @checked($hasBarcode)><label for="bc-y">Yes</label>
                                <input type="radio" id="bc-n" name="_barcode" value="0" @checked(! $hasBarcode)><label for="bc-n">No</label>
                            </div>
                            <span class="ur">کیا اس پر بارکوڈ ہے؟</span>
                        </div>
                        <div class="col-12" data-body="barcode" @if(! $hasBarcode) hidden @endif>
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label class="form-label" for="barcode">Barcode number</label>
                                    <div class="input-group input-group-lg">
                                        <input id="barcode" name="barcode" class="form-control @error('barcode') is-invalid @enderror" value="{{ old('barcode', $barcode) }}" inputmode="numeric" aria-describedby="bc-help" autocomplete="off">
                                        <button type="button" class="btn btn-outline-secondary" id="make-barcode">Make a barcode</button>
                                    </div>
                                    <div id="bc-help" class="help">Scan the barcode on the pack into this box, or press "Make a barcode" for items without one.</div>
                                    @error('barcode')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <div class="bc-wrap" id="bc-preview" aria-live="polite"></div>
                                    <div class="d-flex gap-2 align-items-center mt-1">
                                        <label class="form-label mb-0" for="label-count">Labels</label>
                                        <input id="label-count" type="number" min="1" max="200" value="10" class="form-control" style="width:90px">
                                        <button type="button" class="btn btn-outline-primary" id="print-labels">Print labels</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>

                @if(\App\Support\OnlineOrdering::enabledForTenant())
                    @include('tenant.partials.website-fields', array_merge(['model' => $product, 'kind' => 'product'], \App\Services\OnlineOrdering\WebsiteFields::formData()))
                @endif

                <div class="d-flex gap-2 flex-wrap mb-4">
                    <button type="submit" class="btn btn-primary btn-big">Save <span class="ur-inline" style="color:#fff">محفوظ کریں</span></button>
                    <button type="submit" name="_after" value="new" class="btn btn-outline-primary btn-big">Save and add another</button>
                    <a href="{{ $isEdit ? url('/products/' . $product->id) : url('/products') }}" class="btn btn-light btn-big d-inline-flex align-items-center">Cancel</a>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="what" role="complementary" aria-labelledby="what-title">
                    <h2 id="what-title">What will happen <span class="ur-inline" style="color:#F2DFA8">کیا ہوگا</span></h2>
                    <ul id="what-list" aria-live="polite" class="mb-0 ps-3"></ul>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var CARDS = @json($cards);
    var NEXT = @json($nextCodes);
    var IS_EDIT = @json($isEdit);
    var START_CARD = @json($card);
    var STOCK_LOCKED = @json($stockLocked);
    var form = document.getElementById('pv2-form');
    if (!form) return;
    var $ = function (s) { return form.querySelector(s); };
    var $$ = function (s) { return Array.prototype.slice.call(form.querySelectorAll(s)); };

    function card() { var r = $('input[name="_card"]:checked'); return CARDS[r ? r.value : START_CARD]; }
    function val(name) { var r = $('input[name="' + name + '"]:checked') || $('input[type="hidden"][name="' + name + '"]'); return r ? r.value === '1' : false; }

    function applyCard(c, fromUser) {
        ['sell', 'buy', 'stock', 'expiry', 'recipe'].forEach(function (q) {
            var row = $('[data-q="' + q + '"]'); if (!row) return;
            var rule = c.ask[q];
            row.hidden = !rule;
            var yn = row.querySelector('[data-yn]'), lock = row.querySelector('[data-lock]');
            var hiddenInput = row.querySelector('[data-locked-input]');
            if (hiddenInput) hiddenInput.remove();
            var locked = !!(rule && rule.lock) || (q === 'stock' && STOCK_LOCKED);
            var radios = row.querySelectorAll('input[type="radio"]');
            radios.forEach(function (r) { r.disabled = locked; });
            if (rule && fromUser && !locked) {
                var want = rule['default'] ? '1' : '0';
                radios.forEach(function (r) { r.checked = r.value === want; });
            }
            if (locked) {
                var on = q === 'stock' && STOCK_LOCKED ? true : !!rule['default'];
                radios.forEach(function (r) { r.checked = (r.value === '1') === on; });
                var h = document.createElement('input'); h.type = 'hidden'; h.name = '_' + q; h.value = on ? '1' : '0'; h.setAttribute('data-locked-input', q);
                row.appendChild(h);
                lock.querySelector('[data-lock-text]').textContent = q === 'stock' && STOCK_LOCKED
                    ? 'Stock of this item has already moved, so counting stays on.'
                    : (on ? 'Yes — ' : 'No — ') + rule.lock[0];
            }
            yn.hidden = locked; lock.hidden = !locked;
        });
        $$('[data-only="medicine"]').forEach(function (el) { el.hidden = !c.medicine; });
        document.getElementById('sku-next').textContent = NEXT[c.key] || '';
        var unit = document.getElementById('unit_id');
        if (fromUser && c.weight && unit && !unit.value && unit.dataset.weightUnit) unit.value = unit.dataset.weightUnit;
        if (fromUser && c.barcode) { var y = document.getElementById('bc-y'); if (y) y.checked = true; }
        var warn = document.getElementById('pv2-card-warn'); if (warn) warn.hidden = !(IS_EDIT && c.key !== START_CARD);
        refresh();
    }

    function refresh() {
        ['sell', 'buy', 'stock', 'expiry', 'recipe', 'tax'].forEach(function (q) {
            var b = $('[data-body="' + q + '"]'); if (b) b.hidden = !val('_' + q);
        });
        var bc = $('[data-body="barcode"]'); if (bc) bc.hidden = !val('_barcode');
        drawBarcode();
        var c = card(), w = [];
        var price = (document.getElementById('default_selling_price') || {}).value;
        w.push(val('_sell') ? 'It will appear on the counter' + (price ? ' at Rs ' + price : '') + '.' : 'It will NOT appear on the counter — it is used inside the business.');
        w.push(val('_buy') ? 'You can receive it from suppliers (goods receipt).' : 'It will not appear on purchase screens.');
        w.push(val('_stock') ? 'Its stock is counted: in with goods receipts, out when sold or used.' : 'Its stock is not counted.');
        if (val('_expiry')) w.push('Each goods receipt asks for batch and expiry; the oldest batch is sold first.');
        if (val('_recipe')) w.push('When it sells, the items in its recipe are taken out of stock.');
        if (c.medicine) w.push('The counter will find it by its salt too.');
        if (val('_barcode')) w.push('Scanning its barcode at the counter adds it to the bill.');
        if (window.pv2PackWhat) window.pv2PackWhat().forEach(function (t) { w.push(t); });
        if (val('_tax')) w.push('Tax is added on every sale.');
        var list = document.getElementById('what-list'); list.innerHTML = '';
        w.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; list.appendChild(li); });
    }

    // EAN-13 — valid check digit, drawn as SVG. "2…" codes are the in-store range: they never clash with a maker's code.
    var L = ['0001101','0011001','0010011','0111101','0100011','0110001','0101111','0111011','0110111','0001011'];
    var G = ['0100111','0110011','0011011','0100001','0011101','0111001','0000101','0010001','0001001','0010111'];
    var R = ['1110010','1100110','1101100','1000010','1011100','1001110','1010000','1000100','1001000','1110100'];
    var PARITY = ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG','LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'];
    function checkDigit(d12) { var s = 0; for (var i = 0; i < 12; i++) s += (+d12[i]) * (i % 2 ? 3 : 1); return (10 - s % 10) % 10; }
    function ean13Svg(code) {
        if (!/^\d{13}$/.test(code) || checkDigit(code.slice(0, 12)) !== +code[12]) return null;
        var bits = '101', p = PARITY[+code[0]];
        for (var i = 1; i <= 6; i++) bits += (p[i - 1] === 'L' ? L : G)[+code[i]];
        bits += '01010';
        for (i = 7; i <= 12; i++) bits += R[+code[i]];
        bits += '101';
        var x = 0, rects = '';
        for (i = 0; i < bits.length; i++) { if (bits[i] === '1') rects += '<rect x="' + (x + 10) + '" y="0" width="1" height="60"/>'; x++; }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + (bits.length + 20) + ' 74" role="img" aria-label="Barcode ' + code + '"><rect width="100%" height="100%" fill="#fff"/><g fill="#000">' + rects + '</g><text x="50%" y="72" text-anchor="middle" font-family="monospace" font-size="10" fill="#000">' + code + '</text></svg>';
    }
    function drawBarcode() {
        var box = document.getElementById('bc-preview'); if (!box) return;
        var v = (document.getElementById('barcode').value || '').trim();
        if (!v) { box.innerHTML = '<span class="help">The barcode picture appears here.</span>'; return; }
        var svg = ean13Svg(v);
        box.innerHTML = svg || '<span class="help">Saved as typed (' + v.replace(/[<>&]/g, '') + '). Labels print best with a 13-digit EAN code.</span>';
    }
    // Shared with the Packs step (each pack has its own barcode and labels).
    window.pv2MakeEan = function () {
        var d = '20'; for (var i = 0; i < 10; i++) d += Math.floor(Math.random() * 10);
        return d + checkDigit(d);
    };
    window.pv2PrintLabels = function (code, name, price) {
        var svg = ean13Svg(code);
        if (!svg) { alert('Make or scan a 13-digit barcode first.'); return; }
        var n = Math.max(1, Math.min(200, parseInt(document.getElementById('label-count').value, 10) || 1));
        name = (name || '').replace(/[<>&]/g, '');
        price = String(price || '').replace(/[<>&]/g, '');
        var one = '<div class="lb"><div class="n">' + name + '</div>' + svg + (price ? '<div class="p">Rs ' + price + '</div>' : '') + '</div>';
        var w = window.open('', '_blank', 'width=800,height=600');
        if (!w) { alert('Allow pop-ups to print labels.'); return; }
        w.document.write('<!doctype html><title>Labels</title><style>body{margin:8mm;font-family:Arial,sans-serif}.lb{display:inline-block;width:50mm;height:30mm;margin:1mm;padding:1mm;border:1px dashed #ccc;text-align:center;overflow:hidden;box-sizing:border-box}.lb svg{width:100%;height:17mm}.n{font-size:9pt;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.p{font-size:10pt;font-weight:bold}@media print{.lb{border:none}}</style>' + new Array(n + 1).join(one) + '<script>window.onload=function(){window.print()}<\/script>');
        w.document.close();
    };
    document.getElementById('make-barcode').addEventListener('click', function () {
        document.getElementById('barcode').value = window.pv2MakeEan();
        document.getElementById('_barcode_made').value = '1';
        refresh();
        document.getElementById('barcode').focus();
    });
    document.getElementById('barcode').addEventListener('input', function () { document.getElementById('_barcode_made').value = '0'; drawBarcode(); });
    document.getElementById('make-sku').addEventListener('click', function () {
        var s = document.getElementById('sku'); s.value = NEXT[card().key] || ''; s.focus();
    });
    document.getElementById('print-labels').addEventListener('click', function () {
        window.pv2PrintLabels((document.getElementById('barcode').value || '').trim(),
            document.getElementById('name').value || '', (document.getElementById('default_selling_price') || {}).value || '');
    });

    form.addEventListener('change', function (e) {
        if (e.target.name === '_card') { applyCard(card(), true); return; }
        refresh();
    });
    form.addEventListener('input', function (e) { if (e.target.id === 'default_selling_price') refresh(); });
    applyCard(card(), false);
    var err = document.getElementById('pv2-errors'); if (err) err.focus();
})();
</script>
@endpush
