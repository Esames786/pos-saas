<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * DEPT-2 — custody movement document.
 * issue = branch pool -> department · return = department -> branch pool ·
 * transfer = department -> department (same branch). Custody only: never
 * touches official stock or GL.
 */
class DepartmentStockTransfer extends Model
{
    protected $connection = 'tenant';

    public const TYPES = ['issue', 'return', 'transfer'];

    protected $fillable = [
        'branch_id',
        'transfer_no',
        'transfer_date',
        'transfer_type',
        'from_department_id',
        'to_department_id',
        'status',
        'notes',
        'posted_by',
        'posted_at',
        'cancelled_by',
        'cancelled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'posted_at'     => 'datetime',
            'cancelled_at'  => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function fromDepartment()
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    public function toDepartment()
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    public function lines()
    {
        return $this->hasMany(DepartmentStockTransferLine::class);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return match ($this->transfer_type) {
            'issue'    => 'Issue to Department',
            'return'   => 'Return from Department',
            'transfer' => 'Department to Department',
            default    => ucfirst((string) $this->transfer_type),
        };
    }
}
