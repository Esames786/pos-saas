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

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'received_date' => ['required', 'date'],
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

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
