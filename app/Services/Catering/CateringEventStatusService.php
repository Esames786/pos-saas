<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CATERING-STATUS-ROLLBACK-1 — moving a booking BACK, and the line past which
 * it may not go.
 *
 * The rule is not about which status a booking is in. It is about what that
 * status already DID:
 *
 *   inquiry / draft / quoted / confirmed   nothing has been posted at all —
 *                                          no journal entry, no stock movement.
 *                                          Only intent, and a frozen document.
 *
 *   released / materials issued            stock has left the store through
 *                                          InventoryService::postOutFefo.
 *
 *   completed / closed                     the final invoice posted revenue to
 *                                          4160 and receivables to 1300.
 *
 * Only the first group can be walked back by changing a status, because only
 * there is the status the whole of what happened. Past it, a status change
 * would be a lie told over the top of a ledger entry or a stock ledger row:
 * the money and the goods would still be gone, and the screen would say the
 * booking had never got that far. Those are answered by another DOCUMENT — a
 * refund, a stock correction — never by an edit.
 *
 * `production_ready` and `released` sit in between: they write documents and
 * print kitchen sheets, but they do not move stock on their own. They could
 * technically be walked back. The owner chose not to (decision 3, plan §8), and
 * the line is drawn where NOTHING has been posted, because that line is easy to
 * explain and impossible to get subtly wrong.
 *
 * MONEY IS NEVER TOUCHED HERE. Not a journal entry created, reversed or
 * deleted; not a receipt, refund or invoice row written. A status change
 * re-interprets money — a cancelled booking bills nothing, so a deposit becomes
 * credit — but it never moves any. That property is pinned by
 * CateringFinanceMySqlTest and it is the reason this service can exist at all.
 */
class CateringEventStatusService
{
    /**
     * Where each status may go back to. One step at a time, deliberately: a
     * booking walking from confirmed to inquiry should pass through — and be
     * recorded at — every stage it actually occupied.
     */
    private const BACKWARD = [
        CateringEvent::STATUS_CONFIRMED => CateringEvent::STATUS_QUOTED,
        CateringEvent::STATUS_QUOTED => CateringEvent::STATUS_DRAFT,
        CateringEvent::STATUS_DRAFT => CateringEvent::STATUS_INQUIRY,
    ];

    /** Statuses a cancelled booking may be restored to. */
    private const RESTORABLE = [
        CateringEvent::STATUS_INQUIRY,
        CateringEvent::STATUS_DRAFT,
        CateringEvent::STATUS_QUOTED,
        CateringEvent::STATUS_CONFIRMED,
    ];

    public function __construct(
        private readonly CateringDocumentLock $locks,
        private readonly CateringEstimateService $estimates,
    ) {}

    /** What this booking can be moved back to right now, or null if nothing. */
    public function backwardTarget(CateringEvent $event): ?string
    {
        if ($event->isCancelled()) {
            return $this->restoreTarget($event);
        }

        return self::BACKWARD[$event->status] ?? null;
    }

    /**
     * What a SCREEN should ask before drawing the button.
     *
     * backwardTarget() is the status map alone, and the map is not the whole
     * rule: a confirmed booking with a production release passes it and is
     * then refused by assertNothingPosted(). Correct on the server, but it
     * would put a button on the screen whose only possible outcome is an
     * error message. This is the question the screens actually have.
     *
     * Kept separate rather than folded into backwardTarget() because the
     * belt-and-braces layer has to stay independently testable — a guard
     * that can only be reached through the map is a guard that proves
     * nothing, which this feature has already learned once.
     */
    public function canMoveBack(CateringEvent $event): bool
    {
        if ($this->backwardTarget($event) === null) {
            return false;
        }

        return ! $event->finalInvoice()->exists()
            && ! $event->productionReleases()->exists();
    }

    /**
     * Where an un-cancel would land. The status the booking held when it was
     * cancelled, or `draft` when that was never recorded — every booking
     * cancelled before this feature shipped, where `cancelled_at` says when but
     * nothing says from what. Guessing a later stage would be worse than
     * landing early and saying so.
     */
    public function restoreTarget(CateringEvent $event): string
    {
        $remembered = $event->status_before_cancel;

        return in_array($remembered, self::RESTORABLE, true)
            ? $remembered
            : CateringEvent::STATUS_DRAFT;
    }

    /** True when the booking was cancelled without a record of where from. */
    public function restoreTargetIsAssumed(CateringEvent $event): bool
    {
        return $event->isCancelled()
            && ! in_array($event->status_before_cancel, self::RESTORABLE, true);
    }

    /**
     * Move a booking one step back, or restore a cancelled one.
     *
     * @param  string  $reason  why — required, and kept on the History row
     */
    public function moveBack(CateringEvent $event, string $reason, ?int $userId = null): CateringEvent
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Moving a booking back needs a reason recorded against it.');
        }

        return DB::connection('tenant')->transaction(function () use ($event, $userId) {
            // The same lock confirm and cancel take. Judging "can this go back"
            // while another writer is moving it forward would let both decisions
            // stand on facts that were true separately and never together.
            $this->locks->refreshEvent($event);
            $event->refresh();

            $from = $event->status;
            $to = $this->backwardTarget($event);

            if ($to === null) {
                throw new RuntimeException(
                    "Event {$event->event_no} is {$from} and cannot be moved back. "
                    .'Once the final invoice is issued or materials have been released, the booking has '
                    .'left a mark on the ledger or the store — that is undone with a document, never with a status.'
                );
            }

            // Belt and braces over the map above. If a future edit ever adds a
            // backward step out of a posted status, this refuses it anyway.
            $this->assertNothingPosted($event, $from);

            $attributes = ['status' => $to];

            if ($event->isCancelled()) {
                // The cancellation itself is history and stays legible: the
                // reason and the timestamp are NOT erased. Only the pointer that
                // made sense while it was cancelled is cleared.
                $attributes['status_before_cancel'] = null;
            }

            if ($from === CateringEvent::STATUS_CONFIRMED) {
                $attributes['confirmed_at'] = null;
            }

            $event->forceFill($attributes)->save();

            // quoted -> draft is not only a status: the quotation the customer
            // was sent becomes editable again. Done through the estimate's own
            // authority so the immutability guard and the History row both fire.
            if ($from === CateringEvent::STATUS_QUOTED && $to === CateringEvent::STATUS_DRAFT) {
                $this->reopenCurrentQuotation($event, $userId);
            }

            return $event;
        });
    }

    /**
     * Reopen the current quotation for editing.
     *
     * This is the half of the change with a cost, and it is worth naming: the
     * document the customer is holding stops matching what the system holds.
     * `Create Revision` remains the answer when they should be given new paper;
     * this is for correcting the paper they already have — a typo, one item,
     * one rate.
     */
    private function reopenCurrentQuotation(CateringEvent $event, ?int $userId): void
    {
        $current = $event->currentEstimate;

        if (! $current || $current->isDraft()) {
            return;
        }

        if ($current->status !== CateringEstimate::STATUS_SENT) {
            throw new RuntimeException(
                "Quotation Q{$current->version_no} is {$current->status} and cannot be reopened. "
                .'Create a revision instead.'
            );
        }

        $current->forceFill([
            'status' => CateringEstimate::STATUS_DRAFT,
            'sent_at' => null,
        ])->save();
    }

    /**
     * The last line of defence: nothing may go back once the ledger or the
     * store has been touched, whatever the map says.
     */
    private function assertNothingPosted(CateringEvent $event, string $from): void
    {
        if ($event->finalInvoice()->exists()) {
            throw new RuntimeException(
                "Event {$event->event_no} has a final invoice — revenue and receivables are posted. "
                .'Moving it back would leave the ledger saying one thing and the booking another.'
            );
        }

        if ($event->productionReleases()->exists()) {
            throw new RuntimeException(
                "Event {$event->event_no} has a production release — the kitchen has been told to cook. "
                .'Cancel or amend the release before moving the booking back.'
            );
        }
    }
}
