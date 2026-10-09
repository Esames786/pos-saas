<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WHATSAPP-USAGE-LEDGER-1 — aik bheje gaye message ki aik row.
 *
 * Billing ki buniyad. Jab tak ye nahi tha, "kis tenant ne kitne message bheje" ka jawab sirf
 * takhmeena tha — aur takhmeena 112 nikla jabke Meta 90 keh raha tha.
 */
class WhatsAppMessage extends Model
{
    protected $connection = 'master';

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'tenant_id', 'source', 'template', 'to', 'wamid', 'status', 'failure_reason',
        'rate_charged', 'provider_cost', 'usage_date', 'sent_at', 'invoice_id',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'sent_at' => 'datetime',
        'rate_charged' => 'decimal:4',
        'provider_cost' => 'decimal:4',
    ];

    /**
     * Sirf wo rows jin ka paisa liya ja sakta hai.
     *
     * DELIVERED, accepted nahi. 8/9 October ki raat malik ka card decline hua: humne 14 message
     * bheje, Meta ne sab "accepted" kaha, aur Meta ke apne aankRon ke mutabiq khatri ke saat me se
     * sirf AIK pohancha aur kashiffood ka poora bucket ghayab tha. Accepted par bill karte to tenant
     * us cheez ka paisa deta jo kabhi nahi aayi — aur kisi ko pata bhi na chalta.
     *
     * Meta khud bhi sirf delivered par leta hai.
     */
    public function scopeBillable($q)
    {
        return $q->whereIn('status', ['delivered', 'read']);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'invoice_id');
    }
}
