<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'suppliers';

    protected $fillable = [
        'code', 'name', 'contact_person', 'phone', 'email',
        'address', 'tax_number', 'payment_terms_days',
        'opening_balance', 'current_balance', 'status',
        // PURCHASE-DISCOUNTS-1: what this supplier usually gives (fills the new purchase screens' disc %).
        'default_discount_percent',
    ];

    public function ledgers()
    {
        return $this->hasMany(SupplierLedger::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function purchaseBills()
    {
        return $this->hasMany(PurchaseBill::class);
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
