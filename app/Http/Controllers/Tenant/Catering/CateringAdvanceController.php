<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Mail\Catering\CateringCustomerMail;
use App\Models\Tenant\CateringAdvance;
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

        $advance = CateringAdvance::create($data + [
            'catering_event_id' => $cateringEvent->id,
            'recorded_by_user_id' => $request->user()?->id,
        ]);

        $advanceTotal = (float) $cateringEvent->advances()->sum('amount');

        app(CateringMailService::class)->send(
            CateringCustomerMail::TYPE_ADVANCE_RECEIVED,
            $cateringEvent,
            $cateringEvent->currentEstimate,
            ['advance_amount' => (float) $advance->amount, 'advance_total' => $advanceTotal],
            'advance-'.$advance->id,
        );

        return back()->with('status', 'Advance of '.number_format((float) $advance->amount, 2).' recorded (no accounting entry in V1).');
    }
}
