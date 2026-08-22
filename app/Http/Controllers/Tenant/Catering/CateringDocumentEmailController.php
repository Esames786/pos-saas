<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Mail\Catering\CateringCustomerMail;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringFinalInvoice;
use App\Services\Catering\CateringMailService;
use Illuminate\Http\Request;

/**
 * KASHIF-CATERING-EMAIL-1 — manual Email to Customer / Resend.
 *
 * The automatic emails (on finalize, on advance, on invoice) already exist and
 * keep working. What the operator lacked was a BUTTON: the customer says "it
 * never arrived", or gives a corrected address, and the operator needs to send
 * the document again on purpose.
 *
 * Every action here goes through the ONE CateringMailService/email-log
 * authority — no second email subsystem, no SMTP changes. A manual send uses a
 * fresh dedupe key per attempt, because a deliberate resend IS another
 * delivery attempt and must not be swallowed by the idempotency claim that
 * (rightly) stops the automatic sends from double-firing. Each attempt leaves
 * its own log row.
 *
 * Emailing mutates NO business state: no stock, no GL, no repricing, no
 * lifecycle transition, no invoice change. And a DRAFT quotation is refused
 * explicitly — the formal quotation a customer receives is a finalized one,
 * and emailing must never finalize as a side effect.
 */
class CateringDocumentEmailController extends Controller
{
    public function emailEstimate(Request $request, CateringEstimate $cateringEstimate)
    {
        $event = $cateringEstimate->event;

        if ($cateringEstimate->isDraft()) {
            return back()->withErrors([
                'email' => 'This quotation is still a draft. Finalize it first — the customer '
                    .'receives the frozen document, and emailing must never finalize it for you.',
            ]);
        }

        if (empty($event->customer_email)) {
            return back()->withErrors([
                'email' => 'No customer email on file for this booking. Add one on Edit Event, then resend.',
            ]);
        }

        $type = $cateringEstimate->version_no > 1
            ? CateringCustomerMail::TYPE_QUOTATION_REVISED
            : CateringCustomerMail::TYPE_QUOTATION_SENT;

        // A fresh key per attempt: a manual resend is deliberately ANOTHER
        // delivery, with its own log row — not a replay the claim should skip.
        $result = app(CateringMailService::class)->send(
            $type,
            $event,
            $cateringEstimate,
            [],
            'manual-q'.$cateringEstimate->version_no.'-'.now()->format('YmdHis').'-'.substr(uniqid(), -5),
        );

        return back()->with('status', match ($result) {
            'sent' => "Quotation Q{$cateringEstimate->version_no} emailed to {$event->customer_email}.",
            'failed' => 'The email could not be sent — the attempt was logged. Check the address and try again.',
            default => 'Nothing was emailed.',
        });
    }

    public function emailFinalInvoice(Request $request, CateringFinalInvoice $cateringFinalInvoice)
    {
        $event = $cateringFinalInvoice->event;

        if (empty($event->customer_email)) {
            return back()->withErrors([
                'email' => 'No customer email on file for this booking. Add one on Edit Event, then resend.',
            ]);
        }

        $result = app(CateringMailService::class)->send(
            CateringCustomerMail::TYPE_FINAL_INVOICE,
            $event,
            $event->currentEstimate,
            [
                'advance_total' => (float) $cateringFinalInvoice->advance_total,
                'invoice_no' => $cateringFinalInvoice->invoice_no,
            ],
            'manual-inv'.$cateringFinalInvoice->id.'-'.now()->format('YmdHis').'-'.substr(uniqid(), -5),
        );

        return back()->with('status', match ($result) {
            'sent' => "Invoice {$cateringFinalInvoice->invoice_no} emailed to {$event->customer_email}.",
            'failed' => 'The email could not be sent — the attempt was logged. Check the address and try again.',
            default => 'Nothing was emailed.',
        });
    }
}
