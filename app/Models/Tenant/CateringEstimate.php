<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * CATERING-SLICE-1: a versioned commercial estimate under an event.
 *
 * IMMUTABILITY CONTRACT (spec §9): once an estimate leaves `draft`, its
 * commercial fields can never change — repricing creates a NEW version via
 * CateringEstimateService::revise(). The model-level guard below is
 * defence-in-depth on top of the service/controller checks: any attempt to
 * mutate a commercial column on a sent/accepted document throws.
 */
class CateringEstimate extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'estimate_uuid';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_ACCEPTED,
        self::STATUS_SUPERSEDED,
        self::STATUS_CANCELLED,
    ];

    /** Columns frozen once the estimate is no longer draft. */
    public const COMMERCIAL_COLUMNS = [
        'catering_event_id',
        'version_no',
        'subtotal',
        'service_charge_amount',
        'other_charge_label',
        'other_charge_amount',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_amount',
        'grand_total',
        'terms',
    ];

    protected $fillable = [
        'catering_event_id',
        'version_no',
        'status',
        'subtotal',
        'service_charge_amount',
        'other_charge_label',
        'other_charge_amount',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_amount',
        'grand_total',
        'estimated_material_cost',
        'terms',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'subtotal' => 'decimal:2',
            'service_charge_amount' => 'decimal:2',
            'other_charge_amount' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'estimated_material_cost' => 'decimal:2',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CateringEstimate $estimate) {
            if ($estimate->getOriginal('status') === self::STATUS_DRAFT) {
                return;
            }

            foreach (self::COMMERCIAL_COLUMNS as $column) {
                if ($estimate->isDirty($column)) {
                    throw new RuntimeException(
                        "Catering estimate v{$estimate->getOriginal('version_no')} is no longer a draft; ".
                        "commercial field [{$column}] is immutable. Create a revision instead."
                    );
                }
            }
        });
    }

    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }

    public function lines()
    {
        return $this->hasMany(CateringEstimateLine::class)->orderBy('sort_order');
    }

    public function costSnapshots()
    {
        return $this->hasMany(CateringCostSnapshot::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Display number, e.g. "EV-20260813-0001 / Q2". */
    public function displayNo(): string
    {
        return ($this->event->event_no ?? '?').' / Q'.$this->version_no;
    }
}
