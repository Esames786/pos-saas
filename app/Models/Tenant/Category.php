<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['parent_id', 'branch_id', 'code', 'name', 'slug', 'sort_order', 'is_active', 'cogs_account_id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** PNL-COST-BY-CATEGORY-1: where this category's purchase-only cost goes (NULL = parent's, else 5100). */
    public function cogsAccount()
    {
        return $this->belongsTo(Account::class, 'cogs_account_id');
    }

    /**
     * CATEGORY-BRANCH-SCOPE-1: the branch this category belongs to — NULL means every branch,
     * which is what every category on every existing tenant is.
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** What a branch may show: its own categories, plus the ones shared across all branches. */
    public function scopeForBranch($query, ?int $branchId)
    {
        return $query->where(function ($q) use ($branchId) {
            $q->whereNull('branch_id');
            if ($branchId) {
                $q->orWhere('branch_id', $branchId);
            }
        });
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function translations()
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
