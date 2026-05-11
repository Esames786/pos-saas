<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\Tenant;

class DashboardController extends Controller
{
    public function __invoke()
    {
        return view('central.dashboard', [
            'tenantCount' => Tenant::count(),
            'activeTenantCount' => Tenant::where('status', 'active')->count(),
            'pendingTenantCount' => Tenant::where('status', 'pending')->count(),
        ]);
    }
}
