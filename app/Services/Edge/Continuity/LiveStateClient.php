<?php

namespace App\Services\Edge\Continuity;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * EDGE 0.9 CONTINUITY — appliance side of contract K1: pull the branch's LIVE STATE (open shifts, open table sessions + unpaid
 * orders, held bills) from the Cloud, `GET /api/edge/live-state?since=<revision>`.
 *
 * Same transport discipline as every other Edge → Cloud client (EdgeConfigRefreshClient): the paired device's headers
 * (X-Edge-Device-ID + Bearer device secret, read from config — never argv, never logged), TLS verification ALWAYS on, bounded
 * connect/total timeouts (the authority tick is time-boxed; a slow Cloud can never stall a heartbeat).
 *
 * URL: `edge.continuity.live_state_url` when provisioned, else derived from the provisioned config-refresh URL (same Cloud API
 * base: `…/api/edge/config/refresh` → `…/api/edge/live-state`) so an appliance paired before 0.9 needs no re-pairing.
 */
class LiveStateClient
{
    public function url(): string
    {
        $explicit = trim((string) config('edge.continuity.live_state_url', ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $refresh = trim((string) config('edge.standby.config_refresh_url', ''));
        if ($refresh === '') {
            return '';
        }

        return (string) preg_replace('#/config/refresh/?$#', '/live-state', $refresh);
    }

    /**
     * @return array<string,mixed> the decoded K1 body: a full package {revision, generated_at, branch_id, shifts, table_sessions,
     *                             held_sales} or {unchanged: true, revision}
     */
    public function fetch(?string $since = null): array
    {
        $url = $this->url();
        if ($url === '' || ! str_ends_with((string) parse_url($url, PHP_URL_PATH), '/live-state')) {
            throw new RuntimeException('LIVE_STATE_URL_MISSING: no live-state URL is provisioned on this appliance.');
        }
        try {
            $response = Http::withHeaders([
                'X-Edge-Device-ID' => (string) config('edge.sync.device_id'),
                'Authorization' => 'Bearer ' . (string) config('edge.sync.device_secret'),
                'Accept' => 'application/json',
            ])
                ->withOptions(['verify' => true])
                ->connectTimeout(max(1, min((int) config('edge.sync.connect_timeout', 10), (int) config('edge.continuity.connect_timeout', 5))))
                ->timeout(max(2, (int) config('edge.continuity.timeout', 15)))
                ->get($url, $since !== null && $since !== '' ? ['since' => $since] : []);
        } catch (ConnectionException $e) {
            throw new RuntimeException('LIVE_STATE_UNREACHABLE: ' . mb_substr($e->getMessage(), 0, 200), 0, $e);
        }
        $json = $response->json();
        if (! $response->ok() || ! is_array($json)) {
            throw new RuntimeException('LIVE_STATE_REFUSED: HTTP ' . $response->status() . ' ' . (is_array($json) ? (string) ($json['failure_code'] ?? $json['message'] ?? '') : ''));
        }
        if (! empty($json['unchanged'])) {
            if (! isset($json['revision']) || ! is_scalar($json['revision'])) {
                throw new RuntimeException('LIVE_STATE_MALFORMED: an unchanged answer must name its revision.');
            }

            return ['unchanged' => true, 'revision' => (string) $json['revision']];
        }
        if (! isset($json['revision'], $json['branch_id']) || ! is_array($json['shifts'] ?? null) || ! is_array($json['table_sessions'] ?? null) || ! is_array($json['held_sales'] ?? null)) {
            throw new RuntimeException('LIVE_STATE_MALFORMED: the live-state package is missing revision / branch_id / shifts / table_sessions / held_sales.');
        }

        return $json;
    }
}
