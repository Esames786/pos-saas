<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\Tenant\FinishedGoodReceipt;
use App\Models\Tenant\WipJob;
use App\Services\Manufacturing\FinishedGoodPostingService;
use App\Services\Manufacturing\WipClosingService;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * MFG-FIN-E — Post / reverse a Finished Goods receipt + close a WIP job.
 *
 * POST   /manufacturing/finished-goods/{id}/post    → post FG stock + Dr FG / Cr WIP
 * POST   /manufacturing/finished-goods/{id}/reverse → reverse FG posting
 * POST   /manufacturing/wip/{id}/close              → variance journal + status = completed
 */
class FinishedGoodPostingController extends Controller
{
    public function post(FinishedGoodReceipt $finishedGoodReceipt, FinishedGoodPostingService $service)
    {
        try {
            $service->post($finishedGoodReceipt, Auth::id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['posting' => $e->getMessage()]);
        }

        return back()->with('status', 'FG receipt posted — stock added and Dr FG / Cr WIP journal created.');
    }

    public function reverse(FinishedGoodReceipt $finishedGoodReceipt, FinishedGoodPostingService $service)
    {
        try {
            $service->reverse($finishedGoodReceipt, Auth::id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['posting' => $e->getMessage()]);
        }

        return back()->with('status', 'FG receipt posting reversed — stock removed and journal reversed.');
    }

    public function closeWip(WipJob $wipJob, WipClosingService $service)
    {
        try {
            $service->close($wipJob, Auth::id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['posting' => $e->getMessage()]);
        }

        return back()->with('status', 'WIP job closed — variance journal posted and status set to Completed.');
    }
}
