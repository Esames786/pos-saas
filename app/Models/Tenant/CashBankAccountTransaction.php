<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashBankAccountTransaction extends Model
{
    protected $connection = 'tenant';

    public const DIRECTIONS = ['in', 'out'];

    public const TYPES = ['opening_balance', 'manual_adjustment'];

    protected $fillable = [
        'cash_bank_account_id',
        'transaction_date',
        'direction',
        'amount',
        'balance_after',
        'transaction_type',
        'reference_type',
        'reference_id',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount'           => 'decimal:4',
            'balance_after'    => 'decimal:4',
        ];
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
