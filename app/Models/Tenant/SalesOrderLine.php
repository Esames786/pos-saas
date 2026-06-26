<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'sales_order_id',
        'parent_sales_order_line_id',
        'line_kind',
        'combo_id',
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
        'modifiers',
        'kitchen_status',
        'kitchen_started_at',
        'kitchen_ready_at',
        'kitchen_completed_at',
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
            'modifiers'         => 'array',
            'returned_quantity' => 'decimal:3',
            'kot_sent'          => 'boolean',
            'kot_sent_quantity' => 'decimal:6',
            'kitchen_started_at'   => 'datetime',
            'kitchen_ready_at'     => 'datetime',
            'kitchen_completed_at' => 'datetime',
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

    public function parentLine()
    {
        return $this->belongsTo(self::class, 'parent_sales_order_line_id');
    }

    public function componentLines()
    {
        return $this->hasMany(self::class, 'parent_sales_order_line_id');
    }

    public function combo()
    {
        return $this->belongsTo(Combo::class);
    }
}
