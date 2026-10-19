<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;

/**
 * CATERING-SLICE-1: a catering event/booking. Owns the operational lifecycle;
 * commercial versions live on CateringEstimate. Never a sales_order.
 */
class CateringEvent extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'event_uuid';

    public const STATUS_INQUIRY = 'inquiry';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUOTED = 'quoted';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PRODUCTION_READY = 'production_ready';

    public const STATUS_RELEASED = 'released';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_INQUIRY,
        self::STATUS_DRAFT,
        self::STATUS_QUOTED,
        self::STATUS_CONFIRMED,
        self::STATUS_PRODUCTION_READY,
        self::STATUS_RELEASED,
        self::STATUS_COMPLETED,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    /** States in which the event still accepts commercial (estimate) changes. */
    public const OPEN_STATUSES = [
        self::STATUS_INQUIRY,
        self::STATUS_DRAFT,
        self::STATUS_QUOTED,
        self::STATUS_CONFIRMED,
    ];

    protected $fillable = [
        'event_no',
        'branch_id',
        'customer_id',
        'customer_name',
        'customer_name_ur',
        'customer_phone',
        // CATERING-PHONE-2-1: doosra raabte ka number. PEHCHAN PHONE 1 SE HI
        // hoti hai — customer usi se dhoonda/banaya jata hai. Ye driver ke
        // liye hai; is par pehchan karne se ek hi shaks ke do customer ban
        // jate.
        'customer_phone_2',
        'customer_email',
        'customer_address',
        'event_type',
        'booking_date',
        'event_date',
        'service_time',
        // KITCHEN-SHEET-A5-1: khana NIKLEGA kab — bawarchi-khane ka asal sawal.
        'dispatch_time',
        'venue',
        'pax',
        'status',
        'cancel_reason',
        'cancelled_at',
        'cancelled_by_user_id',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'event_date' => 'date',
            'pax' => 'integer',
            'confirmed_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function estimates()
    {
        return $this->hasMany(CateringEstimate::class)->orderByDesc('version_no');
    }

    /** The commercially current estimate: latest non-superseded/non-cancelled version. */
    public function currentEstimate()
    {
        return $this->hasOne(CateringEstimate::class)
            ->whereNotIn('status', [CateringEstimate::STATUS_SUPERSEDED, CateringEstimate::STATUS_CANCELLED])
            ->orderByDesc('version_no');
    }

    public function advances()
    {
        return $this->hasMany(CateringAdvance::class);
    }

    /**
     * Money handed back. Kept separate from advances rather than stored as
     * negative receipts, so a receipt is always a receipt and the two directions
     * can never be added up by accident.
     */
    public function refunds()
    {
        return $this->hasMany(CateringRefund::class);
    }

    public function productionReleases()
    {
        return $this->hasMany(CateringProductionRelease::class);
    }

    public function finalInvoice()
    {
        return $this->hasOne(CateringFinalInvoice::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * CATERING-BALANCES-STATUS-FILTER-1 — status ka NAAM aur RANG, ek jagah.
     *
     * Ye dono bookings ki fehrist ke Blade me inline likhe hue the. Customer
     * Catering Balances par bhi status dikhana tha, aur wahan in ki naql bana
     * lena sab se aasan raasta tha — aur bilkul wohi ghalti jo isi hafte punch
     * grid me pakri gayi thi, jahan ek faisla aath jagah likha hone ki wajah
     * se ek jagah badla aur baqi saat wahin reh gaye. Model hi vocabulary ka
     * malik hai, is liye jawab yahan rehta hai.
     */
    public static function statusLabel(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    /** Bootstrap ka rang — wohi jo bookings ki fehrist pehle se dikhati hai. */
    public static function statusBadge(string $status): string
    {
        return match ($status) {
            self::STATUS_CONFIRMED, self::STATUS_PRODUCTION_READY, self::STATUS_RELEASED => 'success',
            self::STATUS_QUOTED => 'info',
            self::STATUS_COMPLETED, self::STATUS_CLOSED => 'dark',
            self::STATUS_CANCELLED => 'danger',
            default => 'secondary',
        };
    }

    /**
     * CATERING-EDIT-AFTER-RELEASE-1 (1 Oct) — kya is booking ka SAUDA abhi
     * badla ja sakta hai.
     *
     * `isOpen()` se ALAG rakhi gayi hai, aur ye farq ahem hai. `isOpen()` ka
     * matlab "booking abhi chal rahi hai" hai aur calendar usi se faisla karta
     * hai ke kaun si booking "overdue" ya "needs attention" hai. Us me
     * `released` daal dene se har nikli hui booking calendar par tawajjo
     * maangne lagti — ek screen ka jawab badal kar doosri screen ko ghalat kar
     * dena.
     *
     * Ye method sirf ek sawal ka jawab deti hai: quotation abhi badal sakti
     * hai ya nahi.
     *
     * Malik (1 Oct) ne release ke BAAD bhi badalne ki ijazat maangi: aam taur
     * par graahak usi din item barha deta hai, aur us waqt tak parcha nikal
     * chuka hota hai.
     *
     * ⚠ IS KI EK QEEMAT HAI, aur wo chhupayi nahi ja sakti: nikla hua kitchen
     * sheet us quotation ka hai jo ab purani ho chuki. Bawarchi 10 KG pakayega
     * aur bill 12 KG ka banega. Is liye jahan bhi release aur mojooda
     * quotation alag hon, screen aur parcha dono us par tanbeeh karte hain —
     * dekho `hasStaleRelease()`.
     *
     * INVOICE phir bhi aakhri hadd hai. Us ke baad kuch nahi hilta, aur wo
     * faisla `CateringDocumentLock::isCommerciallyOpen()` me alag se lagta
     * hai.
     */
    public function isCommerciallyOpen(): bool
    {
        return $this->isOpen() || $this->status === self::STATUS_RELEASED;
    }

    /**
     * Koi aisi release mojood hai jo MOJOODA quotation se nahi bani?
     *
     * Yehi wo khabar hai jo release ke baad editing ko qabil-e-bardasht
     * banati hai. Is ke baghair tabdeeli khamosh hoti: kaghaz bawarchi-khane
     * me laga rehta aur koi na jaanta ke wo purana ho chuka.
     */
    public function hasStaleRelease(): bool
    {
        $currentId = $this->currentEstimate?->id;

        if ($currentId === null) {
            return false;
        }

        return $this->productionReleases()
            ->where('catering_estimate_id', '!=', $currentId)
            ->exists();
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
