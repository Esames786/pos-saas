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
     * Wo rows jin ka paisa liya jata hai: HAR bheja gaya message.
     *
     * Malik ka faisla (09-10-2026): charge bhejne par hai, pohanchne par nahi. Maine delivered par
     * rakhne ki tajweez di thi — Meta khud sirf delivered par leta hai (84 bheje, 78 ka bill) — magar
     * ye qeemat ka faisla hai, taknik ka nahi, aur wo malik ka hai.
     *
     * `status` phir bhi likha jata hai aur ahem hai: usi se pata chala ke kashiffood ka aik number
     * HAR raat fail hota hai. Wo maloomat hai, bill ki shart nahi.
     *
     * ⚠️ Aik soorat jis par malik ko kabhi faisla karna paR sakta hai: agar Meta darkhwast hi radd
     * kar de (ghalat number ki shakl, ya 8/9 Oct jaisi roak), to message qatar me gaya hi nahi — phir
     * bhi is usool par us ka bill banega. Us din ye alag karna ho to `status = 'failed'` wali rows
     * nikalni hongi.
     */
    public function scopeBillable($q)
    {
        return $q;
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
