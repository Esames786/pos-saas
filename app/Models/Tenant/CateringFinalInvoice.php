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
        'balance_due',
        'status',
        'issued_at',
        'issued_by_user_id',
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
            'balance_due' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('A catering final invoice is immutable once issued.');
        });
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
