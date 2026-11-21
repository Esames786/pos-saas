<?php

namespace App\Services\Edge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** OFFLINE EDGE — F3: the appliance pulls the Cloud's purchase-return projection (device-authenticated, standby only). */
class EdgePurchaseReturnCacheClient
{
    public function fetchPackage(): array
    {
        $url = (string) config('edge.standby.purchase_return_refresh_url', '');
        if ($url === '') {
            throw new RuntimeException('PURCHASE_RETURN_REFRESH_URL_MISSING: edge.standby.purchase_return_refresh_url is not configured.');
        }
        try {
            $response = Http::withHeaders([
                'X-Edge-Device-ID' => (string) config('edge.sync.device_id'),
                'Authorization' => 'Bearer ' . (string) config('edge.sync.device_secret'),
            ])
                ->withOptions(['verify' => true])
                ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
                ->timeout(max((int) config('edge.sync.timeout', 20), 60))
                ->post($url, []);
        } catch (ConnectionException $e) {
            throw new RuntimeException('PURCHASE_RETURN_REFRESH_UNREACHABLE: ' . $e->getMessage(), 0, $e);
        }
        $json = $response->json();
        if (! $response->ok() || ! is_array($json) || ($json['status'] ?? null) !== 'ok' || ! isset($json['package'])) {
            throw new RuntimeException('PURCHASE_RETURN_REFRESH_REFUSED: HTTP ' . $response->status() . ' ' . (string) ($json['failure_code'] ?? $json['message'] ?? ''));
        }

        return (array) $json['package'];
    }
}
