<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'code',
        'name',
        'method_type',
        'requires_reference',
        'is_cash_drawer',
        'cash_bank_account_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_reference' => 'boolean',
            'is_cash_drawer'     => 'boolean',
            'is_active'          => 'boolean',
        ];
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

    public function cashBankAccount()
    {
        return $this->belongsTo(CashBankAccount::class);
    }
}
