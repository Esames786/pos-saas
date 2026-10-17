<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Combo extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'category_id',
        'code',
        'name',
        'price',
        // ONLINE-ORDERING-1: website ki apni qeemat (null = deal ki qeemat).
        'website_price',
        'sort_order',
        'status',
        'description',
        // ONLINE-ORDERING-1: website par jagah, tasveer aur tafseel (POS par asar nahi).
        'show_on_website', 'website_card_name', 'website_size_label', 'website_category_id',
        'website_tag', 'website_popular', 'website_sort_order', 'website_image_path', 'website_description',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'website_price' => 'decimal:2',
            'sort_order' => 'integer',
            'show_on_website' => 'boolean',
            'website_popular' => 'boolean',
            'website_sort_order' => 'integer',
        ];
    }

    /** ONLINE-ORDERING-1: website par kis category me (khaali = apni POS category). */
    public function websiteCategory()
    {
        return $this->belongsTo(Category::class, 'website_category_id');
    }

    /** ONLINE-ORDERING-1: deal ke add-ons — aaj sirf website ke liye; POS ka deal flow isay nahi parhta. */
    public function modifierGroups()
    {
        return $this->belongsToMany(ModifierGroup::class, 'combo_modifier_group')
            ->withPivot('sort_order')->withTimestamps()->orderByPivot('sort_order');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // POS-COMBO-CATEGORY-1: optional grouping so deals show under their own POS tab (null = "Deals").
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function components()
    {
        return $this->hasMany(ComboComponent::class)->orderBy('sort_order')->orderBy('id');
    }
}
