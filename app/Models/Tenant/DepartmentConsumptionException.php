<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * DEPT-3A — a shadow custody deduction that could not be applied.
 * The official POS sale always succeeded; only the department custody
 * mirror failed (no mapping / insufficient custody).
 */
class DepartmentConsumptionException extends Model
{
    protected $connection = 'tenant';

    public const REASONS = [
        'no_department_mapping',
        'insufficient_department_stock',
        'invalid_stock_ledger',
        'already_processed',
    ];

    protected $fillable = [
        'exception_key',
        'stock_ledger_id',
        'branch_id',
        'department_id',
        'product_id',
        'product_variant_id',
        'movement_type',
        'quantity',
        'reason',
        'status',
        'reference_type',
        'reference_id',
        'reference_no',
        'message',
        'payload',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity'    => 'decimal:3',
            'payload'     => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function stockLedger()
    {
        return $this->belongsTo(StockLedger::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'no_department_mapping'         => 'No department mapping',
            'insufficient_department_stock' => 'Insufficient custody stock',
            'invalid_stock_ledger'          => 'Invalid stock ledger',
            'already_processed'             => 'Already processed',
            default                         => ucfirst(str_replace('_', ' ', (string) $this->reason)),
        };
    }
}
