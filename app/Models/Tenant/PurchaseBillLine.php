<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PurchaseBillLine extends Model
{
    protected $connection = 'tenant';
    protected $table = 'purchase_bill_lines';

    protected $fillable = [
        'purchase_bill_id', 'product_id', 'product_variant_id',
        'quantity', 'unit_cost', 'discount_amount', 'tax_amount', 'line_total', 'notes',
    ];

    public function purchaseBill()
    {
        return $this->belongsTo(PurchaseBill::class);
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
