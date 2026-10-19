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
        // PURCHASING-V2-1: the pack the price was typed in (screens only).
        'pack_unit_id', 'pack_factor', 'base_quantity',
        // PURCHASE-DISCOUNTS-1: what was typed (pack, price, disc, scheme).
        'entry_json',
    ];

    protected $casts = ['entry_json' => 'array'];

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
