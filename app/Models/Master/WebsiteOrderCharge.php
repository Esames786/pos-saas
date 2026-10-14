<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONLINE-ORDERING-1 (Phase 4b) — Bingoo ke 1% ka record: aik paid website order (us ke business din par)
 * ya us ka return (return ke din par, minus). Tenant ka data baad me badle tab bhi band invoice apni
 * wazahat khud rakhta hai.
 */
class WebsiteOrderCharge extends Model
{
    protected $connection = 'master';

    protected $table = 'website_order_charges';

    protected $fillable = [
        'tenant_id', 'kind', 'source_id', 'sale_no', 'business_date',
        'net_amount', 'rate_percent', 'amount', 'invoice_id',
    ];

    protected $casts = [
        'business_date' => 'date',
        'net_amount' => 'decimal:2',
        'rate_percent' => 'decimal:3',
        'amount' => 'decimal:4',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'invoice_id');
    }
}
