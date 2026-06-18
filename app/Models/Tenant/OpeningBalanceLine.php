<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningBalanceLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'opening_balance_batch_id',
        'account_id',
        'cash_bank_account_id',
        'party_type',
        'party_id',
        'description',
        'debit',
        'credit',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'debit'  => 'decimal:4',
            'credit' => 'decimal:4',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceBatch::class, 'opening_balance_batch_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }
}
