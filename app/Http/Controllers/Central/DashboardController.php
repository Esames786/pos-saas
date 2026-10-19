<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Services\Central\MasterOverviewService;

/**
 * MASTER-UNIVERSE-1 — "Bingoo Universe": the server, every tenant and every module on one page.
 * Reads master tables and the newest usage snapshot only (no tenant database is opened here).
 */
class DashboardController extends Controller
{
    public function __invoke(MasterOverviewService $overview)
    {
        $data = $overview->dashboard();

        return view('central.dashboard', $data + [
            'tenantCount'        => $data['counts']['all'],
            'activeTenantCount'  => $data['counts']['active'],
            'pendingTenantCount' => $data['counts']['pending'],
        ]);
    }
}
