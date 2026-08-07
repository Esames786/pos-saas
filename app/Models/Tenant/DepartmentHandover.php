<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * THIRD-PARTY-DEPARTMENT-HANDOVER-1 — one handover record: a department's sales for a period were
 * reclassified out of our income into the owner's payable (reclass entry), and later settled by
 * cash/bank (payout entry). Money-only; stock/COGS untouched.
 */
class DepartmentHandover extends Model
{
    protected $connection = 'tenant';

    public const STATUS_PENDING_PAYOUT = 'pending_payout';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_REVERSED = 'reversed';

    protected $guarded = [];

    protected $casts = [
        'branch_id' => 'integer',
        'department_id' => 'integer',
        'period_from' => 'date',
        'period_to' => 'date',
        'handover_total' => 'decimal:4',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function reclassEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'reclass_journal_entry_id');
    }

    public function payoutEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'payout_journal_entry_id');
    }

    public function isPendingPayout(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYOUT;
    }
}
