<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Catalog\BarcodeLookupService;
use App\Models\Tenant\Category;
use App\Models\Tenant\ModifierGroup;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBarcode;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $context = $this->productContext($request);
        $query = Product::with(['category', 'unit', 'defaultVariant'])->latest();
        $this->applyContextFilter($query, $context, $request);

        // PRODUCT-ROLE-FILTER-1: offer only the roles this list can actually hold (the catalog never
        // shows raw materials, the kitchen list never shows sale items) — read before the other filters.
        $kindsHere = (clone $query)->reorder()->distinct()->pluck('product_kind')
            ->map(fn ($k) => $k ?: Product::KIND_SALE_ITEM)->unique()->values()->all();

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('variants', fn ($v) => $v
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->product_type);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // PRODUCT-ROLE-FILTER-1 — Role / Visibility, as the badges on each row read. Roles: ANY ticked
        // (an item has one role). The rest: ALL ticked — "Purchasable + Sellable" means both.
        $chosenRoles = $this->pickedFrom($request->input('roles', []), array_keys(Product::KINDS));
        $chosenFlags = $this->pickedFrom($request->input('flags', []), array_keys(self::FLAG_FILTERS));
        if ($chosenRoles) {
            $query->where(function ($q) use ($chosenRoles) {
                $q->whereIn('product_kind', $chosenRoles);
                if (in_array(Product::KIND_SALE_ITEM, $chosenRoles, true)) {
                    $q->orWhereNull('product_kind');   // an unset role reads as Sale Item on the badge
                }
            });
        }
        foreach ($chosenFlags as $flag) {
            match ($flag) {
                'purchasable'       => $query->where('is_purchasable', true),
                'sellable'          => $query->where('is_sellable', true),
                // Same rule as Product::isPosVisible(), which draws the badge.
                'pos_visible'       => $query->where('status', 'active')->where('is_sellable', true)->where('is_pos_visible', true),
                'hidden_from_pos'   => $query->where(fn ($q) => $q->where('status', '!=', 'active')->orWhere('is_sellable', false)->orWhere('is_pos_visible', false)),
                'stock_tracked'     => $query->where('is_stock_tracked', true),
                'not_stock_tracked' => $query->where('is_stock_tracked', false),
                'service'           => $query->where(fn ($q) => $q->where('product_type', 'service')->orWhere('product_kind', Product::KIND_SERVICE)),
                'recipe'            => $query->where('inventory_consumption_method', 'recipe'),
            };
        }

        // PRODUCT-LIST-PLAIN-1 / W3/W4 S1: where the new product screen is on, the list keeps three things apart —
        // STRUCTURE (simple, with variants, a deal), ROLE (the recognisable role names) and BEHAVIOUR (bought, sold,
        // stock counted, on the counter, recipe-based) — and asks each behaviour Any / Yes / No, so two filters can
        // never contradict. Presentation and filtering only: no role or flag is changed here.
        $plain = $this->usesScreenV2($request);
        $business = $plain ? \App\Support\Business::of() : null;
        $kindLabels = $plain ? self::PLAIN_ROLES : Product::KINDS;
        $structure = null;
        $chosenRole = null;
        $chosenBeh = [];
        $packsOn = false;
        $combos = null;
        if ($plain) {
            $structure = in_array($request->input('structure'), ['simple', 'variants', 'combo'], true) ? (string) $request->input('structure') : null;
            if ($structure === 'simple') {
                $query->where(fn ($q) => $q->where('has_variants', false)->orWhereNull('has_variants'));
            } elseif ($structure === 'variants') {
                $query->where('has_variants', true);
            }
            $chosenRole = array_key_exists((string) $request->input('role'), self::PLAIN_ROLES) ? (string) $request->input('role') : null;
            if ($chosenRole !== null) {
                $query->where(function ($q) use ($chosenRole) {
                    $q->where('product_kind', $chosenRole);
                    if ($chosenRole === Product::KIND_SALE_ITEM) {
                        $q->orWhereNull('product_kind');   // an unset role reads as Sale item
                    }
                });
            }
            foreach ((array) $request->input('beh', []) as $key => $answer) {
                if (! array_key_exists((string) $key, self::BEHAVIOURS) || ! in_array($answer, ['yes', 'no'], true)) {
                    continue;
                }
                $chosenBeh[$key] = $answer;
                $yes = $answer === 'yes';
                match ($key) {
                    'bought'  => $query->where('is_purchasable', $yes),
                    'sold'    => $query->where('is_sellable', $yes),
                    'stock'   => $query->where('is_stock_tracked', $yes),
                    // The rule the badge reads (Product::isPosVisible): active, sold, and shown on the counter.
                    'counter' => $yes
                        ? $query->where('status', 'active')->where('is_sellable', true)->where('is_pos_visible', true)
                        : $query->where(fn ($q) => $q->where('status', '!=', 'active')->orWhere('is_sellable', false)->orWhere('is_pos_visible', false)),
                    'recipe'  => $yes
                        ? $query->where('inventory_consumption_method', 'recipe')
                        : $query->where(fn ($q) => $q->where('inventory_consumption_method', '!=', 'recipe')->orWhereNull('inventory_consumption_method')),
                };
            }
            $packsOn = \App\Support\PackUnits::enabled();
            if ($packsOn) {
                $query->with('packUnits');
            }
            // Deals (combos) are their own records: the Combo filter lists them, each opening the existing deal
            // editor. A deal is never copied into the products table.
            if ($structure === 'combo') {
                $combos = \App\Models\Tenant\Combo::with('category')->withCount('components')
                    ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%' . trim((string) $request->search) . '%')
                        ->orWhere('code', 'like', '%' . trim((string) $request->search) . '%')))
                    ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
                    ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                    ->orderBy('name')->paginate(15)->withQueryString();
            }
        }

        return view('tenant.products.index', [
            'plainStructure' => $structure,
            'plainRole'   => $chosenRole,
            'plainBeh'    => $chosenBeh,
            'plainBehaviours' => self::BEHAVIOURS,
            'plainPacksOn' => $packsOn,
            'plainCombos' => $combos,
            'plainDealCount' => $plain && $structure !== 'combo' ? \App\Models\Tenant\Combo::count() : 0,
            'products'    => $query->paginate(15)->withQueryString(),
            'categories'  => Category::where('is_active', true)->orderBy('name')->get(),
            'context'     => $context,
            'contextBase' => $this->contextBaseUrl($request, $context),
            'roleOptions' => collect($kindLabels)
                ->filter(fn ($label, $kind) => in_array($kind, $kindsHere, true) || in_array($kind, $chosenRoles, true) || $kind === $chosenRole)->all(),
            'plainRoleNames' => self::PLAIN_ROLES,
            'flagOptions' => $plain ? \App\Support\ProductCards::FLAG_WORDS : self::FLAG_FILTERS,
            'chosenRoles' => $chosenRoles,
            'chosenFlags' => $chosenFlags,
            'listsEveryKind' => $context === 'catalog' && ! $this->materialsHaveTheirOwnList(),
            'plainList'   => $plain,
            'plainCards'  => $plain ? \App\Support\ProductCards::forBusiness($business) : [],
            'plainBusiness' => $business,
        ]);
    }

    /** W3/W4 S1: the role names a shop recognises, the same for every business (the plain list). */
    private const PLAIN_ROLES = [
        Product::KIND_SALE_ITEM          => 'Sale item',
        Product::KIND_RAW_MATERIAL       => 'Raw material',
        Product::KIND_PACKAGING_MATERIAL => 'Packaging',
        Product::KIND_SEMI_FINISHED      => 'Semi-finished',
        Product::KIND_FINISHED_GOOD      => 'Finished good',
        Product::KIND_SERVICE            => 'Service',
        Product::KIND_COMBO_VIRTUAL      => 'Deal item',
    ];

    /** W3/W4 S1: what the system does with an item — each asked Any / Yes / No on the plain list. */
    private const BEHAVIOURS = [
        'bought'  => 'Bought',
        'sold'    => 'Sold',
        'stock'   => 'Stock counted',
        'counter' => 'On the counter',
        'recipe'  => 'Recipe-based',
    ];

    /** PRODUCT-ROLE-FILTER-1: the visibility/behaviour filters, named as their badges are. */
    private const FLAG_FILTERS = [
        'purchasable'       => 'Purchasable',
        'sellable'          => 'Sellable',
        'pos_visible'       => 'POS Visible',
        'hidden_from_pos'   => 'Hidden from POS',
        'stock_tracked'     => 'Stock Tracked',
        'not_stock_tracked' => 'Not Stock Tracked',
        'service'           => 'Service Item',
        'recipe'            => 'Recipe Item',
    ];

    /** A ticked list (or a single value from an old link), kept to the known values only. */
    private function pickedFrom($raw, array $allowed): array
    {
        return collect(is_array($raw) ? $raw : [$raw])
            ->map(fn ($v) => is_string($v) ? trim($v) : $v)
            ->filter(fn ($v) => in_array($v, $allowed, true))
            ->unique()->values()->all();
    }

    public function create(Request $request)
    {
        $context = $this->productContext($request);
        $isCateringMaterials = $this->isCateringMaterials($request);

        if ($this->usesFormV2($request)) {
            return view('tenant.products.form-v2', $this->screenV2Data(null, $request));
        }

        return view('tenant.products.form', [
            'product'     => null,
            'title'       => $isCateringMaterials
                ? 'Create Material'
                : ($context === 'manufacturing' ? 'Create Manufacturing Product' : 'Create Product'),
            'categories'  => Category::where('is_active', true)->orderBy('name')->get(),
            'units'       => Unit::where('is_active', true)->orderBy('name')->get(),
            'context'     => $context,
            'contextBase' => $this->contextBaseUrl($request, $context),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        // ONLINE-ORDERING-1: website hissa pehle validate — POS product adhoora save na ho.
        $website = app(\App\Services\OnlineOrdering\WebsiteFields::class);
        $website->validate($request);
        // W3/W4 S3c: the Recipe / Bill of materials / Event costing tabs — checked now, written in the transaction below.
        $workspace = app(\App\Services\Products\ProductWorkspace::class);
        $touched = $this->usesWorkspace($request) ? $workspace->touched($request, null, $this->areaBusiness($request), $this->areaCards($request, null)) : [];
        $data['image_path'] = $this->handleImageUpload($request);

        $product = DB::transaction(function () use ($data, $request, $workspace, $touched) {
            $product = Product::create($data);

            $product->translations()->updateOrCreate(
                ['language_code' => 'en'],
                ['name' => $product->name, 'description' => $product->description]
            );

            $variant = ProductVariant::create([
                'product_id'      => $product->id,
                'sku'             => $product->sku,
                'name'            => $product->name,
                'barcode'         => $request->input('barcode') ?: null,
                'purchase_price'  => $product->default_purchase_price,
                'selling_price'   => $product->default_selling_price,
                'reorder_level'   => $request->input('reorder_level', 0),
                'reorder_quantity'=> $request->input('reorder_quantity', 0),
                'is_default'      => true,
                'is_active'       => true,
            ]);

            $variant->translations()->updateOrCreate(
                ['language_code' => 'en'],
                ['name' => $variant->name]
            );

            if ($variant->barcode) {
                ProductBarcode::create([
                    'product_id'         => $product->id,
                    'product_variant_id' => $variant->id,
                    'barcode'            => $variant->barcode,
                    // PRODUCT-SCREEN-V2: a code the screen made (valid EAN-13, "2…" in-store range) is 'system'.
                    'barcode_type'       => $request->boolean('_barcode_made') ? 'system' : 'manual',
                    'is_primary'         => true,
                ]);
            }

            if ($this->packsPosted) {
                \App\Support\PackUnits::sync($product, $this->packPlan);
            }

            if ($touched) {
                $workspace->apply($product, $touched, auth('tenant')->id());
            }

            return $product;
        });

        $website->apply($request, $product);

        return redirect($this->productRedirectUrl($request, $product))->with('status', 'Product created successfully.');
    }

    public function show(Product $product)
    {
        $product->load(['category', 'unit', 'variants.barcodes', 'barcodes', 'branchPrices.branch', 'modifierGroups.branch']);

        return view('tenant.products.show', [
            'product'        => $product,
            'branches'       => Branch::where('status', 'active')->orderBy('name')->get(),
            'modifierGroups' => ModifierGroup::with('branch')->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function edit(Request $request, Product $product)
    {
        $context = $this->productContext($request);

        if ($this->usesFormV2($request)) {
            return view('tenant.products.form-v2', $this->screenV2Data($product, $request));
        }

        return view('tenant.products.form', [
            'product'     => $product,
            'title'       => $this->isCateringMaterials($request)
                ? 'Edit Material'
                : ($context === 'manufacturing' ? 'Edit Manufacturing Product' : 'Edit Product'),
            'categories'  => $this->categoriesFor($product),
            'units'       => $this->unitsFor($product),
            'context'     => $context,
            'contextBase' => $this->contextBaseUrl($request, $context),
        ]);
    }

    /**
     * UB-10: the units an edit offers — the active ones, and always the item's own. A unit switched off used to be
     * missing, so the select showed "— Piece —" and an unchanged save took the item's unit away.
     * W3/W4 S2: the same for the unit it is bought in (purchase_unit_id).
     */
    private function unitsFor(?Product $product)
    {
        $own = array_values(array_filter([$product?->unit_id, $product?->purchase_unit_id]));

        return Unit::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($own, fn ($w) => $w->orWhereIn('id', $own)))
            ->orderBy('name')->get();
    }

    /**
     * W3/W4 S2: the categories an edit offers — the active ones, and always the item's own. A category switched off was
     * missing, so the select posted "" and an unchanged save took the item out of its category.
     */
    private function categoriesFor(?Product $product)
    {
        return Category::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($product?->category_id, fn ($w, $id) => $w->orWhere('id', $id)))
            ->orderBy('name')->get();
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validated($request, $product);
        // ONLINE-ORDERING-1: website hissa pehle validate — POS product adhoora save na ho.
        $website = app(\App\Services\OnlineOrdering\WebsiteFields::class);
        $website->validate($request);
        // W3/W4 S3c: the Recipe / Bill of materials / Event costing tabs — checked now, written in the transaction below.
        $workspace = app(\App\Services\Products\ProductWorkspace::class);
        $touched = $this->usesWorkspace($request) ? $workspace->touched($request, $product, $this->areaBusiness($request), $this->areaCards($request, $product)) : [];

        // POS-UX-1: replace / remove the product image.
        if ($request->boolean('remove_image') && $product->image_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image_path);
            $data['image_path'] = null;
        }
        if ($newPath = $this->handleImageUpload($request)) {
            if ($product->image_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image_path);
            }
            $data['image_path'] = $newPath;
        }

        DB::transaction(function () use ($product, $data, $request, $workspace, $touched) {
            $product->update($data);

            $product->translations()->updateOrCreate(
                ['language_code' => 'en'],
                ['name' => $product->name, 'description' => $product->description]
            );

            $default = $product->defaultVariant;
            if ($default) {
                $default->update([
                    'name'           => $product->name,
                    'purchase_price' => $product->default_purchase_price,
                    'selling_price'  => $product->default_selling_price,
                ]);
            }

            if ($request->boolean('_form_v2')) {
                $this->syncScreenV2Variant($request, $product, $default);
            }

            if ($this->packsPosted) {
                \App\Support\PackUnits::sync($product, $this->packPlan);
            }

            if ($touched) {
                $workspace->apply($product, $touched, auth('tenant')->id());
            }
        });

        $website->apply($request, $product);

        return redirect($this->productRedirectUrl($request, $product))->with('status', 'Product updated successfully.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect('/products')->with('status', 'Product deleted successfully.');
    }

    public function lookupBarcode(Request $request, BarcodeLookupService $service)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'code'      => ['required', 'string', 'max:100'],
        ]);

        return response()->json(
            $service->lookup(
                code:     (string) $data['code'],
                branchId: (int) $data['branch_id'],
            )
        );
    }

    /**
     * POS-UX-1: validate + store an uploaded product image on the public disk.
     * Returns the stored path or null when no file was uploaded.
     */
    private function handleImageUpload(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $request->validate([
            'image' => ['image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        return $request->file('image')->store('products', 'public');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        // PRODUCT-SCREEN-V2: the new screen posts a card + yes/no answers; the SERVER turns them into the
        // fields below (locks included), and keeps every field the screen does not show at its saved value.
        $isV2 = $request->boolean('_form_v2');
        if ($isV2) {
            $business = $this->areaBusiness($request);
            // W3/W4 S3d: Catering Materials adds ingredients and disposables; its form offers only those cards.
            if (! $product && $this->isCateringMaterials($request) && ! isset($this->areaCards($request, null)[(string) $request->input('_card')])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['_card' => 'Catering Materials adds ingredients and disposables. Add a dish from Products.']);
            }
            $request->merge(\App\Support\ProductCards::resolve($business, $request->all(), $product));
            $this->guardScreenV2($request, $product);
            // CATERING-SIMPLIFY-1: a caterer's cost-block dish shows its block price, locked — whatever is posted, the
            // stored counter price stays as it is (the locked box posts nothing).
            if ($product && ($this->priceLock($request, $product)['locked'] ?? false)) {
                $request->merge(['default_selling_price' => $product->default_selling_price ?? 0]);
            }
        } elseif ($product && $product->is_stock_tracked && ! $request->boolean('is_stock_tracked')) {
            // KHATRI-STOCK-TO-PNL-1: on the old form, unticking "Track Stock" while stock is on hand would leave
            // that stock and its 1400 value in the books with no way back. The new screen already refuses this.
            $onHand = (float) \App\Models\Tenant\StockBalance::where('product_id', $product->id)->sum('quantity_on_hand');
            if (abs($onHand) > 0.0005) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'is_stock_tracked' => 'This item still has ' . rtrim(rtrim(number_format($onHand, 3), '0'), '.') . ' in stock, so stock cannot be switched off here — '
                        . 'its value would stay in the books. Ask Bingoo support to move it to cost first. (اسٹاک ابھی باقی ہے)',
                ]);
            }
        }

        $data = $request->validate([
            'category_id'            => ['nullable', 'exists:categories,id'],
            'unit_id'                => ['nullable', 'exists:units,id'],
            'sku'                    => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product?->id)],
            'barcode'                => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')->ignore($product?->defaultVariant?->id)],
            'name'                   => ['required', 'string', 'max:190'],
            'product_type'           => ['required', Rule::in(['simple', 'recipe', 'hybrid', 'service'])],
            'default_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'default_selling_price'  => ['required', 'numeric', 'min:0'],
            // ONLINE-ORDERING-1: website ki apni qeemat; khaali = POS ki qeemat.
            'website_price'          => ['nullable', 'numeric', 'min:0'],
            // KITCHEN-RECIPE-COST-1 recipe-costing pack fields
            'purchase_unit_id'       => ['nullable', 'exists:units,id'],
            'purchase_pack_size'     => ['nullable', 'numeric', 'min:0'],
            'reorder_level'          => ['nullable', 'numeric', 'min:0'],
            'reorder_quantity'       => ['nullable', 'numeric', 'min:0'],
            'is_sellable'            => ['nullable', 'boolean'],
            'is_purchasable'         => ['nullable', 'boolean'],
            'is_stock_tracked'       => ['nullable', 'boolean'],
            'has_variants'           => ['nullable', 'boolean'],
            'has_expiry'             => ['nullable', 'boolean'],
            'requires_batch'         => ['nullable', 'boolean'],
            'is_taxable'                    => ['nullable', 'boolean'],
            'tax_rate_percent'              => ['nullable', 'numeric', 'min:0', 'max:100'],
            'description'                  => ['nullable', 'string'],
            'status'                       => ['required', Rule::in(['active', 'inactive'])],
            'item_kind'                    => ['nullable', Rule::in(['ingredient', 'finished_good', 'both'])],
            'inventory_consumption_method' => ['nullable', Rule::in(['stock_item', 'recipe', 'none'])],
            'is_perishable'               => ['nullable', 'boolean'],
            'storage_type'                => ['nullable', 'string', 'max:50'],
            'shelf_life_days'             => ['nullable', 'integer', 'min:0', 'max:65535'],
            'default_wastage_percent'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            // PRODUCT-BOUNDARY-2 role / visibility
            'product_kind'                  => ['nullable', Rule::in(array_keys(Product::KINDS))],
            'is_pos_visible'                => ['nullable', 'boolean'],
            'can_be_bom_component'          => ['nullable', 'boolean'],
            'can_be_bom_output'             => ['nullable', 'boolean'],
            'is_manufactured_finished_good' => ['nullable', 'boolean'],
        ]);

        // UB-10: an item whose stock or purchases already ran in one unit keeps it (the model refuses it too).
        if ($product) {
            \App\Support\StockUnitGuard::refuseChange($product, $data['unit_id'] ?? null);
        }

        $slugBase = Str::slug($data['name']);
        $slug = $slugBase;
        $counter = 1;

        while (
            Product::where('slug', $slug)
                ->when($product, fn ($q) => $q->where('id', '!=', $product->id))
                ->exists()
        ) {
            $slug = $slugBase . '-' . $counter++;
        }

        // PRODUCT-BOUNDARY-2: resolve role flags + enforce the hard boundary server-side
        // (so raw/packaging can never be POS-visible/saleable even if the UI is bypassed).
        $kind         = $data['product_kind'] ?? ($product?->product_kind ?? Product::KIND_SALE_ITEM);
        $isSellable   = !empty($data['is_sellable']);
        $isPosVisible = $request->boolean('is_pos_visible');
        $canComponent = $request->boolean('can_be_bom_component');
        $canOutput    = $request->boolean('can_be_bom_output');
        $isMfgFg      = $request->boolean('is_manufactured_finished_good');

        if (in_array($kind, [Product::KIND_RAW_MATERIAL, Product::KIND_PACKAGING_MATERIAL], true)) {
            $isSellable   = false;
            $isPosVisible = false;
            $canComponent = true;
        }
        if ($kind === Product::KIND_SEMI_FINISHED) {
            $isPosVisible = false;
        }
        if ($kind === Product::KIND_COMBO_VIRTUAL) {
            $isSellable   = false;
            $isPosVisible = false;
        }
        if ($isMfgFg) {
            $canOutput = true;   // a manufactured finished good must be a valid BOM output
        }

        // CATALOG-GUARD: an item whose stock is NOT tracked cannot directly deduct its own stock,
        // so a contradictory 'stock_item' method is coerced to 'none'. 'recipe' is a valid non-stock
        // semantic (it deducts INGREDIENTS, not the product's own stock) and is left untouched.
        $isStockTracked = !empty($data['is_stock_tracked']);
        $consumptionMethod = $data['inventory_consumption_method'] ?? 'stock_item';
        if (!$isStockTracked && $consumptionMethod === 'stock_item') {
            $consumptionMethod = 'none';
        }

        $fields = [
            'category_id'            => $data['category_id'] ?? null,
            'unit_id'                => $data['unit_id'] ?? null,
            'sku'                    => strtoupper(trim($data['sku'])),
            'name'                   => $data['name'],
            'slug'                   => $slug,
            'product_type'           => $data['product_type'],
            'product_kind'                  => $kind,
            'is_pos_visible'                => $isPosVisible,
            'can_be_bom_component'          => $canComponent,
            'can_be_bom_output'             => $canOutput,
            'is_manufactured_finished_good' => $isMfgFg,
            'is_sellable'            => $isSellable,
            'is_purchasable'         => !empty($data['is_purchasable']),
            'is_stock_tracked'       => $isStockTracked,
            'has_variants'           => !empty($data['has_variants']),
            'has_expiry'             => !empty($data['has_expiry']),
            'requires_batch'         => !empty($data['requires_batch']),
            'default_purchase_price' => $data['default_purchase_price'] ?? 0,
            'default_selling_price'  => $data['default_selling_price'],
            'purchase_unit_id'       => $data['purchase_unit_id'] ?? null,
            'purchase_pack_size'     => (isset($data['purchase_pack_size']) && $data['purchase_pack_size'] !== '') ? $data['purchase_pack_size'] : null,
            'is_taxable'             => !empty($data['is_taxable']),
            'tax_rate_percent'              => $data['tax_rate_percent'] ?? null,
            'description'                  => $data['description'] ?? null,
            'status'                       => $data['status'],
            'item_kind'                    => $data['item_kind'] ?? 'finished_good',
            'inventory_consumption_method' => $consumptionMethod,
            'is_perishable'               => $request->boolean('is_perishable'),
            'storage_type'                => $data['storage_type'] ?? null,
            'shelf_life_days'             => isset($data['shelf_life_days']) ? (int) $data['shelf_life_days'] : null,
            'default_wastage_percent'     => $data['default_wastage_percent'] ?? 0,
        ];

        // PRODUCT-SCREEN-V2: medicine details, written only when the new screen sends them.
        if ($isV2 && \Illuminate\Support\Facades\Schema::connection('tenant')->hasColumn('products', 'generic_name')) {
            $med = $request->validate([
                'generic_name' => ['nullable', 'string', 'max:190'],
                'strength'     => ['nullable', 'string', 'max:60'],
            ]);
            if ($request->exists('generic_name')) {
                $fields['generic_name'] = trim((string) ($med['generic_name'] ?? '')) ?: null;
                $fields['strength'] = trim((string) ($med['strength'] ?? '')) ?: null;
                $fields['needs_prescription'] = $request->boolean('_rx');
            }
        }

        // PURCHASE-DISCOUNTS-1: the usual buying discount and scheme — written only when the new screen sends them,
        // so the old form (and an item not bought) keeps whatever was saved.
        if ($isV2 && $request->exists('purchase_discount_value')) {
            $d = $request->validate([
                'purchase_discount_type'  => ['nullable', 'in:percent,amount'],
                'purchase_discount_value' => ['nullable', 'numeric', 'min:0'],
                'purchase_scheme'         => ['nullable', 'regex:/^\s*\d+\s*\+\s*\d+\s*$/'],
            ], ['purchase_scheme.regex' => 'Write the scheme like 10+1 (buy 10, get 1 free).']);
            $value = (float) ($d['purchase_discount_value'] ?? 0);
            $type = ($d['purchase_discount_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
            if ($type === 'percent' && $value > 100) {
                throw \Illuminate\Validation\ValidationException::withMessages(['purchase_discount_value' => 'A discount in % is from 0 to 100.']);
            }
            $fields['purchase_discount_type'] = $value > 0 ? $type : null;
            $fields['purchase_discount_value'] = $value > 0 ? $value : null;
            if ($request->exists('purchase_scheme')) {
                $scheme = preg_match('/^(\d+)\+(\d+)$/', preg_replace('/\s+/', '', (string) ($d['purchase_scheme'] ?? '')), $m) && (int) $m[1] > 0 ? [(int) $m[1], (int) $m[2]] : null;
                $fields['purchase_scheme_buy'] = $scheme[0] ?? null;
                $fields['purchase_scheme_free'] = $scheme[1] ?? null;
            }
        }

        // ONLINE-ORDERING-1: khaana sirf "Online Ordering" wale plan par dikhta hai. Jahan form par
        // khaana hi nahi, wahan save purani website price ko mitaye nahi — sirf aane par likho.
        if ($request->exists('website_price')) {
            $fields['website_price'] = (isset($data['website_price']) && $data['website_price'] !== '') ? $data['website_price'] : null;
        }

        // PHARMACY-PACKS-1: packs come only from the new screen, only where Master switched them on.
        // Elsewhere nothing posted about packs is read, and saved packs stay as they are.
        $this->packsPosted = false;
        if ($isV2 && \App\Support\PackUnits::enabled() && $request->exists('_packs')) {
            $this->packPlan = \App\Support\PackUnits::fromRequest($request->all(), $product);
            if ($product) {
                \App\Support\PackUnits::guardChanges($product, $this->packPlan);
            }
            $this->packsPosted = true;
        }

        return $fields;
    }

    /** PHARMACY-PACKS-1 — the packs checked by validated(), saved inside the product's transaction. */
    private ?array $packPlan = null;

    private bool $packsPosted = false;

    private function productContext(Request $request): string
    {
        $routeName = (string) $request->route()?->getName();

        // Catering materials ARE manufacturing materials — raw inputs consumed by
        // recipes — so they reuse this context rather than duplicating the filter.
        // Only the route name and the redirect target differ.
        if (str_starts_with($routeName, 'tenant.manufacturing.products.')
            || str_starts_with($routeName, 'tenant.catering.materials.')) {
            return 'manufacturing';
        }

        return $request->query('context') === 'manufacturing' ? 'manufacturing' : 'catalog';
    }

    private function applyContextFilter($query, string $context, Request $request): void
    {
        if ($context === 'manufacturing') {
            // KASHIF-CATERING-PRODUCT-UX-1 — a kitchen's materials are raw and
            // packaging kinds, and it has no BOMs to flag them against. store()
            // already forces can_be_bom_component on both kinds, so anything
            // created through this app satisfies the filter below anyway; this
            // only rescues rows written directly by a seeder or import, which is
            // exactly how Kashif's 14 ingredients became invisible.
            //
            // Scoped to the catering path deliberately: the manufacturing list
            // and its "include shared materials" checkbox behave as before.
            $includeSharedMaterials = $request->boolean('include_shared_materials')
                || $this->isCateringMaterials($request);

            $query->where(function ($outer) use ($includeSharedMaterials) {
                $outer->where(function ($q) {
                    $q->where('can_be_bom_component', true)
                        ->orWhere('can_be_bom_output', true)
                        ->orWhere('is_manufactured_finished_good', true)
                        ->orWhereIn('product_kind', [
                            Product::KIND_SEMI_FINISHED,
                            Product::KIND_FINISHED_GOOD,
                        ]);
                });

                if ($includeSharedMaterials) {
                    $outer->orWhere(function ($q) {
                        $q->whereIn('product_kind', [
                            Product::KIND_RAW_MATERIAL,
                            Product::KIND_PACKAGING_MATERIAL,
                        ])->where(function ($stock) {
                            $stock->where('is_stock_tracked', true)
                                ->orWhere('is_purchasable', true);
                        });
                    });
                }
            });

            return;
        }

        // PRODUCT-LIST-EVERY-KIND-1 — the catalog hides ingredients and factory items because they
        // have a list of their own. A restaurant plan has neither list, yet its Add screen offers
        // "Ingredient / Raw Material": Khatri's ICE-BERG saved fine and then could be found nowhere.
        if (! $this->materialsHaveTheirOwnList()) {
            return;
        }

        $query->where(function ($q) {
            $q->where('is_pos_visible', true)
                ->orWhere('is_sellable', true)
                ->orWhere('inventory_consumption_method', 'recipe')
                ->orWhere('product_type', 'service')
                ->orWhere('product_kind', Product::KIND_SERVICE);
        })->where(function ($q) {
            $q->where(function ($inner) {
                $inner->where('can_be_bom_component', false)
                    ->orWhere('is_pos_visible', true)
                    ->orWhere('is_sellable', true);
            })
                ->where('can_be_bom_output', false)
                ->where('is_manufactured_finished_good', false)
                ->whereNotIn('product_kind', [
                    Product::KIND_RAW_MATERIAL,
                    Product::KIND_SEMI_FINISHED,
                    Product::KIND_FINISHED_GOOD,
                ]);
        });
    }

    /**
     * Can this user open Manufacturing Products or Catering Materials? Plan AND role, as the form's
     * areas and the sidebar decide. With no tenant bound (only the test harness) the plan is unknown:
     * yes, so the catalog keeps hiding them as it always did.
     */
    private function materialsHaveTheirOwnList(): bool
    {
        $subscription = app()->bound('tenant') ? app('tenant')->subscription : null;
        if (! $subscription) {
            return true;
        }
        $modules = $subscription->loadMissing('plan.enabledModules')->plan?->enabledModules->pluck('key')->all() ?? [];
        $user = auth()->user();

        return (in_array('manufacturing', $modules, true) && (bool) $user?->can('tenant.manufacturing.products.index'))
            || (in_array('catering', $modules, true) && (bool) $user?->can('tenant.catering.materials.index'));
    }

    private function isCateringMaterials(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), 'tenant.catering.materials.');
    }

    /** Base path the index/form views build their own links from. */
    private function contextBaseUrl(Request $request, string $context): string
    {
        if ($this->isCateringMaterials($request)) {
            return '/catering/materials';
        }

        return $context === 'manufacturing' ? '/manufacturing/products' : '/products';
    }

    /**
     * CATERING-SIMPLIFY-1 — the dish's block price and whether its Selling price is locked to it
     * (ProductWorkspace::blockPrice). A posted "Priced by" counts only from a user who may edit the Event costing tab.
     */
    private function priceLock(Request $request, Product $product): ?array
    {
        $workspace = app(\App\Services\Products\ProductWorkspace::class);
        $business = $this->areaBusiness($request);
        $mode = $request->input('catering.costing_mode');
        $mayPost = is_string($mode) && $this->usesWorkspace($request)
            && $workspace->access($product, $business, $this->areaCards($request, $product))['catering'];

        return $workspace->blockPrice($product, $business, $mayPost ? $mode : null);
    }

    /** W3/W4 S3c — the workspace sections ride only on a post from the new screen, where the owner switched it on. */
    private function usesWorkspace(Request $request): bool
    {
        return $request->boolean('_form_v2') && \App\Support\TenantFeatures::on(\App\Support\TenantFeatures::PRODUCT_SCREEN_V2);
    }

    /**
     * W3/W4 S3d — the one product form opens for Add / Edit in the catalog, Manufacturing Products and Catering Materials,
     * where the owner switched it on. (usesScreenV2() below still decides the catalog's own list.)
     */
    private function usesFormV2(Request $request): bool
    {
        return \App\Support\TenantFeatures::on(\App\Support\TenantFeatures::PRODUCT_SCREEN_V2);
    }

    /** W3/W4 S3d — whose cards the form shows: Catering Materials a caterer's, Manufacturing Products a factory's. */
    private function areaBusiness(Request $request): ?string
    {
        if ($this->isCateringMaterials($request)) {
            return \App\Support\Business::CATERING;
        }

        return $this->productContext($request) === 'manufacturing' ? \App\Support\Business::INDUSTRY : \App\Support\Business::of();
    }

    /**
     * W3/W4 S3d — the cards offered. Catering Materials offers its material cards for a new item; an item opened there
     * keeps the full set, so one no material card fits is never re-kinded by the screen.
     */
    private function areaCards(Request $request, ?Product $product): array
    {
        $cards = \App\Support\ProductCards::forBusiness($this->areaBusiness($request));
        if (! $product && $this->isCateringMaterials($request)) {
            $cards = array_filter($cards, fn ($c) => in_array($c['kind'], [Product::KIND_RAW_MATERIAL, Product::KIND_PACKAGING_MATERIAL], true));
        }

        return $cards;
    }

    /** PRODUCT-SCREEN-V2 — only on the catalog's own Add/Edit, and only where the owner switched it on. */
    private function usesScreenV2(Request $request): bool
    {
        return $this->productContext($request) === 'catalog'
            && ! $this->isCateringMaterials($request)
            && \App\Support\TenantFeatures::on(\App\Support\TenantFeatures::PRODUCT_SCREEN_V2);
    }

    private function screenV2Data(?Product $product, Request $request): array
    {
        $business = $this->areaBusiness($request);
        $cards = $this->areaCards($request, $product);
        $card = $product
            ? \App\Support\ProductCards::detect($business, $product)
            : (isset($cards[(string) $request->query('card')]) ? (string) $request->query('card') : array_key_first($cards));
        $variant = $product?->defaultVariant;
        $ws = app(\App\Services\Products\ProductWorkspace::class)->screen($product, $business, $cards);
        $context = $this->productContext($request);
        $area = $this->isCateringMaterials($request) ? 'material' : ($context === 'manufacturing' ? 'manufacturing product' : 'product');
        $hasStockHistory = $product ? \App\Models\Tenant\StockLedger::where('product_id', $product->id)->exists() : false;

        return [
            'product'         => $product,
            'title'           => $product ? 'Edit ' . $area : 'Add a ' . $area,
            // W3/W4 S3d: the area's own routes, so its own permissions apply.
            'formBase'        => $this->contextBaseUrl($request, $context),
            'business'        => $business,
            'businessInfo'    => \App\Support\Business::all()[$business] ?? null,
            'cards'           => $cards,
            'currentCard'     => $card,
            'categories'      => $this->categoriesFor($product),
            'units'           => $this->unitsFor($product),
            // W3/W4 S2: what this edit does not show and keeps as saved, and an item no card here fits.
            'keptAsSaved'     => ($product ? \App\Support\ProductCards::keptAsSaved($business, $product, $card) : [])
                + array_fill_keys($ws['kept'], 'kept as saved — changing it needs its own permission or module'),
            // W3/W4 S3c: the Recipe / Bill of materials / Event costing tabs this user may use here.
            'ws'              => $ws,
            // CATERING-SIMPLIFY-1: a dish's event price from its cost blocks; a caterer's Selling price box is locked to it.
            // After a refused save the posted "Priced by" decides, as the tab then shows it.
            'priceLock'       => $product ? app(\App\Services\Products\ProductWorkspace::class)->blockPrice($product, $business,
                ($ws['access']['catering'] ?? false) && is_string(old('catering.costing_mode')) && ! session('errors')?->has('catering.stale')
                    ? old('catering.costing_mode') : null) : null,
            'misfitRole'      => $product && \App\Support\ProductCards::isMisfit($business, $product, $card)
                ? (self::PLAIN_ROLES[$product->product_kind ?: Product::KIND_SALE_ITEM] ?? (string) $product->product_kind) : null,
            'barcode'         => $variant?->barcode ?? $product?->barcodes()->where('is_primary', true)->value('barcode'),
            'reorderLevel'    => $variant?->reorder_level,
            'reorderQuantity' => $variant?->reorder_quantity,
            'hasStockHistory' => $hasStockHistory,
            'salesCount'      => $product ? \App\Models\Tenant\SalesOrderLine::where('product_id', $product->id)->count() : 0,
            'onHand'          => $product ? (float) \App\Models\Tenant\StockBalance::where('product_id', $product->id)->sum('quantity_on_hand') : 0.0,
            'hasMedicineCols' => \Illuminate\Support\Facades\Schema::connection('tenant')->hasColumn('products', 'generic_name'),
        ] + $this->packScreenData($product);
    }

    /** PHARMACY-PACKS-1 — the packs part of the screen; empty when Master has packs off for this tenant. */
    private function packScreenData(?Product $product): array
    {
        if (! \App\Support\PackUnits::enabled()) {
            return ['packsOn' => false];
        }
        $saved = \App\Support\PackUnits::forScreen($product);
        $baseName = \App\Support\PackUnits::baseName($product?->unit_id);
        $onHand = $product ? (float) \App\Models\Tenant\StockBalance::where('product_id', $product->id)->sum('quantity_on_hand') : 0.0;

        return [
            'packsOn'       => true,
            'savedPacks'    => $saved['packs'],
            'packBaseSell'  => $saved['base_sell'],
            'packBaseName'  => $baseName,
            'packStockSays' => $product && $saved['packs'] && $onHand != 0
                ? \App\Support\PackUnits::describe($product->packUnits()->get(), $onHand, $baseName)
                : null,
            'packPresets'   => \App\Support\PackUnits::presets(\App\Support\Business::of()),
        ];
    }

    /** Refuse what would hurt: switching stock counting off after stock has moved; a barcode another product owns. */
    private function guardScreenV2(Request $request, ?Product $product): void
    {
        $errors = [];
        if ($product && $product->is_stock_tracked && ! $request->boolean('is_stock_tracked')
            && \App\Models\Tenant\StockLedger::where('product_id', $product->id)->exists()) {
            $errors['_stock'] = 'Stock of this item has already moved, so counting cannot be switched off.';
        }
        $code = trim((string) $request->input('barcode', ''));
        if ($code !== '' && ProductBarcode::where('barcode', $code)->when($product, fn ($q) => $q->where('product_id', '!=', $product->id))->exists()) {
            $errors['barcode'] = 'This barcode already belongs to another product.';
        } elseif ($code !== '' && \App\Support\PackUnits::isPackBarcode($code)) {
            // PHARMACY-PACKS-1: a box's barcode cannot also be the single item's — one scan, one meaning.
            $errors['barcode'] = 'This barcode is already a pack’s barcode (a box or strip). Give the single item its own.';
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /** Edit on the new screen also saves what the old form dropped: code, reorder level, barcode. */
    private function syncScreenV2Variant(Request $request, Product $product, ?ProductVariant $default): void
    {
        if (! $default) {
            return;
        }
        $update = ['sku' => $product->sku];
        if ($request->exists('reorder_level')) {
            $update['reorder_level'] = (float) $request->input('reorder_level', 0);
        }
        if ($request->exists('reorder_quantity')) {
            $update['reorder_quantity'] = (float) $request->input('reorder_quantity', 0);
        }
        if (! ProductVariant::where('sku', $product->sku)->where('id', '!=', $default->id)->exists()) {
            $default->update($update);
        } else {
            unset($update['sku']);
            $default->update($update);
        }

        $code = trim((string) $request->input('barcode', ''));
        if ($code !== '' && $code !== (string) $default->barcode) {
            $default->update(['barcode' => $code]);
            ProductBarcode::where('product_id', $product->id)->where('product_variant_id', $default->id)->update(['is_primary' => false]);
            ProductBarcode::updateOrCreate(
                ['barcode' => $code],
                ['product_id' => $product->id, 'product_variant_id' => $default->id, 'is_primary' => true,
                 'barcode_type' => $request->boolean('_barcode_made') ? 'system' : 'manual']
            );
        }
    }

    private function productRedirectUrl(Request $request, Product $product): string
    {
        if ($request->boolean('_form_v2') && $request->input('_after') === 'new') {
            return $this->contextBaseUrl($request, $this->productContext($request)) . '/create?card=' . urlencode((string) $request->input('_card'));
        }

        // Catering shares the manufacturing context but must land back on its own
        // screen — /manufacturing/products is not reachable on a catering-only plan.
        if ($this->isCateringMaterials($request)) {
            return '/catering/materials';
        }

        $context = $this->productContext($request);
        if ($context !== 'manufacturing') {
            return '/products/' . $product->id;
        }

        // PRODUCT-FORM-AREA-1: the manufacturing screen can now save a Restaurant type too (picked
        // above the cards). Landing on a list that never shows it reads as "my product vanished" —
        // open the product instead. The list's OWN filter decides, not a second copy of it.
        $listed = Product::query()->whereKey($product->id);
        $this->applyContextFilter($listed, $context, $request);

        return $listed->exists() ? '/manufacturing/products' : '/products/' . $product->id;
    }
}
