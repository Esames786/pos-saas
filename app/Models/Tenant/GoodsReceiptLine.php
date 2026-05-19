<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    protected $connection = 'tenant';
    protected $table = 'goods_receipt_lines';

    protected $fillable = [
        'goods_receipt_id', 'purchase_order_line_id', 'product_id', 'product_variant_id',
        'batch_no', 'expiry_date', 'quantity_received', 'unit_cost',
        'discount_amount', 'tax_amount', 'notes',
    ];

    protected $casts = [
        'expiry_date' => 'date',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderLine()
    {
        return $this->belongsTo(PurchaseOrderLine::class);
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
        return ($this->quantity_received * $this->unit_cost)
            - $this->discount_amount
            + $this->tax_amount;
    }
}
