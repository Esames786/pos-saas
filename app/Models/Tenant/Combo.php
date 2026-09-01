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
        'sort_order',
        'status',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
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
