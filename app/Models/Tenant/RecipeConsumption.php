<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class RecipeConsumption extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'recipe_id',
        'sales_order_id',
        'sales_order_line_id',
        'product_id',
        'product_variant_id',
        'quantity_consumed',
        'unit_id',
        'consumed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_consumed' => 'decimal:4',
            'consumed_at'       => 'datetime',
        ];
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesOrderLine()
    {
        return $this->belongsTo(SalesOrderLine::class);
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
