<?php

namespace App\Services\Edge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * P5B §3 — appliance-side client of the Cloud backup recovery authority (device-authenticated, TLS).
 * Fetches the branch's current + retired backup wrapping keys; validates the shape; hands them to the
 * provisioner ONLY (never logged, never printed).
 */
class EdgeRecoveryKeyClient
{
    /** @return array{key_id:string,key:string,retired:array<string,string>} */
    public function fetch(string $cloudBaseUrl, ?string $forKeyId = null): array
    {
        $deviceId = (string) config('edge.sync.device_id');
        $secret = (string) config('edge.sync.device_secret');
        if ($deviceId === '' || $secret === '') {
            throw new RuntimeException('RECOVERY_NOT_PAIRED: this appliance holds no device identity — pair it first (edge:local:pair).');
        }
        $api = rtrim($cloudBaseUrl, '/') . '/api/edge/backup/recovery-keys';
        $body = $forKeyId !== null && $forKeyId !== '' ? ['key_id' => $forKeyId] : [];
        try {
            $response = $this->client($deviceId, $secret)->post($api, $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException('RECOVERY_CLOUD_UNREACHABLE: ' . $e->getMessage(), 0, $e);
        }
        if (! $response->successful()) {
            $json = $response->json() ?? [];
            throw new RuntimeException('RECOVERY_REFUSED: HTTP ' . $response->status() . ' ' . (string) ($json['code'] ?? $json['message'] ?? ''));
        }
        $json = $response->json();
        $current = is_array($json) ? ($json['current'] ?? null) : null;
        if (! is_array($current) || ! is_string($current['key_id'] ?? null) || ! self::isKey($current['key'] ?? null)) {
            throw new RuntimeException('RECOVERY_MATERIAL_INVALID: the Cloud did not return a well-formed current key.');
        }
        $retired = [];
        foreach ((array) ($json['retired'] ?? []) as $id => $key) {
            if (! is_string($id) || $id === '' || ! self::isKey($key)) {
                throw new RuntimeException('RECOVERY_MATERIAL_INVALID: a retired key is malformed.');
            }
            $retired[$id] = $key;
        }

        return ['key_id' => $current['key_id'], 'key' => $current['key'], 'retired' => $retired];
    }

    private static function isKey(mixed $b64): bool
    {
        if (! is_string($b64)) {
            return false;
        }
        $raw = base64_decode($b64, true);

        return $raw !== false && strlen($raw) === 32;
    }

    private function client(string $deviceId, string $secret): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['X-Edge-Device-ID' => $deviceId, 'Authorization' => 'Bearer ' . $secret])
            ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
            ->timeout(max(30, (int) config('edge.sync.timeout', 20)));
    }
}
