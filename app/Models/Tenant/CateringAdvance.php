<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * CATERING-SLICE-3: operational advance record for the event balance display.
 * V1 HARD RULE: no GL, no cash-bank, no shift mutation (spec §19). Future
 * finance posting will reuse JournalPostingService via a translator method.
 *
 * CATERING-V1-CLOSURE-1 (§4): V1 has NO customer-credit/deposit accounting
 * authority, so an advance may never exceed the outstanding balance of the
 * event's current estimate — cumulatively across all advances. Overpayment is
 * REFUSED at the model layer (every code path), not rendered as a "negative
 * balance". Customer credit/deposit is a separate future finance design on
 * the existing accounting authority.
 */
class CateringAdvance extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'advance_uuid';

    protected static function booted(): void
    {
        static::creating(function (CateringAdvance $advance) {
            $event = CateringEvent::with('currentEstimate')->find($advance->catering_event_id);
            $estimate = $event?->currentEstimate;

            if (! $estimate || (float) $estimate->grand_total <= 0) {
                throw new RuntimeException(
                    'An advance needs a priced estimate first — the event has no estimate total to advance against.'
                );
            }

            $alreadyAdvanced = (float) static::query()
                ->where('catering_event_id', $advance->catering_event_id)
                ->sum('amount');
            $outstanding = round((float) $estimate->grand_total - $alreadyAdvanced, 2);

            if (round((float) $advance->amount, 2) > $outstanding) {
                throw new RuntimeException(
                    'Advance of '.number_format((float) $advance->amount, 2)
                    .' exceeds the outstanding balance of '.number_format(max($outstanding, 0), 2)
                    ." for {$event->event_no}. V1 has no customer-credit authority — overpayment is refused."
                );
            }
        });
    }

    protected $fillable = [
        'catering_event_id',
        'amount',
        'received_date',
        'payment_method_id',
        'reference',
        'notes',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_date' => 'date',
        ];
    }

    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
