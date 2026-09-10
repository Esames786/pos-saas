<?php

namespace App\Services\Edge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OFFLINE EDGE — Q: WARM STANDBY FRESHNESS, appliance side — pull the current config refresh package from the Cloud
 * (device-authenticated, TLS verified, bounded timeouts, secrets from config never from a command line).
 */
class EdgeConfigRefreshClient
{
    /** @return array{manifest:array, sections:array} */
    public function fetchPackage(): array
    {
        $url = (string) config('edge.standby.config_refresh_url', '');
        if ($url === '') {
            throw new RuntimeException('CONFIG_REFRESH_URL_MISSING: edge.standby.config_refresh_url is not configured.');
        }
        try {
            $response = Http::withHeaders([
                'X-Edge-Device-ID' => (string) config('edge.sync.device_id'),
                'Authorization' => 'Bearer ' . (string) config('edge.sync.device_secret'),
            ])
                ->withOptions(['verify' => true])
                ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
                ->timeout(max((int) config('edge.sync.timeout', 20), 60)) // a full config package is larger than a heartbeat
                ->post($url, []);
        } catch (ConnectionException $e) {
            throw new RuntimeException('CONFIG_REFRESH_UNREACHABLE: ' . $e->getMessage(), 0, $e);
        }
        $json = $response->json();
        if (! $response->ok() || ! is_array($json) || ($json['status'] ?? null) !== 'ok' || ! isset($json['manifest'], $json['sections'])) {
            throw new RuntimeException('CONFIG_REFRESH_REFUSED: HTTP ' . $response->status() . ' ' . (string) ($json['failure_code'] ?? $json['message'] ?? ''));
        }

        return ['manifest' => (array) $json['manifest'], 'sections' => (array) $json['sections']];
    }
}
