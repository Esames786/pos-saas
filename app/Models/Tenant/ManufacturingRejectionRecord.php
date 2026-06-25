<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\HasManufacturingPostingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ManufacturingRejectionRecord extends Model
{
    use HasManufacturingPostingStatus;

    protected $connection = 'tenant';

    public const SOURCE_TYPES = ['wip', 'finished_goods', 'manual'];

    public const STATUSES = [
        'draft', 'recorded', 'under_review', 'approved_rejection', 'sent_to_rework', 'scrapped', 'cancelled', 'closed',
    ];

    public const REJECTION_TYPES = [
        'quality_fail', 'dimension_defect', 'material_defect', 'process_defect',
        'packaging_defect', 'customer_rejection', 'qc_hold', 'other',
    ];

    public const SEVERITIES = ['minor', 'major', 'critical'];

    public const DISPOSITIONS = [
        'pending', 'rework', 'scrap', 'accept_after_review', 'dispose', 'return_to_wip', 'use_as_seconds', 'other',
    ];

    public const REASON_CODES = [
        'wrong_spec', 'dimension_issue', 'surface_defect', 'material_issue', 'process_error',
        'operator_error', 'machine_issue', 'packaging_damage', 'customer_return', 'other',
    ];

    public const QUALITY_STATUSES = [
        'pending', 'failed', 'reworkable', 'accepted_after_review', 'non_recoverable', 'disposed', 'not_required',
    ];

    public const STATUS_COLORS = [
        'draft'              => 'secondary',
        'recorded'           => 'info',
        'under_review'       => 'primary',
        'approved_rejection' => 'warning',
        'sent_to_rework'     => 'info',
        'scrapped'           => 'danger',
        'cancelled'          => 'danger',
        'closed'             => 'dark',
    ];

    public const SEVERITY_COLORS = [
        'minor'    => 'info',
        'major'    => 'warning',
        'critical' => 'danger',
    ];

    protected $fillable = [
        'rejection_no',
        'rejection_date',
        'source_type',
        'wip_job_id',
        'finished_good_receipt_id',
        'production_order_id',
        'manufacturing_customer_id',
        'branch_id',
        'status',
        'rejection_type',
        'severity',
        'disposition',
        'reason_code',
        'quality_status',
        'total_quantity',
        'rework_quantity',
        'scrap_quantity',
        'accepted_after_review_quantity',
        'disposed_quantity',
        'estimated_loss_value',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'rejection_date'                 => 'date',
            'total_quantity'                 => 'decimal:4',
            'rework_quantity'                => 'decimal:4',
            'scrap_quantity'                 => 'decimal:4',
            'accepted_after_review_quantity' => 'decimal:4',
            'disposed_quantity'              => 'decimal:4',
            'estimated_loss_value'           => 'decimal:4',
        ];
    }

    public function wipJob()
    {
        return $this->belongsTo(WipJob::class);
    }

    public function finishedGoodReceipt()
    {
        return $this->belongsTo(FinishedGoodReceipt::class);
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
        return $this->hasMany(ManufacturingRejectionLine::class)->orderBy('sort_order');
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

    /** Quantity still awaiting disposition (never negative). */
    public function remainingToDispose(): float
    {
        return max(0, (float) $this->total_quantity
            - (float) $this->disposed_quantity
            - (float) $this->scrap_quantity
            - (float) $this->accepted_after_review_quantity);
    }

    /** Rework as a % of total rejected (safe when total is zero). */
    public function reworkPercent(): float
    {
        $total = (float) $this->total_quantity;
        return $total > 0 ? round(((float) $this->rework_quantity / $total) * 100, 2) : 0;
    }

    /** Scrap as a % of total rejected (safe when total is zero). */
    public function scrapPercent(): float
    {
        $total = (float) $this->total_quantity;
        return $total > 0 ? round(((float) $this->scrap_quantity / $total) * 100, 2) : 0;
    }
}
