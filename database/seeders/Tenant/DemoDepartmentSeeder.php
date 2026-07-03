<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Department;
use App\Models\Tenant\Product;

/**
 * DEPARTMENT-FOUNDATION-1 — shared demo department seeding.
 *
 * Called from demo seeders AFTER branches, categories, units, and products
 * exist (tenant connection must be active). Idempotent: updateOrCreate on
 * (branch_id, code). Category/product candidates that do not exist in the
 * current tenant are skipped silently — seeding never fails on a missing
 * code/SKU. Products left unmapped intentionally stay "Unassigned" in
 * reports (Main Store gets NO broad mapping on purpose).
 */
class DemoDepartmentSeeder
{
    /**
     * @return array{departments:int, category_maps:int, product_overrides:int}
     */
    public static function seed(): array
    {
        // code, name, sort, [category-code candidates], [include-product SKU candidates]
        $definitions = [
            ['MAINSTORE', 'Main Store',         0,  [],                                                        []],
            ['KITCHEN',   'Kitchen',            10, ['RESTO', 'GROC', 'RKITCHEN', 'ENT-KITCHEN', 'ENT-MENU'],  []],
            ['BAR',       'Bar / Beverages',    20, ['BEV', 'ENT-BEV'],                                        []],
            ['BAKERY',    'Bakery / Production', 30, ['BAKERY'],                                               []],
            ['PACKING',   'Packing',            40, ['PACK', 'PACKING', 'ENT-PACK'],                          ['KRH-PKG-FOIL', 'KRH-PKG-CONT']],
        ];

        $counts = ['departments' => 0, 'category_maps' => 0, 'product_overrides' => 0];

        // Resolve candidates once per tenant (categories/products are tenant-global).
        $categoriesByCode = Category::query()->whereNotNull('code')->pluck('id', 'code');
        $productsBySku    = Product::query()->whereNotNull('sku')->pluck('id', 'sku');

        foreach (Branch::where('status', 'active')->orderBy('id')->get() as $branch) {
            foreach ($definitions as [$code, $name, $sort, $categoryCodes, $productSkus]) {
                $department = Department::updateOrCreate(
                    ['branch_id' => $branch->id, 'code' => $code],
                    [
                        'name'                  => $name,
                        'status'                => 'active',
                        'allow_stock_issue'     => true,
                        'require_end_day_count' => in_array($code, ['KITCHEN', 'BAR'], true),
                        'sort_order'            => $sort,
                    ]
                );
                $counts['departments']++;

                foreach ($categoryCodes as $categoryCode) {
                    $categoryId = $categoriesByCode[$categoryCode] ?? null;
                    if (! $categoryId) {
                        continue; // candidate not present in this tenant — skip safely
                    }
                    $department->categoryMaps()->updateOrCreate(
                        ['category_id' => $categoryId],
                        ['include_children' => true]
                    );
                    $counts['category_maps']++;
                }

                foreach ($productSkus as $sku) {
                    $productId = $productsBySku[$sku] ?? null;
                    if (! $productId) {
                        continue;
                    }
                    $department->productOverrides()->updateOrCreate(
                        ['product_id' => $productId],
                        ['mapping_type' => 'include']
                    );
                    $counts['product_overrides']++;
                }
            }
        }

        return $counts;
    }
}
