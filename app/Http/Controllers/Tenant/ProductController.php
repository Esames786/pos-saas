<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
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
        $query = Product::with(['category', 'unit', 'defaultVariant'])->latest();

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
        ]);
    }

    public function create()
    {
        return view('tenant.products.form', [
            'product'    => null,
            'title'      => 'Create Product',
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
            'units'      => Unit::where('is_active', true)->orderBy('name')->get(),
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

        return redirect('/products/' . $product->id)->with('status', 'Product created successfully.');
    }

    public function show(Product $product)
    {
        $product->load(['category', 'unit', 'variants.barcodes', 'barcodes', 'branchPrices.branch']);

        return view('tenant.products.show', [
            'product'  => $product,
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function edit(Product $product)
    {
        return view('tenant.products.form', [
            'product'    => $product,
            'title'      => 'Edit Product',
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
            'units'      => Unit::where('is_active', true)->orderBy('name')->get(),
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

        return redirect('/products/' . $product->id)->with('status', 'Product updated successfully.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect('/products')->with('status', 'Product deleted successfully.');
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

        return [
            'category_id'            => $data['category_id'] ?? null,
            'unit_id'                => $data['unit_id'] ?? null,
            'sku'                    => strtoupper(trim($data['sku'])),
            'name'                   => $data['name'],
            'slug'                   => $slug,
            'product_type'           => $data['product_type'],
            'is_sellable'            => !empty($data['is_sellable']),
            'is_purchasable'         => !empty($data['is_purchasable']),
            'is_stock_tracked'       => !empty($data['is_stock_tracked']),
            'has_variants'           => !empty($data['has_variants']),
            'has_expiry'             => !empty($data['has_expiry']),
            'requires_batch'         => !empty($data['requires_batch']),
            'default_purchase_price' => $data['default_purchase_price'] ?? 0,
            'default_selling_price'  => $data['default_selling_price'],
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
}
