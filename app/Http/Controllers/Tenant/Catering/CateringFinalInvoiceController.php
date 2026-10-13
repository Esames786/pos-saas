<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringFinalInvoiceService;
use Illuminate\Http\Request;
use RuntimeException;

/** CATERING-V1-CLOSURE-1 (§5): issue the final invoice / close the event. */
class CateringFinalInvoiceController extends Controller
{
    public function __construct(private readonly CateringFinalInvoiceService $invoices) {}

    public function store(Request $request, CateringEvent $cateringEvent)
    {
        try {
            $invoice = $this->invoices->issue($cateringEvent, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('status', "Final invoice {$invoice->invoice_no} issued — balance due ".number_format((float) $invoice->balance_due, 2).'.');
    }

    public function close(CateringEvent $cateringEvent)
    {
        try {
            $this->invoices->close($cateringEvent);
        } catch (RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('status', "Event {$cateringEvent->event_no} closed — fully settled.");
    }

    /**
     * Poori booking khaton se nikal dena — test entry ya darj karne ki ghalti.
     *
     * CATERING-VOID-BUTTON-1 (13 Oct). Malik: "ye wala order cancel kardo, ye
     * test entry thi — aur jo bhi paisa khate mein hai usay void kardo."
     *
     * Ye 11 Oct ko server se haath se chalaya gaya tha. Malik ne poochha "ye
     * system mein kaise karsakte hain?" — jawab tha: nahi kar sakte. Ab kar
     * sakte hain.
     *
     * ── TASDEEQ BOOKING KE NUMBER SE ──────────────────────────────────────
     *
     * Sirf "OK" kaafi nahi. Operator ko booking ka number KHUD likhna parta
     * hai, aur wo yahan milaya jata hai. Wajah: ek ghalat click us screen par
     * ho sakta hai jahan dozens bookings ek jaisi dikhti hain, aur is kaam ka
     * anjaam wapas nahi hota — wo asli graahak ka bill aur us ki adaygiyan
     * khaton se nikal deta hai. Number likhna aadmi ko ek lamhe ke liye rukne
     * par majboor karta hai.
     */
    public function voidEverything(Request $request, CateringEvent $cateringEvent)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'confirm_event_no' => ['required', 'string'],
        ], [], [
            'reason' => 'wajah',
            'confirm_event_no' => 'booking ka number',
        ]);

        if (trim($data['confirm_event_no']) !== $cateringEvent->event_no) {
            return back()->withErrors([
                'void' => 'Booking ka number mel nahi khaya. Tasdeeq ke liye '
                    .$cateringEvent->event_no.' likhna hoga — kuch bhi nahi badla.',
            ]);
        }

        try {
            $kiya = app(\App\Services\Catering\CateringFinalInvoiceService::class)
                ->voidEntireBooking($cateringEvent, $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['void' => $e->getMessage()]);
        }

        $kehna = 'Booking '.$cateringEvent->event_no.' cancel kar di gayi';
        if ($kiya['invoice']) { $kehna .= ', bill '.$kiya['invoice'].' void'; }
        if ($kiya['receipts']) { $kehna .= ', '.count($kiya['receipts']).' receipt void'; }

        return redirect()->to('/catering/events/'.$cateringEvent->id)->with('status', $kehna.'.');
    }
}
