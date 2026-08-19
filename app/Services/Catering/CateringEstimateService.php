<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerTranslation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CATERING-SLICE-1: event + estimate lifecycle authority.
 *
 * HARD SAFETY RULE (spec §19/§20): nothing in this service touches stock,
 * GL, shifts, payments, sales_orders, or KOT — estimates are pure documents.
 * Versioning contract (spec §9): a sent estimate is immutable; repricing
 * clones it into version N+1 and marks the old version superseded.
 */
class CateringEstimateService
{
    public function __construct(
        private readonly CateringNumberService $numbers,
        // KASHIF-CATERING-COSTING-SOURCE-1: the per-line dispatcher, not the
        // recipe engine directly. An estimate may mix costing sources, and each
        // line must be judged by its own.
        private readonly CateringEstimateCostingService $costing,
        private readonly CateringLineCostBlockService $lineBlocks,
    ) {}

    /**
     * CATERING-V1-CLOSURE-1 (§2): SEND/CONFIRM fail closed on an incomplete cost.
     * Throws with the full blocker list so the operator knows exactly what to fix.
     */
    private function assertCostingReady(CateringEstimate $estimate, string $action): void
    {
        $readiness = $this->costing->readiness($estimate);
        if (! $readiness['ready']) {
            throw new RuntimeException(
                "Cannot {$action} {$estimate->displayNo()} — the cost basis is incomplete: "
                .implode(' | ', $readiness['blockers'])
            );
        }
    }

    /** Create an event with an empty draft estimate (v1). */
    public function createEvent(array $eventData, ?int $userId = null): CateringEvent
    {
        return DB::connection('tenant')->transaction(function () use ($eventData, $userId) {
            $event = CateringEvent::create(array_merge($eventData, [
                'event_no' => $this->numbers->nextEventNo(),
                'status' => CateringEvent::STATUS_INQUIRY,
                'created_by_user_id' => $userId,
            ]));

            CateringEstimate::create([
                'catering_event_id' => $event->id,
                'version_no' => 1,
                'status' => CateringEstimate::STATUS_DRAFT,
                'created_by_user_id' => $userId,
            ]);

            $this->rememberCustomerUrduName($event);

            return $event;
        });
    }

    public function updateEvent(CateringEvent $event, array $eventData): CateringEvent
    {
        if (! $event->isOpen()) {
            throw new RuntimeException("Event {$event->event_no} is {$event->status} and can no longer be edited.");
        }

        $event->update($eventData);
        $this->rememberCustomerUrduName($event);

        return $event;
    }

    /**
     * Replace the draft estimate's lines and recompute totals.
     * $lines: [{product_id?, item_name, item_name_ur?, quantity, unit_id?, unit_code?, rate, instructions?}, ...]
     */
    public function saveDraftLines(CateringEstimate $estimate, array $lines, array $charges = []): CateringEstimate
    {
        $this->assertDraft($estimate);

        return DB::connection('tenant')->transaction(function () use ($estimate, $lines, $charges) {
            // KASHIF-CATERING-LINE-SNAPSHOT-1: RECONCILE, never wipe and rebuild.
            //
            // This used to delete every line and recreate it. That was harmless
            // when a line was three numbers; it is destructive now that a line
            // carries decisions somebody made deliberately — a material quantity
            // this event needs, an agreed rate and the reason for it. Saving the
            // form after changing a venue would have thrown all of that away
            // without a word.
            $existing = $estimate->lines()->get()->keyBy('line_uuid');
            $keptIds = [];

            foreach (array_values($lines) as $index => $line) {
                $quantity = round((float) ($line['quantity'] ?? 0), 3);
                $rate = round((float) ($line['rate'] ?? 0), 2);
                $productId = $line['product_id'] ?? null;

                $uuid = $line['line_uuid'] ?? null;
                $match = $uuid ? $existing->get($uuid) : null;

                // A row whose product changed is a different dish. Its old
                // costing explains nothing about the new one, so it starts again
                // — keeping a Chicken Biryani breakdown under Beef Biryani would
                // be worse than having none.
                $productChanged = $match && (int) $match->product_id !== (int) $productId;

                $attributes = [
                    'catering_estimate_id' => $estimate->id,
                    'product_id' => $productId,
                    'item_name' => $line['item_name'],
                    'item_name_ur' => $line['item_name_ur'] ?? null,
                    'quantity' => $quantity,
                    'unit_id' => $line['unit_id'] ?? null,
                    'unit_code' => $line['unit_code'] ?? null,
                    'instructions' => $line['instructions'] ?? null,
                    'sort_order' => $index,
                ];

                if ($match && ! $productChanged) {
                    $hasSnapshot = $match->costBlocks()->exists();

                    // A block-costed line prices itself; the form's rate box is
                    // not the authority for it, and honouring it here would undo
                    // an agreed rate every time the form was saved.
                    if (! $hasSnapshot) {
                        $attributes['rate'] = $rate;
                        $attributes['amount'] = round($quantity * $rate, 2);
                    }

                    $match->fill($attributes)->save();
                    $keptIds[] = $match->id;

                    if ($hasSnapshot) {
                        // Quantities that follow the order follow it; a quantity
                        // somebody typed for this event stays where they put it.
                        $this->lineBlocks->recalculateForQuantity($match->refresh());
                    }

                    continue;
                }

                if ($productChanged) {
                    $match->costBlocks()->delete();
                    $match->fill($attributes + [
                        'rate' => $rate,
                        'amount' => round($quantity * $rate, 2),
                        'calculated_rate' => null,
                        'rate_override_reason' => null,
                        'lump_sum_amount' => 0,
                    ])->save();

                    $keptIds[] = $match->id;
                    $this->lineBlocks->snapshot($match->refresh());

                    continue;
                }

                $created = CateringEstimateLine::create($attributes + [
                    'rate' => $rate,
                    'amount' => round($quantity * $rate, 2),
                ]);
                $keptIds[] = $created->id;

                // A cost-block dish copies its blocks onto the line, and the
                // line's amount becomes whatever that copy works out to.
                $this->lineBlocks->snapshot($created);
            }

            // Whatever the operator removed goes, and its snapshot with it.
            $estimate->lines()->whereNotIn('id', $keptIds ?: [0])->delete();

            // Refresh before the final write: repricing each line already wrote
            // totals to the row, so this instance's idea of them is stale. Fill
            // without it and Eloquent sees nothing dirty, skips the update, and
            // leaves whatever the last mid-loop reprice happened to compute.
            $estimate->refresh();

            $subtotal = round((float) $estimate->lines()->sum('amount'), 2);
            $estimate->fill($this->totals($subtotal, $charges))->save();

            $event = $estimate->event;
            if ($event->status === CateringEvent::STATUS_INQUIRY) {
                $event->update(['status' => CateringEvent::STATUS_DRAFT]);
            }

            return $estimate->refresh();
        });
    }

    /** Mark the estimate sent — from this moment it is commercially immutable. */
    public function markSent(CateringEstimate $estimate): CateringEstimate
    {
        $this->assertDraft($estimate);
        if ($estimate->lines()->count() === 0) {
            throw new RuntimeException('An estimate needs at least one line before it can be sent.');
        }

        $this->assertCostingReady($estimate, 'send');

        $estimate->forceFill(['status' => CateringEstimate::STATUS_SENT, 'sent_at' => now()])->save();

        $event = $estimate->event;
        if (in_array($event->status, [CateringEvent::STATUS_INQUIRY, CateringEvent::STATUS_DRAFT], true)) {
            $event->update(['status' => CateringEvent::STATUS_QUOTED]);
        }

        return $estimate;
    }

    public function markAccepted(CateringEstimate $estimate): CateringEstimate
    {
        if ($estimate->status !== CateringEstimate::STATUS_SENT) {
            throw new RuntimeException('Only a sent estimate can be accepted.');
        }

        $estimate->forceFill(['status' => CateringEstimate::STATUS_ACCEPTED, 'accepted_at' => now()])->save();

        return $estimate;
    }

    /** Confirm the booking (event level). */
    public function confirmEvent(CateringEvent $event): CateringEvent
    {
        if (! in_array($event->status, [CateringEvent::STATUS_QUOTED, CateringEvent::STATUS_DRAFT], true)) {
            throw new RuntimeException("Event {$event->event_no} cannot be confirmed from status {$event->status}.");
        }

        if ($current = $event->currentEstimate) {
            $this->assertCostingReady($current, 'confirm');
        }

        $event->forceFill(['status' => CateringEvent::STATUS_CONFIRMED, 'confirmed_at' => now()])->save();

        return $event;
    }

    /**
     * KASHIF-CATERING-PRODUCT-UX-1 (item 9) — cancel a booking, on the record.
     *
     * A reason is required. A cancelled booking that carried a received advance
     * used to leave no trace of what was agreed, which is exactly the case
     * someone will need to reconstruct months later.
     *
     * What this deliberately does NOT do: touch the advance. Money that was
     * received was really received, and its journal entry and cash/bank
     * movement are history. Deleting the row, reversing the entry, or writing a
     * refund here would be rewriting the books from a cancel button. If the
     * customer is owed money back, that is a separate, deliberate financial
     * action — the UI says so, and the outstanding amount stays visible.
     *
     * Idempotent: cancelling an already-cancelled event returns it untouched
     * rather than overwriting the original reason or timestamp.
     */
    public function cancelEvent(CateringEvent $event, string $reason, ?int $userId = null): CateringEvent
    {
        if (in_array($event->status, [CateringEvent::STATUS_COMPLETED, CateringEvent::STATUS_CLOSED], true)) {
            throw new RuntimeException("Event {$event->event_no} is {$event->status} and cannot be cancelled.");
        }

        if ($event->isCancelled()) {
            return $event;
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A cancellation reason is required.');
        }

        $event->forceFill([
            'status' => CateringEvent::STATUS_CANCELLED,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $userId,
        ])->save();

        return $event;
    }

    /**
     * Create revision v(N+1) from an immutable estimate, cloning its lines as a
     * new DRAFT. The source version is marked superseded — never rewritten.
     */
    public function revise(CateringEstimate $estimate, ?int $userId = null): CateringEstimate
    {
        if ($estimate->isDraft()) {
            throw new RuntimeException('The estimate is still a draft — edit it directly instead of revising.');
        }
        if ($estimate->status === CateringEstimate::STATUS_SUPERSEDED) {
            throw new RuntimeException('This version is already superseded; revise the current version.');
        }

        return DB::connection('tenant')->transaction(function () use ($estimate, $userId) {
            $nextVersion = (int) CateringEstimate::query()
                ->where('catering_event_id', $estimate->catering_event_id)
                ->lockForUpdate()
                ->max('version_no') + 1;

            $revision = CateringEstimate::create([
                'catering_event_id' => $estimate->catering_event_id,
                'version_no' => $nextVersion,
                'status' => CateringEstimate::STATUS_DRAFT,
                'subtotal' => $estimate->subtotal,
                'service_charge_amount' => $estimate->service_charge_amount,
                'other_charge_label' => $estimate->other_charge_label,
                'other_charge_amount' => $estimate->other_charge_amount,
                'discount_type' => $estimate->discount_type,
                'discount_value' => $estimate->discount_value,
                'discount_amount' => $estimate->discount_amount,
                'tax_amount' => $estimate->tax_amount,
                'grand_total' => $estimate->grand_total,
                'terms' => $estimate->terms,
                'notes' => $estimate->notes,
                'created_by_user_id' => $userId,
            ]);

            foreach ($estimate->lines as $line) {
                $copy = CateringEstimateLine::create([
                    'catering_estimate_id' => $revision->id,
                    'product_id' => $line->product_id,
                    'item_name' => $line->item_name,
                    'item_name_ur' => $line->item_name_ur,
                    'quantity' => $line->quantity,
                    'unit_id' => $line->unit_id,
                    'unit_code' => $line->unit_code,
                    'rate' => $line->rate,
                    // KASHIF-CATERING-LINE-SNAPSHOT-1: a revision is a copy of a
                    // quotation, so it has to carry HOW that quotation was
                    // priced. Without these the new version arrives with no
                    // breakdown, no agreed rate and no reason for it — the
                    // operator would have to reconstruct decisions from memory.
                    'calculated_rate' => $line->calculated_rate,
                    'rate_override_reason' => $line->rate_override_reason,
                    'amount' => $line->amount,
                    'lump_sum_amount' => $line->lump_sum_amount,
                    'instructions' => $line->instructions,
                    'estimated_unit_cost' => $line->estimated_unit_cost,
                    'estimated_cost_total' => $line->estimated_cost_total,
                    'sort_order' => $line->sort_order,
                ]);

                // And the breakdown itself, block by block — including the
                // quantity this event settled on and who was bringing it.
                foreach ($line->costBlocks as $block) {
                    $clone = $block->replicate(['catering_estimate_line_id']);
                    $clone->catering_estimate_line_id = $copy->id;
                    $clone->save();
                }
            }

            $estimate->forceFill([
                'status' => CateringEstimate::STATUS_SUPERSEDED,
                'superseded_at' => now(),
            ])->save();

            return $revision;
        });
    }

    /** Totals block from a computed subtotal + submitted charge inputs. */
    /**
     * KASHIF-CATERING-LINE-SNAPSHOT-1 — re-add the quotation from its lines.
     *
     * Anything that changes one line's amount must change the document, or the
     * screen shows a line at 1,960 inside a quotation that still says 1,910 —
     * and whichever the customer is shown, one of them is a lie.
     *
     * The document's own charges (service, other, tax, discount) are re-applied
     * exactly as stored, through the same totals() every other path uses. There
     * is one totals formula and this is not a second one.
     */
    public function recalculateTotals(CateringEstimate $estimate): CateringEstimate
    {
        $subtotal = round((float) $estimate->lines()->sum('amount'), 2);

        $estimate->fill($this->totals($subtotal, [
            'service_charge_amount' => $estimate->service_charge_amount,
            'other_charge_label' => $estimate->other_charge_label,
            'other_charge_amount' => $estimate->other_charge_amount,
            'discount_type' => $estimate->discount_type,
            'discount_value' => $estimate->discount_value,
            'tax_amount' => $estimate->tax_amount,
        ]))->save();

        return $estimate->refresh();
    }

    private function totals(float $subtotal, array $charges): array
    {
        $serviceCharge = round((float) ($charges['service_charge_amount'] ?? 0), 2);
        $otherCharge = round((float) ($charges['other_charge_amount'] ?? 0), 2);
        $taxAmount = round((float) ($charges['tax_amount'] ?? 0), 2);
        $discountType = $charges['discount_type'] ?? 'none';
        $discountValue = round((float) ($charges['discount_value'] ?? 0), 2);

        $discountAmount = match ($discountType) {
            'fixed' => min($discountValue, $subtotal),
            'percent' => round($subtotal * min($discountValue, 100) / 100, 2),
            default => 0.0,
        };

        $grand = round($subtotal + $serviceCharge + $otherCharge + $taxAmount - $discountAmount, 2);

        return [
            'subtotal' => round($subtotal, 2),
            'service_charge_amount' => $serviceCharge,
            'other_charge_label' => $charges['other_charge_label'] ?? null,
            'other_charge_amount' => $otherCharge,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'grand_total' => max($grand, 0),
        ];
    }

    private function assertDraft(CateringEstimate $estimate): void
    {
        if (! $estimate->isDraft()) {
            throw new RuntimeException(
                "Estimate {$estimate->displayNo()} is {$estimate->status} and immutable. Create a revision instead."
            );
        }
    }

    /**
     * The Urdu customer name typed on the event is remembered on the customer's
     * translation row (optional, spec §4) so future documents reuse it. Base
     * customers table is never modified here.
     */
    private function rememberCustomerUrduName(CateringEvent $event): void
    {
        if (! $event->customer_id || empty($event->customer_name_ur)) {
            return;
        }

        if (Customer::query()->whereKey($event->customer_id)->exists()) {
            CustomerTranslation::query()->updateOrCreate(
                ['customer_id' => $event->customer_id, 'language_code' => 'ur'],
                ['name' => trim($event->customer_name_ur)]
            );
        }
    }
}
