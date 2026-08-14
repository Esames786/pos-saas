<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\CateringSetting;
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
        $advanceTotal = (float) $cateringEstimate->event->advances()->sum('amount');

        return view('tenant.catering.documents.estimate', [
            'estimate' => $cateringEstimate,
            'event' => $cateringEstimate->event,
            'lang' => $lang,
            'advanceTotal' => $advanceTotal,
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
