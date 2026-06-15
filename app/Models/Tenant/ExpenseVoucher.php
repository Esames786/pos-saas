<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseVoucher extends Model
{
    protected $connection = 'tenant';

    public const STATUSES = ['draft', 'posted', 'void'];

    protected $fillable = [
        'voucher_no',
        'branch_id',
        'cash_bank_account_id',
        'expense_date',
        'payment_date',
        'payee_name',
        'status',
        'subtotal',
        'tax_amount',
        'total_amount',
        'notes',
        'receipt_path',
        'created_by_user_id',
        'posted_by_user_id',
        'posted_at',
        'voided_by_user_id',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'payment_date' => 'date',
            'posted_at'    => 'datetime',
            'voided_at'    => 'datetime',
            'subtotal'     => 'decimal:4',
            'tax_amount'   => 'decimal:4',
            'total_amount' => 'decimal:4',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseVoucherLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopeVoid(Builder $query): Builder
    {
        return $query->where('status', 'void');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
