@extends('layouts.app')

@section('title', $title)

@php
    /** PRODUCT-UX-1: guided product setup. UI only — same fields, same controller. */
    $u = auth()->user();
    $context = $context ?? 'catalog';
    $isManufacturing = $context === 'manufacturing';
    $kitchenAvailable = (bool) ($u?->can('tenant.recipes.index') ?? false);
    $mfgAvailable     = (bool) (($u?->can('tenant.manufacturing.bom.index') ?? false) || ($u?->can('tenant.manufacturing.products.index') ?? false));
    $backUrl = $isManufacturing ? url('/manufacturing/products') : url('/products');
    $formUrl = $product
        ? ($isManufacturing ? url('/manufacturing/products/' . $product->id) : url('/products/' . $product->id))
        : ($isManufacturing ? url('/manufacturing/products') : url('/products'));

    // Detect the current product's setup mode from its existing flags (edit screens).
    $detectMode = function ($p) use ($mfgAvailable, $kitchenAvailable) {
        if (! $p) return 'pos_sale';
        $kind = $p->product_kind ?? 'sale_item';
        if ($p->product_type === 'service' || $kind === 'service')      return 'service';
        if ($kind === 'raw_material')      return ($p->can_be_bom_component && $mfgAvailable) ? 'mfg_raw' : 'raw_material';
        if ($kind === 'packaging_material') return 'packaging';
        if ($kind === 'finished_good')      return $mfgAvailable ? 'mfg_fg' : 'advanced';
        if ($kind === 'sale_item' && $p->inventory_consumption_method === 'recipe') return 'recipe';
        if ($kind === 'sale_item')          return 'pos_sale';
        return 'advanced';
    };
    $currentMode = old('_setup_mode', $detectMode($product ?? null));
    if (! $product && ! old('_setup_mode') && $isManufacturing) {
        $currentMode = 'mfg_raw';
    }

    // Card catalogue (manufacturing cards gated by availability).
    $modeCards = [
        'pos_sale'     => ['POS Sale Item', 'ti-shopping-cart', 'Sold at the till, tracked in stock', ! $isManufacturing],
        'recipe'       => ['Recipe / Kitchen Item', 'ti-chef-hat', 'Sold in POS, consumes ingredients', ! $isManufacturing],
        'raw_material' => ['Ingredient / Raw Material', 'ti-meat', 'Hidden from POS, used in recipes', ! $isManufacturing],
        'packaging'    => ['Packing Material', 'ti-package', 'Hidden from POS, packaging stock', ! $isManufacturing],
        'service'      => ['Service Item', 'ti-businessplan', 'Sold in POS, no stock', ! $isManufacturing],
        'mfg_raw'      => ['Manufacturing Raw Material', 'ti-building-factory-2', 'BOM component', $isManufacturing && $mfgAvailable],
        'mfg_fg'       => ['Manufacturing Finished Good', 'ti-box', 'Produced via BOM', $isManufacturing && $mfgAvailable],
        'advanced'     => ['Advanced / Custom', 'ti-adjustments', 'Show every field', true],
    ];
    if (! ($modeCards[$currentMode][3] ?? false)) {
        $currentMode = 'advanced';
    }
@endphp

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">{{ $title }}</h1>
    <a href="{{ $backUrl }}" class="btn btn-light">{{ $isManufacturing ? 'Back to Manufacturing Products' : 'Back to Catalog Products' }}</a>
</div>

<style>
    .pmode-card { border:1px solid #dee2e6; border-radius:.5rem; padding:.6rem .8rem; text-align:left; background:#fff; min-width:170px; flex:1 1 170px; transition:.12s; }
    .pmode-card:hover { border-color:#0d6efd; }
    .pmode-card.active { border-color:#0d6efd; background:#eef4ff; box-shadow:0 0 0 1px #0d6efd inset; }
    .pmode-card .pmode-title { font-weight:600; font-size:.9rem; }
    .pmode-card .pmode-desc { font-size:.72rem; color:#6c757d; }
    .pchip { font-size:.72rem; }
</style>

<div class="card">
    <div class="card-body">
        <form method="POST"
              action="{{ $formUrl }}"
              novalidate>
            @csrf
            @if($product) @method('PUT') @endif
            <input type="hidden" name="_setup_mode" id="_setup_mode" value="{{ $currentMode }}">

            @if($errors->any())
                <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
            @endif

            @if($isManufacturing)
                <div class="alert alert-info d-flex align-items-start gap-2">
                    <i class="ti ti-info-circle fs-18 mt-1"></i>
                    <div>
                        Use this screen for materials and finished goods used in manufacturing.
                        Choose <strong>Raw Material</strong> for items consumed in BOMs and
                        <strong>Finished Good</strong> for products produced through WIP/FG receipts.
                        Use Advanced only if the product must also appear in POS or sales.
                    </div>
                </div>
            @endif

            {{-- ── Product Setup Mode ─────────────────────────────────────────── --}}
            <div class="mb-4">
                <h5 class="mb-1">What type of product is this?</h5>
                <p class="text-muted small mb-2">Pick a type to show only the fields you need. Choose <strong>Advanced / Custom</strong> for full control.</p>
                <div class="d-flex flex-wrap gap-2" id="pmode-cards">
                    @foreach($modeCards as $key => [$label, $icon, $desc, $show])
                        @if($show)
                            <button type="button" class="pmode-card {{ $currentMode === $key ? 'active' : '' }}" data-mode="{{ $key }}">
                                <div class="pmode-title"><i class="ti {{ $icon }} me-1"></i>{{ $label }}</div>
                                <div class="pmode-desc">{{ $desc }}</div>
                            </button>
                        @endif
                    @endforeach
                </div>
                <div id="pmode-summary" class="alert alert-light border py-2 px-3 mt-2 mb-0 small"></div>
                <div id="pos-hide-warn" class="alert alert-warning py-2 px-3 mt-2 mb-0 small d-none">
                    <i class="ti ti-alert-triangle me-1"></i>This type is normally <strong>hidden from POS</strong>. Use Advanced if you need it sold at the till.
                </div>
                <div id="mode-chips" class="d-flex flex-wrap gap-1 mt-2"></div>
            </div>

            {{-- ── Basic Information ──────────────────────────────────────────── --}}
            <div class="psection">
            <h5 class="mb-3">Basic Information</h5>
            <div class="row g-3">
                <div class="col-md-3 pf" data-pg="always">
                    <label for="sku" class="form-label required">SKU</label>
                    <input id="sku" type="text" name="sku"
                           value="{{ old('sku', $product?->sku) }}"
                           class="form-control @error('sku') is-invalid @enderror"
                           maxlength="100" required>
                    @error('sku') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-help">Will be uppercased automatically.</div>
                </div>

                <div class="col-md-5 pf" data-pg="always">
                    <label for="name" class="form-label required">Name</label>
                    <input id="name" type="text" name="name"
                           value="{{ old('name', $product?->name) }}"
                           class="form-control @error('name') is-invalid @enderror"
                           maxlength="190" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4 pf" data-pg="always">
                    <label for="product_type" class="form-label required">Product Type</label>
                    <select id="product_type" name="product_type"
                            class="form-select @error('product_type') is-invalid @enderror" required>
                        @foreach(['simple'=>'Simple','recipe'=>'Recipe','hybrid'=>'Hybrid','service'=>'Service'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('product_type', $product?->product_type) === $val)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('product_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4 pf" data-pg="always">
                    <label for="category_id" class="form-label">Category</label>
                    <select id="category_id" name="category_id"
                            class="form-select @error('category_id') is-invalid @enderror">
                        <option value="">— None —</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}"
                                    @selected(old('category_id', $product?->category_id) == $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('category_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4 pf" data-pg="always">
                    <label for="unit_id" class="form-label">Unit of Measure</label>
                    <select id="unit_id" name="unit_id"
                            class="form-select @error('unit_id') is-invalid @enderror">
                        <option value="">— None —</option>
                        @foreach($units as $unit)
                            <option value="{{ $unit->id }}"
                                    @selected(old('unit_id', $product?->unit_id) == $unit->id)>
                                {{ $unit->name }} ({{ $unit->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('unit_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4 pf" data-pg="always">
                    <label for="status" class="form-label required">Status</label>
                    <select id="status" name="status"
                            class="form-select @error('status') is-invalid @enderror" required>
                        <option value="active"   @selected(old('status', $product?->status ?? 'active') === 'active')>Active</option>
                        <option value="inactive" @selected(old('status', $product?->status) === 'inactive')>Inactive</option>
                    </select>
                    @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-12 pf" data-pg="always">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" rows="3"
                              class="form-control @error('description') is-invalid @enderror">{{ old('description', $product?->description) }}</textarea>
                    @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                @if(!$product)
                <div class="col-md-4 pf" data-pg="always">
                    <label for="barcode" class="form-label">Default Variant Barcode</label>
                    <input id="barcode" type="text" name="barcode"
                           value="{{ old('barcode') }}"
                           class="form-control @error('barcode') is-invalid @enderror"
                           maxlength="100">
                    @error('barcode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-help">Leave blank to assign later or auto-generate.</div>
                </div>
                @endif
            </div>
            </div>

            {{-- ── Pricing ───────────────────────────────────────────────────── --}}
            <div class="psection">
            <h5 class="mb-3 mt-4">Pricing &amp; Tax</h5>
            <div class="row g-3">
                <div class="col-md-3 pf" data-pg="purchase" data-link="purchasable">
                    <label for="default_purchase_price" class="form-label">Purchase Price</label>
                    <input id="default_purchase_price" type="number" name="default_purchase_price"
                           step="0.01" min="0"
                           value="{{ old('default_purchase_price', $product?->default_purchase_price ?? 0) }}"
                           class="form-control @error('default_purchase_price') is-invalid @enderror">
                    @error('default_purchase_price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                {{-- KITCHEN-RECIPE-COST-1 recipe-costing pack fields --}}
                <div class="col-md-3 pf" data-pg="pack" data-link="purchasable">
                    <label for="purchase_unit_id" class="form-label">Purchase Unit</label>
                    <select id="purchase_unit_id" name="purchase_unit_id" class="form-select">
                        <option value="">— Same as stock unit —</option>
                        @foreach($units as $unit)
                            <option value="{{ $unit->id }}" @selected(old('purchase_unit_id', $product?->purchase_unit_id) == $unit->id)>{{ $unit->name }} ({{ $unit->code }})</option>
                        @endforeach
                    </select>
                    <div class="form-help">Unit you buy in: KG, PKT, ROLL, PC.</div>
                </div>

                <div class="col-md-3 pf" data-pg="pack" data-link="purchasable">
                    <label for="purchase_pack_size" class="form-label">Purchase Pack Size</label>
                    <input id="purchase_pack_size" type="number" name="purchase_pack_size"
                           step="0.0001" min="0"
                           value="{{ old('purchase_pack_size', $product?->purchase_pack_size) }}"
                           class="form-control @error('purchase_pack_size') is-invalid @enderror" placeholder="e.g. 1000">
                    @error('purchase_pack_size') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-help">Recipe units inside one purchase unit. 1 KG = 1000 g, 1 roll = 50 pcs. Used for recipe costing.</div>
                </div>

                <div class="col-md-3 pf" data-pg="sell">
                    <label for="default_selling_price" class="form-label required">Selling Price</label>
                    <input id="default_selling_price" type="number" name="default_selling_price"
                           step="0.01" min="0"
                           value="{{ old('default_selling_price', $product?->default_selling_price ?? 0) }}"
                           class="form-control @error('default_selling_price') is-invalid @enderror" required>
                    @error('default_selling_price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-help">Used when this product is sold in POS or Sales.</div>
                </div>

                <div class="col-md-2 pf" data-pg="tax" data-link="taxable">
                    <label for="tax_rate_percent" class="form-label">Tax Rate (%)</label>
                    <input id="tax_rate_percent" type="number" name="tax_rate_percent"
                           step="0.01" min="0" max="100"
                           value="{{ old('tax_rate_percent', $product?->tax_rate_percent) }}"
                           class="form-control @error('tax_rate_percent') is-invalid @enderror">
                    @error('tax_rate_percent') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-2 pf" data-pg="tax" data-link="not-taxable">
                    <label class="form-label text-muted">Tax Rate (%)</label>
                    <div class="form-help mt-1"><i class="ti ti-lock me-1"></i>Enable <strong>Taxable</strong> to enter a tax rate.</div>
                </div>
            </div>
            </div>

            {{-- ── Stock & Reorder ───────────────────────────────────────────── --}}
            <div class="psection">
            <h5 class="mb-3 mt-4">Stock &amp; Reorder</h5>
            <div class="row g-3">
                <div class="col-md-3 pf" data-pg="stock" data-link="stock">
                    <label for="reorder_level" class="form-label">Reorder Level</label>
                    <input id="reorder_level" type="number" name="reorder_level"
                           step="0.001" min="0"
                           value="{{ old('reorder_level', $product?->defaultVariant?->reorder_level ?? 0) }}"
                           class="form-control @error('reorder_level') is-invalid @enderror">
                    @error('reorder_level') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3 pf" data-pg="stock" data-link="stock">
                    <label for="reorder_quantity" class="form-label">Reorder Quantity</label>
                    <input id="reorder_quantity" type="number" name="reorder_quantity"
                           step="0.001" min="0"
                           value="{{ old('reorder_quantity', $product?->defaultVariant?->reorder_quantity ?? 0) }}"
                           class="form-control @error('reorder_quantity') is-invalid @enderror">
                    @error('reorder_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            </div>

            {{-- ── Settings (flags) ──────────────────────────────────────────── --}}
            <div class="psection">
            <h5 class="mb-3 mt-4">Settings</h5>
            <div class="row g-3">
                <div class="col-12 d-flex flex-wrap gap-4">
                    <div class="form-check pf" data-pg="sell">
                        <input id="is_sellable" type="checkbox" name="is_sellable" value="1"
                               class="form-check-input"
                               @checked(old('is_sellable', $product?->is_sellable ?? true))>
                        <label for="is_sellable" class="form-check-label">Sellable</label>
                    </div>
                    <div class="form-check pf" data-pg="purchase">
                        <input id="is_purchasable" type="checkbox" name="is_purchasable" value="1"
                               class="form-check-input"
                               @checked(old('is_purchasable', $product?->is_purchasable ?? true))>
                        <label for="is_purchasable" class="form-check-label">Purchasable</label>
                    </div>
                    <div class="form-check pf" data-pg="stock">
                        <input id="is_stock_tracked" type="checkbox" name="is_stock_tracked" value="1"
                               class="form-check-input"
                               @checked(old('is_stock_tracked', $product?->is_stock_tracked ?? true))>
                        <label for="is_stock_tracked" class="form-check-label">Track Stock</label>
                    </div>
                    <div class="form-check pf" data-pg="stock">
                        <input id="has_variants" type="checkbox" name="has_variants" value="1"
                               class="form-check-input"
                               @checked(old('has_variants', $product?->has_variants))>
                        <label for="has_variants" class="form-check-label">Has Variants</label>
                    </div>
                    <div class="form-check pf" data-pg="batch">
                        <input id="has_expiry" type="checkbox" name="has_expiry" value="1"
                               class="form-check-input"
                               @checked(old('has_expiry', $product?->has_expiry))>
                        <label for="has_expiry" class="form-check-label">Track Expiry</label>
                    </div>
                    <div class="form-check pf" data-pg="batch">
                        <input id="requires_batch" type="checkbox" name="requires_batch" value="1"
                               class="form-check-input"
                               @checked(old('requires_batch', $product?->requires_batch))>
                        <label for="requires_batch" class="form-check-label">Batch Tracking</label>
                    </div>
                    <div class="form-check pf" data-pg="tax">
                        <input id="is_taxable" type="checkbox" name="is_taxable" value="1"
                               class="form-check-input"
                               @checked(old('is_taxable', $product?->is_taxable))>
                        <label for="is_taxable" class="form-check-label">Taxable</label>
                    </div>
                </div>
                <div class="col-12" id="stock-off-note" style="display:none">
                    <div class="form-help"><i class="ti ti-info-circle me-1"></i>Stock will not be deducted for this product.</div>
                </div>
            </div>
            </div>

            {{-- ── Product Role & Visibility (PRODUCT-BOUNDARY-2) ─────────────── --}}
            <div class="psection">
            <h5 class="mb-3 mt-4">Product Role &amp; Visibility</h5>
            <div class="row g-3">
                <div class="col-md-4 pf" data-pg="role">
                    <label for="product_kind" class="form-label">Product Kind</label>
                    <select id="product_kind" name="product_kind"
                            class="form-select @error('product_kind') is-invalid @enderror">
                        @foreach(\App\Models\Tenant\Product::KINDS as $val => $label)
                            <option value="{{ $val }}" @selected(old('product_kind', $product?->product_kind ?? 'sale_item') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('product_kind') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-help">Raw materials &amp; packaging are normally hidden from POS. Finished goods show in POS only when saleable.</div>
                </div>
                <div class="col-md-8">
                    <div class="d-flex flex-wrap gap-4 mt-2">
                        <div class="form-check pf" data-pg="pos">
                            <input id="is_pos_visible" type="checkbox" name="is_pos_visible" value="1" class="form-check-input"
                                   @checked(old('is_pos_visible', $product?->is_pos_visible ?? true))>
                            <label for="is_pos_visible" class="form-check-label">POS Visible</label>
                        </div>
                        <div class="form-check pf" data-pg="mfg">
                            <input id="can_be_bom_component" type="checkbox" name="can_be_bom_component" value="1" class="form-check-input"
                                   @checked(old('can_be_bom_component', $product?->can_be_bom_component ?? false))>
                            <label for="can_be_bom_component" class="form-check-label">Can be BOM Component</label>
                        </div>
                        <div class="form-check pf" data-pg="mfg">
                            <input id="can_be_bom_output" type="checkbox" name="can_be_bom_output" value="1" class="form-check-input"
                                   @checked(old('can_be_bom_output', $product?->can_be_bom_output ?? false))>
                            <label for="can_be_bom_output" class="form-check-label">Can be BOM Output</label>
                        </div>
                        <div class="form-check pf" data-pg="mfg">
                            <input id="is_manufactured_finished_good" type="checkbox" name="is_manufactured_finished_good" value="1" class="form-check-input"
                                   @checked(old('is_manufactured_finished_good', $product?->is_manufactured_finished_good ?? false))>
                            <label for="is_manufactured_finished_good" class="form-check-label">Manufactured Finished Good</label>
                        </div>
                    </div>
                    <div class="form-help mt-1 pf" data-pg="mfg">
                        <strong>BOM Component</strong> = used as input inside manufacturing.
                        <strong>BOM Output</strong> = product produced by manufacturing.
                        <strong>Manufactured FG</strong> affects future manufacturing COGS.
                    </div>
                </div>
            </div>
            </div>
            <script>
            (function () {
                var kind = document.getElementById('product_kind');
                if (!kind) return;
                var set = function (id, val) { var el = document.getElementById(id); if (el) el.checked = !!val; };
                // Smart defaults applied only when the user CHANGES the kind directly (Advanced),
                // never on load — existing products keep their saved flags.
                kind.addEventListener('change', function () {
                    switch (kind.value) {
                        case 'raw_material':
                        case 'packaging_material':
                            set('is_pos_visible', false); set('is_sellable', false); set('is_purchasable', true);
                            set('can_be_bom_component', true); set('can_be_bom_output', false); set('is_manufactured_finished_good', false);
                            break;
                        case 'semi_finished':
                            set('is_pos_visible', false); set('is_sellable', false); set('is_purchasable', false);
                            set('can_be_bom_component', true); set('can_be_bom_output', true);
                            break;
                        case 'finished_good':
                            set('is_pos_visible', false); set('is_purchasable', false);
                            set('can_be_bom_component', false); set('can_be_bom_output', true); set('is_manufactured_finished_good', true);
                            break;
                        case 'service':
                            set('is_pos_visible', true); set('is_sellable', true); set('is_stock_tracked', false);
                            set('can_be_bom_component', false); set('can_be_bom_output', false); set('is_manufactured_finished_good', false);
                            break;
                        case 'combo_virtual':
                            set('is_pos_visible', false); set('is_sellable', false); set('is_purchasable', false);
                            break;
                        default: // sale_item
                            set('is_pos_visible', true); set('is_sellable', true); set('is_purchasable', true);
                            set('can_be_bom_component', false); set('can_be_bom_output', false); set('is_manufactured_finished_good', false);
                    }
                    if (window.posUpdateChips) window.posUpdateChips();
                });
            })();
            </script>

            {{-- ── Inventory Profile (kitchen) ───────────────────────────────── --}}
            <div class="psection">
            <h5 class="mb-3 mt-4">Inventory &amp; Kitchen Profile</h5>
            <div class="row g-3">
                <div class="col-md-4 pf" data-pg="kitchen">
                    <label for="item_kind" class="form-label">Item Kind</label>
                    <select id="item_kind" name="item_kind"
                            class="form-select @error('item_kind') is-invalid @enderror">
                        @foreach(['finished_good'=>'Finished Good','ingredient'=>'Ingredient','both'=>'Both'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('item_kind', $product?->item_kind ?? 'finished_good') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('item_kind') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4 pf" data-pg="kitchen">
                    <label for="inventory_consumption_method" class="form-label">Consumption Method</label>
                    <select id="inventory_consumption_method" name="inventory_consumption_method"
                            class="form-select @error('inventory_consumption_method') is-invalid @enderror">
                        @foreach(['stock_item'=>'Stock Item (direct deduction)','recipe'=>'Recipe / BOM','none'=>'None (no stock)'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('inventory_consumption_method', $product?->inventory_consumption_method ?? 'stock_item') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('inventory_consumption_method') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-help">Recipe mode deducts ingredients at time of sale.</div>
                </div>

                <div class="col-md-4 pf" data-pg="kitchen">
                    <label for="storage_type" class="form-label">Storage Type</label>
                    <input id="storage_type" type="text" name="storage_type"
                           value="{{ old('storage_type', $product?->storage_type) }}"
                           class="form-control @error('storage_type') is-invalid @enderror"
                           maxlength="50" placeholder="e.g. refrigerated, dry, frozen">
                    @error('storage_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3 pf" data-pg="kitchen">
                    <label for="shelf_life_days" class="form-label">Shelf Life (days)</label>
                    <input id="shelf_life_days" type="number" name="shelf_life_days"
                           value="{{ old('shelf_life_days', $product?->shelf_life_days) }}"
                           class="form-control @error('shelf_life_days') is-invalid @enderror"
                           min="1" max="65535">
                    @error('shelf_life_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3 pf" data-pg="kitchen">
                    <label for="default_wastage_percent" class="form-label">Default Wastage %</label>
                    <input id="default_wastage_percent" type="number" name="default_wastage_percent"
                           value="{{ old('default_wastage_percent', $product?->default_wastage_percent ?? 0) }}"
                           class="form-control @error('default_wastage_percent') is-invalid @enderror"
                           step="0.01" min="0" max="100">
                    @error('default_wastage_percent') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-12 pf" data-pg="kitchen">
                    <div class="form-check">
                        <input id="is_perishable" type="checkbox" name="is_perishable" value="1"
                               class="form-check-input"
                               @checked(old('is_perishable', $product?->is_perishable))>
                        <label for="is_perishable" class="form-check-label">Perishable Item</label>
                    </div>
                </div>
            </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Product</button>
                <a href="{{ $backUrl }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var IS_EDIT = {{ $product ? 'true' : 'false' }};
    var MFG = {{ $mfgAvailable ? 'true' : 'false' }};
    var KITCHEN = {{ $kitchenAvailable ? 'true' : 'false' }};
    var CONTEXT = {!! json_encode($context) !!};

    // groups always-visible regardless of mode
    var ALL_GROUPS = ['sell','pos','tax','purchase','pack','stock','batch','kitchen','mfg','role'];

    var MODES = {
        pos_sale:     { groups:['sell','pos','tax','purchase','stock'],
                        def:{ product_kind:'sale_item', is_sellable:1, is_pos_visible:1, is_purchasable:1, is_stock_tracked:1, item_kind:'finished_good', inventory_consumption_method:'stock_item', can_be_bom_component:0, can_be_bom_output:0, is_manufactured_finished_good:0 } },
        recipe:       { groups:['sell','pos','tax','purchase','stock','kitchen'],
                        def:{ product_kind:'sale_item', is_sellable:1, is_pos_visible:1, is_purchasable:1, is_stock_tracked:1, item_kind:'finished_good', inventory_consumption_method:'recipe', can_be_bom_component:0, can_be_bom_output:0, is_manufactured_finished_good:0 } },
        raw_material: { groups:['purchase','pack','stock','batch','kitchen'],
                        def:{ product_kind:'raw_material', is_sellable:0, is_pos_visible:0, is_purchasable:1, is_stock_tracked:1, item_kind:'ingredient', inventory_consumption_method:'stock_item', can_be_bom_component:0, can_be_bom_output:0, is_manufactured_finished_good:0 } },
        packaging:    { groups:['purchase','pack','stock'],
                        def:{ product_kind:'packaging_material', is_sellable:0, is_pos_visible:0, is_purchasable:1, is_stock_tracked:1, item_kind:'ingredient', inventory_consumption_method:'stock_item', can_be_bom_component:0, can_be_bom_output:0, is_manufactured_finished_good:0 } },
        service:      { groups:['sell','pos','tax'],
                        def:{ product_type:'service', product_kind:'service', is_sellable:1, is_pos_visible:1, is_purchasable:0, is_stock_tracked:0, item_kind:'finished_good', inventory_consumption_method:'none', can_be_bom_component:0, can_be_bom_output:0, is_manufactured_finished_good:0 } },
        mfg_raw:      { groups:['purchase','pack','stock','mfg'],
                        def:{ product_kind:'raw_material', is_sellable:0, is_pos_visible:0, is_purchasable:1, is_stock_tracked:1, item_kind:'ingredient', inventory_consumption_method:'stock_item', can_be_bom_component:1, can_be_bom_output:0, is_manufactured_finished_good:0 } },
        mfg_fg:       { groups:['purchase','pack','stock','mfg'],
                        def:{ product_kind:'finished_good', is_sellable:0, is_pos_visible:0, is_purchasable:0, is_stock_tracked:1, item_kind:'finished_good', inventory_consumption_method:'stock_item', can_be_bom_component:0, can_be_bom_output:1, is_manufactured_finished_good:1 } },
        advanced:     { groups:'all', def:null },
    };

    function visibleGroups(mode) {
        var m = MODES[mode] || MODES.pos_sale;
        var groups = (m.groups === 'all') ? ALL_GROUPS.slice() : m.groups.slice();
        if (m.groups === 'all') {
            if (!MFG)     groups = groups.filter(function (g) { return g !== 'mfg'; });
            if (!KITCHEN) groups = groups.filter(function (g) { return g !== 'kitchen'; });
        }
        groups.push('always');
        return groups;
    }

    function setField(id, val) {
        var el = document.getElementById(id);
        if (!el) return;
        if (el.type === 'checkbox') { el.checked = !!val; }
        else { el.value = val; }
    }

    function applyDefaults(def) {
        if (!def) return;
        Object.keys(def).forEach(function (k) { setField(k, def[k]); });
    }

    function refreshSections() {
        document.querySelectorAll('.psection').forEach(function (sec) {
            var fields = sec.querySelectorAll('.pf');
            if (!fields.length) { sec.style.display = ''; return; }
            var anyVisible = Array.prototype.some.call(fields, function (f) { return f.style.display !== 'none'; });
            sec.style.display = anyVisible ? '' : 'none';
        });
    }

    // UX-POLISH-1: short per-mode explanation under the type cards.
    var SUMMARIES = {
        pos_sale:     'This item appears in POS and can be sold to customers.',
        recipe:       'This item is sold in POS and consumes ingredients through Recipes/BOM.',
        raw_material: 'This item is not sold in POS. It is purchased and consumed by recipes.',
        packaging:    'This item is hidden from POS and used for takeaway/delivery packaging.',
        service:      'This item is sold without stock tracking.',
        mfg_raw:      'This item is hidden from POS and used as a manufacturing BOM component.',
        mfg_fg:       'This item is produced by manufacturing and hidden from POS unless explicitly made saleable.',
        advanced:     CONTEXT === 'manufacturing'
            ? 'Advanced mode shows every field, including POS visibility, for special cross-context products.'
            : 'Advanced mode — every field is shown for full manual control.'
    };
    function updateSummary(mode) {
        var box = document.getElementById('pmode-summary');
        if (box) box.innerHTML = '<i class="ti ti-info-circle me-1"></i>' + (SUMMARIES[mode] || SUMMARIES.pos_sale);
    }

    function isChecked(id) { var e = document.getElementById(id); return e ? !!e.checked : false; }

    // A field linked to a control checkbox is only shown when that checkbox agrees.
    function linkOk(f) {
        var link = f.getAttribute('data-link');
        if (!link) return true;
        if (link === 'taxable')     return isChecked('is_taxable');
        if (link === 'not-taxable') return !isChecked('is_taxable');
        if (link === 'purchasable') return isChecked('is_purchasable');
        if (link === 'stock')       return isChecked('is_stock_tracked');
        return true;
    }

    // Show/hide every .pf using (group ∈ mode) AND its data-link condition.
    function renderVisibility(groups) {
        document.querySelectorAll('.pf').forEach(function (f) {
            var pg = f.getAttribute('data-pg') || 'always';
            var vis = (groups.indexOf(pg) !== -1) && linkOk(f);
            f.style.display = vis ? '' : 'none';
        });
        var note = document.getElementById('stock-off-note');
        if (note) note.style.display = (groups.indexOf('stock') !== -1 && !isChecked('is_stock_tracked')) ? '' : 'none';
        refreshSections();
    }

    function applyMode(mode, withDefaults) {
        var hid = document.getElementById('_setup_mode');
        if (hid) hid.value = mode;
        document.querySelectorAll('#pmode-cards .pmode-card').forEach(function (c) {
            c.classList.toggle('active', c.getAttribute('data-mode') === mode);
        });

        // Defaults BEFORE visibility so linked fields reflect the new checkbox states.
        if (withDefaults && MODES[mode] && MODES[mode].def) applyDefaults(MODES[mode].def);

        renderVisibility(visibleGroups(mode));

        var def = MODES[mode] && MODES[mode].def;
        var hidesPos = def && (def.is_pos_visible === 0 || def.is_sellable === 0);
        var warn = document.getElementById('pos-hide-warn');
        if (warn) warn.classList.toggle('d-none', !hidesPos);

        updateSummary(mode);
        updateChips();
    }

    // Re-evaluate visibility for the current mode when a linked checkbox toggles.
    function refreshVisibility() {
        var hid = document.getElementById('_setup_mode');
        renderVisibility(visibleGroups(hid ? hid.value : 'pos_sale'));
        updateChips();
    }

    function chip(text, cls) { return '<span class="badge pchip bg-' + cls + '">' + text + '</span>'; }
    function updateChips() {
        var box = document.getElementById('mode-chips');
        if (!box) return;
        var get = function (id) { var e = document.getElementById(id); return e ? (e.type === 'checkbox' ? e.checked : e.value) : null; };
        var mode = (document.getElementById('_setup_mode') || {}).value || 'pos_sale';
        var showMfgBadges = CONTEXT === 'manufacturing' || mode === 'advanced';
        var out = [];
        out.push(get('is_pos_visible') ? chip('Appears in POS', 'success') : chip('Hidden from POS', 'secondary'));
        if (get('is_sellable')) out.push(chip('Sellable', 'primary'));
        if (get('is_stock_tracked')) out.push(chip('Stock Tracked', 'light text-dark border'));
        if (get('inventory_consumption_method') === 'recipe') out.push(chip('Consumed by recipe', 'info text-dark'));
        if (get('inventory_consumption_method') === 'none') out.push(chip('No stock consumption', 'light text-dark border'));
        if (!get('is_stock_tracked')) out.push(chip('No stock tracking', 'light text-dark border'));
        if (showMfgBadges && get('can_be_bom_component')) out.push(chip('BOM Component', 'warning text-dark'));
        if (showMfgBadges && get('can_be_bom_output')) out.push(chip('BOM Output', 'info text-dark'));
        if (showMfgBadges && get('is_manufactured_finished_good')) out.push(chip('Manufactured FG', 'dark'));
        box.innerHTML = out.join(' ');
    }
    window.posUpdateChips = updateChips;

    document.querySelectorAll('#pmode-cards .pmode-card').forEach(function (card) {
        card.addEventListener('click', function () { applyMode(card.getAttribute('data-mode'), true); });
    });

    // Linked control checkboxes re-evaluate dependent field visibility (+ chips).
    ['is_taxable','is_purchasable','is_stock_tracked'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', refreshVisibility);
    });
    // Entering a tax rate > 0 auto-enables Taxable.
    var taxRate = document.getElementById('tax_rate_percent');
    if (taxRate) taxRate.addEventListener('input', function () {
        if (parseFloat(taxRate.value) > 0) {
            var t = document.getElementById('is_taxable');
            if (t && !t.checked) t.checked = true;
        }
        refreshVisibility();
    });
    // Reflect a few other field edits in the chips live.
    ['is_pos_visible','is_sellable','is_stock_tracked','can_be_bom_component','can_be_bom_output','is_manufactured_finished_good','inventory_consumption_method'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', updateChips);
    });

    // Initial render: show the detected mode's sections WITHOUT rewriting any saved data.
    applyMode({!! json_encode($currentMode) !!}, false);
})();
</script>
@endsection
