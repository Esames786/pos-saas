<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBarcode;
use App\Models\Tenant\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductBarcodeController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $data = $request->validate([
            'product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'barcode'            => ['required', 'string', 'max:100', Rule::unique('product_barcodes', 'barcode')],
            'barcode_type'       => ['required', Rule::in(['manual', 'system', 'supplier'])],
            'is_primary'         => ['nullable', 'boolean'],
        ]);

        $variant = !empty($data['product_variant_id'])
            ? ProductVariant::where('product_id', $product->id)->where('id', $data['product_variant_id'])->firstOrFail()
            : $product->defaultVariant;

        if (!empty($data['is_primary'])) {
            ProductBarcode::where('product_id', $product->id)->update(['is_primary' => false]);
        }

        ProductBarcode::create([
            'product_id'         => $product->id,
            'product_variant_id' => $variant?->id,
            'barcode'            => $data['barcode'],
            'barcode_type'       => $data['barcode_type'],
            'is_primary'         => !empty($data['is_primary']),
        ]);

        return back()->with('status', 'Barcode added successfully.');
    }

    public function generate(Product $product)
    {
        $barcode = $this->uniqueBarcode();
        $variant = $product->defaultVariant;

        if ($variant && !$variant->barcode) {
            $variant->update(['barcode' => $barcode]);
        }

        ProductBarcode::create([
            'product_id'         => $product->id,
            'product_variant_id' => $variant?->id,
            'barcode'            => $barcode,
            'barcode_type'       => 'system',
            'is_primary'         => true,
        ]);

        return back()->with('status', 'System barcode generated: ' . $barcode);
    }

    public function destroy(ProductBarcode $barcode)
    {
        $barcode->delete();

        return back()->with('status', 'Barcode deleted successfully.');
    }

    private function uniqueBarcode(): string
    {
        do {
            $code = '2' . now()->format('ymd') . random_int(100000, 999999);
        } while (ProductBarcode::where('barcode', $code)->exists());

        return $code;
    }
}
