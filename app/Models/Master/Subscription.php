<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'billing_period',
        // SAAS-BILLING-AUTO-1 — kis tareekh ko is tenant ka mahana invoice khud banta hai.
        'invoice_day',
        // WEBSITE-I18N-GEO-1 P3: what a per-branch signup bought, and the quote it was sold at.
        'pricing_model',
        'currency_code',
        'branches_purchased',
        'extra_terminals',
        'price_snapshot',
        'trial_ends_at',
        'current_period_ends_at',
        'gateway_code',
        'gateway_customer_id',
        'gateway_subscription_id',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'branches_purchased' => 'integer',
            'extra_terminals' => 'integer',
            'price_snapshot' => 'array',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoices()
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function changeRequests()
    {
        return $this->hasMany(SubscriptionChangeRequest::class);
    }
}
