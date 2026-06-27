<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class RecipeIngredient extends Model
{
    protected $connection = 'tenant';

    /** KITCHEN-RECIPE-COST-1 report sections. */
    public const SECTIONS = [
        'food_cost'        => 'Recipe / Food Cost',
        'packing_material' => 'Packing Material',
        'garnish'          => 'Garnish',
        'sauce'            => 'Sauce',
        'other'            => 'Other',
    ];

    protected $fillable = [
        'recipe_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_id',
        'cost_override',
        'line_section',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity'      => 'decimal:4',
            'cost_override' => 'decimal:4',
            'sort_order'    => 'integer',
        ];
    }

    public function sectionLabel(): string
    {
        return self::SECTIONS[$this->line_section ?? 'food_cost'] ?? 'Recipe / Food Cost';
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
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
