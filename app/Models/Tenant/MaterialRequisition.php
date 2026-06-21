<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MaterialRequisition extends Model
{
    protected $connection = 'tenant';

    public const STATUSES = [
        'draft', 'requested', 'approved', 'issued', 'partially_issued', 'cancelled', 'closed',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUS_COLORS = [
        'draft'            => 'secondary',
        'requested'        => 'info',
        'approved'         => 'primary',
        'issued'           => 'success',
        'partially_issued' => 'warning',
        'cancelled'        => 'danger',
        'closed'           => 'dark',
    ];

    public const PRIORITY_COLORS = [
        'low'    => 'secondary',
        'normal' => 'info',
        'high'   => 'warning',
        'urgent' => 'danger',
    ];

    protected $fillable = [
        'mrc_no',
        'production_order_id',
        'manufacturing_customer_id',
        'branch_id',
        'request_date',
        'required_date',
        'status',
        'priority',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'request_date'  => 'date',
            'required_date' => 'date',
        ];
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
        return $this->hasMany(MaterialRequisitionLine::class)->orderBy('sort_order');
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
}
