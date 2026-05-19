<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class KitchenProductionIngredient extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'kitchen_production_id',
        'product_id',
        'product_variant_id',
        'quantity_required',
        'quantity_used',
        'unit_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:4',
            'quantity_used'     => 'decimal:4',
        ];
    }

    public function production()
    {
        return $this->belongsTo(KitchenProduction::class, 'kitchen_production_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
