<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'address',
        'tax_number',
        'date_of_birth',
        'gender',
        'status',
        'opening_balance',
        'current_balance',
        'credit_limit',
        'credit_days',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth'   => 'date',
            'opening_balance' => 'decimal:4',
            'current_balance' => 'decimal:4',
            'credit_limit'    => 'decimal:4',
            'credit_days'     => 'integer',
        ];
    }

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function ledgers()
    {
        return $this->hasMany(CustomerLedger::class);
    }

    public function payments()
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
