<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SUPPLIER-RUNNING-ACCOUNT-1 — one credit (a supplier payment or a purchase return) settling part
 * of one purchase bill. A bill's amount_paid is the sum of its rows; a credit's amount not in any
 * row is the supplier's advance.
 */
class SupplierCreditAllocation extends Model
{
    protected $connection = 'tenant';

    public const SOURCE_PAYMENT = 'payment';

    public const SOURCE_RETURN = 'return';

    protected $fillable = ['supplier_id', 'source_type', 'source_id', 'purchase_bill_id', 'amount'];

    protected $casts = ['amount' => 'decimal:4'];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }
}
