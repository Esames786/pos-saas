<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'sales_order_id',
        'payment_method_id',
        'amount',
        'tendered_amount',
        'change_amount',
        'bank_name',
        'account_no',
        'transaction_ref',
        'card_last_four',
        'cheque_no',
        'cheque_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'          => 'decimal:2',
            'tendered_amount' => 'decimal:2',
            'change_amount'   => 'decimal:2',
            'cheque_date'     => 'date',
        ];
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
