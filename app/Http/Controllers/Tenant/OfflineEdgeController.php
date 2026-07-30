<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * OFFLINE-EDGE-ENTITLEMENT-1 + BRANCH-DEVICE-PAIRING-1 (+HARDEN-1) — the entitled Settings
 * page (generate code / download) AND a security-management path (cancel code / revoke
 * device) that stays reachable even after entitlement is removed or the rollout flag is off.
 *
 * Gating summary:
 *   index / generate / download → entitlement + rollout (assertSetupAccessAllowed)
 *   security / cancel / revoke  → permission + ownership ONLY (security controls)
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

        // HARDEN-2: CONSUME the one-time code (pull → removed from the session immediately,
        // so it cannot be read again even within this session's lifetime).
        $newCode       = $this->pullOneTimeCode();
        $newCodeBranch = session()->pull('edge_pairing_branch');
        $newCodeExpires = session()->pull('edge_pairing_expires');

        $response = response()->view('tenant.offline-edge.index', array_merge($this->branchState(), [
            'securityOnly'       => false,
            'installerAvailable' => $this->entitlement->installerIsAvailable(),
            'installerVersion'   => $this->entitlement->installerVersion(),
            'newCode'            => $newCode,
            'newCodeBranch'      => $newCodeBranch,
            'newCodeExpires'     => $newCodeExpires,
        ]));

        // When a plaintext code is on the page, forbid any caching so a browser Back /
        // bfcache / proxy can never re-surface it.
        if ($newCode !== null) {
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }

    /**
     * Security-management view — permission-gated only (NO entitlement / rollout gate), so an
     * Owner can always revoke a device / cancel a code after entitlement is removed, the
     * subscription lapses, or EDGE_FEATURE_ENABLED is turned off. Generation/download controls
     * are never rendered here.
     */
    public function security(Request $request)
    {
        return view('tenant.offline-edge.index', array_merge($this->branchState(), [
            'securityOnly'       => true,
            'installerAvailable' => false,
            'installerVersion'   => null,
            'newCode'            => null,
            'newCodeBranch'      => null,
            'newCodeExpires'     => null,
        ]));
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

        // The plaintext code is ENCRYPTED before it enters session flash (the server-side
        // session store is not encrypted at rest), decrypted once for the immediate render,
        // and never persisted, logged, or placed in a URL.
        return redirect(url('/settings/offline-edge'))
            ->with('edge_pairing_code', Crypt::encryptString($result['code']))
            ->with('edge_pairing_branch', $branch->id)
            ->with('edge_pairing_expires', $result['expires_at']);
    }

    /** Cancel a live code. SECURITY control — no entitlement/flag dependency. */
    public function cancelPairingCode(Request $request, Branch $branch)
    {
        $this->pairing->cancelCode(app('tenant'), $branch, (int) auth('tenant')->id());

        return redirect($this->backTo())->with('status', 'Pairing code cancelled.');
    }

    /** Revoke a paired device. SECURITY control — no entitlement/flag dependency. */
    public function revokeDevice(Request $request, EdgeDevice $edgeDevice)
    {
        abort_unless((int) $edgeDevice->tenant_id === (int) app('tenant')->id, 404);

        $this->pairing->revokeDevice(
            app('tenant'),
            $edgeDevice,
            (int) auth('tenant')->id(),
            $request->string('reason')->limit(190, '')->value() ?: null,
        );

        return redirect($this->backTo())->with('status', 'Edge device revoked.');
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    /** Per-branch device + live-code state (master joined to tenant branches in memory). */
    private function branchState(): array
    {
        $tenant   = app('tenant');
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $devices  = EdgeDevice::active()->where('tenant_id', $tenant->id)->get()->keyBy('branch_id');

        $rows = $branches->map(function (Branch $b) use ($tenant, $devices) {
            $liveCode = $this->pairing->liveCodeForBranch($tenant->id, $b->id);
            return [
                'id'            => $b->id,
                'name'          => $b->name,
                'lifecycle'     => $b->local_edge_status,
                'device'        => $devices->get($b->id),
                'has_live_code' => (bool) $liveCode,
                'code_expires'  => $liveCode?->expires_at,
            ];
        });

        return [
            'branchRows'    => $rows,
            'deviceLimit'   => $this->pairing->deviceLimit($tenant),
            'activeDevices' => $this->pairing->activeDeviceCount($tenant->id),
        ];
    }

    private function pullOneTimeCode(): ?string
    {
        $enc = session()->pull('edge_pairing_code');   // read AND forget in one step
        if (! $enc) {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Return to the setup page when reachable, else the security page. */
    private function backTo(): string
    {
        return $this->entitlement->tenantHasOfflineEdgeAccess() && $this->entitlement->featureIsEnabled()
            ? url('/settings/offline-edge')
            : url('/settings/offline-edge/security');
    }

    private function assertEligibleBranch(Branch $branch): void
    {
        abort_unless(
            in_array($branch->local_edge_status, [Branch::STATUS_INACTIVE, Branch::STATUS_PENDING], true),
            422,
        );
    }
}
