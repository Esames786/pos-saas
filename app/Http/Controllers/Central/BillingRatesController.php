<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\PlatformSetting;
use App\Services\Saas\BillingRates;
use Illuminate\Http\Request;

/**
 * ONLINE-ORDERING-1 — platform admin → Billing rates.
 *
 * Malik: "env me rakhne ke bajaye master me daldo". Yahan badla hua rate sirf AAGE ke kaam par lagta
 * hai: WhatsApp ka rate har message ki row par likha jata hai, to guzra hua aik paisa bhi nahi hilta.
 */
class BillingRatesController extends Controller
{
    public function edit(BillingRates $rates)
    {
        $stored = PlatformSetting::query()
            ->with('updatedBy:id,name')
            ->whereIn('key', array_keys(BillingRates::KEYS))
            ->get()
            ->keyBy('key');

        return view('central.billing-rates.edit', [
            'values' => [
                BillingRates::WHATSAPP_RATE => $rates->whatsappRatePkr(),
                BillingRates::WHATSAPP_COST => $rates->whatsappCostPkr(),
                BillingRates::WEBSITE_COMMISSION => $rates->websiteCommissionPercent(),
            ],
            'stored' => $stored,
        ]);
    }

    public function update(Request $request, BillingRates $rates)
    {
        $data = $request->validate([
            BillingRates::WHATSAPP_RATE => ['required', 'numeric', 'min:0', 'max:1000'],
            BillingRates::WHATSAPP_COST => ['required', 'numeric', 'min:0', 'max:1000'],
            BillingRates::WEBSITE_COMMISSION => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $rates->put($data, auth('central')->id());

        return redirect()->route('central.billing-rates.edit')
            ->with('status', 'Billing rates saved. New rates apply from now on; past usage keeps its own rate.');
    }
}
