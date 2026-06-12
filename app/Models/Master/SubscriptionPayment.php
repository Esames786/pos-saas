<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'subscription_invoice_id',
        'tenant_id',
        'payment_gateway_id',
        'payment_method_code',
        'amount',
        'currency_code',
        'payment_date',
        'reference_no',
        'status',
        'notes',
        'verified_by_user_id',
        'verified_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'payment_date' => 'date',
        'verified_at'  => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'verified_by_user_id');
    }
}
