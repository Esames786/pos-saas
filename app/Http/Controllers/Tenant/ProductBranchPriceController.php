<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBranchPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductBranchPriceController extends Controller
{
    public function update(Request $request, Product $product)
    {
        $request->validate([
            'prices'                          => ['nullable', 'array'],
            'prices.*.branch_id'              => ['required', 'exists:branches,id'],
            'prices.*.product_variant_id'     => ['nullable', 'exists:product_variants,id'],
            'prices.*.selling_price'          => ['required', 'numeric', 'min:0'],
            'prices.*.minimum_selling_price'  => ['nullable', 'numeric', 'min:0'],
            'prices.*.is_available'           => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $product) {
            foreach ($request->input('prices', []) as $row) {
                ProductBranchPrice::updateOrCreate(
                    [
                        'branch_id'          => $row['branch_id'],
                        'product_id'         => $product->id,
                        'product_variant_id' => $row['product_variant_id'] ?: null,
                    ],
                    [
                        'selling_price'         => $row['selling_price'],
                        'minimum_selling_price' => $row['minimum_selling_price'] ?? null,
                        'is_available'          => !empty($row['is_available']),
                    ]
                );
            }
        });

        return back()->with('status', 'Branch prices updated successfully.');
    }
}
