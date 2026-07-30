<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Services\Edge\EdgePairingService;
use Illuminate\Http\Request;

/**
 * BRANCH-DEVICE-PAIRING-1 — CENTRAL, unauthenticated pairing API. The installer needs
 * only the cloud URL + six-digit code; tenant + branch are resolved from the code, never
 * from request input. Never returns DB/master credentials, tenant DB names, permanent
 * tokens, catalog/financial data, or any other tenant/branch.
 */
class EdgePairingApiController extends Controller
{
    public function __construct(
        private readonly EdgePairingService $pairing,
    ) {}

    /** POST /api/edge/pair — exchange a code for a pending_bootstrap device. */
    public function pair(Request $request)
    {
        $data = $request->validate([
            'pairing_code'       => ['required', 'string'],
            'installation_uuid'  => ['required', 'uuid'],
            'device_name'        => ['nullable', 'string', 'max:190'],
            'device_secret_hash' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'app_version'        => ['nullable', 'string', 'max:40'],
            'schema_version'     => ['nullable', 'string', 'max:40'],
        ]);

        // EdgePairingException self-renders structured 422/409/403 codes (never a 500).
        $meta = $this->pairing->exchange([
            'code'               => $data['pairing_code'],
            'installation_uuid'  => $data['installation_uuid'],
            'device_name'        => $data['device_name'] ?? null,
            'device_secret_hash' => $data['device_secret_hash'],
            'app_version'        => $data['app_version'] ?? null,
            'schema_version'     => $data['schema_version'] ?? null,
        ]);

        return response()->json($meta, 200);
    }

    /** GET /api/edge/device/me — proves the client-held secret authenticates (device-auth middleware). */
    public function me(Request $request)
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');

        return response()->json([
            'device_id'      => $device->public_uuid,
            'public_uuid'    => $device->public_uuid,
            'tenant_code'    => optional(\App\Models\Master\Tenant::find($device->tenant_id))->tenant_code,
            'branch_id'      => $device->branch_id,
            'status'         => $device->status,
            'app_version'    => $device->app_version,
            'schema_version' => $device->schema_version,
            'paired_at'      => optional($device->paired_at)->toIso8601String(),
        ], 200);
    }
}
