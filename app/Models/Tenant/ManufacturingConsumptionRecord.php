<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\HasManufacturingPostingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ManufacturingConsumptionRecord extends Model
{
    use HasManufacturingPostingStatus;

    protected $connection = 'tenant';

    public const SOURCE_TYPES = ['wip', 'material_requisition', 'manual'];

    public const STATUSES = ['draft', 'recorded', 'reviewed', 'cancelled', 'closed'];

    public const CONSUMPTION_TYPES = [
        'production_usage', 'trial_run', 'rework_usage', 'wastage_adjustment', 'manual_usage', 'other',
    ];

    public const STATUS_COLORS = [
        'draft'     => 'secondary',
        'recorded'  => 'info',
        'reviewed'  => 'primary',
        'cancelled' => 'danger',
        'closed'    => 'dark',
    ];

    public const VARIANCE_COLORS = [
        'over_consumed'  => 'danger',
        'under_consumed' => 'warning',
        'on_plan'        => 'success',
    ];

    protected $fillable = [
        'consumption_no',
        'consumption_date',
        'source_type',
        'wip_job_id',
        'material_requisition_id',
        'production_order_id',
        'manufacturing_customer_id',
        'branch_id',
        'status',
        'consumption_type',
        'issue_reference',
        'total_planned_quantity',
        'total_consumed_quantity',
        'total_wastage_quantity',
        'total_variance_quantity',
        'estimated_consumption_value',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'consumption_date'            => 'date',
            'total_planned_quantity'      => 'decimal:4',
            'total_consumed_quantity'     => 'decimal:4',
            'total_wastage_quantity'      => 'decimal:4',
            'total_variance_quantity'     => 'decimal:4',
            'estimated_consumption_value' => 'decimal:4',
        ];
    }

    public function wipJob()
    {
        return $this->belongsTo(WipJob::class);
    }

    public function materialRequisition()
    {
        return $this->belongsTo(MaterialRequisition::class);
    }

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function manufacturingCustomer()
    {
        return $this->belongsTo(ManufacturingCustomer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines()
    {
        return $this->hasMany(ManufacturingConsumptionLine::class)->orderBy('sort_order');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['cancelled', 'closed']);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['cancelled', 'closed'], true);
    }

    /** Consumed as a % of planned (safe when planned is zero). */
    public function consumptionPercent(): float
    {
        return (float) $this->total_planned_quantity > 0
            ? round(((float) $this->total_consumed_quantity / (float) $this->total_planned_quantity) * 100, 2)
            : 0;
    }

    /** Wastage as a % of consumed (safe when consumed is zero). */
    public function wastagePercent(): float
    {
        return (float) $this->total_consumed_quantity > 0
            ? round(((float) $this->total_wastage_quantity / (float) $this->total_consumed_quantity) * 100, 2)
            : 0;
    }

    /** Over / under / on-plan based on total variance (consumed − planned). */
    public function varianceStatus(): string
    {
        if ((float) $this->total_variance_quantity > 0) {
            return 'over_consumed';
        }
        if ((float) $this->total_variance_quantity < 0) {
            return 'under_consumed';
        }
        return 'on_plan';
    }
}
