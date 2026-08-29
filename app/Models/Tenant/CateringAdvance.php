<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use App\Services\Catering\CateringFinancialPositionService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * CATERING-SLICE-3: a receipt against a booking. Posts to the general ledger and
 * moves the mapped cash/bank balance through CateringAdvanceService; no stock and
 * no shift is ever touched.
 *
 * An advance may not exceed what the booking is short by, cumulatively — refused
 * at the model layer so every code path meets the same rule. What changed in
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 is the reason for the refusal: it is no
 * longer that customer credit is unrepresentable, because it now is, and a
 * CateringRefund settles it. It is that a receipt is the wrong instrument for
 * money the business has not billed for. If the customer is paying for more, the
 * quotation says so first.
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

            // One authority for what this booking owes, shared with every screen,
            // and already net of anything refunded.
            $position = app(CateringFinancialPositionService::class)->position($event);
            $outstanding = round((float) $position['balance_due'], 2);
            $amount = round((float) $advance->amount, 2);

            if ($amount <= $outstanding) {
                return;
            }

            // Taking more while the business already owes money back would deepen
            // a debt in the wrong direction, so it is refused with the settlement
            // named rather than with a bare limit.
            if ($position['customer_credit'] > 0) {
                throw new RuntimeException(
                    "{$event->event_no} is already carrying "
                    .number_format((float) $position['customer_credit'], 2)
                    .' of credit owed to the customer, so no further payment can be taken. '
                    .'Refund the credit first, or raise the quotation to cover it.'
                );
            }

            throw new RuntimeException(
                'Advance of '.number_format($amount, 2)
                .' exceeds the outstanding balance of '.number_format($outstanding, 2)
                ." for {$event->event_no}. Taking more would leave the business holding money it has "
                .'not billed for — raise the quotation first if the customer is paying for more.'
            );
        });
    }

    public const POSTING_ADVANCE = 'advance';       // pre-invoice deposit → Cr 2300

    public const POSTING_SETTLEMENT = 'settlement'; // post-invoice AR payment → Cr 1300

    protected $fillable = [
        'catering_event_id',
        'amount',
        'received_date',
        'payment_method_id',
        'reference',
        'notes',
        'recorded_by_user_id',
        'posting_type',
        'cash_bank_account_id',
        'journal_entry_id',
        'gl_posted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_date' => 'date',
            'gl_posted_at' => 'datetime',
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
