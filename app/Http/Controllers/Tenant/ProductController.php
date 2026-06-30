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

        return view('tenant.products.index', [
            'products'   => $query->paginate(15)->withQueryString(),
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
            'context'    => $context,
        ]);
    }

    public function create(Request $request)
    {
        $context = $this->productContext($request);

        return view('tenant.products.form', [
            'product'    => null,
            'title'      => $context === 'manufacturing' ? 'Create Manufacturing Product' : 'Create Product',
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
            'units'      => Unit::where('is_active', true)->orderBy('name')->get(),
            'context'    => $context,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($data, $request) {
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
                    'barcode_type'       => 'manual',
                    'is_primary'         => true,
                ]);
            }

            return $product;
        });

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

        return view('tenant.products.form', [
            'product'    => $product,
            'title'      => $context === 'manufacturing' ? 'Edit Manufacturing Product' : 'Edit Product',
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
            'units'      => Unit::where('is_active', true)->orderBy('name')->get(),
            'context'    => $context,
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validated($request, $product);

        DB::transaction(function () use ($product, $data) {
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
        });

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

    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'category_id'            => ['nullable', 'exists:categories,id'],
            'unit_id'                => ['nullable', 'exists:units,id'],
            'sku'                    => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product?->id)],
            'barcode'                => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')->ignore($product?->defaultVariant?->id)],
            'name'                   => ['required', 'string', 'max:190'],
            'product_type'           => ['required', Rule::in(['simple', 'recipe', 'hybrid', 'service'])],
            'default_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'default_selling_price'  => ['required', 'numeric', 'min:0'],
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

        return [
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
            'is_stock_tracked'       => !empty($data['is_stock_tracked']),
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
            'inventory_consumption_method' => $data['inventory_consumption_method'] ?? 'stock_item',
            'is_perishable'               => $request->boolean('is_perishable'),
            'storage_type'                => $data['storage_type'] ?? null,
            'shelf_life_days'             => isset($data['shelf_life_days']) ? (int) $data['shelf_life_days'] : null,
            'default_wastage_percent'     => $data['default_wastage_percent'] ?? 0,
        ];
    }

    private function productContext(Request $request): string
    {
        $routeName = (string) $request->route()?->getName();

        if (str_starts_with($routeName, 'tenant.manufacturing.products.')) {
            return 'manufacturing';
        }

        return $request->query('context') === 'manufacturing' ? 'manufacturing' : 'catalog';
    }

    private function applyContextFilter($query, string $context, Request $request): void
    {
        if ($context === 'manufacturing') {
            $includeSharedMaterials = $request->boolean('include_shared_materials');

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

    private function productRedirectUrl(Request $request, Product $product): string
    {
        return $this->productContext($request) === 'manufacturing'
            ? '/manufacturing/products'
            : '/products/' . $product->id;
    }
}
