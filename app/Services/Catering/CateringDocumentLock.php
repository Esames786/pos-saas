<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use Illuminate\Support\Collection;

/**
 * KASHIF-CATERING-LIFECYCLE-LOCK-1 — one lock order for a booking's commercial
 * state, used by every operation that competes for it.
 *
 * THE RACE THIS EXISTS TO CLOSE. Rate Impact reads a quotation, finds it is a
 * draft, and reprices it. Send reads the same quotation, finds it is a draft,
 * and marks it sent. Run those a few milliseconds apart and the customer is
 * holding a quotation whose numbers changed after it left the building.
 *
 * Three separate guards were supposed to prevent that and none of them did,
 * because all three ask an object that was loaded BEFORE the other transaction
 * committed:
 *
 *   CateringEstimate::updating   reads getOriginal('status') — 'draft', as loaded
 *   CateringEstimateLine guard   reads $line->estimate?->status — the CACHED relation
 *   CateringEstimateLineCostBlock  has no guard at all
 *
 * An in-memory check cannot see a row another connection has just written. Only
 * the database can serialize this, so the fix is a lock taken before the state
 * is read, and a re-read taken after the lock is held.
 *
 * THE ORDER, and it is the same everywhere:
 *
 *      catering_events
 *      catering_final_invoices      (the boundary that ends the event)
 *      catering_estimates
 *      catering_estimate_lines
 *      catering_estimate_line_cost_blocks
 *
 * parent before child, each set ascending by id. Two transactions that both
 * follow it can queue behind each other but can never hold what the other needs
 * next, which is what makes a deadlock cycle impossible rather than unlikely.
 *
 * WHY EVERY DECISION READ IS A LOCKING READ. InnoDB's default REPEATABLE READ
 * gives a transaction a consistent snapshot fixed at its first plain read. Wait
 * on a lock after that, and the row you were waiting for arrives — but every
 * ORDINARY read still answers from the old snapshot. A first attempt at this fix
 * locked correctly and then asked `$estimate->event` the ordinary way, so an
 * apply queued behind a cancellation waited for it, acquired the lock, read a
 * pre-cancellation event, and applied anyway. Waiting for a lock and then
 * believing a stale read is not serialization; it is a slower race.
 *
 * So the rows that decide WHETHER an operation may proceed — the event, its
 * final invoice, the estimate — are always read FOR UPDATE and handed back
 * attached to each other. Everything beneath the estimate lock may then be read
 * ordinarily, because no other writer can reach it without first taking that
 * lock from us.
 */
class CateringDocumentLock
{
    /**
     * Lock event rows and the final invoices that close them, and return the
     * events with that invoice already attached.
     *
     * The invoice is locked here rather than looked up later because "has this
     * booking been billed" is a decision input, and a plain lookup would answer
     * from a snapshot taken before the invoice existed.
     *
     * @param  array<int, int>  $eventIds
     * @return Collection<int, CateringEvent>
     */
    public function events(array $eventIds): Collection
    {
        $ids = $this->clean($eventIds);
        if ($ids === []) {
            return collect();
        }

        $events = CateringEvent::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $invoices = CateringFinalInvoice::query()
            ->whereIn('catering_event_id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('catering_event_id');

        foreach ($events as $event) {
            // Set either way: a relation explicitly loaded as null is how a
            // caller knows the answer came from under the lock rather than from
            // a lazy query it should not be making.
            $event->setRelation('finalInvoice', $invoices->get($event->getKey()));
        }

        return $events;
    }

    /**
     * Lock the given estimates AND the events they belong to, in order, and hand
     * back the freshly-read rows with their locked event attached.
     *
     * The returned models are the only ones a caller may trust: anything loaded
     * before the lock describes the world as it was, not as it is.
     *
     * @param  array<int, int>  $estimateIds
     * @return Collection<int, CateringEstimate>
     */
    public function estimates(array $estimateIds): Collection
    {
        $ids = $this->clean($estimateIds);
        if ($ids === []) {
            return collect();
        }

        // Which events, before locking anything — an unlocked read used only to
        // decide what to lock, never to decide what to do.
        $events = $this->events(
            CateringEstimate::query()->whereIn('id', $ids)->pluck('catering_event_id')->all()
        );

        $estimates = CateringEstimate::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($estimates as $estimate) {
            if ($event = $events->get($estimate->catering_event_id)) {
                $estimate->setRelation('event', $event);
            }
        }

        return $estimates;
    }

    /**
     * The single-document case: lock its event and itself, then bring the
     * caller's own instance up to the locked truth.
     *
     * Callers keep their model — existing code all over the estimate service
     * holds one and expects it to stay the same object — but its attributes are
     * replaced from a locking read rather than refreshed by an ordinary one,
     * which under REPEATABLE READ could still be answering from before the wait.
     */
    public function refreshEstimate(CateringEstimate $estimate): CateringEstimate
    {
        $locked = $this->estimates([$estimate->getKey()]);
        $fresh = $locked->get($estimate->getKey());

        if ($fresh === null) {
            return $estimate;
        }

        // Same object, current attributes, and getOriginal() now describes the
        // locked row — which is what the model's own immutability guard reads.
        $estimate->setRawAttributes($fresh->getAttributes(), true);
        $estimate->setRelations([]);
        $estimate->setRelation('event', $fresh->getRelation('event'));

        return $estimate;
    }

    /** The event-level equivalent, for transitions that close a booking. */
    public function refreshEvent(CateringEvent $event): CateringEvent
    {
        $fresh = $this->events([$event->getKey()])->get($event->getKey());

        if ($fresh === null) {
            return $event;
        }

        $event->setRawAttributes($fresh->getAttributes(), true);
        $event->setRelations([]);
        $event->setRelation('finalInvoice', $fresh->getRelation('finalInvoice'));

        return $event;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function clean(array $ids): array
    {
        return collect($ids)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
