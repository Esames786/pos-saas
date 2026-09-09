<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Mail\Catering\CateringCustomerMail;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringMailService;
use Illuminate\Http\Request;

/**
 * CATERING-SLICE-3: record an advance against an event.
 * V1 HARD RULE (spec §19): operational record only — zero GL, zero cash-bank,
 * zero shift mutation. Finance posting is a future flow through
 * JournalPostingService.
 */
class CateringAdvanceController extends Controller
{
    public function store(Request $request, CateringEvent $cateringEvent)
    {
        if ($cateringEvent->isCancelled()) {
            return back()->withErrors(['advance' => 'Cancelled events cannot receive advances.']);
        }

        // CATERING-OVERPAYMENT-1 §4b: a NEGATIVE amount on this one box hands
        // money back. `not_in:0` rather than a minimum, because zero is the only
        // figure that means nothing at all.
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0'],
            'received_date' => ['required', 'date'],
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
            'allow_overpayment' => ['nullable', 'boolean'],
            'overpayment_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $amount = round((float) $data['amount'], 2);

        // Any departure from "a plain payment of what is owed" has to say why:
        // taking money the business has not billed for, or giving money back.
        if (($amount < 0 || $request->boolean('allow_overpayment')) && trim((string) ($data['overpayment_reason'] ?? '')) === '') {
            return back()->withErrors([
                'advance' => $amount < 0
                    ? 'Returning money needs a reason recorded against it.'
                    : 'Taking more than the amount due needs a reason recorded against it.',
            ])->withInput();
        }

        // §4b — money out is a REFUND, not a negative receipt. A negative
        // CateringAdvance would break the one authority every screen reads:
        // position() SUMS advances, so a minus row would quietly redefine
        // "received", and it would slip past the refundable cap that stops us
        // handing back money which is covering a bill.
        if ($amount < 0) {
            try {
                $refund = app(\App\Services\Catering\CateringRefundService::class)->record($cateringEvent, [
                    'amount' => abs($amount),
                    'refund_date' => $data['received_date'],
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'reason' => $data['overpayment_reason'],
                ], $request->user()?->id);
            } catch (\RuntimeException $e) {
                return back()->withErrors(['advance' => $e->getMessage()])->withInput();
            }

            return back()->with('status', 'Refund of '.number_format((float) $refund->amount, 2)
                .' recorded ('.$refund->refund_no.') — taken out of the credit held for this booking.');
        }

        // The authority to create a liability is not the authority to record a
        // payment. A crafted post cannot borrow it: the flag is dropped here for
        // anyone who has not been granted it.
        $data['allow_overpayment'] = $request->boolean('allow_overpayment')
            && $request->user()?->can('tenant.catering.advances.overpay');

        try {
            // GO-LIVE §5: ONE atomic operation — operational advance + cash/bank
            // movement + GL posting (deposit before invoice, settlement after).
            $advance = app(\App\Services\Catering\CateringAdvanceService::class)
                ->record($cateringEvent, $data, $request->user()?->id);
        } catch (\RuntimeException $e) {
            // §4 overpayment / §5 posting refusal — nothing was recorded.
            return back()->withErrors(['advance' => $e->getMessage()])->withInput();
        }

        $advanceTotal = (float) $cateringEvent->advances()->sum('amount');

        app(CateringMailService::class)->send(
            CateringCustomerMail::TYPE_ADVANCE_RECEIVED,
            $cateringEvent,
            $cateringEvent->currentEstimate,
            ['advance_amount' => (float) $advance->amount, 'advance_total' => $advanceTotal],
            'advance-'.$advance->id,
        );

        // This message used to deny that any accounting entry was made, while
        // this very action posts a journal entry AND moves the cash/bank
        // balance. The screen text was corrected earlier; the flash message was
        // missed, so the operator was told the opposite of what had happened.
        return back()->with('status', 'Advance of '.number_format((float) $advance->amount, 2)
            .' recorded — posted to the general ledger and added to the cash/bank balance.');
    }
}
