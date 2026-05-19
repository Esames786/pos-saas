<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SupplierLedger extends Model
{
    protected $connection = 'tenant';
    protected $table = 'supplier_ledgers';

    protected $fillable = [
        'supplier_id', 'entry_type', 'direction', 'amount', 'balance_after',
        'reference_type', 'reference_id', 'reference_no', 'notes', 'created_by_user_id',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
