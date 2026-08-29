<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringRefundService;
use Illuminate\Http\Request;

/**
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 — pay a customer back what they are owed.
 *
 * Deliberately its own action behind its own permission, because this is the one
 * catering screen that takes money OUT. A reason is required: money leaving the
 * business without a stated reason is not something the record should be able to
 * contain.
 *
 * The route sits inside the duplicate-submission guard and the form carries a
 * one-time token, so a double-clicked or resubmitted refund lands once. Genuine
 * concurrency is handled a layer down, by the booking lock in the service.
 */
class CateringRefundController extends Controller
{
    public function store(Request $request, CateringEvent $cateringEvent)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'refund_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            // Required, unlike on a receipt. Cash can honestly turn up without a
            // named account; money leaving has to leave from somewhere. The
            // service proves the method is active and actually linked to a live
            // cash/bank account — this only stops the empty case early.
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $refund = app(CateringRefundService::class)
                ->record($cateringEvent, $data, $request->user()?->id);
        } catch (\RuntimeException $e) {
            // Over the refundable amount, or a posting refusal. Either way
            // nothing was recorded and no money moved.
            return back()->withErrors(['refund' => $e->getMessage()])->withInput();
        }

        // The tenant routes carry a {subdomain} parameter, so route() would bind
        // the event to the subdomain and throw after the money had already
        // moved. A path keeps the redirect out of that.
        return redirect()->to('/catering/events/'.$cateringEvent->id)
            ->with('status', 'Refund '.$refund->refund_no.' of '.number_format((float) $refund->amount, 2)
                .' recorded — posted to the general ledger and taken off the cash/bank balance.');
    }
}
