<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Services\Edge\EdgeBaselineIssuanceService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PRODUCTIZATION GATE 0 — CENTRAL, device-authenticated baseline ISSUANCE endpoint. A thin transport boundary
 * around EdgeBaselineIssuanceService. The appliance requests a fresh operational-stock baseline for the config
 * watermark it has moved to; the Cloud computes the authoritative official position and returns an immutable,
 * integrity-hashed package. It posts nothing and never derives quantities from Edge provisional stock.
 *
 * Binding is authoritative from the AUTHENTICATED device: branch comes from the device, and the requested
 * activation epoch must match a real activation of THIS device for THIS branch. The appliance still
 * independently re-validates the package (branch/epoch/revision/integrity) and enforces the drain gate before
 * its atomic cutover — this endpoint never bypasses that.
 */
class EdgeBaselineApiController extends Controller
{
    public function __construct(
        private readonly EdgeBaselineIssuanceService $issuance,
        private readonly TenancyManager $tenancy,
    ) {
    }

    /** POST /api/edge/sync/baseline */
    public function issue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_revision' => ['required', 'string', 'max:128'],
            'activation_epoch' => ['required', 'integer', 'min:1'],
        ]);

        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $branchId = (int) $device->branch_id;
        $epoch = (int) $validated['activation_epoch'];
        $revision = (string) $validated['source_revision'];

        // The requested epoch must be a REAL activation of this device for this branch (never request-trusted alone).
        $activationExists = DB::connection('master')->table('edge_branch_activations')
            ->where('tenant_id', (int) $device->tenant_id)
            ->where('branch_id', $branchId)
            ->where('device_public_uuid', (string) $device->public_uuid)
            ->where('generation', $epoch)
            ->exists();
        if (! $activationExists) {
            return response()->json([
                'status' => 'refused', 'failure_code' => 'BASELINE_EPOCH_INVALID',
                'message' => 'no activation of this device for this branch/epoch',
            ], 403);
        }

        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant) {
            return response()->json(['status' => 'refused', 'failure_code' => 'WRONG_TENANT', 'message' => 'device tenant not found'], 403);
        }

        $this->tenancy->activate($tenant);
        try {
            $package = $this->issuance->issue($branchId, $epoch, $revision);
        } finally {
            $this->tenancy->deactivate();
        }

        return response()->json(['status' => 'issued', 'package' => $package], 200);
    }
}
