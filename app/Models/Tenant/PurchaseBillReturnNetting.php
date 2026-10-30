<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SUPPLIER-NET-BILL-1 — a return the supplier's invoice already takes off (see the migration). */
class PurchaseBillReturnNetting extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['purchase_bill_id', 'purchase_return_id', 'supplier_id', 'amount', 'journal_entry_id', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }
}
