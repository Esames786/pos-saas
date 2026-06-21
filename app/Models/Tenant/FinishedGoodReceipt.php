<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FinishedGoodReceipt extends Model
{
    protected $connection = 'tenant';

    public const STATUSES = [
        'draft', 'recorded', 'quality_check', 'accepted', 'partially_accepted', 'rejected', 'cancelled', 'closed',
    ];

    public const QUALITY_STATUSES = ['pending', 'passed', 'failed', 'partial', 'not_required'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUS_COLORS = [
        'draft'              => 'secondary',
        'recorded'           => 'info',
        'quality_check'      => 'primary',
        'accepted'           => 'success',
        'partially_accepted' => 'warning',
        'rejected'           => 'danger',
        'cancelled'          => 'danger',
        'closed'             => 'dark',
    ];

    public const QUALITY_COLORS = [
        'pending'      => 'secondary',
        'passed'       => 'success',
        'failed'       => 'danger',
        'partial'      => 'warning',
        'not_required' => 'light',
    ];

    public const PRIORITY_COLORS = [
        'low'    => 'secondary',
        'normal' => 'info',
        'high'   => 'warning',
        'urgent' => 'danger',
    ];

    protected $fillable = [
        'fg_no',
        'wip_job_id',
        'production_order_id',
        'manufacturing_customer_id',
        'branch_id',
        'finished_product_id',
        'receipt_date',
        'status',
        'quality_status',
        'planned_quantity',
        'received_quantity',
        'accepted_quantity',
        'rejected_quantity',
        'scrap_quantity',
        'priority',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date'      => 'date',
            'planned_quantity'  => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'accepted_quantity' => 'decimal:4',
            'rejected_quantity' => 'decimal:4',
            'scrap_quantity'    => 'decimal:4',
        ];
    }

    public function wipJob()
    {
        return $this->belongsTo(WipJob::class);
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

    public function finishedProduct()
    {
        return $this->belongsTo(Product::class, 'finished_product_id');
    }

    public function lines()
    {
        return $this->hasMany(FinishedGoodReceiptLine::class)->orderBy('sort_order');
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

    /** Received quantity not yet dispositioned (never negative). */
    public function remainingToAccept(): float
    {
        return max(0, (float) $this->received_quantity
            - (float) $this->accepted_quantity
            - (float) $this->rejected_quantity
            - (float) $this->scrap_quantity);
    }

    /** Accepted as a % of received (safe when received is zero). */
    public function acceptancePercent(): float
    {
        $received = (float) $this->received_quantity;
        return $received > 0 ? round(((float) $this->accepted_quantity / $received) * 100, 2) : 0;
    }
}
