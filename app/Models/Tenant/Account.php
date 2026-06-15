<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $connection = 'tenant';

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    public const NORMAL_BALANCES = ['debit', 'credit'];

    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'type',
        'normal_balance',
        'description',
        'is_system',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system'  => 'boolean',
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }

    public function isCreditNormal(): bool
    {
        return $this->normal_balance === 'credit';
    }

    /**
     * Conventional normal balance for an account type:
     *   asset, expense  → debit
     *   liability, equity, income → credit
     */
    public static function normalBalanceForType(string $type): string
    {
        return in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }
}
