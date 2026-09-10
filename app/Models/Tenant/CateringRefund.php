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

    /**
     * CATERING-REFUND-BEYOND-CREDIT-1 — permission to hand back money that is
     * covering a bill, decided by the caller and never by the request.
     *
     * A plain property on purpose: not a column, not fillable, nothing a form
     * post can set. It exists for the length of one save and then it is gone,
     * so the authority cannot be smuggled in as a field.
     */
    public bool $allowBeyondCredit = false;

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
            $ceiling = round((float) $position['refund_ceiling'], 2);

            // The ceiling that never moves. No permission reaches past it,
            // because there is nothing behind it: the business cannot hand back
            // money it never received.
            if ($ceiling <= 0) {
                throw new RuntimeException(
                    "Nothing has been received on {$event->event_no}, so there is nothing to hand back."
                );
            }

            if ($amount > $ceiling) {
                throw new RuntimeException(
                    'Refund of '.number_format($amount, 2).' is more than the '
                    .number_format($ceiling, 2)." ever received on {$event->event_no}. "
                    .'Money that never arrived cannot be handed back.'
                );
            }

            // Beyond the credit is a DIFFERENT act, not a larger one. Up to the
            // credit the business is returning money it was merely holding;
            // past it, it is returning money that was paying a bill, and the
            // balance due comes back. That is legitimate — a deposit on a
            // booking that is still going ahead is the owner's to return — but
            // it needs someone who is allowed to decide it, and a reason on the
            // record saying why.
            if ($amount > $refundable) {
                if (! $refund->allowBeyondCredit) {
                    throw new RuntimeException(
                        'Refund of '.number_format($amount, 2).' exceeds the '
                        .number_format($refundable, 2)." credit owed on {$event->event_no}. "
                        .'Handing back money that is covering the bill needs the authority to do so — '
                        .'the balance due will go back up by the difference.'
                    );
                }

                if (trim((string) $refund->reason) === '') {
                    throw new RuntimeException(
                        'Handing back money that is covering the bill needs a reason recorded against it.'
                    );
                }
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
