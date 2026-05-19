<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PurchaseBill extends Model
{
    protected $connection = 'tenant';
    protected $table = 'purchase_bills';

    protected $fillable = [
        'bill_no', 'supplier_invoice_no', 'supplier_id', 'branch_id', 'purchase_order_id', 'goods_receipt_id',
        'bill_date', 'due_date', 'status', 'subtotal', 'discount_total', 'tax_total',
        'grand_total', 'amount_paid', 'balance_due', 'notes',
        'posted_by_user_id', 'posted_at',
    ];

    protected $casts = [
        'bill_date'  => 'date',
        'due_date'   => 'date',
        'posted_at'  => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseBillLine::class);
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function supplierLedgers()
    {
        return $this->hasMany(SupplierLedger::class, 'reference_id')
            ->where('reference_type', self::class);
    }
}
