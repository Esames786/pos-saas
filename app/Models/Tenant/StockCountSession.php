<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCountSession extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'count_no',
        'branch_id',
        'status',
        'started_by_user_id',
        'reviewed_by_user_id',
        'posted_by_user_id',
        'cancelled_by_user_id',
        'started_at',
        'reviewed_at',
        'posted_at',
        'cancelled_at',
        'increase_stock_adjustment_id',
        'decrease_stock_adjustment_id',
        'notes',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'reviewed_at'  => 'datetime',
        'posted_at'    => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    public function increaseAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'increase_stock_adjustment_id');
    }

    public function decreaseAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'decrease_stock_adjustment_id');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['posted', 'cancelled'], true);
    }

    public function canEdit(): bool
    {
        return !$this->isLocked();
    }
}
