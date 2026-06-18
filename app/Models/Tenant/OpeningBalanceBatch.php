<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningBalanceBatch extends Model
{
    protected $connection = 'tenant';

    public const STATUSES = ['draft', 'posted', 'void'];

    protected $fillable = [
        'batch_no',
        'opening_date',
        'branch_id',
        'description',
        'status',
        'total_debit',
        'total_credit',
        'journal_entry_id',
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
            'opening_date' => 'date',
            'posted_at'    => 'datetime',
            'voided_at'    => 'datetime',
            'total_debit'  => 'decimal:4',
            'total_credit' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class)->orderBy('sort_order');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
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

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
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

    /** Debit − credit; zero means the batch balances and can be posted. */
    public function difference(): float
    {
        return round((float) $this->total_debit - (float) $this->total_credit, 4);
    }

    public function isBalanced(): bool
    {
        return $this->difference() === 0.0 && (float) $this->total_debit > 0;
    }
}
