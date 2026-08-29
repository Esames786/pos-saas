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
}
