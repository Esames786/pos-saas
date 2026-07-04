<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * DEPT-4 — end-day department physical count header.
 * Reconciles CUSTODY stock only; official branch stock/GL untouched.
 */
class DepartmentCountSession extends Model
{
    protected $connection = 'tenant';

    public const REASON_CODES = [
        'prep_loss',
        'wastage',
        'counting_error',
        'staff_meal',
        'theft',
        'transfer_missing',
        'sale_not_recorded',
        'purchase_not_recorded',
        'other',
    ];

    protected $fillable = [
        'branch_id',
        'department_id',
        'count_no',
        'count_date',
        'status',
        'notes',
        'rejection_reason',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'cancelled_by',
        'cancelled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'count_date'   => 'date',
            'submitted_at' => 'datetime',
            'approved_at'  => 'datetime',
            'rejected_at'  => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function lines()
    {
        return $this->hasMany(DepartmentCountLine::class);
    }

    public function adjustments()
    {
        return $this->hasMany(DepartmentCountAdjustment::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function totalVarianceQty(): float
    {
        return (float) $this->lines->sum('variance_qty');
    }

    public function totalVarianceValue(): float
    {
        return (float) $this->lines->sum('variance_value');
    }
}
