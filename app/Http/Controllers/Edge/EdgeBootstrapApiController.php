<?php

namespace App\Http\Controllers\Edge;

use App\Exceptions\EdgeBootstrapException;
use App\Http\Controllers\Controller;
use App\Models\Master\EdgeBootstrapSnapshot;
use App\Models\Master\EdgeDevice;
use App\Services\Edge\EdgeBootstrapService;
use Illuminate\Http\Request;

/**
 * BRANCH-BOOTSTRAP-SNAPSHOT-1 — CENTRAL, device-authenticated bootstrap API. The tenant +
 * branch come ONLY from the authenticated EdgeDevice; request tenant_id/branch_id are ignored.
 * Snapshots are addressed by public UUID and every snapshot access is ownership-checked.
 */
class EdgeBootstrapApiController extends Controller
{
    public function __construct(private readonly EdgeBootstrapService $bootstrap) {}

    private function device(Request $request): EdgeDevice
    {
        return $request->attributes->get('edgeDevice');
    }

    /** Resolve a snapshot by public UUID and enforce device ownership (no existence leak). */
    private function ownedSnapshot(Request $request, string $uuid): EdgeBootstrapSnapshot
    {
        $snapshot = EdgeBootstrapSnapshot::where('public_uuid', $uuid)->first();
        if (! $snapshot || (int) $snapshot->edge_device_id !== (int) $this->device($request)->id) {
            throw EdgeBootstrapException::of(EdgeBootstrapException::SNAPSHOT_NOT_FOUND);
        }
        return $snapshot;
    }

    /** POST /api/edge/bootstrap/snapshots */
    public function create(Request $request)
    {
        $snapshot = $this->bootstrap->createOrReuse($this->device($request));

        return response()->json([
            'snapshot_uuid' => $snapshot->public_uuid,
            'status'        => $snapshot->status,
            'schema_version' => $snapshot->schema_version,
            'manifest_hash' => $snapshot->manifest_hash,
            'expires_at'    => optional($snapshot->expires_at)->toIso8601String(),
        ], 201);
    }

    /** GET /api/edge/bootstrap/snapshots/{uuid}/manifest */
    public function manifest(Request $request, string $uuid)
    {
        $snapshot = $this->ownedSnapshot($request, $uuid);
        return response()->json($this->bootstrap->manifest($snapshot), 200);
    }

    /** GET /api/edge/bootstrap/snapshots/{uuid}/sections/{section} */
    public function section(Request $request, string $uuid, string $section)
    {
        $snapshot = $this->ownedSnapshot($request, $uuid);
        $result   = $this->bootstrap->sectionPayload($snapshot, $section);

        $headers = [
            'Content-Type'    => 'application/json',
            'X-Content-SHA256' => $result['hash'],   // logical hash over the UNCOMPRESSED JSON
            'X-Row-Count'     => (string) $result['count'],
            'Cache-Control'   => 'private, max-age=0',
        ];

        // Transport compression when the client supports it — logical hash is unchanged.
        if (str_contains(strtolower((string) $request->header('Accept-Encoding', '')), 'gzip')) {
            $headers['Content-Encoding'] = 'gzip';
            return response(gzencode($result['json'], 6), 200, $headers);
        }

        return response($result['json'], 200, $headers);
    }

    /** POST /api/edge/bootstrap/snapshots/{uuid}/acknowledge */
    public function acknowledge(Request $request, string $uuid)
    {
        $data = $request->validate([
            'schema_version' => ['required', 'string', 'max:40'],
            'manifest_hash'  => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
        ]);

        $snapshot = $this->ownedSnapshot($request, $uuid);
        $result   = $this->bootstrap->acknowledge(
            $this->device($request),
            $snapshot,
            $data['schema_version'],
            strtolower($data['manifest_hash']),
        );

        return response()->json($result, 200);
    }
}
