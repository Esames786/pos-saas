<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use App\Services\Catering\CateringFinancialPositionService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 — money handed back to a customer.
 *
 * A refund is a document, not an edit. It never touches the receipt it settles:
 * the original advance row and its journal entry stay exactly as posted, and the
 * refund sits beside them as its own dated, numbered, authored record. Two
 * entries that both happened is the truth; one entry quietly rewritten is not.
 *
 * The limit is enforced here, in the model, so that every path reaches it — the
 * controller, a console command, a future import. A refund may only draw on
 * money that is not covering a bill; refunding out of an unpaid booking would
 * just recreate the balance due, on the customer's money.
 */
class CateringRefund extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    protected string $canonicalIdentityColumn = 'refund_uuid';

    protected static function booted(): void
    {
        static::creating(function (CateringRefund $refund) {
            $amount = round((float) $refund->amount, 2);

            if ($amount <= 0) {
                throw new RuntimeException('A refund must be for a positive amount.');
            }

            $event = CateringEvent::find($refund->catering_event_id);
            if (! $event) {
                throw new RuntimeException('A refund needs the booking it belongs to.');
            }

            $position = app(CateringFinancialPositionService::class)->position($event);
            $refundable = round((float) $position['refundable'], 2);

            if ($refundable <= 0) {
                throw new RuntimeException(
                    "There is nothing to refund on {$event->event_no} — the customer is not in credit. "
                    .'Money that is covering the bill cannot be handed back while the bill stands.'
                );
            }

            if ($amount > $refundable) {
                throw new RuntimeException(
                    'Refund of '.number_format($amount, 2).' exceeds the '
                    .number_format($refundable, 2)." credit owed on {$event->event_no}. "
                    .'Only money the customer has paid beyond their bill can be refunded.'
                );
            }
        });

        // A refund is history the moment it is written. Correcting one means
        // recording the offsetting receipt, not editing the row.
        static::updating(function (CateringRefund $refund) {
            foreach (array_keys($refund->getDirty()) as $column) {
                if ($column === 'updated_at') {
                    continue;
                }
                $isLinkage = in_array($column, self::WRITE_ONCE_LINKAGE, true);
                if (! $isLinkage || $refund->getOriginal($column) !== null) {
                    throw new RuntimeException('A catering refund is immutable once recorded.');
                }
            }
        });

        static::deleting(function () {
            throw new RuntimeException(
                'A catering refund cannot be deleted — money that left the business stays on the record.'
            );
        });
    }

    /** Written once, from NULL, inside the recording transaction. */
    private const WRITE_ONCE_LINKAGE = ['journal_entry_id', 'gl_posted_at'];

    protected $fillable = [
        'refund_no',
        'catering_event_id',
        'amount',
        'refund_date',
        'payment_method_id',
        'cash_bank_account_id',
        'reference',
        'reason',
        'refunded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refund_date' => 'date',
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
