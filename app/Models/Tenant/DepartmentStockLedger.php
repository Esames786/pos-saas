<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * DEPT-2 — immutable department custody movement log.
 * movement_type is a STRING (not enum) so future types need no ALTER.
 */
class DepartmentStockLedger extends Model
{
    protected $connection = 'tenant';

    /** Custody movement types for this phase. */
    public const MOVEMENT_TYPES = [
        'branch_issue_in',
        'branch_return_out',
        'department_transfer_in',
        'department_transfer_out',
        'department_adjustment_in',
        'department_adjustment_out',
        'opening_department_stock',
    ];

    protected $fillable = [
        'branch_id',
        'department_id',
        'product_id',
        'product_variant_id',
        'inventory_batch_id',
        'movement_type',
        'direction',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_after',
        'reference_type',
        'reference_id',
        'reference_no',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity'      => 'decimal:3',
            'unit_cost'     => 'decimal:4',
            'total_cost'    => 'decimal:4',
            'balance_after' => 'decimal:3',
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

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function batch()
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
