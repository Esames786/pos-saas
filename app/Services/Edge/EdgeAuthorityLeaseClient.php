<?php

namespace App\Services\Edge;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OFFLINE EDGE — the appliance's transport for the branch authority lease (heartbeat / handback) to the Cloud.
 * Same device authentication as the sale sync transport (device id header + bearer secret, TLS verified).
 * A transport failure is a controlled RuntimeException — the caller decides what a missed beat means.
 */
class EdgeAuthorityLeaseClient
{
    public function heartbeat(int $seq, string $edgeState): array
    {
        return $this->post((string) config('edge.authority.heartbeat_url'), ['seq' => $seq, 'edge_state' => $edgeState]);
    }

    public function handback(int $outboxPending, int $failedPermanent): array
    {
        return $this->post((string) config('edge.authority.handback_url'), ['outbox_pending' => $outboxPending, 'failed_permanent' => $failedPermanent]);
    }

    private function post(string $url, array $body): array
    {
        if ($url === '') {
            throw new RuntimeException('AUTHORITY_URL_MISSING');
        }
        try {
            $response = Http::withHeaders([
                    'X-Edge-Device-ID' => (string) config('edge.sync.device_id'),
                    'Authorization' => 'Bearer ' . (string) config('edge.sync.device_secret'),
                    'Accept' => 'application/json',
                ])
                ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
                ->timeout((int) config('edge.sync.timeout', 20))
                ->post($url, $body);
        } catch (\Throwable $e) {
            throw new RuntimeException('AUTHORITY_UNREACHABLE: ' . $e->getMessage(), 0, $e);
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json)) {
            throw new RuntimeException('AUTHORITY_REFUSED: HTTP ' . $response->status() . ' ' . (string) ($json['failure_code'] ?? $json['message'] ?? ''));
        }

        return $json;
    }
}
