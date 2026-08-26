<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Services\Edge\EdgeInboundSaleIngestionService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OFFLINE-SYNC-ENGINE-1D — CENTRAL, device-authenticated sync ingestion endpoint. A THIN transport
 * boundary around 1C: it authenticates the appliance (edge.device.auth), binds the envelope to the
 * AUTHENTICATED device, activates that device's tenant, and delegates to EdgeInboundSaleIngestionService,
 * which independently re-validates everything and posts authoritatively exactly once. It duplicates NO 1C
 * posting logic.
 *
 * The appliance never trusts request-supplied identity alone: tenant/branch/device come from the
 * authenticated EdgeDevice, and the envelope's device_public_uuid MUST equal the authenticated device — a
 * device can never submit another device's envelope, and no browser tenant user can reach this authority.
 */
class EdgeSyncIngestionApiController extends Controller
{
    public function __construct(
        private readonly EdgeInboundSaleIngestionService $ingestion,
        private readonly TenancyManager $tenancy,
    ) {
    }

    /** POST /api/edge/sync/sales */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'envelope' => ['required', 'array'],
            'envelope.sale_uuid' => ['required', 'string', 'max:26'],
            'envelope.content_hash' => ['required', 'string', 'size:64'],
            'envelope.device_public_uuid' => ['required', 'string', 'max:64'],
        ]);

        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $envelope = $request->input('envelope');

        // Bind the envelope to the AUTHENTICATED device — a device cannot post another device's envelope.
        if ((string) ($envelope['device_public_uuid'] ?? '') !== (string) $device->public_uuid) {
            return response()->json([
                'status' => 'refused', 'failure_code' => 'DEVICE_MISMATCH',
                'sale_uuid' => (string) ($envelope['sale_uuid'] ?? ''),
                'message' => 'the envelope device does not match the authenticated device',
            ], 403);
        }

        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant) {
            return response()->json(['status' => 'refused', 'failure_code' => 'WRONG_TENANT', 'message' => 'device tenant not found'], 403);
        }

        $this->tenancy->activate($tenant);
        try {
            $ack = $this->ingestion->ingest($envelope);   // 1C: independent validation + authoritative posting
        } finally {
            $this->tenancy->deactivate();
        }

        return response()->json($ack, $this->httpStatusFor($ack));
    }

    /** Map the 1C result to a stable HTTP status the transport layer classifies (transient vs terminal). */
    private function httpStatusFor(array $ack): int
    {
        return match ((string) ($ack['status'] ?? '')) {
            'applied' => 201,
            'already_applied' => 200,
            'conflict' => 409,
            'refused' => in_array((string) ($ack['failure_code'] ?? ''), ['WRONG_TENANT', 'WRONG_BRANCH', 'DEVICE_UNKNOWN', 'DEVICE_REVOKED', 'DEVICE_MISMATCH'], true) ? 403 : 422,
            'exception' => 422,   // deterministic non-applied (e.g. INSUFFICIENT_STOCK / FINANCE_*): retryable, 1E-gated
            default => 422,
        };
    }
}
