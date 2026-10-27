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
    // W3/W4 S3d: Manufacturing Products and Catering Materials post to their own routes.
    $formBase = $formBase ?? '/products';
    $formUrl = $isEdit ? url($formBase . '/' . $product->id) : url($formBase);
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
        'sell'   => ['Do you sell it to customers?', ''],
        'buy'    => ['Do you buy it from a supplier?', ''],
        'stock'  => ['Do you want to count its stock?', ''],
        'expiry' => ['Does it have a batch and an expiry date?', ''],
        'recipe' => ['Is it made from other items (a recipe)?', ''],
    ];
    // Counter button: its own answer, never forced by "sold" (19 Oct).
    $posOn = (bool) (int) old('_pos', $isEdit ? ($product->is_pos_visible ? 1 : 0) : 1);
    // "More settings": the old form's kitchen and costing boxes this business uses.
    $moreFields = match ($business) {
        \App\Support\Business::RESTAURANT, \App\Support\Business::CATERING => ['item_kind', 'perishable', 'storage', 'shelf', 'wastage', 'buyunit'],
        \App\Support\Business::PHARMACY => ['storage'],
        \App\Support\Business::MART     => ['storage', 'shelf'],
        \App\Support\Business::INDUSTRY => ['item_kind', 'wastage', 'storage'],
        default                         => ['item_kind', 'perishable', 'storage', 'shelf', 'wastage', 'buyunit'],
    };
    $moreVals = [
        'item_kind' => old('item_kind', $product?->item_kind ?? (in_array($cardDef['kind'], ['raw_material', 'packaging_material'], true) ? 'ingredient' : 'finished_good')),
        'perishable' => (bool) (int) old('_perishable', $product?->is_perishable ? 1 : 0),
        'storage' => old('storage_type', $product?->storage_type),
        'shelf' => old('shelf_life_days', $product?->shelf_life_days),
        'wastage' => old('default_wastage_percent', $product !== null && (float) $product->default_wastage_percent != 0.0 ? rtrim(rtrim((string) $product->default_wastage_percent, '0'), '.') : ''),
        'buy_unit' => old('purchase_unit_id', $product?->purchase_unit_id),
        'buy_size' => old('purchase_pack_size', $product?->purchase_pack_size !== null ? rtrim(rtrim((string) $product->purchase_pack_size, '0'), '.') : ''),
    ];
    $moreOpen = $errors->hasAny(['item_kind', 'storage_type', 'shelf_life_days', 'default_wastage_percent', 'purchase_unit_id', 'purchase_pack_size'])
        || $moreVals['storage'] || $moreVals['shelf'] || $moreVals['wastage'] || $moreVals['buy_unit'] || $moreVals['perishable'];
    // PRODUCT-SCREEN-TABS-1 — the sections as tabs (docs/plans/product-screen-tabs-2026-10-19.md). Built from
    // what this tenant has: Packs only where Master switched them on, Website only with online ordering.
    $websiteOn = \App\Support\OnlineOrdering::enabledForTenant();
    $tabs = array_values(array_filter([
        ['key' => 'type',    'label' => 'Type',            'ur' => '',             'icon' => 'ti-category'],
        ['key' => 'details', 'label' => 'Details',         'ur' => '',           'icon' => 'ti-file-description'],
        ['key' => 'selling', 'label' => 'Selling & stock', 'ur' => '', 'icon' => 'ti-building-store'],
        $packsOn ? ['key' => 'packs', 'label' => 'Packs', 'ur' => '', 'icon' => 'ti-packages'] : null,
        // W3/W4 S3c: the server decides these three (card, plan module, permission) — App\Services\Products\ProductWorkspace.
        ($ws['access']['recipe'] ?? false) ? ['key' => 'recipe', 'label' => 'Recipe', 'ur' => '', 'icon' => 'ti-tools-kitchen-2'] : null,
        ($ws['access']['bom'] ?? false) ? ['key' => 'bom', 'label' => 'Bill of materials', 'ur' => '', 'icon' => 'ti-list-tree'] : null,
        ($ws['access']['catering'] ?? false) ? ['key' => 'catering', 'label' => 'Event costing', 'ur' => '', 'icon' => 'ti-chef-hat'] : null,
        ['key' => 'code',    'label' => 'Code',            'ur' => '',             'icon' => 'ti-barcode'],
        $websiteOn ? ['key' => 'website', 'label' => 'Website', 'ur' => '', 'icon' => 'ti-world'] : null,
        ['key' => 'review',  'label' => 'Review',          'ur' => '',           'icon' => 'ti-list-check'],
    ]));
@endphp

@push('styles')
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
    .pv2 .what { background:var(--navy); color:#fff; border-radius:14px; padding:16px 18px; }
    .pv2 .what h2 { font-size:1rem; color:#F2DFA8; margin:0 0 8px; font-weight:800; }
    .pv2 .what li { margin-bottom:6px; }
    .pv2 .note { background:var(--goldbg); border:1px solid #F2DFA8; border-radius:10px; padding:10px 12px; color:#6B5310; }
    .pv2 .btn-big { min-height:46px; padding:0 22px; font-weight:800; }
    .pv2 .input-group-lg .btn { font-size:1rem; font-weight:700; }
    .pv2 .bc-wrap svg { max-width:100%; height:70px; }
    .pv2 [hidden] { display:none !important; }
    @media (max-width: 991px) { .pv2 .what { position:static; } }

    /* PRODUCT-SCREEN-TABS-1 — one row of tabs above the panel, sticky under the app header (65px). */
    .pv2 .visually-hidden { position:absolute !important; width:1px !important; height:1px !important; padding:0 !important; margin:-1px !important; overflow:hidden !important; clip:rect(0,0,0,0) !important; white-space:nowrap !important; border:0 !important; }
    .pv2 .tabbar { position:sticky; top:65px; z-index:20; background:#F6F8FB; padding:8px 0 10px; margin-bottom:12px; }
    .pv2 .tablist { display:flex; gap:6px; overflow-x:auto; scrollbar-width:thin; scroll-snap-type:x proximity; padding:2px; }
    .pv2 .tab { position:relative; flex:1 0 auto; scroll-snap-align:center; display:flex; align-items:center; gap:8px; min-height:52px; padding:6px 14px;
        border:1px solid var(--line); border-bottom:3px solid transparent; border-radius:12px; background:#fff; color:#344054; font-weight:700; text-align:left; white-space:nowrap; }
    .pv2 .tab:hover { border-color:#CBD3DE; }
    .pv2 .tab:focus-visible { outline:3px solid var(--navy); outline-offset:2px; }
    .pv2 .tab .tn { position:relative; display:inline-grid; place-items:center; width:26px; height:26px; border-radius:50%; background:#EEF2F6; color:var(--navy); font-size:.85rem; flex:none; }
    .pv2 .tab .tl { display:flex; flex-direction:column; line-height:1.15; }
    .pv2 .tab .tl .ur-inline { font-size:.75rem; line-height:1.6; }
    .pv2 .tab[aria-selected="true"] { background:var(--goldbg); border-color:var(--gold); border-bottom-color:var(--gold); color:var(--navy); box-shadow:0 4px 14px rgba(184,144,46,.18); }
    .pv2 .tab[aria-selected="true"] .tn { background:var(--navy); color:#fff; }
    .pv2 .tab[data-state="done"] .tn { background:var(--ok); color:#fff; }
    .pv2 .tab[data-state="done"] .tn::after { content:"✓"; position:absolute; inset:0; display:grid; place-items:center; font-size:.85rem; }
    .pv2 .tab[data-state="done"] .tn span { visibility:hidden; }
    .pv2 .tab .terr { display:none; min-width:20px; height:20px; padding:0 6px; border-radius:10px; background:var(--bad); color:#fff; font-size:.75rem; line-height:20px; text-align:center; }
    .pv2 .tab[data-errors] .terr { display:inline-block; }
    .pv2 .tp:not([hidden]) { animation:pv2fade .15s ease-out; }
    .pv2 .tp:focus { outline:none; }
    @keyframes pv2fade { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:none; } }
    @media (prefers-reduced-motion: reduce) { .pv2 .tp:not([hidden]) { animation:none; } }
    .pv2 .savebar { position:sticky; bottom:0; z-index:20; display:flex; gap:8px; flex-wrap:wrap; align-items:center; background:#fff; border:1px solid var(--line);
        border-radius:14px 14px 0 0; padding:10px 14px; margin-top:12px; box-shadow:0 -6px 18px rgba(9,44,76,.08); }
    .pv2 .savebar .grow { flex:1 1 auto; }
    .pv2 .savebar .hint { color:var(--ink2); font-size:.8rem; }
    @media (max-width: 575px) { .pv2 .savebar { gap:6px; padding:8px 10px; } .pv2 .savebar .btn-big { min-height:42px; padding:0 12px; } .pv2 .savebar .grow { display:none; } }
    .pv2 .review { display:grid; grid-template-columns:minmax(150px,220px) 1fr auto; gap:0; margin:8px 0 0; }
    .pv2 .review > * { padding:9px 4px; border-bottom:1px solid var(--line); margin:0; }
    .pv2 .review dt { font-weight:700; color:#344054; }
    .pv2 .review dd.miss { color:var(--bad); font-weight:700; }
    .pv2 .review .chg { border:0; background:transparent; color:#8A6A12; font-weight:700; text-decoration:underline; }
    @media (max-width: 767px) {
        .pv2 .tab { flex:0 0 auto; min-height:46px; padding:6px 10px; }
        .pv2 .tab .tl .ur-inline { display:none; }
        .pv2 .review { grid-template-columns:1fr auto; }
        .pv2 .review dt { grid-column:1 / -1; border-bottom:0; padding-bottom:0; }
    }
    /* W3/W4 S3c — the Recipe, Bill of materials and Event costing tabs. On a phone each line becomes a small card. */
    .pv2 .ws-head { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; }
    .pv2 .ws-make { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
    .pv2 .ws-make .ws-num { width:110px; }
    .pv2 .ws-make .ws-unit { width:auto; min-width:120px; }
    .pv2 .ws-total { color:var(--navy); }
    .pv2 .ws-errors { margin-bottom:10px; }
    .pv2 .ws-table th { font-size:.8rem; color:var(--ink2); font-weight:700; white-space:nowrap; }
    .pv2 .ws-table td.ws-item, .pv2 .ws-table td.ws-mat { min-width:220px; }
    .pv2 .ws-table td.ws-label, .pv2 .ws-table td.ws-part { min-width:160px; }
    .pv2 .ws-table td.ws-qty { width:120px; min-width:100px; }
    .pv2 .ws-table td.ws-unitcell { width:180px; min-width:150px; }
    .pv2 .ws-table td.ws-cost { white-space:nowrap; font-variant-numeric:tabular-nums; }
    .pv2 .ws-table td.ws-rm { width:1%; white-space:nowrap; }
    @media (max-width: 575px) {
        .pv2 .ws-table thead { display:none; }
        .pv2 .ws-table tr[data-ws-row] { display:grid; grid-template-columns:1fr 1fr; gap:8px; padding:10px 0; border-bottom:1px solid var(--line); }
        .pv2 .ws-table tr[data-ws-row] > td { display:block; border:0; padding:0; width:auto; min-width:0; }
        .pv2 .ws-table td.ws-item, .pv2 .ws-table td.ws-label, .pv2 .ws-table td.ws-mat { grid-column:1 / -1; }
        .pv2 .ws-table td[data-label]::before { content:attr(data-label); display:block; font-size:.75rem; font-weight:700; color:var(--ink2); margin-bottom:2px; }
        .pv2 .ws-table td.ws-cost { text-align:left !important; }
    }
</style>
@endpush

@section('content')
<div class="pv2">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
        <div>
            <h1 class="mb-0">{{ $title }}</h1>
        </div>
        <a href="{{ $formBase !== '/products' ? url($formBase) : ($isEdit ? url('/products/' . $product->id) : url('/products')) }}" class="btn btn-light">Back</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger" role="alert" tabindex="-1" id="pv2-errors">
            <b>Please check these:</b>
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    @if($isEdit && ($salesCount > 0 || $onHand != 0))
        <div class="note mb-3">This item has <b>{{ number_format($salesCount) }}</b> sale lines and <b>{{ rtrim(rtrim(number_format($onHand, 3), '0'), '.') }}</b> in stock. Saving here never changes past sales or stock.
            </div>
    @endif

    <form method="POST" action="{{ $formUrl }}" enctype="multipart/form-data" novalidate id="pv2-form">
        @csrf
        @if($isEdit) @method('PUT') @endif
        <input type="hidden" name="_form_v2" value="1">
        <input type="hidden" name="_barcode_made" id="_barcode_made" value="{{ old('_barcode_made', 0) }}">

        <div class="tabbar">
            <div class="tablist" role="tablist" aria-label="Sections of this product" id="pv2-tablist">
                @foreach($tabs as $n => $t)
                    <button type="button" class="tab" role="tab" id="tab-{{ $t['key'] }}" aria-controls="tp-{{ $t['key'] }}" aria-selected="{{ $n === 0 ? 'true' : 'false' }}" tabindex="{{ $n === 0 ? '0' : '-1' }}" data-tab="{{ $t['key'] }}">
                        <span class="tn" aria-hidden="true"><span>{{ $n + 1 }}</span></span>
                        <span class="tl">{{ $t['label'] }}</span>
                        <span class="terr" aria-hidden="true"></span>
                        <span class="visually-hidden" data-tab-sr></span>
                    </button>
                @endforeach
            </div>
        </div>
        <div class="visually-hidden" aria-live="polite" id="pv2-tab-live"></div>

        <div class="row g-3">
            <div class="col-12">

                <section class="tp" id="tp-type" role="tabpanel" aria-labelledby="tab-type" tabindex="-1">
                <fieldset class="step">
                    <legend>What are you adding?</legend>
                    @if($businessInfo)
                        <p class="help mb-0">Showing {{ $businessInfo['label'] }} items only.</p>
                    @endif
                    <div class="cards" role="radiogroup" aria-label="What are you adding?">
                        @foreach($cards as $c)
                            <label class="pcard">
                                <input type="radio" name="_card" value="{{ $c['key'] }}" @checked($card === $c['key']) aria-describedby="card-{{ $c['key'] }}-says">
                                <img src="{{ asset($c['image']) }}" alt="" aria-hidden="true">
                                <b>{{ $c['label'] }}</b>
                                <small id="card-{{ $c['key'] }}-says">{{ $c['says'] }}</small>
                                <span class="eg">e.g. {{ $c['example'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if($isEdit)
                        <p class="help mt-2 mb-0" id="pv2-card-warn" hidden>You are changing the type of a saved item. Past sales and stock stay as they are.</p>
                    @endif
                    {{-- W3/W4 S2: an item no card here fits keeps its role and every saved setting; only picking another card changes it. --}}
                    @if($misfitRole ?? null)
                        <div class="alert alert-warning mt-2 mb-0 py-2" id="pv2-misfit" role="status">
                            None of these cards fits this item. Its role (<b>{{ $misfitRole }}</b>) and its saved settings are kept as they are. Pick a card only if you want to change it.
                        </div>
                    @endif
                </fieldset>
                </section>

                <section class="tp" id="tp-details" role="tabpanel" aria-labelledby="tab-details" tabindex="-1" hidden>
                <fieldset class="step">
                    <legend>Basic details</legend>
                    <div class="row g-3 mt-1">
                        <div class="col-md-7">
                            <label class="form-label" for="name">Name <span class="text-danger" aria-hidden="true">*</span></label>
                            <input id="name" name="name" class="form-control form-control-lg @error('name') is-invalid @enderror" value="{{ $saved('name') }}" required aria-required="true" aria-describedby="name-help" autocomplete="off">
                            <div id="name-help" class="help">The name your staff and customers know it by.</div>
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
                            <div id="unit-help" class="help">Piece, tablet, bottle — or kg / litre to sell part quantities.</div>
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
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label" for="generic_name">Salt / formula</label>
                            <input id="generic_name" name="generic_name" class="form-control form-control-lg" value="{{ $saved('generic_name') }}" aria-describedby="gen-help" autocomplete="off">
                            <div id="gen-help" class="help">e.g. Paracetamol. The counter finds every brand by its salt.</div>
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
                        </div>
                    </div>
                </fieldset>
                @endif
                </section>

                <section class="tp" id="tp-selling" role="tabpanel" aria-labelledby="tab-selling" tabindex="-1" hidden>
                <fieldset class="step">
                    <legend>A few questions</legend>

                    @foreach($questions as $q => [$en, $ur])
                        @php $rule = $cardDef['ask'][$q] ?? null; $locked = $rule !== null && isset($rule['lock']) || ($q === 'stock' && $stockLocked); $on = $q === 'stock' && $stockLocked ? true : $ans[$q]; @endphp
                        <div class="q" data-q="{{ $q }}" @if($rule === null) hidden @endif>
                            <div class="q-head">
                                <div><span class="q-text" id="q-{{ $q }}-label">{{ $en }}</span></div>
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
                                        {{-- CATERING-SIMPLIFY-1 (owner, 27 Oct): a caterer's dish priced by cost blocks shows its block
                                             price here, locked, with a way to the blocks — the counter price is not what events charge,
                                             and changing it changed nothing. The server keeps the stored counter price
                                             (ProductWorkspace::blockPrice). "Priced by" on the Event costing tab switches the lock live. --}}
                                        @php
                                            $lock = $priceLock ?? null;
                                            $locked = (bool) ($lock['locked'] ?? false);
                                            $plainRs = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
                                            $shownRs = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
                                            $posPrice = (float) ($product?->default_selling_price ?? 0);
                                        @endphp
                                        <div class="col-md-6" @if($lock && $lock['catering']) data-price-lock data-block-price="{{ $plainRs($lock['rate']) }}" data-pos-price="{{ $plainRs($posPrice) }}" @endif>
                                            <label class="form-label" for="default_selling_price">Selling price (Rs) <span class="text-danger" aria-hidden="true">*</span></label>
                                            <input id="default_selling_price" name="default_selling_price" inputmode="decimal" class="form-control form-control-lg @error('default_selling_price') is-invalid @enderror" value="{{ $locked ? $plainRs($lock['rate']) : $saved('default_selling_price') }}" aria-describedby="sp-help" @disabled($locked)>
                                            <div id="sp-help" class="help" @if($locked) hidden @endif>The price for one {{ $cardDef['weight'] ? 'kg / litre' : 'piece' }}.</div>
                                            @if($lock && $lock['catering'])
                                                <div class="help" data-sp-locked @unless($locked) hidden @endunless>
                                                    🔒 This dish's price comes from its cost blocks: <b>Rs {{ $shownRs($lock['rate']) }}</b>.
                                                    @if($ws['access']['catering'] ?? false)
                                                        <button type="button" class="btn btn-sm btn-outline-primary ms-1" data-go-tab="catering">Change in Cost blocks</button>
                                                    @else
                                                        It is changed on the Event costing tab, by someone who may edit it.
                                                    @endif
                                                    @if($posPrice > 0 && abs($posPrice - (float) $lock['rate']) >= 0.005)
                                                        <div class="mt-1">Counter (POS) price saved: Rs {{ $shownRs($posPrice) }} — events do not use it.</div>
                                                    @endif
                                                </div>
                                            @elseif($lock && $lock['blocks'])
                                                <div class="help">Events price this dish from its cost blocks (Rs {{ $shownRs($lock['rate']) }}). This box is the counter (POS) price.</div>
                                            @endif
                                            @error('default_selling_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        {{-- Its own answer: "sold" never means "has a button" (19 Oct — items sold inside deals,
                                             as add-ons or online are hidden from the counter on purpose). --}}
                                        <div class="col-md-6">
                                            <span class="form-label d-block" id="q-pos-label">Show its button on the counter (POS)?</span>
                                            <div class="yn" role="radiogroup" aria-labelledby="q-pos-label">
                                                <input type="radio" id="pos-y" name="_pos" value="1" @checked($posOn)><label for="pos-y">Yes</label>
                                                <input type="radio" id="pos-n" name="_pos" value="0" @checked(! $posOn)><label for="pos-n">No, hide it</label>
                                            </div>
                                            <div class="help">"No" = still sold — inside a deal, as an add-on, or on the website — but the counter has no button for it.</div>
                                        </div>
                                    </div>
                                @elseif($q === 'buy')
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="default_purchase_price">Buying price (Rs)</label>
                                            <input id="default_purchase_price" name="default_purchase_price" inputmode="decimal" class="form-control form-control-lg" value="{{ $saved('default_purchase_price') }}" aria-describedby="bp-help">
                                            <div id="bp-help" class="help">What you pay the supplier for one. Goods receipts (GRN) fill this in later.</div>
                                        </div>
                                        {{-- PURCHASE-DISCOUNTS-1 (owner: "product level discount fixed ya % pehle se"). The new GRN / order
                                             fill these in; the last deal with the supplier comes first, and the line can always change. --}}
                                        @php
                                            $pdType = old('purchase_discount_type', $product?->purchase_discount_type ?? 'percent');
                                            $pdVal = old('purchase_discount_value', $product?->purchase_discount_value !== null ? rtrim(rtrim((string) $product->purchase_discount_value, '0'), '.') : '');
                                            $pScheme = old('purchase_scheme', $product?->purchase_scheme_buy ? $product->purchase_scheme_buy . '+' . (int) $product->purchase_scheme_free : '');
                                        @endphp
                                        <div class="col-md-6">
                                            <label class="form-label" for="purchase_discount_value">Usual discount when buying</label>
                                            <div class="input-group input-group-lg">
                                                <input id="purchase_discount_value" name="purchase_discount_value" inputmode="decimal" class="form-control @error('purchase_discount_value') is-invalid @enderror" value="{{ $pdVal }}" placeholder="e.g. 5" aria-describedby="pd-help">
                                                <select name="purchase_discount_type" class="form-select" style="max-width:96px" aria-label="Percent or rupees">
                                                    <option value="percent" @selected($pdType !== 'amount')>%</option>
                                                    <option value="amount" @selected($pdType === 'amount')>Rs</option>
                                                </select>
                                            </div>
                                            <div id="pd-help" class="help">The GRN fills it in; you can change it on the line.</div>
                                            @error('purchase_discount_value')<div class="text-danger small">{{ $message }}</div>@enderror
                                        </div>
                                        @if(in_array($business, [\App\Support\Business::PHARMACY, \App\Support\Business::MART], true))
                                            <div class="col-md-6">
                                                <label class="form-label" for="purchase_scheme">Usual scheme</label>
                                                <input id="purchase_scheme" name="purchase_scheme" class="form-control form-control-lg @error('purchase_scheme') is-invalid @enderror" value="{{ $pScheme }}" placeholder="10+1" aria-describedby="ps-help" autocomplete="off">
                                                <div id="ps-help" class="help">Buy 10, get 1 free — the GRN adds the free quantity by itself.</div>
                                                @error('purchase_scheme')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                        @endif
                                    </div>
                                @elseif($q === 'stock')
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="reorder_level">Tell me when stock goes below</label>
                                            <input id="reorder_level" name="reorder_level" inputmode="decimal" class="form-control form-control-lg" value="{{ old('reorder_level', $reorderLevel ?? 0) }}" aria-describedby="rl-help">
                                            <div id="rl-help" class="help">Shown on the low-stock list.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="reorder_quantity">How much to order then</label>
                                            <input id="reorder_quantity" name="reorder_quantity" inputmode="decimal" class="form-control form-control-lg" value="{{ old('reorder_quantity', $reorderQuantity ?? 0) }}">
                                        </div>
                                        <div class="col-12 help">Stock comes in with a goods receipt (GRN) or a stock adjustment, and goes out when it is sold or used.</div>
                                    </div>
                                @elseif($q === 'expiry')
                                    <p class="help mb-0">Every goods receipt will ask for the batch number and expiry date, and the counter sells the batch that expires first.</p>
                                @elseif($q === 'recipe' && ($ws['access']['recipe'] ?? false))
                                    <p class="help mb-0">Add what it is made from on the <b>Recipe</b> tab — it is saved with the item. When it sells, those items are taken out of stock.</p>
                                @elseif($q === 'recipe')
                                    <p class="help mb-0">After saving, add what it is made from on the <a href="{{ url('/recipes/create') }}">Recipes</a> screen. When it sells, those items are taken out of stock.</p>
                                @endif
                            </div>
                            @if($locked)<input type="hidden" name="_{{ $q }}" value="{{ $on ? 1 : 0 }}" data-locked-input="{{ $q }}">@endif
                        </div>
                    @endforeach

                    <div class="q" data-q="tax">
                        <div class="q-head">
                            <div><span class="q-text" id="q-tax-label">Is there tax on it?</span></div>
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

                {{-- The old form's kitchen and costing boxes, in plain words — only the ones this business uses.
                     A box not shown keeps its saved value (ProductCards::resolve reads only what is posted). --}}
                @if($moreFields)
                <details class="step" id="pv2-more" @if($moreOpen) open @endif>
                    <summary class="step-title" style="cursor:pointer">More settings
                        <span class="help d-block" style="font-weight:400">Only if you need them — each keeps its saved value until you change it.</span></summary>
                    <input type="hidden" name="_more" value="1">
                    <div class="row g-3 mt-1">
                        @if(in_array('item_kind', $moreFields, true))
                            <div class="col-md-6">
                                <label class="form-label" for="item_kind">Used as</label>
                                <select id="item_kind" name="item_kind" class="form-select form-select-lg" data-touched="{{ old('item_kind') !== null || $isEdit ? 1 : 0 }}">
                                    @foreach(['finished_good' => 'Sold as it is (finished)', 'ingredient' => 'Used in cooking (ingredient)', 'both' => 'Both'] as $v => $l)
                                        <option value="{{ $v }}" @selected($moreVals['item_kind'] === $v)>{{ $l }}</option>
                                    @endforeach
                                </select>
                                <div class="help">The old screen called this "Item Kind".</div>
                            </div>
                        @endif
                        @if(in_array('perishable', $moreFields, true))
                            <div class="col-md-6">
                                <span class="form-label d-block" id="q-per-label">Goes bad quickly?</span>
                                <div class="yn" role="radiogroup" aria-labelledby="q-per-label">
                                    <input type="radio" id="per-y" name="_perishable" value="1" @checked($moreVals['perishable'])><label for="per-y">Yes</label>
                                    <input type="radio" id="per-n" name="_perishable" value="0" @checked(! $moreVals['perishable'])><label for="per-n">No</label>
                                </div>
                                <div class="help">Milk, meat, vegetables.</div>
                            </div>
                        @endif
                        @if(in_array('storage', $moreFields, true))
                            <div class="col-md-6">
                                <label class="form-label" for="storage_type">Kept in</label>
                                <input id="storage_type" name="storage_type" list="pv2-storage" maxlength="50" class="form-control form-control-lg" value="{{ $moreVals['storage'] }}" placeholder="e.g. Fridge">
                                <datalist id="pv2-storage">@foreach(['Room temperature', 'Dry store', 'Fridge', 'Freezer'] as $o)<option value="{{ $o }}"></option>@endforeach</datalist>
                                <div class="help">Where it is kept.</div>
                            </div>
                        @endif
                        @if(in_array('shelf', $moreFields, true))
                            <div class="col-md-6">
                                <label class="form-label" for="shelf_life_days">Stays good for (days)</label>
                                <input id="shelf_life_days" name="shelf_life_days" inputmode="numeric" class="form-control form-control-lg" value="{{ $moreVals['shelf'] }}" style="max-width:200px">
                            </div>
                        @endif
                        @if(in_array('wastage', $moreFields, true))
                            <div class="col-md-6">
                                <label class="form-label" for="default_wastage_percent">Usual waste (%)</label>
                                <input id="default_wastage_percent" name="default_wastage_percent" inputmode="decimal" class="form-control form-control-lg" value="{{ $moreVals['wastage'] }}" style="max-width:200px">
                                <div class="help">Used when a recipe is costed — e.g. 10% of onions is peel.</div>
                            </div>
                        @endif
                        @if(in_array('buyunit', $moreFields, true))
                            <div class="col-md-6">
                                <label class="form-label" for="purchase_unit_id">For recipe cost: bought in</label>
                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                    <select id="purchase_unit_id" name="purchase_unit_id" class="form-select form-select-lg" style="max-width:200px">
                                        <option value="">— same unit —</option>
                                        @foreach($units as $u)<option value="{{ $u->id }}" @selected((string) $moreVals['buy_unit'] === (string) $u->id)>{{ $u->name }}</option>@endforeach
                                    </select>
                                    <span>holding</span>
                                    <input id="purchase_pack_size" name="purchase_pack_size" inputmode="decimal" class="form-control form-control-lg" value="{{ $moreVals['buy_size'] }}" style="max-width:120px" aria-label="How many in one">
                                </div>
                                <div class="help">e.g. a 50 kg sack. Only recipe costing reads this.</div>
                            </div>
                        @endif
                    </div>
                </details>
                @endif
                </section>

                @if($packsOn)
                    <section class="tp" id="tp-packs" role="tabpanel" aria-labelledby="tab-packs" tabindex="-1" hidden>
                        @include('tenant.products.partials.packs-step')
                    </section>
                @endif

                @if($ws['access']['recipe'] ?? false)
                    <section class="tp" id="tp-recipe" role="tabpanel" aria-labelledby="tab-recipe" tabindex="-1" hidden>
                        @include('tenant.products.partials.ws-recipe')
                    </section>
                @endif
                @if($ws['access']['bom'] ?? false)
                    <section class="tp" id="tp-bom" role="tabpanel" aria-labelledby="tab-bom" tabindex="-1" hidden>
                        @include('tenant.products.partials.ws-bom')
                    </section>
                @endif
                @if($ws['access']['catering'] ?? false)
                    <section class="tp" id="tp-catering" role="tabpanel" aria-labelledby="tab-catering" tabindex="-1" hidden>
                        @include('tenant.products.partials.ws-catering')
                    </section>
                @endif

                <section class="tp" id="tp-code" role="tabpanel" aria-labelledby="tab-code" tabindex="-1" hidden>
                <fieldset class="step">
                    <legend>Code and barcode</legend>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label" for="sku">Item code</label>
                            <div class="input-group input-group-lg">
                                <input id="sku" name="sku" class="form-control @error('sku') is-invalid @enderror" value="{{ $saved('sku') }}" aria-describedby="sku-help" autocomplete="off" style="text-transform:uppercase">
                                <button type="button" class="btn btn-outline-secondary" id="make-sku">Make code</button>
                            </div>
                            <div id="sku-help" class="help">Leave it empty and Bingoo makes the next code (e.g. <span id="sku-next">{{ $nextCodes[$card] ?? '' }}</span>).</div>
                            @error('sku')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <span class="form-label d-block" id="q-bc-label">Does it have a barcode?</span>
                            <div class="yn" role="radiogroup" aria-labelledby="q-bc-label">
                                <input type="radio" id="bc-y" name="_barcode" value="1" @checked($hasBarcode)><label for="bc-y">Yes</label>
                                <input type="radio" id="bc-n" name="_barcode" value="0" @checked(! $hasBarcode)><label for="bc-n">No</label>
                            </div>
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
                </section>

                @if($websiteOn)
                    <section class="tp" id="tp-website" role="tabpanel" aria-labelledby="tab-website" tabindex="-1" hidden>
                        @include('tenant.partials.website-fields', array_merge(['model' => $product, 'kind' => 'product'], \App\Services\OnlineOrdering\WebsiteFields::formData()))
                    </section>
                @endif

                {{-- Check your answers: every answer in plain words, each with a way back to its tab. --}}
                <section class="tp" id="tp-review" role="tabpanel" aria-labelledby="tab-review" tabindex="-1" hidden>
                    <fieldset class="step">
                        <legend>Check before saving</legend>
                        <dl class="review" id="pv2-review"></dl>
                        {{-- W3/W4 S2: the server decides what this save may change; everything else keeps its saved value. --}}
                        @if(! empty($keptAsSaved))
                            <div class="mt-3" id="pv2-kept" role="region" aria-labelledby="pv2-kept-title">
                                <h2 class="h6 mb-1" id="pv2-kept-title">Not shown for this item — kept as saved</h2>
                                <ul class="mb-1 ps-3 small">
                                    @foreach($keptAsSaved as $words => $value)
                                        <li>{{ $words }}: <b>{{ $value }}</b></li>
                                    @endforeach
                                </ul>
                                <p class="help mb-0">The server decides which settings this screen may change, from this item's card and the shop's switches. Every other setting keeps its saved value.</p>
                            </div>
                        @endif
                        {{-- "What will happen" lives here now: the owner (19 Oct) found the side box took too much room. --}}
                        <div class="what mt-3" role="region" aria-labelledby="what-title">
                            <h2 id="what-title">What will happen</h2>
                            <ul id="what-list" class="mb-0 ps-3"></ul>
                        </div>
                        <p class="help mt-2 mb-0">Nothing is saved until you press Save.</p>
                    </fieldset>
                </section>

                <div class="savebar" role="group" aria-label="Move between sections and save">
                    <button type="button" class="btn btn-light btn-big" id="pv2-prev">← Back</button>
                    <button type="button" class="btn btn-outline-primary btn-big" id="pv2-next">Next →</button>
                    <span class="grow"></span>
                    <span class="hint d-none d-xl-inline">Ctrl + Enter saves</span>
                    <button type="submit" class="btn btn-primary btn-big" id="pv2-save">Save</button>
                    <button type="submit" name="_after" value="new" class="btn btn-outline-primary btn-big d-none d-sm-inline-block">Save and add another</button>
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
        var ik = document.getElementById('item_kind');
        if (fromUser && ik && ik.dataset.touched !== '1') ik.value = (c.kind === 'raw_material' || c.kind === 'packaging_material') ? 'ingredient' : 'finished_good';
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
        w.push(!val('_sell') ? 'It will NOT appear on the counter — it is used inside the business.'
            : (val('_pos') ? 'It will appear on the counter' + (price ? ' at Rs ' + price : '') + '.'
                : 'It is sold' + (price ? ' at Rs ' + price : '') + ', but has no button on the counter — e.g. inside a deal, as an add-on, or online.'));
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
    window.pv2Refresh = refresh;   // the Packs step updates "What will happen" while a size is typed
    window.pv2Card = card;         // the Review tab names the type from the chosen card
    var ikSel = document.getElementById('item_kind'); if (ikSel) ikSel.addEventListener('change', function () { ikSel.dataset.touched = '1'; });
    applyCard(card(), false);
    var err = document.getElementById('pv2-errors'); if (err) err.focus();
})();

// PRODUCT-SCREEN-TABS-1 — the sections as tabs (W3C ARIA tabs pattern, automatic activation: every panel is
// already on the page, so switching is instant and nothing typed is ever lost). One form, one Save.
(function () {
    var form = document.getElementById('pv2-form');
    var list = document.getElementById('pv2-tablist');
    if (!form || !list) return;
    var tabs = Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));
    var keys = tabs.map(function (t) { return t.dataset.tab; });
    var live = document.getElementById('pv2-tab-live');
    var prevBtn = document.getElementById('pv2-prev'), nextBtn = document.getElementById('pv2-next');
    var isEdit = !!form.querySelector('input[name="_method"]');
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var current = keys[0], visited = {}, dirty = false, submitting = false;
    var SAVED = @json(['kind' => $product?->product_kind, 'type' => $product?->product_type]);
    var KIND = { sale_item: 'Sold item', raw_material: 'Raw material / ingredient', packaging_material: 'Packing material', service: 'Service', finished_good: 'Finished good', semi_finished: 'Half-made part', combo_virtual: 'Deal' };
    var TYPE = { simple: 'sold as it is', recipe: 'made from a recipe', hybrid: 'recipe + its own stock', service: 'service, no stock' };
    var METHOD = { stock_item: 'its own stock goes down', recipe: 'its recipe items go down', none: 'no stock moves' };
    var OLD = { sale_item: 'POS Sale Item', recipe: 'Recipe / Kitchen Item', raw_material: 'Ingredient / Raw Material', packaging_material: 'Packing Material', service: 'Service Item', finished_good: 'Finished Good', semi_finished: 'Half-made part', combo_virtual: 'Deal' };

    function panel(k) { return document.getElementById('tp-' + k); }
    function tab(k) { return document.getElementById('tab-' + k); }
    function label(k) { var t = tab(k); return t ? t.querySelector('.tl').firstChild.textContent.trim() : k; }
    function val(sel) { var el = form.querySelector(sel); return el ? (el.value || '').trim() : ''; }
    function yes(name) {
        var r = form.querySelector('input[name="' + name + '"]:checked') || form.querySelector('input[type="hidden"][name="' + name + '"]');
        return !!r && r.value === '1';
    }
    // Shown by its own answers — a tab panel being hidden right now does not count.
    function shown(el) { if (!el || el.closest('[hidden]:not(.tp)')) return false; var d = el.closest('details'); return !d || d.open || el.tagName === 'SUMMARY'; }
    function selText(sel) { var s = form.querySelector(sel); return s && s.selectedIndex >= 0 ? s.options[s.selectedIndex].text.split(' — ')[0].replace(/^—\s*|\s*—$/g, '').trim() : ''; }

    function errorsIn(k) {
        var p = panel(k);
        return p ? p.querySelectorAll('.invalid-feedback, .text-danger.small, [data-client-error]').length : 0;
    }
    function complete(k) {
        switch (k) {
            case 'type': return !!form.querySelector('input[name="_card"]:checked');
            case 'details': return val('#name') !== '';
            case 'selling': return !yes('_sell') || val('#default_selling_price') !== '';
            case 'packs':
                if (!yes('_packs')) return true;
                var rows = Array.prototype.slice.call(form.querySelectorAll('#pack-rows .pack-row'));
                return rows.length > 0 && rows.every(function (r) {
                    var c = (r.querySelector('[data-f="contains"]').value || '').trim();
                    return (r.querySelector('[data-f="name"]').value || '').trim() !== '' && /^\d+$/.test(c) && +c >= 2;
                });
            case 'review': return false;
            default: return true;
        }
    }
    function refreshStates() {
        tabs.forEach(function (t) {
            var k = t.dataset.tab, n = errorsIn(k), sr = t.querySelector('[data-tab-sr]');
            t.removeAttribute('data-state');
            if (n) {
                t.setAttribute('data-errors', n);
                t.querySelector('.terr').textContent = n;
                sr.textContent = ' (' + n + (n === 1 ? ' problem' : ' problems') + ')';
            } else {
                t.removeAttribute('data-errors');
                var done = (visited[k] || isEdit) && k !== current && complete(k);
                if (done) t.setAttribute('data-state', 'done');
                sr.textContent = done ? ' (done)' : '';
            }
        });
    }

    function show(k, opts) {
        opts = opts || {};
        if (keys.indexOf(k) < 0) k = keys[0];
        visited[current] = true;
        current = k;
        tabs.forEach(function (t) {
            var on = t.dataset.tab === k;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            panel(t.dataset.tab).hidden = !on;
        });
        var i = keys.indexOf(k);
        prevBtn.hidden = i === 0;
        nextBtn.hidden = i === keys.length - 1;
        if (i < keys.length - 1) nextBtn.textContent = 'Next: ' + label(keys[i + 1]) + ' →';
        if (k === 'review') buildReview();
        refreshStates();
        // Keep the chosen tab in view on a phone, where the row scrolls sideways.
        var t = tab(k);
        list.scrollTo({ left: Math.max(0, t.offsetLeft - (list.clientWidth - t.clientWidth) / 2), behavior: reduce ? 'auto' : 'smooth' });
        // A new tab starts at its top: if the page was scrolled down into the last tab, come back up.
        var top = form.getBoundingClientRect().top + window.scrollY - 70;
        if (opts.scroll !== false && window.scrollY > top) window.scrollTo({ top: top, behavior: reduce ? 'auto' : 'smooth' });
        if (opts.focusTab) t.focus();
        if (opts.focusPanel) {
            var first = Array.prototype.slice.call(panel(k).querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])')).find(shown);
            (first || panel(k)).focus({ preventScroll: true });
        }
        live.textContent = 'Step ' + (i + 1) + ' of ' + keys.length + ': ' + label(k);
        try { history.replaceState(null, '', '#' + k); } catch (e) {}
    }
    function step(d, focusPanel) {
        var i = keys.indexOf(current) + d;
        if (i >= 0 && i < keys.length) show(keys[i], { focusPanel: focusPanel });
    }

    list.addEventListener('click', function (e) { var t = e.target.closest('[role="tab"]'); if (t) show(t.dataset.tab); });
    list.addEventListener('keydown', function (e) {
        var i = keys.indexOf(current), n = null;
        if (e.key === 'ArrowRight') n = (i + 1) % keys.length;
        else if (e.key === 'ArrowLeft') n = (i - 1 + keys.length) % keys.length;
        else if (e.key === 'Home') n = 0;
        else if (e.key === 'End') n = keys.length - 1;
        if (n === null) return;
        e.preventDefault();
        show(keys[n], { focusTab: true });
    });
    prevBtn.addEventListener('click', function () { step(-1, true); });
    nextBtn.addEventListener('click', function () { step(1, true); });

    // Check your answers (Review tab): every answer in plain words, each with a way back to its tab.
    function buildReview() {
        var dl = document.getElementById('pv2-review');
        dl.innerHTML = '';
        function row(title, value, goTo, missing) {
            var dt = document.createElement('dt'); dt.textContent = title;
            var dd = document.createElement('dd'); dd.textContent = value || '—'; if (missing) dd.className = 'miss';
            var act = document.createElement('dd');
            var b = document.createElement('button'); b.type = 'button'; b.className = 'chg'; b.dataset.goto = goTo;
            b.textContent = 'Change'; b.setAttribute('aria-label', 'Change ' + title);
            act.appendChild(b);
            dl.appendChild(dt); dl.appendChild(dd); dl.appendChild(act);
        }
        var card = form.querySelector('input[name="_card"]:checked');
        row('Type', card ? card.closest('.pcard').querySelector('b').textContent : '', 'type');
        row('Name', val('#name') || 'Not written yet', 'details', !val('#name'));
        row('Group', selText('#category_id') || 'None', 'details');
        row('Sold and counted in', selText('#unit_id') || 'Piece', 'details');
        if (shown(form.querySelector('[data-only="medicine"]'))) {
            row('Salt and strength', [val('#generic_name'), val('#strength')].filter(Boolean).join(' · ') || 'Not written', 'details');
            row('Prescription', yes('_rx') ? 'Needed' : 'Not needed', 'details');
        }
        var price = val('#default_selling_price');
        row('Sold to customers', yes('_sell') ? (price ? 'Yes, Rs ' + price : 'Yes — price not written yet') : 'No', 'selling', yes('_sell') && !price);
        if (yes('_sell')) row('Button on the counter', yes('_pos') ? 'Yes' : 'Hidden — sold in deals, as an add-on or online', 'selling');
        row('Bought from a supplier', yes('_buy') ? 'Yes' + (val('#default_purchase_price') ? ', Rs ' + val('#default_purchase_price') : '')
            + (val('#purchase_discount_value') ? ', usual discount ' + (selText('[name="purchase_discount_type"]') === 'Rs' ? 'Rs ' + val('#purchase_discount_value') : val('#purchase_discount_value') + '%') : '')
            + (val('#purchase_scheme') ? ', scheme ' + val('#purchase_scheme') : '') : 'No', 'selling');
        row('Stock counted', yes('_stock') ? 'Yes' : 'No', 'selling');
        if (shown(form.querySelector('[data-q="expiry"]'))) row('Batch and expiry', yes('_expiry') ? 'Yes' : 'No', 'selling');
        if (shown(form.querySelector('[data-q="recipe"]'))) row('Made from a recipe', yes('_recipe') ? 'Yes' : 'No', 'selling');
        row('Tax', yes('_tax') ? (val('#tax_rate_percent') || '?') + '%' : 'No', 'selling');
        if (panel('packs')) {
            var sum = (document.getElementById('pack-sum') || {}).textContent || '';
            row('Packs', yes('_packs') ? (/=/.test(sum) ? sum : 'Not finished') : 'No', 'packs', yes('_packs') && !/=/.test(sum));
        }
        if (window.pv2WsReview) window.pv2WsReview(row);
        var next = (document.getElementById('sku-next') || {}).textContent || '';
        row('Item code', val('#sku') || ('Made by Bingoo' + (next ? ' (' + next + ')' : '')), 'code');
        row('Barcode', yes('_barcode') ? (val('#barcode') || 'Not written yet') : 'No', 'code', yes('_barcode') && !val('#barcode'));
        // What the system will store, in words — the old screen's name too, so nothing is a surprise.
        var c = window.pv2Card ? window.pv2Card() : null;
        if (c) {
            var kind = (SAVED.kind && c.allowed_kinds.indexOf(SAVED.kind) >= 0) ? SAVED.kind : c.kind;
            var recipe = yes('_recipe'), stock = yes('_stock') && kind !== 'service';
            var type = recipe ? 'recipe' : (kind === 'service' ? 'service' : 'simple');
            if (SAVED.type && ((/^(simple|service)$/.test(SAVED.type) && /^(simple|service)$/.test(type)) || (recipe && SAVED.type === 'hybrid'))) type = SAVED.type;
            var method = recipe ? 'recipe' : (stock ? 'stock_item' : 'none');
            row('In the old screen', OLD[kind === 'sale_item' && method === 'recipe' ? 'recipe' : kind] || kind, 'type');
            row('In the system', (KIND[kind] || kind) + ' · ' + (TYPE[type] || type) + ' · ' + METHOD[method], 'type');
        }
        var ik = form.querySelector('#item_kind'); if (ik) row('Used as', selText('#item_kind'), 'selling');
        if (form.querySelector('input[name="_perishable"]')) row('Goes bad quickly', yes('_perishable') ? 'Yes' : 'No', 'selling');
        if (val('#storage_type')) row('Kept in', val('#storage_type'), 'selling');
        if (val('#shelf_life_days')) row('Stays good for', val('#shelf_life_days') + ' days', 'selling');
        if (val('#default_wastage_percent')) row('Usual waste', val('#default_wastage_percent') + '%', 'selling');
        if (val('#purchase_unit_id')) row('For recipe cost: bought in', selText('#purchase_unit_id') + (val('#purchase_pack_size') ? ' holding ' + val('#purchase_pack_size') : ''), 'selling');
    }
    document.getElementById('pv2-review').addEventListener('click', function (e) {
        var b = e.target.closest('[data-goto]'); if (b) show(b.dataset.goto, { focusPanel: true });
    });

    // Enter never sends the form by accident (a barcode scanner ends with Enter): it moves to the next box,
    // and from the last box of a tab to the next tab. Ctrl + Enter saves.
    form.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var t = e.target;
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            var save = document.getElementById('pv2-save');
            if (form.requestSubmit) form.requestSubmit(save); else save.click();
            return;
        }
        if (t.tagName !== 'INPUT' && t.tagName !== 'SELECT') return;
        if (['submit', 'button', 'file', 'checkbox'].indexOf(t.type) >= 0) return;
        e.preventDefault();
        var p = panel(current);
        var fields = Array.prototype.slice.call(p.querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])')).filter(shown);
        var at = fields.indexOf(t);
        if (at >= 0 && at < fields.length - 1) fields[at + 1].focus();
        else step(1, true);
    });

    // Quick checks before sending; the server checks everything again and decides.
    form.addEventListener('submit', function (e) {
        form.querySelectorAll('[data-client-error]').forEach(function (el) { el.remove(); });
        var problems = [];
        function need(k, el, msg) { if (el) problems.push([k, el, msg]); }
        if (!val('#name')) need('details', form.querySelector('#name'), 'Write the item name.');
        if (yes('_sell') && !val('#default_selling_price')) need('selling', form.querySelector('#default_selling_price'), 'Write the selling price.');
        if (panel('packs') && yes('_packs')) {
            form.querySelectorAll('#pack-rows .pack-row').forEach(function (r) {
                var name = r.querySelector('[data-f="name"]'), c = r.querySelector('[data-f="contains"]');
                if (!(name.value || '').trim()) need('packs', name, 'Give this pack a name, for example Strip.');
                if (!/^\d+$/.test((c.value || '').trim()) || +c.value < 2) need('packs', c, 'How many are in one? A whole number, 2 or more.');
            });
        }
        if (problems.length) {
            e.preventDefault();
            problems.forEach(function (p) {
                var m = document.createElement('div');
                m.className = 'text-danger small mt-1'; m.setAttribute('data-client-error', '1'); m.setAttribute('role', 'alert');
                m.textContent = p[2];
                (p[1].closest('.input-group, .pack-holds') || p[1]).insertAdjacentElement('afterend', m);
            });
            show(problems[0][0]);
            problems[0][1].focus();
            refreshStates();
            return;
        }
        submitting = true;
    });

    // Leaving with unsaved changes asks first.
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('change', function (e) {
        dirty = true;
        // An answer fixed here clears this box's earlier client message.
        var m = e.target.parentElement && e.target.parentElement.parentElement && e.target.parentElement.parentElement.querySelector('[data-client-error]');
        if (m && (e.target.value || '').trim()) { m.remove(); refreshStates(); }
    });
    window.addEventListener('beforeunload', function (e) { if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; } });

    // Start: the first tab with a problem after a failed save, else the tab in the address (#packs), else the first.
    var hash = (location.hash || '').replace('#', '');
    if (hash === 'packs-step') hash = 'packs';
    var withError = keys.filter(function (k) { return errorsIn(k) > 0; })[0];
    show(withError || (keys.indexOf(hash) >= 0 ? hash : keys[0]), { scroll: false });
})();

// CATERING-SIMPLIFY-1 — "Change in Cost blocks" opens the Event costing tab; "Priced by" there locks or opens the
// Selling price box as it changes (the server decides the same way on save).
(function () {
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-go-tab]');
        var t = b && document.getElementById('tab-' + b.dataset.goTab);
        if (t) t.click();
    });
    var wrap = document.querySelector('[data-price-lock]');
    var box = document.getElementById('default_selling_price');
    if (!wrap || !box) return;
    var open = document.getElementById('sp-help'), lockedNote = wrap.querySelector('[data-sp-locked]');
    var typed = box.disabled ? wrap.dataset.posPrice : box.value;
    function apply(blocks) {
        if (blocks && !box.disabled) { typed = box.value; box.value = wrap.dataset.blockPrice; }
        if (!blocks && box.disabled) { box.value = typed; }
        box.disabled = blocks;
        if (open) open.hidden = blocks;
        if (lockedNote) lockedNote.hidden = !blocks;
    }
    document.querySelectorAll('input[name="catering[costing_mode]"]').forEach(function (r) {
        r.addEventListener('change', function () { if (r.checked) apply(r.value === 'blocks'); });
    });
})();

@include('tenant.products.partials.ws-script')
</script>
@endpush
