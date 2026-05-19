<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    protected $connection = 'tenant';
    protected $table = 'purchase_order_lines';

    protected $fillable = [
        'purchase_order_id', 'product_id', 'product_variant_id',
        'quantity_ordered', 'unit_cost', 'discount_amount', 'tax_amount', 'notes',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function getLineTotalAttribute(): float
    {
        return ($this->quantity_ordered * $this->unit_cost)
            - $this->discount_amount
            + $this->tax_amount;
    }
}
