<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringFinalInvoice;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\CateringSetting;
use App\Models\Tenant\Printer;
use App\Services\Catering\CateringDocumentPrintService;
use App\Services\Catering\CateringFinancialPositionService;
use Illuminate\Http\Request;

/**
 * CATERING-SLICE-3: printable A4 documents (spec §17/§18) rendered as
 * standalone Blade pages + window.print() — the platform's A4 architecture.
 * lang=en|ur|both controls language profile; Urdu renders lang="ur" dir="rtl"
 * with a Unicode Urdu font stack. Thermal output is NOT claimed here.
 */
class CateringDocumentController extends Controller
{
    /** Customer-facing A4 estimate. */
    public function estimate(Request $request, CateringEstimate $cateringEstimate)
    {
        $cateringEstimate->load(['event.customer', 'lines']);
        $lang = $this->language($request);

        // CAT-DOC-001 — one authority for what the customer owes.
        //
        // This printed a balance computed from GROSS advances, so a booking that
        // had been partly refunded printed as though the business still held all
        // of the money. The customer's copy understated what was due, and the
        // screen beside it — which has always subtracted refunds — disagreed with
        // the paper the customer was handed.
        //
        // The document now asks the same service every other finance surface
        // asks. There is one settlement formula and this is not a second one.
        $position = app(CateringFinancialPositionService::class)->position($cateringEstimate->event);

        return view('tenant.catering.documents.estimate', [
            'estimate' => $cateringEstimate,
            'event' => $cateringEstimate->event,
            'lang' => $lang,
            'position' => $position,
            // Kept for anything still reading it, but it is now the NET figure —
            // what the business actually holds.
            'advanceTotal' => $position['net_received'],
            'businessName' => $this->businessName(),
        ]);
    }

    /** Kitchen/service sheet from a production release — NO commercial prices. */
    public function kitchenSheet(Request $request, CateringProductionRelease $cateringProductionRelease)
    {
        $cateringProductionRelease->load(['lines', 'event']);
        $lang = $this->language($request);

        return view('tenant.catering.documents.kitchen-sheet', [
            'release' => $cateringProductionRelease,
            'lang' => $lang,
            'businessName' => $this->businessName(),
        ]);
    }

    /** CATERING-V1-CLOSURE-1 (§5): A4 final invoice from the immutable snapshot. */
    public function finalInvoice(Request $request, \App\Models\Tenant\CateringFinalInvoice $cateringFinalInvoice)
    {
        $cateringFinalInvoice->load('event');
        $lang = $this->language($request);

        return view('tenant.catering.documents.final-invoice', [
            'invoice' => $cateringFinalInvoice,
            'lang' => $lang,
            'businessName' => $this->businessName(),
        ]);
    }

    /**
     * KASHIF-CATERING-PRODUCT-UX-1 (item 7) — queue a quotation to a printer.
     *
     * Creates one print_jobs row and nothing else. No journal entry, no stock
     * movement, no change to the estimate itself: printing a document is not an
     * accounting event, and a second press returns the job that already exists
     * rather than queueing a second sheet.
     */
    public function printEstimate(Request $request, CateringEstimate $cateringEstimate)
    {
        return $this->queueDocument($request, fn (Printer $printer, string $lang, bool $reprint) => app(CateringDocumentPrintService::class)
            ->queueEstimate($cateringEstimate, $printer, $lang, $request->user()?->id, $reprint));
    }

    public function printFinalInvoice(Request $request, CateringFinalInvoice $cateringFinalInvoice)
    {
        return $this->queueDocument($request, fn (Printer $printer, string $lang, bool $reprint) => app(CateringDocumentPrintService::class)
            ->queueFinalInvoice($cateringFinalInvoice, $printer, $lang, $request->user()?->id, $reprint));
    }

    /** Shared validation, printer resolution and honest failure for both documents. */
    private function queueDocument(Request $request, callable $queue)
    {
        $data = $request->validate([
            'printer_id' => ['required', 'integer', 'exists:printers,id'],
            'lang' => ['nullable', 'string'],
            'reprint' => ['nullable', 'boolean'],
        ]);

        $printer = Printer::where('is_active', true)->find($data['printer_id']);

        if (! $printer) {
            return back()->withErrors(['print' => 'That printer is not active.']);
        }

        $lang = $data['lang'] ?? 'en';

        // Refuse rather than emit bytes the printer cannot render. Saying no
        // here is the honest outcome; a page of mojibake would look like the
        // feature worked.
        if (! app(CateringDocumentPrintService::class)->supportsThermal($lang)) {
            return back()->withErrors([
                'print' => 'Thermal printing is English only — this transport cannot render Urdu. '
                    .'Use the A4 document for Urdu or bilingual output.',
            ]);
        }

        try {
            $job = $queue($printer, $lang, (bool) ($data['reprint'] ?? false));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['print' => $e->getMessage()]);
        }

        return back()->with('status', "Queued to {$printer->name} (job {$job->job_no}). Nothing was posted to finance and no stock moved.");
    }

    private function language(Request $request): string
    {
        $lang = $request->input('lang');
        if (! in_array($lang, CateringSetting::PRINT_PROFILES, true)) {
            $lang = CateringSetting::tenantDefault()->print_language_profile;
        }

        return $lang;
    }

    private function businessName(): string
    {
        try {
            return app('tenant')->business_name ?? config('saas.brand_name', 'Bingoo');
        } catch (\Throwable) {
            return config('saas.brand_name', 'Bingoo');
        }
    }
}
