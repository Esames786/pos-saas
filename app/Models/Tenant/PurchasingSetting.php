<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * SUPPLIER-RUNNING-ACCOUNT-1 — purchasing switches for this tenant (one row).
 *
 * `supplier_running_account`: suppliers are paid on account — a payment may exceed what is owed
 * (the excess is an advance, still in 2100), and payments/returns settle bills oldest first.
 * OFF unless the owner turns it on with `finance:supplier-running-account`.
 */
class PurchasingSetting extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'supplier_running_account',
        'supplier_running_account_changed_at',
        'supplier_running_account_changed_by',
    ];

    protected $casts = [
        'supplier_running_account'            => 'boolean',
        'supplier_running_account_changed_at' => 'datetime',
    ];

    /** Read-only: no row (every tenant until its owner decides) means OFF. */
    public static function supplierRunningAccount(): bool
    {
        return (bool) static::query()->orderBy('id')->value('supplier_running_account');
    }

    public static function tenantDefault(): self
    {
        return static::query()->orderBy('id')->firstOrCreate([], ['supplier_running_account' => false]);
    }
}
