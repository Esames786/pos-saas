<?php

namespace App\Services\Edge;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PRODUCTIZATION GATE 0 — the Edge-side authenticated fetch of the Cloud ingestion truth for reconciliation.
 *
 * Mirrors EdgeSyncSender's transport posture exactly: device-auth headers, TLS verification ON (never
 * disabled), explicit connect/request timeouts. It is READ-ONLY — it fetches the Cloud reconciliation status
 * for a bounded set of sale_uuids and returns the `statuses` map that EdgeSyncReconciliationService consumes.
 * It posts nothing and reposts no business effect.
 */
class EdgeSyncReconciliationClient
{
    /**
     * Fetch the Cloud ingestion status for the given sale_uuids. Returns a map keyed by sale_uuid, or throws
     * on a transport/HTTP failure (the caller decides whether to retry later — reconciliation is best-effort).
     *
     * @param  array<int,string>  $saleUuids
     * @return array<string,array>
     */
    public function fetchStatuses(array $saleUuids): array
    {
        $url = (string) config('edge.sync.reconcile_url');
        if ($url === '') {
            throw new RuntimeException('RECONCILE_URL_MISSING: edge.sync.reconcile_url is not configured.');
        }
        if ($saleUuids === []) {
            return [];
        }

        $response = Http::withHeaders([
            'X-Edge-Device-ID' => (string) config('edge.sync.device_id'),
            'Authorization' => 'Bearer ' . (string) config('edge.sync.device_secret'),
        ])
            ->withOptions(['verify' => true]) // TLS verification ON — never disabled
            ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
            ->timeout((int) config('edge.sync.timeout', 20))
            ->asJson()
            ->post($url, ['sale_uuids' => array_values(array_unique($saleUuids))]);

        if (! $response->successful()) {
            throw new RuntimeException('RECONCILE_HTTP_' . $response->status() . ': reconciliation status query failed.');
        }

        return (array) ($response->json('statuses') ?? []);
    }
}
