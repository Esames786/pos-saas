<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * CATERING-V1-CLOSURE-1 (§5): the immutable event-day commercial document.
 * Totals/advances/balance are frozen at issue time; the row can never be
 * updated (void/reversal policy is a future finance design). NOT a
 * sales_order; posts no GL in V1.
 */
class CateringFinalInvoice extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'invoice_uuid';

    public const STATUS_ISSUED = 'issued';

    protected $fillable = [
        'invoice_no',
        'catering_event_id',
        'catering_estimate_id',
        'snapshot',
        'subtotal',
        'service_charge_amount',
        'other_charge_label',
        'other_charge_amount',
        'discount_amount',
        'tax_amount',
        'grand_total',
        'advance_total',
        'advance_applied',
        'balance_due',
        'status',
        'issued_at',
        'issued_by_user_id',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'subtotal' => 'decimal:2',
            'service_charge_amount' => 'decimal:2',
            'other_charge_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'advance_total' => 'decimal:2',
            'advance_applied' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * CATERING-GO-LIVE-READINESS-1 (§6): the ONLY mutable columns — accounting
     * linkage, write-once (NULL → value inside the issue transaction). Every
     * commercial field stays frozen forever.
     */
    private const WRITE_ONCE_LINKAGE = [
        'journal_entry_id',
        'advance_application_journal_entry_id',
        'gl_posted_at',
    ];

    /**
     * CATERING-INVOICE-VOID-1 (10 Oct) — nishan lagane ke khaane.
     *
     * Ye bhi WRITE-ONCE hain, bilkul linkage ki tarah: ek baar void hua to
     * wo faisla bhi jam jata hai. Bill ke COMMERCIAL khaane (rakam, lines,
     * totals) ab bhi hamesha ke liye jame hue hain — void unhen badalta
     * nahi, sirf kehta hai ke ye bill ab nahi chalta.
     */
    private const WRITE_ONCE_VOID = [
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    protected static function booted(): void
    {
        static::updating(function (CateringFinalInvoice $invoice) {
            foreach (array_keys($invoice->getDirty()) as $column) {
                if ($column === 'updated_at') {
                    continue;
                }
                $isLinkage = in_array($column, self::WRITE_ONCE_LINKAGE, true)
                    || in_array($column, self::WRITE_ONCE_VOID, true);
                if (! $isLinkage || $invoice->getOriginal($column) !== null) {
                    throw new RuntimeException('A catering final invoice is immutable once issued.');
                }
            }
        });

        // 🚨 VOID HUA BILL HAR SAWAL SE BAHAR.
        //
        // `finalInvoice` is code me 14 files me 34 jagah parha jata hai —
        // balance ka hisaab, Customer Balances, calendar ka filter, document
        // lock, advance service. Har jagah "aur void to nahi?" likhna nakami
        // ka pakka nuskha hai: ek jagah bhoolte hi booking par ek aisa bill
        // "mojood" rehta hai jo void ho chuka, aur graahak ka baqi ghalat ho
        // jata hai.
        //
        // Is liye shart EK jagah hai. Bilkul wohi tareeqa jo advances par
        // `notVoided` ki shakl me pehle se chal raha hai.
        //
        // Void hue bill tak pahunchne ka EK hi raasta hai — `withVoided()` —
        // aur wo jaan boojh kar numaya hai, taake har pukarne wale ko pata ho
        // ke wo kis cheez ko chher raha hai.
        static::addGlobalScope('notVoided', function (\Illuminate\Database\Eloquent\Builder $query) {
            $query->whereNull($query->getModel()->getTable().'.voided_at');
        });
    }

    /** Void hue bill bhi — sirf wahan jahan maqsad hi wo ho (void karna, tareekh dekhna). */
    public function scopeWithVoided(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->withoutGlobalScope('notVoided');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }

    public function estimate()
    {
        return $this->belongsTo(CateringEstimate::class, 'catering_estimate_id');
    }
}
