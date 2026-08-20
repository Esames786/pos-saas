<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CATERING-V1-CLOSURE-1 (§5): final invoice + event closure lifecycle.
 *
 * The final invoice freezes the agreed estimate's commercial figures plus the
 * advance position into an immutable document. Settlement in V1 stays
 * OPERATIONAL: the remaining balance is received through the §4 advance flow
 * (capped at the outstanding amount), and the event can only CLOSE when the
 * live balance reaches zero — no override exists until an authorized business
 * rule says otherwise.
 *
 * FINANCE STOP (reported, not built): posting advances/final settlement to
 * the GL needs a customer-advance liability account and a catering revenue
 * mapping on the chart of accounts — a finance design that must land as
 * JournalPostingService translator methods. No journal tables are written
 * here and no homemade ledger exists.
 */
class CateringFinalInvoiceService
{
    public function __construct(
        private readonly CateringNumberService $numbers,
        private readonly CateringMailService $mail,
        private readonly \App\Services\Finance\JournalPostingService $journalPosting,
        private readonly CateringDocumentLock $locks,
    ) {}

    public function issue(CateringEvent $event, ?int $userId = null): CateringFinalInvoice
    {
        // KASHIF-CATERING-LIFECYCLE-LOCK-1: the invoice is the hardest boundary in
        // the whole booking — after it, nothing commercial may move. So it is
        // established under the same lock order Rate Impact waits on, and every
        // condition below is judged on rows re-read while holding it. Two
        // concurrent issues would otherwise both find no invoice and both write
        // one, against a unique invoice_no.
        $estimate = null;

        $invoice = DB::connection('tenant')->transaction(function () use ($event, $userId, &$estimate) {
            // Taken INSIDE the transaction, because a lock held outside one is
            // released the instant the statement ends and protects nothing.
            $this->locks->refreshEvent($event);

            $estimate = $event->currentEstimate;
            if (! $estimate || $estimate->isDraft()) {
                throw new RuntimeException('A final invoice needs a sent/accepted estimate — drafts cannot be invoiced.');
            }
            if ($event->finalInvoice()->exists()) {
                throw new RuntimeException("Event {$event->event_no} already has a final invoice.");
            }
            if (! in_array($event->status, [
                CateringEvent::STATUS_CONFIRMED,
                CateringEvent::STATUS_PRODUCTION_READY,
                CateringEvent::STATUS_RELEASED,
            ], true)) {
                throw new RuntimeException("Event {$event->event_no} ({$event->status}) cannot be invoiced — confirm the booking first.");
            }

            $advances = $event->advances()->orderBy('received_date')->get();
            $advanceTotal = round((float) $advances->sum('amount') - (float) $event->refunds()->sum('amount'), 2);
            $balanceDue = round((float) $estimate->grand_total - $advanceTotal, 2);

            // KASHIF-CATERING-CUSTOMER-CREDIT-1: an invoice can only absorb its
            // own value. Clearing the whole advance would take money out of the
            // customer-advance liability that this invoice does not account for,
            // and the business would stop being able to see that it owes it.
            $advanceApplied = round(min($advanceTotal, (float) $estimate->grand_total), 2);

            $invoice = CateringFinalInvoice::create([
                'invoice_no' => $this->numbers->nextFinalInvoiceNo(),
                'catering_event_id' => $event->id,
                'catering_estimate_id' => $estimate->id,
                'snapshot' => [
                    'event_no' => $event->event_no,
                    'estimate_version' => $estimate->version_no,
                    'customer_name' => $event->customer_name,
                    'customer_name_ur' => $event->customer_name_ur,
                    'customer_phone' => $event->customer_phone,
                    'customer_address' => $event->customer_address,
                    'event_type' => $event->event_type,
                    'event_date' => $event->event_date->toDateString(),
                    'service_time' => $event->service_time,
                    'venue' => $event->venue,
                    'pax' => $event->pax,
                    'lines' => $estimate->lines->map(fn ($line) => [
                        'item_name' => $line->item_name,
                        'item_name_ur' => $line->item_name_ur,
                        'quantity' => (float) $line->quantity,
                        'unit_code' => $line->unit_code,
                        'rate' => (float) $line->rate,
                        'amount' => (float) $line->amount,
                        'instructions' => $line->instructions,
                    ])->values()->all(),
                    'advances' => $advances->map(fn ($advance) => [
                        'received_date' => $advance->received_date->toDateString(),
                        'amount' => (float) $advance->amount,
                        'reference' => $advance->reference,
                    ])->values()->all(),
                ],
                'subtotal' => $estimate->subtotal,
                'service_charge_amount' => $estimate->service_charge_amount,
                'other_charge_label' => $estimate->other_charge_label,
                'other_charge_amount' => $estimate->other_charge_amount,
                'discount_amount' => $estimate->discount_amount,
                'tax_amount' => $estimate->tax_amount,
                'grand_total' => $estimate->grand_total,
                'advance_total' => $advanceTotal,
                'advance_applied' => $advanceApplied,
                'balance_due' => max($balanceDue, 0),
                'status' => CateringFinalInvoice::STATUS_ISSUED,
                'issued_at' => now(),
                'issued_by_user_id' => $userId,
            ]);

            // CATERING-GO-LIVE-READINESS-1 (§6): accounting posts INSIDE the issue
            // transaction — an invoice exists iff its GL exists. Translators throw
            // on failure/conflict, rolling the whole issue back.
            $invoice->setRelation('event', $event);
            $invoiceEntry = $this->journalPosting->postCateringFinalInvoice($invoice, $userId);
            $applicationEntry = $this->journalPosting->applyCateringAdvance($invoice, $userId);

            $invoice->forceFill([
                'journal_entry_id' => $invoiceEntry->id,
                'advance_application_journal_entry_id' => $applicationEntry?->id,
                'gl_posted_at' => now(),
            ])->save();

            // Event day is billed → the event is operationally complete. Inside
            // the transaction with the invoice: an invoice that exists on an
            // event still open for commercial change is precisely the state this
            // whole lock order exists to make unreachable.
            $event->forceFill(['status' => CateringEvent::STATUS_COMPLETED])->save();

            return $invoice;
        });

        $this->mail->send(
            \App\Mail\Catering\CateringCustomerMail::TYPE_FINAL_INVOICE,
            $event->refresh(),
            $estimate,
            ['advance_total' => (float) $invoice->advance_total, 'invoice_no' => $invoice->invoice_no],
            'invoice-'.$invoice->id,
        );

        return $invoice;
    }

    /**
     * Close the event. HARD RULE: an unresolved final balance blocks closure —
     * the remaining amount must first be received via the §4 advance flow.
     */
    public function close(CateringEvent $event): CateringEvent
    {
        return DB::connection('tenant')->transaction(fn () => $this->closeLocked($event));
    }

    private function closeLocked(CateringEvent $event): CateringEvent
    {
        $this->locks->refreshEvent($event);

        $invoice = $event->finalInvoice()->first();
        if (! $invoice) {
            throw new RuntimeException("Event {$event->event_no} has no final invoice — issue it before closing.");
        }
        if ($event->status !== CateringEvent::STATUS_COMPLETED) {
            throw new RuntimeException("Event {$event->event_no} ({$event->status}) is not in a closable state.");
        }

        $position = app(CateringFinancialPositionService::class)->position($event);

        if ($position['balance_due'] > 0) {
            throw new RuntimeException(
                "Event {$event->event_no} still has an outstanding balance of "
                .number_format((float) $position['balance_due'], 2)
                .' — record the final payment before closing.'
            );
        }

        // KASHIF-CATERING-CUSTOMER-CREDIT-1: a debt in the other direction blocks
        // closure just as firmly. Closing here would stamp "settled" on a booking
        // that is still holding the customer's money, and the liability would go
        // quiet behind a closed record.
        if ($position['customer_credit'] > 0) {
            throw new RuntimeException(
                "Event {$event->event_no} still owes the customer "
                .number_format((float) $position['customer_credit'], 2)
                .' — refund the credit before closing.'
            );
        }

        $event->forceFill(['status' => CateringEvent::STATUS_CLOSED, 'closed_at' => now()])->save();

        return $event;
    }
}
