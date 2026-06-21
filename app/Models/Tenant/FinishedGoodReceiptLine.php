<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class FinishedGoodReceiptLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'finished_good_receipt_id',
        'finished_product_id',
        'unit_id',
        'batch_no',
        'lot_no',
        'received_quantity',
        'accepted_quantity',
        'rejected_quantity',
        'scrap_quantity',
        'expiry_date',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'received_quantity' => 'decimal:4',
            'accepted_quantity' => 'decimal:4',
            'rejected_quantity' => 'decimal:4',
            'scrap_quantity'    => 'decimal:4',
            'expiry_date'       => 'date',
            'sort_order'        => 'integer',
        ];
    }

    public function receipt()
    {
        return $this->belongsTo(FinishedGoodReceipt::class, 'finished_good_receipt_id');
    }

    public function finishedProduct()
    {
        return $this->belongsTo(Product::class, 'finished_product_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
