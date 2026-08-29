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
        'customer_email',
        'customer_address',
        'event_type',
        'booking_date',
        'event_date',
        'service_time',
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

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
