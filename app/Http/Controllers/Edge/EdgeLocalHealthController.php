<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Services\Edge\EdgeApplianceHealthService;
use App\Services\Edge\EdgeBranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * P4 §11 — the ONE operator/admin health PAGE on the Branch Server (same report as `edge:local:health`).
 * Authenticated local users only (edge.auth + edge.branch); non-secret by construction; the cashier sees a simple
 * status headline while the detail sections serve the supervisor. Server-rendered, no scripts, auto-refreshes.
 */
class EdgeLocalHealthController extends Controller
{
    public function __construct(
        private readonly EdgeApplianceHealthService $health,
        private readonly EdgeBranchContext $context,
    ) {
    }

    public function view(Request $request): View
    {
        $report = $this->health->report();
        if ($request->wantsJson()) {
            abort(406); // JSON lives on the CLI report; the page is the operator surface
        }

        return view('edge.health', [
            'report' => $report,
            'branchId' => $this->context->boundBranchId(),
            'userName' => auth('tenant')->user()?->name,
        ]);
    }
}
