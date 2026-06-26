<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ModifierGroup extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'name',
        'min_select',
        'max_select',
        'is_required',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'min_select'  => 'integer',
            'max_select'  => 'integer',
            'is_required' => 'boolean',
            'sort_order'  => 'integer',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function modifiers()
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order')->orderBy('name');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_modifier_group')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
