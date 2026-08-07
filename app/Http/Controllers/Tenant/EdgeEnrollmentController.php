<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeEnrollmentIssuer;
use App\Services\Edge\EdgePairingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EDGE-LOCAL-AUTH-1 (Section 6) — CLOUD side. An authorized Cloud actor obtains a signed, single-use
 * enrollment assertion for a target Branch Server user. This route is CLOUD-only (routes/tenant.php,
 * absent on a branch_server) and permission-gated (route.permission — permission == route name), so a
 * cashier cannot manufacture assertions for arbitrary users. The device is resolved as the current
 * active appliance of the target branch; the issuer enforces the rest of the contract and fails
 * closed if no signing key is configured.
 */
class EdgeEnrollmentController extends Controller
{
    public function __construct(
        private readonly EdgeEnrollmentIssuer $issuer,
        private readonly EdgePairingService $pairing,
    ) {
    }

    public function issue(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
        ]);

        $tenant = app('tenant'); // active tenant (IdentifyTenant)
        $branchId = (int) $data['branch_id'];

        $device = $this->pairing->activeDeviceForBranch((int) $tenant->id, $branchId);
        if (! $device) {
            return response()->json(['message' => 'No active Branch Server device for that branch.'], 422);
        }
        $user = User::find((int) $data['user_id']);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        try {
            $assertion = $this->issuer->issue($tenant, $branchId, $device, $user, (int) Auth::guard('tenant')->id());
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Audit the issuance on the Cloud side (identifiers only — never the credential/assertion body).
        Log::info('[edge-enrollment-issued]', [
            'issuer_user_id' => Auth::guard('tenant')->id(),
            'target_user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'branch_id' => $branchId,
            'device' => $device->public_uuid,
            'activation_epoch' => $assertion['payload']['activation_epoch'] ?? null,
            'jti' => $assertion['payload']['jti'] ?? null,
            'expires_at' => $assertion['payload']['expires_at'] ?? null,
        ]);

        return response()->json(['assertion' => $assertion]);
    }
}
