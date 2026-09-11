<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\Tenant;
use App\Services\Edge\EdgeInboundSupplierFinanceIngestionService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OFFLINE EDGE — F2: the thin device-authenticated ingestion endpoint for Edge supplier-finance events
 * (supplier payments, supplier-aware manual AP journals). Everything financial happens inside
 * EdgeInboundSupplierFinanceIngestionService around the EXISTING canonical authorities. Cloud-only.
 */
class EdgeInboundSupplierFinanceApiController extends Controller
{
    public function __construct(private readonly EdgeInboundSupplierFinanceIngestionService $ingestion, private readonly TenancyManager $tenancy)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'envelope' => ['required', 'array'],
            'envelope.event_uuid' => ['required', 'string', 'max:26'],
            'envelope.content_hash' => ['required', 'string', 'size:64'],
            'envelope.device_public_uuid' => ['required', 'string', 'max:64'],
        ]);
        $device = $request->attributes->get('edgeDevice');
        $envelope = $request->input('envelope');
        $uuid = (string) ($envelope['event_uuid'] ?? '');
        if ((string) ($envelope['device_public_uuid'] ?? '') !== (string) $device->public_uuid) {
            return response()->json(['status' => 'refused', 'failure_code' => 'DEVICE_MISMATCH', 'sale_uuid' => $uuid, 'event_uuid' => $uuid, 'message' => 'the envelope device does not match the authenticated device'], 403);
        }
        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant) {
            return response()->json(['status' => 'refused', 'failure_code' => 'WRONG_TENANT', 'message' => 'device tenant not found'], 403);
        }
        $this->tenancy->activate($tenant);
        try {
            $ack = $this->ingestion->ingest($envelope);
        } finally {
            $this->tenancy->deactivate();
        }

        return response()->json($ack, match ($ack['status'] ?? 'exception') {
            'applied', 'already_applied' => 200,
            'conflict' => 409,
            'refused' => 422,
            default => 500,   // exception → the sender classifies by failure_code (transient unless terminal)
        });
    }
}
