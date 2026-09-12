<?php

namespace App\Services\Edge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * P4 FIRST-INSTALL — the appliance side of BRANCH-DEVICE-PAIRING-1.
 *
 * Exchanges a one-time pairing code (issued by the tenant's Offline Edge page) for THIS appliance's device identity:
 * the appliance generates the device secret LOCALLY, sends only its sha256 to the Cloud (POST /api/edge/pair) and
 * keeps the plaintext for its own env file. The Cloud never sees the secret; the secret never appears on argv or in a
 * log. Returns the Cloud's device meta + the generated secret to the caller (the pair command persists it).
 */
class EdgeCloudPairingClient
{
    public function pair(string $cloudBaseUrl, string $pairingCode, string $installationUuid, ?string $deviceName): array
    {
        $url = rtrim($cloudBaseUrl, '/') . '/api/edge/pair';
        $secret = bin2hex(random_bytes(32));
        $body = [
            'pairing_code' => trim($pairingCode),
            'installation_uuid' => $installationUuid,
            'device_name' => $deviceName,
            'device_secret_hash' => hash('sha256', $secret),
            'app_version' => (string) config('edge.app_version'),
            'schema_version' => (string) config('edge.bootstrap_schema'),
        ];
        try {
            $response = Http::acceptJson()
                ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
                ->timeout((int) config('edge.sync.timeout', 20))
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException('PAIR_CLOUD_UNREACHABLE: ' . $e->getMessage(), 0, $e);
        }
        $json = $response->json() ?? [];
        if (! $response->successful()) {
            throw new RuntimeException('PAIR_REFUSED: HTTP ' . $response->status() . ' ' . (string) ($json['code'] ?? $json['failure_code'] ?? $json['message'] ?? ''));
        }
        $deviceId = (string) ($json['device_id'] ?? $json['public_uuid'] ?? '');
        if ($deviceId === '') {
            throw new RuntimeException('PAIR_INVALID_RESPONSE: the Cloud did not return a device identity.');
        }

        return ['device_id' => $deviceId, 'device_secret' => $secret, 'meta' => $json];
    }

    /** Prove the persisted identity authenticates (GET /api/edge/device/me). Returns the safe device meta. */
    public function me(string $cloudBaseUrl, string $deviceId, string $deviceSecret): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-Edge-Device-ID' => $deviceId, 'Authorization' => 'Bearer ' . $deviceSecret])
                ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
                ->timeout((int) config('edge.sync.timeout', 20))
                ->get(rtrim($cloudBaseUrl, '/') . '/api/edge/device/me');
        } catch (ConnectionException $e) {
            throw new RuntimeException('PAIR_CLOUD_UNREACHABLE: ' . $e->getMessage(), 0, $e);
        }
        if (! $response->successful()) {
            throw new RuntimeException('PAIR_VERIFY_FAILED: HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }
}
