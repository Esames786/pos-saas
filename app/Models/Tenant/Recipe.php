<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'product_id',
        'name',
        'yield_quantity',
        'yield_unit_id',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'yield_quantity' => 'decimal:4',
            'is_active'      => 'boolean',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function yieldUnit()
    {
        return $this->belongsTo(Unit::class, 'yield_unit_id');
    }

    public function ingredients()
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('sort_order');
    }

    public function consumptions()
    {
        return $this->hasMany(RecipeConsumption::class);
    }

    public function productions()
    {
        return $this->hasMany(KitchenProduction::class);
    }
}
