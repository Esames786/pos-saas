<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Models\Master\EdgeRelease;
use App\Models\Master\Tenant;
use App\Services\Edge\Cloud\DeviceProfileService;
use App\Services\Edge\Cloud\EdgeSetupException;
use App\Services\Edge\Cloud\ReleaseRegistry;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * EDGE 0.9 (K6) — the appliance auto-update channel (Cloud only).
 *
 *   GET /api/edge/updates/latest               device-authenticated: is a newer SIGNED release registered for this device?
 *                                              → version, hashes, size, a 15-minute signed download URL, the device's
 *                                              maintenance window and the owner's "Update now" flag
 *   GET /api/edge/updates/{release}/download   short-lived SIGNED URL (bound to the device): streams the registered zip
 *                                              only after its size + SHA-256 re-verify; the device must still be active
 *
 * The appliance still verifies the Ed25519 signature + manifest hash itself (EdgeUpdateVerifier) before staging anything.
 */
class EdgeUpdatesApiController extends Controller
{
    public function __construct(
        private readonly ReleaseRegistry $releases,
        private readonly DeviceProfileService $profiles,
        private readonly TenancyManager $tenancy,
    ) {}

    public function latest(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $channel = EdgeRelease::CHANNEL_STABLE;

        $offer = $this->releases->offerFor($device, $channel);
        $base = [
            'available'               => (bool) $offer['available'],
            'channel'                 => $channel,
            'current_version'         => $offer['current_version'],
            'update_now'              => $device->update_now_requested_at !== null,
            'update_now_requested_at' => optional($device->update_now_requested_at)->toIso8601String(),
            'maintenance_window'      => $this->window($device),
        ];
        if (! $offer['available']) {
            $base['reason'] = $offer['reason'] ?? null;
            foreach (['latest_version', 'min_from_version'] as $k) {
                if (isset($offer[$k])) {
                    $base[$k] = $offer[$k];
                }
            }

            return $this->noStore(response()->json($base));
        }

        /** @var EdgeRelease $r */
        $r = $offer['release'];

        return $this->noStore(response()->json($base + [
            'release_id'          => $r->public_uuid,
            'version'             => $r->version,
            'manifest_sha256'     => $r->manifest_sha256,
            'package_hash'        => $r->package_hash,
            'zip_sha256'          => $r->zip_sha256,
            'signing_key_id'      => $r->signing_key_id,
            'size_bytes'          => (int) $r->size_bytes,
            'update_package_file' => $r->update_package_file,
            'min_from_version'    => $r->min_from_version,
            'schema_generation'   => $r->schema_generation,
            // K6 plan approval: the owner-approved migration plan hashes {"<from_version>": "sha256:…"} and the one for THIS
            // device's installed version (null when none was approved — an appliance with pending migrations then refuses).
            'plan_hashes'         => (object) (is_array($r->approved_plan_hashes) ? $r->approved_plan_hashes : []),
            'expected_plan_hash'  => $offer['current_version'] !== null && is_array($r->approved_plan_hashes)
                ? ($r->approved_plan_hashes[$offer['current_version']] ?? null) : null,
            'download_url'        => $this->releases->signedDownloadUrl($r, $device),
            'download_expires_at' => now()->addMinutes(ReleaseRegistry::DOWNLOAD_TTL_MINUTES)->toIso8601String(),
        ]));
    }

    public function download(Request $request, string $release)
    {
        $device = EdgeDevice::active()->where('public_uuid', (string) $request->query('d', ''))->first();
        if (! $device) {
            return response()->json(['message' => 'Edge device authentication failed.', 'code' => 'EDGE_DEVICE_UNAUTHENTICATED'], 401);
        }
        $row = EdgeRelease::where('public_uuid', $release)->first();
        if (! $row || $row->status !== EdgeRelease::STATUS_PUBLISHED || ! $this->releases->integrityOk($row)) {
            Log::warning('[edge-release-audit] edge.release.download_refused', ['release' => $release, 'device_uuid' => $device->public_uuid]);
            throw EdgeSetupException::of(EdgeSetupException::RELEASE_UNAVAILABLE);
        }
        Log::info('[edge-release-audit] edge.release.downloaded', [
            'release' => $row->public_uuid, 'version' => $row->version, 'device_uuid' => $device->public_uuid,
            'tenant_id' => $device->tenant_id, 'branch_id' => $device->branch_id,
        ]);

        return response()->download((string) $this->releases->absolutePath($row), ReleaseRegistry::downloadName($row), [
            'Content-Type'          => 'application/zip',
            'X-Edge-Release-Sha256' => $row->zip_sha256,
            'Cache-Control'         => 'no-store, private, max-age=0',
        ]);
    }

    /** @return array{start:string,end:string,tz:string} */
    private function window(EdgeDevice $device): array
    {
        $tenant = Tenant::find((int) $device->tenant_id);
        $w = null;
        if ($tenant) {
            $this->tenancy->activate($tenant);
            try {
                $w = $this->profiles->maintenanceWindow($device);
            } finally {
                $this->tenancy->deactivate();
            }
        }
        $w ??= $this->profiles->maintenanceWindow($device, null);

        return ['start' => $w['start'], 'end' => $w['end'], 'tz' => $w['tz']];
    }

    private function noStore(JsonResponse $r): JsonResponse
    {
        $r->headers->set('Cache-Control', 'no-store, private, max-age=0');

        return $r;
    }
}
