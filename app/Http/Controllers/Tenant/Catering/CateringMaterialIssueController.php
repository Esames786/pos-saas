<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CateringProductionRelease;
use App\Services\Catering\CateringMaterialIssueService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * CATERING-GO-LIVE-READINESS-1 (§7): the explicit, separately-permissioned
 * "Issue Materials" action. Printing a release never moves stock — this does,
 * and only through InventoryService::postOutFefo inside the service.
 */
class CateringMaterialIssueController extends Controller
{
    public function store(Request $request, CateringProductionRelease $cateringProductionRelease, CateringMaterialIssueService $issues)
    {
        try {
            $issue = $issues->issue(
                $cateringProductionRelease,
                $request->integer('branch_id') ?: null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return back()->with('status', "Materials issued as {$issue->issue_no} — official FEFO cost ".number_format((float) $issue->total_fefo_cost, 2).'. Retrying is safe: one issue per release.');
    }
}
