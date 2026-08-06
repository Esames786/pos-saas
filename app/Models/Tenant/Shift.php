<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $connection = 'tenant';

    /**
     * SHIFT-POS-INTEGRATION-CLOSURE-1: shift_uuid is the stable cross-system sync identity, so once
     * a shift has one it must NEVER change. Generation at open and the null->value backfill are
     * allowed (original is null); any later change is rejected. Normal status/cash/close updates
     * are unaffected.
     */
    protected static function booted(): void
    {
        static::updating(function (Shift $shift) {
            if ($shift->isDirty('shift_uuid') && $shift->getOriginal('shift_uuid') !== null) {
                throw new \RuntimeException('shift_uuid is immutable once assigned and cannot be changed.');
            }
        });
    }

    protected $fillable = [
        'shift_uuid',
        'branch_id',
        'terminal_id',
        'opened_by_user_id',
        'closed_by_user_id',
        'opening_cash',
        'total_sales',
        'total_cash',
        'total_card',
        'total_bank_transfer',
        'total_cheque',
        'total_refunds',
        'total_cash_refunds',
        'total_card_refunds',
        'total_bank_refunds',
        'total_other_refunds',
        'total_discount',
        'total_tax',
        'expected_cash',
        'counted_cash',
        'cash_variance',
        'status',
        'business_date',
        'timezone_name',
        'opened_at',
        'closed_at',
        'opening_notes',
        'closing_notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:2',
            'total_sales' => 'decimal:2',
            'total_cash' => 'decimal:2',
            'total_card' => 'decimal:2',
            'total_bank_transfer' => 'decimal:2',
            'total_cheque' => 'decimal:2',
            'total_refunds' => 'decimal:2',
            'total_cash_refunds' => 'decimal:2',
            'total_card_refunds' => 'decimal:2',
            'total_bank_refunds' => 'decimal:2',
            'total_other_refunds' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'cash_variance' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'business_date' => 'date:Y-m-d',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function terminal()
    {
        return $this->belongsTo(Terminal::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function cashCountLines()
    {
        return $this->hasMany(CashCountLine::class, 'source_id')
            ->where('source_type', 'shift');
    }

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }
}
