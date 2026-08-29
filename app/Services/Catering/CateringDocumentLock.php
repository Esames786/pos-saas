<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

    // ─────────────────────────────────────────────────────────────────────────
    // THE COMMERCIAL MUTATION CONTRACT.
    //
    // Every operation that can change what a customer is quoted enters through
    // one of these. The pattern is always the same and the order of the three
    // steps is the whole point:
    //
    //      1. discover which document the target belongs to   (unlocked)
    //      2. lock that document, parent first                (locking)
    //      3. re-read the target and decide                   (locking)
    //
    // Step 1 is allowed to be unlocked because it only chooses what to lock. It
    // is never allowed to authorize anything. The reason a first fix of this
    // defect still failed is that authorization was being taken from step 1: a
    // writer checked "is it a draft", queued behind a row, and by the time it
    // woke up the answer had changed and nobody asked again.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Is this booking still open to commercial change at all — regardless of
     * what the quotation's own status says?
     *
     * Event-level closure outranks it. A draft can outlive the booking that
     * justified it: revise after invoicing and v2 is a draft on an invoiced
     * event, where "it is a draft" would otherwise read as permission.
     */
    public function isCommerciallyOpen(CateringEstimate $estimate): bool
    {
        $event = $estimate->event;

        if ($event === null || ! $event->isOpen()) {
            return false;
        }

        // Prefer the invoice resolved UNDER the lock. Asking the relation afresh
        // issues an ordinary read, and an ordinary read inside a transaction
        // that has been waiting answers from the snapshot it had before the
        // wait — so a writer queued behind an invoice would be told, correctly
        // and uselessly, that there wasn't one.
        $invoice = $event->relationLoaded('finalInvoice')
            ? $event->getRelation('finalInvoice')
            : $event->finalInvoice()->first();

        return $invoice === null;
    }

    /** May this quotation's commercial state be changed right now? */
    public function isCommerciallyEditable(CateringEstimate $estimate): bool
    {
        return $estimate->isDraft() && $this->isCommerciallyOpen($estimate);
    }

    public function assertEditable(CateringEstimate $estimate): void
    {
        if (! $estimate->isDraft()) {
            throw new RuntimeException(
                'This quotation has been sent — its costing is history. Revise it to change anything.'
            );
        }

        if (! $this->isCommerciallyOpen($estimate)) {
            throw new RuntimeException(
                'This booking is closed to commercial change — it has been invoiced, completed or cancelled.'
            );
        }
    }

    /**
     * Enter the critical section for one quotation and prove it may still be
     * changed. The returned estimate is the locked one; the caller's own copy is
     * evidence of nothing.
     */
    public function editableEstimate(CateringEstimate|int $estimate): CateringEstimate
    {
        $id = $estimate instanceof CateringEstimate ? (int) $estimate->getKey() : $estimate;
        $locked = $this->estimates([$id])->get($id);

        if ($locked === null) {
            throw new RuntimeException('That quotation no longer exists.');
        }

        $this->assertEditable($locked);

        return $locked;
    }

    /**
     * The same, entered from a LINE: find its parent, lock the document, then
     * lock and re-read the line itself.
     *
     * @return array{0: CateringEstimate, 1: CateringEstimateLine}
     */
    public function editableLine(CateringEstimateLine $line): array
    {
        // Unlocked, and used only to decide what to lock.
        $estimateId = CateringEstimateLine::query()->whereKey($line->getKey())->value('catering_estimate_id');

        if ($estimateId === null) {
            throw new RuntimeException('That quotation line no longer exists.');
        }

        $estimate = $this->editableEstimate((int) $estimateId);
        $locked = $this->lines([$line->getKey()])->get($line->getKey());

        if ($locked === null || (int) $locked->catering_estimate_id !== (int) $estimate->getKey()) {
            throw new RuntimeException('That quotation line no longer belongs to this quotation.');
        }

        $locked->setRelation('estimate', $estimate);

        return [$estimate, $locked];
    }

    /**
     * And from a SNAPSHOT: line first, then document, then back down.
     *
     * @return array{0: CateringEstimate, 1: CateringEstimateLine, 2: CateringEstimateLineCostBlock}
     */
    public function editableSnapshot(CateringEstimateLineCostBlock $snapshot): array
    {
        $lineId = CateringEstimateLineCostBlock::query()
            ->whereKey($snapshot->getKey())->value('catering_estimate_line_id');

        if ($lineId === null) {
            throw new RuntimeException('That cost block no longer exists.');
        }

        $line = CateringEstimateLine::query()->whereKey($lineId)->first();
        if ($line === null) {
            throw new RuntimeException('That cost block no longer belongs to a quotation line.');
        }

        [$estimate, $lockedLine] = $this->editableLine($line);

        $locked = $this->snapshots([$snapshot->getKey()])->get($snapshot->getKey());

        if ($locked === null || (int) $locked->catering_estimate_line_id !== (int) $lockedLine->getKey()) {
            throw new RuntimeException('That cost block no longer belongs to this quotation line.');
        }

        $locked->setRelation('line', $lockedLine);

        return [$estimate, $lockedLine, $locked];
    }

    /**
     * Lock estimate lines, ascending. Below the estimate in the order, so it is
     * only ever safe to call once the document itself is held.
     *
     * @param  array<int, int>  $lineIds
     * @return Collection<int, CateringEstimateLine>
     */
    public function lines(array $lineIds): Collection
    {
        $ids = $this->clean($lineIds);
        if ($ids === []) {
            return collect();
        }

        return CateringEstimateLine::query()
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * Lock cost-block snapshots, ascending — the bottom of the order.
     *
     * @param  array<int, int>  $snapshotIds
     * @return Collection<int, CateringEstimateLineCostBlock>
     */
    public function snapshots(array $snapshotIds): Collection
    {
        $ids = $this->clean($snapshotIds);
        if ($ids === []) {
            return collect();
        }

        return CateringEstimateLineCostBlock::query()
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * Lock the whole child graph of one already-locked estimate: its lines, then
     * their snapshots, each ascending.
     *
     * Used by the form save, which reconciles an arbitrary set of lines and can
     * update or delete any of them.
     */
    public function estimateGraph(CateringEstimate $estimate): void
    {
        $lineIds = CateringEstimateLine::query()
            ->where('catering_estimate_id', $estimate->getKey())
            ->orderBy('id')->pluck('id')->all();

        if ($lineIds === []) {
            return;
        }

        $this->lines($lineIds);

        $this->snapshots(
            CateringEstimateLineCostBlock::query()
                ->whereIn('catering_estimate_line_id', $lineIds)
                ->orderBy('id')->pluck('id')->all()
        );
    }

    /**
     * A cheap structural guard for the internal, already-locked helpers.
     *
     * It cannot prove the right rows are held — only that the caller is inside a
     * transaction, which is where the locks would be. That is enough to catch
     * the mistake this is aimed at: a recalculation helper called casually from
     * a controller, outside any critical section, as a way around the front
     * door. Developer discipline alone is not a safety mechanism when the check
     * costs one function call.
     */
    public function assertInsideCriticalSection(string $operation): void
    {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException(
                "[{$operation}] may only be called inside a commercial document transaction. "
                .'Enter through the locked public operation instead.'
            );
        }
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
