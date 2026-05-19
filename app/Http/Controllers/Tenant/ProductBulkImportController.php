<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Category;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductBulkImportController extends Controller
{
    public function create()
    {
        return view('tenant.products.bulk-import');
    }

    public function store(Request $request)
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
        ]);

        $path   = $request->file('csv_file')->getRealPath();
        $handle = fopen($path, 'r');

        if (!$handle) {
            return back()->withErrors(['csv_file' => 'Unable to read CSV file.']);
        }

        $header   = fgetcsv($handle);
        $required = ['sku', 'name', 'category', 'unit', 'product_type', 'purchase_price', 'selling_price', 'barcode'];

        if (!$header || array_diff($required, $header)) {
            fclose($handle);

            return back()->withErrors(['csv_file' => 'CSV header must include: ' . implode(', ', $required)]);
        }

        $imported = 0;
        $skipped  = 0;

        DB::transaction(function () use ($handle, $header, &$imported, &$skipped) {
            while (($row = fgetcsv($handle)) !== false) {
                $data = array_combine($header, $row);

                if (empty($data['sku']) || empty($data['name'])) {
                    $skipped++;
                    continue;
                }

                $category = null;

                if (!empty($data['category'])) {
                    $category = Category::firstOrCreate(
                        ['slug' => Str::slug($data['category'])],
                        ['name' => $data['category'], 'is_active' => true, 'sort_order' => 0]
                    );
                }

                $unit = null;

                if (!empty($data['unit'])) {
                    $unit = Unit::firstOrCreate(
                        ['code' => strtoupper($data['unit'])],
                        ['name' => strtoupper($data['unit']), 'unit_type' => 'quantity', 'base_factor' => 1, 'is_active' => true]
                    );
                }

                $sku  = strtoupper(trim($data['sku']));
                $type = in_array($data['product_type'] ?? '', ['simple', 'recipe', 'hybrid', 'service'])
                    ? $data['product_type'] : 'simple';

                $product = Product::updateOrCreate(
                    ['sku' => $sku],
                    [
                        'category_id'            => $category?->id,
                        'unit_id'                => $unit?->id,
                        'name'                   => $data['name'],
                        'slug'                   => Str::slug($data['name']) . '-' . strtolower($sku),
                        'product_type'           => $type,
                        'is_sellable'            => true,
                        'is_purchasable'         => true,
                        'is_stock_tracked'       => $type !== 'service',
                        'default_purchase_price' => (float) ($data['purchase_price'] ?? 0),
                        'default_selling_price'  => (float) ($data['selling_price'] ?? 0),
                        'status'                 => 'active',
                    ]
                );

                ProductVariant::updateOrCreate(
                    ['sku' => $sku],
                    [
                        'product_id'     => $product->id,
                        'name'           => $product->name,
                        'barcode'        => $data['barcode'] ?: null,
                        'purchase_price' => $product->default_purchase_price,
                        'selling_price'  => $product->default_selling_price,
                        'is_default'     => true,
                        'is_active'      => true,
                    ]
                );

                $imported++;
            }
        });

        fclose($handle);

        return redirect('/products')->with('status', "Import complete. Imported: {$imported}, Skipped: {$skipped}");
    }
}
