<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SalesReturnLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'sales_return_id',
        'sales_order_line_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_price',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function orderLine()
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
