<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use Illuminate\Http\Request;

/**
 * OFFLINE-EDGE-ENTITLEMENT-1 + BRANCH-DEVICE-PAIRING-1 — the entitled Settings page for
 * Offline Branch Edge: installer-download gate, plus generate/cancel branch pairing codes
 * and revoke paired devices.
 *
 * Generation/cancel require entitlement + rollout (assertSetupAccessAllowed). Revocation
 * is a SECURITY control and deliberately does NOT depend on entitlement or the rollout
 * flag — only authentication, the revoke permission, and device ownership.
 */
class OfflineEdgeController extends Controller
{
    public function __construct(
        private readonly OfflineEdgeEntitlementService $entitlement,
        private readonly EdgePairingService $pairing,
    ) {}

    public function index(Request $request)
    {
        $this->entitlement->assertSetupAccessAllowed();

        $tenant = app('tenant');
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        // Per-branch pairing/device state (master DB), joined in memory (no cross-DB FK).
        $devices = EdgeDevice::active()->where('tenant_id', $tenant->id)->get()->keyBy('branch_id');

        $rows = $branches->map(function (Branch $b) use ($tenant, $devices) {
            $device   = $devices->get($b->id);
            $liveCode = $this->pairing->liveCodeForBranch($tenant->id, $b->id);

            return [
                'id'            => $b->id,
                'name'          => $b->name,
                'lifecycle'     => $b->local_edge_status,
                'device'        => $device,
                'has_live_code' => (bool) $liveCode,
                'code_expires'  => $liveCode?->expires_at,
            ];
        });

        return view('tenant.offline-edge.index', [
            'branchRows'         => $rows,
            'deviceLimit'        => $this->pairing->deviceLimit($tenant),
            'activeDevices'      => $this->pairing->activeDeviceCount($tenant->id),
            'installerAvailable' => $this->entitlement->installerIsAvailable(),
            'installerVersion'   => $this->entitlement->installerVersion(),
            // A freshly generated code is flashed ONCE and never re-rendered / stored.
            'newCode'            => session('edge_pairing_code'),
            'newCodeBranch'      => session('edge_pairing_branch'),
            'newCodeExpires'     => session('edge_pairing_expires'),
        ]);
    }

    public function download(Request $request)
    {
        $this->entitlement->assertSetupAccessAllowed();

        if (! $this->entitlement->installerIsAvailable()) {
            throw \App\Exceptions\OfflineEdgeAccessException::installerNotAvailable();
        }

        $path    = config('app.edge_installer_path');
        $version = $this->entitlement->installerVersion();
        $name    = 'BingooEdgeSetup' . ($version ? '-' . $version : '') . '.exe';

        return response()->download($path, $name);
    }

    /** Generate a fresh six-digit code for a branch (shown ONCE). Entitlement + rollout required. */
    public function generatePairingCode(Request $request, Branch $branch)
    {
        $this->entitlement->assertSetupAccessAllowed();
        $this->assertEligibleBranch($branch);

        $result = $this->pairing->generateCode(app('tenant'), $branch, (int) auth('tenant')->id());

        // Show the code exactly once via a one-shot flash — never persisted, never in the URL.
        return redirect(url('/settings/offline-edge'))
            ->with('edge_pairing_code', $result['code'])
            ->with('edge_pairing_branch', $branch->id)
            ->with('edge_pairing_expires', $result['expires_at']);
    }

    public function cancelPairingCode(Request $request, Branch $branch)
    {
        $this->entitlement->assertSetupAccessAllowed();

        $this->pairing->cancelCode(app('tenant'), $branch, (int) auth('tenant')->id());

        return redirect(url('/settings/offline-edge'))->with('status', 'Pairing code cancelled.');
    }

    /** Revoke a paired device. SECURITY control — no entitlement/flag dependency. */
    public function revokeDevice(Request $request, EdgeDevice $edgeDevice)
    {
        // Ownership: the device must belong to the CURRENT tenant (route binding is global in master).
        abort_unless((int) $edgeDevice->tenant_id === (int) app('tenant')->id, 404);

        $this->pairing->revokeDevice(
            app('tenant'),
            $edgeDevice,
            (int) auth('tenant')->id(),
            $request->string('reason')->limit(190, '')->value() ?: null,
        );

        return redirect(url('/settings/offline-edge'))->with('status', 'Edge device revoked.');
    }

    private function assertEligibleBranch(Branch $branch): void
    {
        // Only cloud/inactive or local_edge/pending branches may be paired (never active/closing/suspended).
        abort_unless(
            in_array($branch->local_edge_status, [Branch::STATUS_INACTIVE, Branch::STATUS_PENDING], true),
            422,
        );
    }
}
