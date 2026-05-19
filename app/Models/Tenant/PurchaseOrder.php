<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $connection = 'tenant';
    protected $table = 'purchase_orders';

    protected $fillable = [
        'po_no', 'branch_id', 'supplier_id', 'order_date',
        'expected_delivery_date', 'status', 'notes', 'total_amount',
        'posted_by_user_id', 'approved_by_user_id', 'approved_at',
    ];

    protected $casts = [
        'order_date'               => 'date',
        'expected_delivery_date'   => 'date',
        'approved_at'              => 'datetime',
    ];

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
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function bills()
    {
        return $this->hasMany(PurchaseBill::class);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
