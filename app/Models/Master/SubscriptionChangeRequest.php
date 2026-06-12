<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionChangeRequest extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'current_plan_id',
        'requested_plan_id',
        'requested_module_id',
        'related_invoice_id',
        'type',
        'status',
        'requested_by_user_id',
        'approved_by_user_id',
        'rejected_by_user_id',
        'customer_notes',
        'admin_notes',
        'approved_at',
        'rejected_at',
        'cancelled_at',
    ];

    protected $casts = [
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'current_plan_id');
    }

    public function requestedPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'requested_plan_id');
    }

    public function requestedModule(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'requested_module_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'related_invoice_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'approved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'rejected_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInvoiced(): bool
    {
        return $this->status === 'invoiced';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['rejected', 'cancelled', 'paid'], true);
    }
}
