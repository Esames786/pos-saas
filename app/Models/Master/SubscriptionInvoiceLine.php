<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONLINE-ORDERING-1 (Phase 4b) — usage invoice ki aik child row: aik service, aik din.
 *
 * Seedha mat likho — `App\Services\Saas\UsageInvoiceService` se: wohi har builder ko sirf apni
 * line_type chhune deta hai aur total ko SUM(lines) se dobara nikalta hai.
 */
class SubscriptionInvoiceLine extends Model
{
    public const TYPE_WHATSAPP = 'whatsapp_messages';

    public const TYPE_WEBSITE = 'website_commission';

    public const LABELS = [
        self::TYPE_WHATSAPP => 'WhatsApp messages',
        self::TYPE_WEBSITE => 'Website orders',
        'plan' => 'Plan',
        'manual' => 'Other',
    ];

    protected $connection = 'master';

    protected $table = 'subscription_invoice_lines';

    protected $fillable = [
        'invoice_id', 'line_type', 'usage_date', 'description', 'quantity',
        'base_amount', 'rate', 'rate_unit', 'amount',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'quantity' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'rate' => 'decimal:4',
        'amount' => 'decimal:4',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'invoice_id');
    }
}
