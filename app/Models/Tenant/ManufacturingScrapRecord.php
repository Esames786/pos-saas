<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ManufacturingScrapRecord extends Model
{
    protected $connection = 'tenant';

    public const SOURCE_TYPES = ['wip', 'finished_goods', 'manual'];

    public const STATUSES = ['draft', 'recorded', 'reviewed', 'disposed', 'cancelled', 'closed'];

    public const SCRAP_TYPES = [
        'raw_material_loss', 'wip_loss', 'finished_goods_loss', 'packaging_loss',
        'machine_waste', 'production_loss', 'other',
    ];

    public const REASON_CODES = [
        'damage', 'quality_fail', 'machine_loss', 'over_processing', 'expiry',
        'handling_loss', 'setup_loss', 'other',
    ];

    public const QUALITY_STATUSES = ['pending', 'recoverable', 'non_recoverable', 'disposed', 'not_required'];

    public const STATUS_COLORS = [
        'draft'     => 'secondary',
        'recorded'  => 'info',
        'reviewed'  => 'primary',
        'disposed'  => 'success',
        'cancelled' => 'danger',
        'closed'    => 'dark',
    ];

    public const QUALITY_COLORS = [
        'pending'         => 'secondary',
        'recoverable'     => 'success',
        'non_recoverable' => 'danger',
        'disposed'        => 'dark',
        'not_required'    => 'light',
    ];

    protected $fillable = [
        'scrap_no',
        'scrap_date',
        'source_type',
        'wip_job_id',
        'finished_good_receipt_id',
        'production_order_id',
        'manufacturing_customer_id',
        'branch_id',
        'status',
        'scrap_type',
        'reason_code',
        'quality_status',
        'total_quantity',
        'recoverable_quantity',
        'disposed_quantity',
        'estimated_loss_value',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'scrap_date'           => 'date',
            'total_quantity'       => 'decimal:4',
            'recoverable_quantity' => 'decimal:4',
            'disposed_quantity'    => 'decimal:4',
            'estimated_loss_value' => 'decimal:4',
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
        return $this->hasMany(ManufacturingScrapLine::class)->orderBy('sort_order');
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

    /** Quantity still awaiting disposal (never negative). */
    public function remainingToDispose(): float
    {
        return max(0, (float) $this->total_quantity - (float) $this->disposed_quantity);
    }

    /** Recoverable as a % of total scrap (safe when total is zero). */
    public function recoverablePercent(): float
    {
        $total = (float) $this->total_quantity;
        return $total > 0 ? round(((float) $this->recoverable_quantity / $total) * 100, 2) : 0;
    }
}
