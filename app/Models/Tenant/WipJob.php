<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WipJob extends Model
{
    protected $connection = 'tenant';

    public const STATUSES = [
        'draft', 'released', 'in_progress', 'on_hold', 'ready_for_completion', 'completed', 'cancelled',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUS_COLORS = [
        'draft'                => 'secondary',
        'released'             => 'primary',
        'in_progress'          => 'warning',
        'on_hold'              => 'dark',
        'ready_for_completion' => 'info',
        'completed'            => 'success',
        'cancelled'            => 'danger',
    ];

    public const PRIORITY_COLORS = [
        'low'    => 'secondary',
        'normal' => 'info',
        'high'   => 'warning',
        'urgent' => 'danger',
    ];

    protected $fillable = [
        'wip_no',
        'production_order_id',
        'material_requisition_id',
        'manufacturing_customer_id',
        'branch_id',
        'finished_product_id',
        'planned_quantity',
        'started_quantity',
        'completed_quantity',
        'start_date',
        'target_date',
        'status',
        'priority',
        'progress_percent',
        'notes',
        'created_by_user_id',
        // WIP cost accumulation (MFG-FIN-B) — populated by a future posting phase only.
        'accumulated_cost',
        'costed_quantity',
        'average_unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'start_date'         => 'date',
            'target_date'        => 'date',
            'planned_quantity'   => 'decimal:4',
            'started_quantity'   => 'decimal:4',
            'completed_quantity' => 'decimal:4',
            'progress_percent'   => 'decimal:2',
            'accumulated_cost'   => 'decimal:4',
            'costed_quantity'    => 'decimal:4',
            'average_unit_cost'  => 'decimal:4',
        ];
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function materialRequisition()
    {
        return $this->belongsTo(MaterialRequisition::class);
    }

    public function manufacturingCustomer()
    {
        return $this->belongsTo(ManufacturingCustomer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function finishedProduct()
    {
        return $this->belongsTo(Product::class, 'finished_product_id');
    }

    public function lines()
    {
        return $this->hasMany(WipJobLine::class)->orderBy('sort_order');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['completed', 'cancelled'], true);
    }

    /** Finished quantity still outstanding (never negative). */
    public function remainingQuantity(): float
    {
        return max(0, (float) $this->planned_quantity - (float) $this->completed_quantity);
    }

    /** Recompute progress_percent from completed vs planned (safe when planned is zero). */
    public function recalculateProgress(): void
    {
        $planned = (float) $this->planned_quantity;
        $this->progress_percent = $planned > 0
            ? min(100, round(((float) $this->completed_quantity / $planned) * 100, 2))
            : 0;
    }
}
