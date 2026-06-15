<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $connection = 'tenant';

    public const STATUSES = ['draft', 'posted', 'void'];

    protected $fillable = [
        'entry_no',
        'entry_date',
        'source_type',
        'source_id',
        'source_no',
        'description',
        'status',
        'total_debit',
        'total_credit',
        'posted_by_user_id',
        'posted_at',
        'reversed_entry_id',
        'is_reversal',
    ];

    protected function casts(): array
    {
        return [
            'entry_date'   => 'date',
            'posted_at'    => 'datetime',
            'total_debit'  => 'decimal:4',
            'total_credit' => 'decimal:4',
            'is_reversal'  => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_entry_id');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }

    public function scopeSource(Builder $query, string $type, int $id): Builder
    {
        return $query->where('source_type', $type)->where('source_id', $id);
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isBalanced(): bool
    {
        return round((float) $this->total_debit, 4) === round((float) $this->total_credit, 4);
    }
}
