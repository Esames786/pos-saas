<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Services\Edge\EdgeCompatibilityService;
use Illuminate\Http\Request;

/**
 * EDGE-COMPATIBILITY-CONTRACT-1 — CENTRAL, device-authenticated compatibility report endpoint.
 * The appliance POSTs its grounded manifest (EdgeCompatibilityService::manifest()); the Cloud stores
 * it on the device row and answers with the explicit classification. NOT heartbeat/sync/activation —
 * a version/capability exchange only.
 */
class EdgeCompatibilityApiController extends Controller
{
    public function __construct(private readonly EdgeCompatibilityService $compatibility) {}

    /** POST /api/edge/compatibility/report */
    public function report(Request $request)
    {
        $data = $request->validate([
            'edge_app_version' => ['required', 'string', 'max:64'],
            'edge_schema_version' => ['required', 'string', 'max:190'],
            'bootstrap_schema_version' => ['required', 'string', 'max:64'],
            'config_schema_version' => ['required', 'string', 'max:64'],
            'applied_config_schema_version' => ['nullable', 'string', 'max:64'],
            'last_config_revision' => ['nullable', 'integer', 'min:0'],
            'activation_epoch' => ['nullable', 'integer', 'min:0'],
            'capabilities' => ['present', 'array', 'max:100'],
            'capabilities.*' => ['string', 'max:64'],
        ]);

        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $device->forceFill([
            'compatibility_manifest' => $data,
            'compatibility_reported_at' => now(),
            'app_version' => $data['edge_app_version'],
            'schema_version' => $data['bootstrap_schema_version'],
        ])->save();

        return response()->json($this->compatibility->classify($data), 200);
    }
}
