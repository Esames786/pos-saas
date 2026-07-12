<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'purchase_return_id',
        'product_id',
        'product_variant_id',
        'inventory_batch_id',
        'source_line_type',
        'source_line_id',
        'quantity',
        'unit_cost',
        'tax_amount',
        'discount_amount',
        'line_total',
        'reason_code',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity'        => 'decimal:3',
            'unit_cost'       => 'decimal:4',
            'tax_amount'      => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'line_total'      => 'decimal:4',
        ];
    }

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function batch()
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    /** Source GRN line when this return line was sourced from a receipt. */
    public function sourceGrnLine()
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'source_line_id');
    }
}
