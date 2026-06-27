<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ManufacturingConsumptionRecord;
use App\Services\Manufacturing\ConsumptionPostingService;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * MFG-FIN-C — post / reverse a manufacturing consumption record.
 * All business rules live in ConsumptionPostingService; this just translates the
 * result (or a clear RuntimeException) back to the consumption show page.
 */
class ManufacturingConsumptionPostingController extends Controller
{
    public function post(ManufacturingConsumptionRecord $manufacturingConsumptionRecord, ConsumptionPostingService $service)
    {
        try {
            $service->post($manufacturingConsumptionRecord, Auth::id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['posting' => $e->getMessage()]);
        }

        return back()->with('status', 'Consumption posted — Dr WIP / Cr Raw Material Inventory.');
    }

    public function reverse(ManufacturingConsumptionRecord $manufacturingConsumptionRecord, ConsumptionPostingService $service)
    {
        try {
            $service->reverse($manufacturingConsumptionRecord, Auth::id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['posting' => $e->getMessage()]);
        }

        return back()->with('status', 'Consumption posting reversed — stock and journal reversed.');
    }
}
