<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** SUPPLIER-NET-BILL-1 — the audit trail of a return credit moved off one bill onto a net invoice (who confirmed it). */
class SupplierCreditReallocation extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['purchase_bill_id', 'purchase_return_id', 'from_purchase_bill_id', 'amount',
        'from_due_before', 'from_due_after', 'confirmed_by', 'confirmed_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'from_due_before' => 'decimal:4', 'from_due_after' => 'decimal:4', 'confirmed_at' => 'datetime'];
    }
}
