<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SalesLedger extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'sales_order_id',
        'sale_payment_id',
        'entry_type',
        'direction',
        'amount',
        'reference_no',
        'created_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function payment()
    {
        return $this->belongsTo(SalePayment::class, 'sale_payment_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
