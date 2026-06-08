<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'sales_order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'variant_name',
        'unit_code',
        'quantity',
        'unit_price',
        'unit_cost',
        'cost_total',
        'discount_amount',
        'tax_amount',
        'line_total',
        'kitchen_note',
        'returned_quantity',
        'kot_sent',
        'kot_sent_quantity',
        'void_reason_id',
        'manager_approval_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity'          => 'decimal:3',
            'unit_price'        => 'decimal:2',
            'unit_cost'         => 'decimal:4',
            'cost_total'        => 'decimal:4',
            'discount_amount'   => 'decimal:2',
            'tax_amount'        => 'decimal:2',
            'line_total'        => 'decimal:2',
            'returned_quantity' => 'decimal:3',
            'kot_sent'          => 'boolean',
            'kot_sent_quantity' => 'decimal:6',
        ];
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
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
