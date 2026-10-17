<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'parent_id', 'branch_id', 'code', 'name', 'slug', 'sort_order', 'is_active', 'cogs_account_id',
        // ONLINE-ORDERING-1: website par dikhe ya nahi, kis naam/tarteeb/waqt me (POS par asar nahi).
        'show_on_website', 'website_name', 'website_sort_order', 'website_visible_from', 'website_visible_to',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
            'show_on_website' => 'boolean',
            'website_sort_order' => 'integer',
        ];
    }

    /**
     * ONLINE-ORDERING-1: is waqt ("H:i", tenant ka clock) website par dikhe? Dono khaali = hamesha;
     * to < from = aadhi raat paar (00:00 → 04:00 bhi, 22:00 → 03:00 bhi).
     */
    public function websiteVisibleAt(string $hhmm): bool
    {
        $from = $this->website_visible_from ? substr((string) $this->website_visible_from, 0, 5) : null;
        $to = $this->website_visible_to ? substr((string) $this->website_visible_to, 0, 5) : null;

        if ($from === null || $to === null) {
            return true;
        }

        return $from <= $to ? ($hhmm >= $from && $hhmm < $to) : ($hhmm >= $from || $hhmm < $to);
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
