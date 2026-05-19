<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'return_no',
        'sales_order_id',
        'branch_id',
        'return_date',
        'subtotal',
        'tax_amount',
        'grand_total',
        'refund_method',
        'refund_amount',
        'status',
        'created_by_user_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'return_date'   => 'datetime',
            'subtotal'      => 'decimal:2',
            'tax_amount'    => 'decimal:2',
            'grand_total'   => 'decimal:2',
            'refund_amount' => 'decimal:2',
        ];
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines()
    {
        return $this->hasMany(SalesReturnLine::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
