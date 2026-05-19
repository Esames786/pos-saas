<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    protected $connection = 'tenant';
    protected $table = 'goods_receipts';

    protected $fillable = [
        'grn_no', 'purchase_order_id', 'branch_id', 'supplier_id',
        'receipt_date', 'status', 'notes', 'posted_by_user_id', 'posted_at',
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'posted_at'    => 'datetime',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function bill()
    {
        return $this->hasOne(PurchaseBill::class);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function ledgers()
    {
        return $this->hasMany(StockLedger::class, 'reference_id')
            ->where('reference_type', self::class);
    }
}
