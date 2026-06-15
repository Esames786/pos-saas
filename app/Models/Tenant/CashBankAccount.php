<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashBankAccount extends Model
{
    protected $connection = 'tenant';

    public const TYPES = ['cash', 'bank', 'wallet', 'card', 'other'];

    protected $fillable = [
        'account_id',
        'branch_id',
        'currency_id',
        'code',
        'name',
        'account_type',
        'bank_name',
        'account_number',
        'iban',
        'opening_balance',
        'current_balance',
        'is_default',
        'is_system',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:4',
            'current_balance' => 'decimal:4',
            'is_default'      => 'boolean',
            'is_system'       => 'boolean',
            'is_active'       => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashBankAccountTransaction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('account_type', $type);
    }

    public function isCash(): bool
    {
        return $this->account_type === 'cash';
    }

    public function isBank(): bool
    {
        return $this->account_type === 'bank';
    }
}
