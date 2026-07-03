<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * DEPARTMENT-FOUNDATION-1 — internal responsibility area inside a branch
 * (Kitchen, Bar, Packing, Bakery, Main Store...). Mapping/reporting only in
 * this phase: no stock balances, no ledgers, no GL. Branch stock stays the
 * official truth.
 */
class Department extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'description',
        'manager_user_id',
        'status',
        'allow_stock_issue',
        'require_end_day_count',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'allow_stock_issue'     => 'boolean',
            'require_end_day_count' => 'boolean',
            'sort_order'            => 'integer',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function categoryMaps()
    {
        return $this->hasMany(DepartmentCategoryMap::class);
    }

    public function productOverrides()
    {
        return $this->hasMany(DepartmentProductOverride::class);
    }

    public function includeOverrides()
    {
        return $this->hasMany(DepartmentProductOverride::class)->where('mapping_type', 'include');
    }

    public function excludeOverrides()
    {
        return $this->hasMany(DepartmentProductOverride::class)->where('mapping_type', 'exclude');
    }

    /**
     * All category IDs this department is responsible for, expanding
     * include_children maps through the categories.parent_id tree.
     *
     * @return array<int>
     */
    public function mappedCategoryIdsIncludingChildren(): array
    {
        $maps = $this->categoryMaps()->get(['category_id', 'include_children']);
        if ($maps->isEmpty()) {
            return [];
        }

        // One query for the whole tree; expansion in memory (tree is small).
        $childrenByParent = Category::query()
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $ids = [];
        foreach ($maps as $map) {
            $ids[] = (int) $map->category_id;
            if ($map->include_children) {
                $queue = [(int) $map->category_id];
                while ($queue) {
                    $parentId = array_shift($queue);
                    foreach ($childrenByParent->get($parentId, collect()) as $child) {
                        $ids[]   = (int) $child->id;
                        $queue[] = (int) $child->id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Does this department own the given product?
     * Rules: explicit exclude wins → explicit include wins → category map → false.
     * A product matching nothing is simply "Unassigned" in reports — never an error.
     */
    public function matchesProduct(Product $product): bool
    {
        $override = $this->productOverrides()
            ->where('product_id', $product->id)
            ->first();

        if ($override) {
            return $override->mapping_type === 'include';
        }

        if (! $product->category_id) {
            return false;
        }

        return in_array((int) $product->category_id, $this->mappedCategoryIdsIncludingChildren(), true);
    }
}
