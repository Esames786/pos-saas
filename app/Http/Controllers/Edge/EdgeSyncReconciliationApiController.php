<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Models\Tenant\EdgeInboundSaleIngestion;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRODUCTIZATION GATE 0 — CENTRAL, device-authenticated sync RECONCILIATION status endpoint. READ ONLY.
 *
 * The appliance's EdgeSyncReconciliationService is deliberately transport-free; this endpoint is the real
 * authenticated source of the Cloud ingestion truth it consumes. It reads edge_inbound_sale_ingestions and
 * returns a safe, machine-readable status per sale — it NEVER posts, never duplicates 1C logic, and never
 * exposes another device's rows, a device secret, or a full sensitive payload.
 *
 * Scope is the AUTHENTICATED device: every row is filtered to device_public_uuid == the authenticated
 * device's public_uuid (and its tenant, via activation). A device can only ever see the sales IT ingested;
 * a foreign device or a browser tenant user can never read another device/branch's ingestion truth.
 */
class EdgeSyncReconciliationApiController extends Controller
{
    /** Hard bound on how many rows one reconciliation request may read. */
    private const MAX_ROWS = 200;

    public function __construct(private readonly TenancyManager $tenancy)
    {
    }

    /** POST /api/edge/sync/reconcile */
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sale_uuids' => ['sometimes', 'array', 'max:' . self::MAX_ROWS],
            'sale_uuids.*' => ['string', 'max:26'],
            'recent' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_ROWS],
        ]);

        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $saleUuids = $validated['sale_uuids'] ?? [];
        $recent = (int) ($validated['recent'] ?? 0);
        if ($saleUuids === [] && $recent === 0) {
            return response()->json(['status' => 'refused', 'failure_code' => 'QUERY_EMPTY', 'message' => 'provide sale_uuids or recent'], 422);
        }

        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant) {
            return response()->json(['status' => 'refused', 'failure_code' => 'WRONG_TENANT', 'message' => 'device tenant not found'], 403);
        }

        $this->tenancy->activate($tenant);
        try {
            // STRICT scope: only rows this device ingested, for its own branch. Never another device's truth.
            $query = EdgeInboundSaleIngestion::query()
                ->where('device_public_uuid', (string) $device->public_uuid)
                ->where('branch_id', (int) $device->branch_id);

            if ($saleUuids !== []) {
                $query->whereIn('sale_uuid', array_values(array_unique($saleUuids)));
            } else {
                $query->orderByDesc('id')->limit(min($recent, self::MAX_ROWS));
            }

            $statuses = [];
            foreach ($query->get() as $row) {
                $statuses[(string) $row->sale_uuid] = $this->safeStatus($row);
            }
        } finally {
            $this->tenancy->deactivate();
        }

        return response()->json([
            'device_public_uuid' => (string) $device->public_uuid,
            'branch_id' => (int) $device->branch_id,
            'statuses' => $statuses,
        ], 200);
    }

    /** The safe, machine-readable projection of a registry row — enough to reconcile, nothing sensitive. */
    private function safeStatus(EdgeInboundSaleIngestion $row): array
    {
        return [
            'sale_uuid' => (string) $row->sale_uuid,
            'status' => (string) $row->status,
            'content_hash' => (string) $row->content_hash,
            'failure_code' => $row->failure_code,
            'ingestion_uuid' => $row->ingestion_uuid,
            'official_sale_no' => $row->official_sale_no,
            'activation_epoch' => $row->activation_epoch !== null ? (int) $row->activation_epoch : null,
            'config_revision' => $row->config_revision !== null ? (int) $row->config_revision : null,
            'branch_id' => (int) $row->branch_id,
            'ingested_at' => $row->ingested_at?->toIso8601String(),
        ];
    }
}
