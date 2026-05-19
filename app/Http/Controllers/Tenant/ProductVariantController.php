<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBarcode;
use App\Models\Tenant\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductVariantController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($product, $data) {
            if (!empty($data['is_default'])) {
                $product->variants()->update(['is_default' => false]);
            }

            $variant = $product->variants()->create($data);

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
                    'is_primary'         => false,
                ]);
            }

            $product->update(['has_variants' => true]);
        });

        return back()->with('status', 'Variant added successfully.');
    }

    public function update(Request $request, ProductVariant $variant)
    {
        $data = $this->validated($request, $variant);

        DB::transaction(function () use ($variant, $data) {
            if (!empty($data['is_default'])) {
                $variant->product->variants()->where('id', '!=', $variant->id)->update(['is_default' => false]);
            }

            $variant->update($data);

            $variant->translations()->updateOrCreate(
                ['language_code' => 'en'],
                ['name' => $variant->name]
            );
        });

        return back()->with('status', 'Variant updated successfully.');
    }

    public function destroy(ProductVariant $variant)
    {
        if ($variant->is_default) {
            return back()->withErrors(['variant' => 'Default variant cannot be deleted.']);
        }

        $variant->delete();

        return back()->with('status', 'Variant deleted successfully.');
    }

    private function validated(Request $request, ?ProductVariant $variant = null): array
    {
        $data = $request->validate([
            'sku'              => ['required', 'string', 'max:100', Rule::unique('product_variants', 'sku')->ignore($variant?->id)],
            'name'             => ['required', 'string', 'max:190'],
            'barcode'          => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')->ignore($variant?->id)],
            'purchase_price'   => ['nullable', 'numeric', 'min:0'],
            'selling_price'    => ['required', 'numeric', 'min:0'],
            'reorder_level'    => ['nullable', 'numeric', 'min:0'],
            'reorder_quantity' => ['nullable', 'numeric', 'min:0'],
            'is_default'       => ['nullable', 'boolean'],
            'is_active'        => ['nullable', 'boolean'],
        ]);

        return [
            'sku'              => strtoupper(trim($data['sku'])),
            'name'             => $data['name'],
            'barcode'          => $data['barcode'] ?: null,
            'purchase_price'   => $data['purchase_price'] ?? 0,
            'selling_price'    => $data['selling_price'],
            'reorder_level'    => $data['reorder_level'] ?? 0,
            'reorder_quantity' => $data['reorder_quantity'] ?? 0,
            'is_default'       => !empty($data['is_default']),
            'is_active'        => !empty($data['is_active']),
        ];
    }
}
