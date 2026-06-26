<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Modifier extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'modifier_group_id',
        'name',
        'price_delta',
        'linked_product_id',
        'is_default',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'is_default'  => 'boolean',
            'sort_order'  => 'integer',
        ];
    }

    public function group()
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    public function linkedProduct()
    {
        return $this->belongsTo(Product::class, 'linked_product_id');
    }
}
