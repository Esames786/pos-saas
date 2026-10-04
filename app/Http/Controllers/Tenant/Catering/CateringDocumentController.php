<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\CateringSetting;
use App\Models\Tenant\Printer;
use App\Services\Catering\CateringDocumentPrintService;
use App\Services\Catering\CateringDocumentQueueService;
use App\Services\Catering\CateringFinancialPositionService;
use App\Services\Catering\CateringProductionReleaseService;
use Dompdf\Dompdf;
use Dompdf\Options;
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

        $data = [
            'estimate' => $cateringEstimate,
            'event' => $cateringEstimate->event,
            'lang' => $lang,
            'position' => $position,
            // Kept for anything still reading it, but it is now the NET figure —
            // what the business actually holds.
            'advanceTotal' => $position['net_received'],
            'businessName' => $this->businessName(),
        ];

        // KASHIF-CATERING-PDF-1: ?format=pdf hands back the SAME document as a
        // file. Deliberately a parameter on this route and not a route of its
        // own: a new route needs a new permission, granted per role on every
        // tenant, and whoever may read this document on screen may obviously
        // read it as a file. One authority, not two that can disagree.
        if ($request->query('format') === 'pdf') {
            return $this->asPdf('tenant.catering.documents.estimate', $data, $lang,
                $cateringEstimate->event->event_no.'-Q'.$cateringEstimate->version_no);
        }

        return view('tenant.catering.documents.estimate', $data);
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

    /**
     * KITCHEN-SHEET-PREVIEW-1 — wohi parcha, production release se PEHLE.
     *
     * Malik ki farmaish (27 Sep). SIRF CHHAPNE KE LIYE: ye raasta kuch mehfooz
     * nahi karta — na release banti hai, na release number kharch hota hai, na
     * event ka status hilta hai, na koi snapshot jamta hai. Isi liye ye GET hai
     * aur service ki `preview()` bulati hai, `release()` nahi.
     *
     * Kaghaz khud oopar likhta hai ke wo abhi jaari nahi hua. Ye is feature ka
     * sab se bara khatra hai: deewar par laga hua preview agar asli parche jaisa
     * lage to bawarchi-khane ke paas do sach ho jayenge.
     */
    public function kitchenSheetPreview(Request $request, CateringEvent $cateringEvent)
    {
        return view('tenant.catering.documents.kitchen-sheet', [
            'release' => app(CateringProductionReleaseService::class)->preview($cateringEvent),
            'lang' => $this->language($request),
            'businessName' => $this->businessName(),
            // Koi `preview` jhanda nahi bheja ja raha: kaghaz khud dekh leta
            // hai ke release mehfooz hui hai ya nahi (`$release->exists`). Ek
            // jhanda har naye caller par dobara sahi likhna parta — aur bulk
            // wala raasta usay bhool jata.
        ]);
    }

    /** CATERING-V1-CLOSURE-1 (§5): A4 final invoice from the immutable snapshot. */
    public function finalInvoice(Request $request, \App\Models\Tenant\CateringFinalInvoice $cateringFinalInvoice)
    {
        $cateringFinalInvoice->load('event');
        $lang = $this->language($request);

        $data = [
            'invoice' => $cateringFinalInvoice,
            'lang' => $lang,
            'businessName' => $this->businessName(),
        ];

        if ($request->query('format') === 'pdf') {
            return $this->asPdf('tenant.catering.documents.final-invoice', $data, $lang,
                $cateringFinalInvoice->invoice_no);
        }

        return view('tenant.catering.documents.final-invoice', $data);
    }

    /**
     * KASHIF-CATERING-PDF-1 — the document, drawn into a file.
     *
     * The same Blade the browser gets, with one extra flag the stylesheet reads
     * to say its layout in CSS 2.1 tables instead of flexbox. Nothing about the
     * document's CONTENT changes: a PDF that disagreed with the printed sheet
     * would be worse than no PDF at all.
     *
     * English only, and that refusal is the point rather than a shortcoming
     * being hidden. dompdf has no complex-script shaping engine, so Urdu comes
     * out as isolated letters running the wrong way — a page that looks like the
     * feature worked while being unreadable. The house already answers this way
     * for thermal ("Saying no here is the honest outcome"), and the browser's
     * own Print → Save as PDF reaches a real Urdu PDF with the real font.
     */
    private function asPdf(string $view, array $data, string $lang, string $filename)
    {
        if ($lang !== 'en') {
            return response()->view('tenant.catering.documents.nothing-to-print', [
                'title' => 'The PDF file is English only',
                'message' => 'This file is drawn by a renderer with no Nastaliq support, so Urdu '
                    .'would come out as separated letters in the wrong order — a page that looks '
                    .'printed and cannot be read. For an Urdu or bilingual file, open the Urdu '
                    .'document and use your browser\'s own Print → Save as PDF: it uses the real font.',
                'references' => [],
                'hint' => 'Nothing was printed, and nothing about the booking was changed.',
            ], 422);
        }

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->setPaper('a4', 'portrait');
        $pdf->loadHtml(view($view, array_merge($data, ['pdf' => true]))->render(), 'UTF-8');
        $pdf->render();

        // A document number can carry a slash; a Content-Disposition header
        // cannot carry whatever it likes.
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($filename)) ?: 'document';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$safe.'.pdf"',
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
        return $this->queueDocument($request, fn (Printer $printer, string $lang, bool $reprint) => $printer->printer_type === Printer::TYPE_WINDOWS
            // A4/A5 par poora document — Urdu samet, agent ke Chrome se.
            ? app(CateringDocumentQueueService::class)
                ->queueQuotation($cateringEstimate, $printer, $request->user()?->id, $reprint)
            // Thermal par wohi purana English slip — ek harf nahi badla.
            : app(CateringDocumentPrintService::class)
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
        //
        // CATERING-SEND-TO-PRINTER-1 — magar ye shart SIRF THERMAL par lagti
        // hai. A4/A5 printer par document agent ke Chrome se banta hai, is liye
        // wahan Urdu bilkul theek chhapti hai. Yahan wo rok laga chhorna us
        // feature ko maar dena hota jis ke liye ye poora kaam hua hai.
        if ($printer->printer_type !== Printer::TYPE_WINDOWS
            && ! app(CateringDocumentPrintService::class)->supportsThermal($lang)) {
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
            // A tenant that has never opened Catering Settings has no stored
            // profile yet — a document must still print, in English, not 500.
            $lang = CateringSetting::tenantDefault()->print_language_profile ?? 'en';
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
