<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionInvoice extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'invoice_no',
        'tenant_id',
        'subscription_id',
        'plan_id',
        'invoice_type',
        'status',
        'currency_code',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'balance_amount',
        'period_start',
        'period_end',
        'due_date',
        'issued_at',
        'paid_at',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'paid_amount'     => 'decimal:2',
        'balance_amount'  => 'decimal:2',
        'period_start'    => 'date',
        'period_end'      => 'date',
        'due_date'        => 'date',
        'issued_at'       => 'datetime',
        'paid_at'         => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'created_by_user_id');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
