<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Services\Edge\Cloud\DeviceProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EDGE 0.9 — device-authenticated status reporting (Cloud only).
 *
 *   POST /api/edge/device/status        K4 — install / update phase, version, ok, message → shown on the Offline Edge page
 *   POST /api/edge/device/lan-profile   K5 — the PUBLIC LAN profile (hostname, IP, HTTPS port, branch CA certificate + SHA-256)
 *
 * Purely informational: nothing here changes pairing, authority, fencing or ingestion. The device row is the one the
 * AuthenticateEdgeDevice middleware resolved — never a device named in the body.
 */
class EdgeDeviceStatusApiController extends Controller
{
    public function __construct(private readonly DeviceProfileService $profiles) {}

    public function status(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $data = $request->validate([
            'phase'   => ['required', 'string', Rule::in(DeviceProfileService::PHASES)],
            'version' => ['nullable', 'string', 'max:64', 'regex:/^[0-9A-Za-z][0-9A-Za-z._+-]*$/'],
            'ok'      => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:2000'],
            'at'      => ['nullable', 'date'],
        ]);

        $device = $this->profiles->recordStatus($device, $data);

        return response()->json([
            'status'                  => 'recorded',
            'phase'                   => $device->install_phase,
            'reported_version'        => $device->reported_version,
            'recorded_at'             => optional($device->install_reported_at)->toIso8601String(),
            'update_now'              => $device->update_now_requested_at !== null,
        ]);
    }

    public function lanProfile(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $data = $request->validate([
            'lan_hostname'       => ['required', 'string', 'max:253'],
            'lan_ip'             => ['required', 'ip'],
            'https_port'         => ['required', 'integer', 'min:1', 'max:65535'],
            'ca_certificate_pem' => ['required', 'string', 'max:16384'],
            'ca_sha256'          => ['required', 'string', 'max:128'],
        ]);

        $device = $this->profiles->recordLanProfile($device, $data);

        return response()->json([
            'status'       => 'recorded',
            'lan_hostname' => $device->lan_hostname,
            'lan_ip'       => $device->lan_ip,
            'https_port'   => $device->lan_https_port,
            'ca_sha256'    => $device->lan_ca_sha256,
            'recorded_at'  => optional($device->lan_reported_at)->toIso8601String(),
        ]);
    }
}
