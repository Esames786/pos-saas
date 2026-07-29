<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Edge\OfflineEdgeEntitlementService;
use Illuminate\Http\Request;

/**
 * OFFLINE-EDGE-ENTITLEMENT-1 — the entitled Settings landing page for Offline Branch
 * Edge and the installer-download gate.
 *
 * Module entitlement (offline_edge) is enforced by the route's subscription middleware
 * (renders the standard module-disabled page). This controller additionally enforces
 * the rollout flag and installer availability via OfflineEdgeEntitlementService, so
 * sidebar-hiding is never the only protection. It does NOT build pairing, bootstrap,
 * sync, device licensing, leases or a real installer.
 */
class OfflineEdgeController extends Controller
{
    public function __construct(
        private readonly OfflineEdgeEntitlementService $entitlement,
    ) {}

    public function index(Request $request)
    {
        // Entitled + rolled out. (Entitlement is also enforced by middleware upstream.)
        $this->entitlement->assertSetupAccessAllowed();

        // Eligible branches are read-only context here — this sprint does NOT bind or
        // activate anything; each future licensed Edge install pairs to ONE branch.
        $branches = Branch::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'sales_operating_mode', 'local_edge_status']);

        return view('tenant.offline-edge.index', [
            'branches'          => $branches,
            'installerAvailable' => $this->entitlement->installerIsAvailable(),
            'installerVersion'   => $this->entitlement->installerVersion(),
        ]);
    }

    public function download(Request $request)
    {
        // Re-check every gate at the download boundary — download gating alone is never
        // sufficient, and an entitled+enabled tenant still gets nothing if no real,
        // signed artifact exists yet (controlled 503, never a fake file, never a 500).
        $this->entitlement->assertSetupAccessAllowed();

        if (! $this->entitlement->installerIsAvailable()) {
            throw \App\Exceptions\OfflineEdgeAccessException::installerNotAvailable();
        }

        // The real signed artifact + checksum/signature verification arrive with
        // EDGE-BUILD-PACKAGING-1. Path comes from config, never request input.
        $path    = config('app.edge_installer_path');
        $version = $this->entitlement->installerVersion();
        $name    = 'BingooEdgeSetup' . ($version ? '-' . $version : '') . '.exe';

        return response()->download($path, $name);
    }
}
