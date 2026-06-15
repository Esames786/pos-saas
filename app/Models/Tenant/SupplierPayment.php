<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    protected $connection = 'tenant';
    protected $table = 'supplier_payments';

    protected $fillable = [
        'payment_no', 'supplier_id', 'branch_id', 'cash_bank_account_id', 'purchase_bill_id',
        'payment_date', 'amount', 'payment_method', 'reference_no',
        'bank_name', 'account_no', 'transaction_ref', 'cheque_no', 'cheque_date',
        'notes', 'posted_by_user_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'cheque_date'  => 'date',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashBankAccount()
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    public function bill()
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
