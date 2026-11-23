<?php

namespace App\Services\Edge\AutoUpdate;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * EDGE 0.9 (stream B) — the appliance's device-authenticated calls for the setup wizard and the auto-updater:
 *
 *   K3  POST /api/edge/setup/enrollment-assertions   (+ X-Edge-Setup-Token)  bulk Cloud-signed enrollment assertions
 *   K4  POST /api/edge/device/status                 install / update phase reports shown on the Cloud device row
 *   K5  POST /api/edge/device/lan-profile             LAN hostname / IP / port / PUBLIC CA certificate (counter setup)
 *   K6  GET  /api/edge/updates/latest                 the newest signed release for this device + a short-lived download URL
 *
 * Device auth = the paired identity (X-Edge-Device-ID + Bearer device secret, from appliance.env via edge.sync.*), exactly as
 * every other appliance -> Cloud call. HTTPS with certificate verification (never disabled); plain HTTP only on a flagged
 * LAB (EDGE_CLOUD_ALLOW_HTTP). The device secret is never sent to a download host other than the Cloud's own host, never
 * logged and never returned.
 */
class EdgeDeviceCloudClient
{
    public function deviceConfigured(): bool
    {
        return trim((string) config('edge.sync.device_id', '')) !== '' && trim((string) config('edge.sync.device_secret', '')) !== '';
    }

    /** K6 — {available:false} or the release document. Throws AUTO_UPDATE_CHECK_FAILED on transport / HTTP errors. */
    public function latestRelease(string $currentVersion): array
    {
        $url = $this->requireUrl(EdgeAutoUpdateSettings::latestUrl(), 'K6 updates/latest');
        try {
            $response = $this->device()->get($url, ['current_version' => $currentVersion]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('AUTO_UPDATE_CHECK_FAILED: Cloud unreachable (' . mb_substr($e->getMessage(), 0, 160) . ')', 0, $e);
        }
        if ($response->status() === 404 || $response->status() === 204) {
            // A Cloud without the releases endpoint yet (deploy ordering) is "nothing available", not an error.
            return ['available' => false, 'reason' => 'endpoint_' . $response->status()];
        }
        if (! $response->successful()) {
            throw new RuntimeException('AUTO_UPDATE_CHECK_FAILED: HTTP ' . $response->status() . ' ' . mb_substr((string) ($response->json('code') ?? $response->json('message') ?? ''), 0, 120));
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('AUTO_UPDATE_CHECK_FAILED: the Cloud answered with no JSON document.');
        }

        return $json;
    }

    /**
     * Stream the release zip to $dest (temp file first, then rename). Refuses a plain-HTTP URL (unless LAB), a body larger
     * than $maxBytes, and — when the release states size_bytes — a body of another size. Returns the byte count.
     */
    public function download(string $url, string $dest, int $maxBytes, ?int $expectedBytes = null): int
    {
        $this->assertTransport($url);
        $tmp = $dest . '.part';
        @unlink($tmp);
        $request = Http::withOptions(['sink' => $tmp, 'verify' => true])
            ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
            ->timeout(max(60, (int) config('edge.auto_update.download_timeout', 1800)));
        // The device credential travels ONLY to the Cloud's own host (a CDN / object-store URL gets no secret).
        if ($this->sameHostAsCloud($url) && $this->deviceConfigured()) {
            $request = $request->withHeaders($this->deviceHeaders());
        }
        try {
            $response = $request->get($url);
        } catch (ConnectionException $e) {
            @unlink($tmp);
            throw new RuntimeException('AUTO_UPDATE_DOWNLOAD_FAILED: ' . mb_substr($e->getMessage(), 0, 160), 0, $e);
        }
        if (! $response->successful()) {
            @unlink($tmp);
            throw new RuntimeException('AUTO_UPDATE_DOWNLOAD_FAILED: HTTP ' . $response->status());
        }
        clearstatcache(true, $tmp);
        $bytes = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($bytes <= 0 || $bytes > $maxBytes) {
            @unlink($tmp);
            throw new RuntimeException('AUTO_UPDATE_DOWNLOAD_SIZE: ' . $bytes . ' bytes (limit ' . $maxBytes . ').');
        }
        if ($expectedBytes !== null && $expectedBytes > 0 && $bytes !== $expectedBytes) {
            @unlink($tmp);
            throw new RuntimeException('AUTO_UPDATE_DOWNLOAD_SIZE: received ' . $bytes . ' bytes, the release states ' . $expectedBytes . '.');
        }
        @unlink($dest);
        if (! @rename($tmp, $dest)) {
            @unlink($tmp);
            throw new RuntimeException('AUTO_UPDATE_DOWNLOAD_FAILED: cannot move the download into place.');
        }

        return $bytes;
    }

    /** K4 — best effort: a missing endpoint / old Cloud / network error never fails the caller. */
    public function reportStatus(string $phase, string $version, bool $ok, string $message = ''): array
    {
        return $this->bestEffortPost(EdgeAutoUpdateSettings::statusUrl(), [
            'phase' => $phase,
            'version' => $version,
            'ok' => $ok,
            'message' => mb_substr($message, 0, 500),
            'at' => now()->toIso8601String(),
        ]);
    }

    /** K5 — public material only (hostname, IP, port, the PUBLIC CA certificate and its sha256). */
    public function reportLanProfile(array $profile): array
    {
        $allowed = array_intersect_key($profile, array_flip(['lan_hostname', 'lan_ip', 'https_port', 'ca_certificate_pem', 'ca_sha256']));

        return $this->bestEffortPost(EdgeAutoUpdateSettings::lanProfileUrl(), $allowed);
    }

    /**
     * K3 — bulk enrollment assertions. $selection = ['user_ids' => [..]] or ['all_active' => true]. The setup token is a
     * secret: it travels only in the X-Edge-Setup-Token header, never in a URL or a log line. Throws on refusal.
     *
     * @return array{assertions:array<int,array>,skipped:array<int,array>}
     */
    public function enrollmentAssertions(string $setupToken, array $selection): array
    {
        $url = $this->requireUrl(EdgeAutoUpdateSettings::enrollmentAssertionsUrl(), 'K3 setup/enrollment-assertions');
        try {
            $response = $this->device()->withHeaders(['X-Edge-Setup-Token' => $setupToken])->post($url, $selection);
        } catch (ConnectionException $e) {
            throw new RuntimeException('ENROLLMENT_ASSERTIONS_FAILED: Cloud unreachable (' . mb_substr($e->getMessage(), 0, 160) . ')', 0, $e);
        }
        if (! $response->successful()) {
            throw new RuntimeException('ENROLLMENT_ASSERTIONS_FAILED: HTTP ' . $response->status() . ' ' . mb_substr((string) ($response->json('code') ?? $response->json('message') ?? ''), 0, 160));
        }
        $json = $response->json();
        if (! is_array($json) || ! isset($json['assertions']) || ! is_array($json['assertions'])) {
            throw new RuntimeException('ENROLLMENT_ASSERTIONS_FAILED: the Cloud answered without an assertions list.');
        }

        return ['assertions' => array_values($json['assertions']), 'skipped' => array_values((array) ($json['skipped'] ?? []))];
    }

    private function bestEffortPost(?string $url, array $body): array
    {
        if ($url === null || ! $this->deviceConfigured()) {
            return ['sent' => false, 'reason' => $url === null ? 'no_endpoint' : 'device_not_paired'];
        }
        try {
            $this->assertTransport($url);
            $response = $this->device()->post($url, $body);

            return ['sent' => $response->successful(), 'status' => $response->status()];
        } catch (\Throwable $e) {
            return ['sent' => false, 'reason' => mb_substr($e->getMessage(), 0, 160)];
        }
    }

    private function device(): PendingRequest
    {
        if (! $this->deviceConfigured()) {
            throw new RuntimeException('DEVICE_NOT_PAIRED: this appliance has no device identity (run edge:local:pair).');
        }

        return Http::acceptJson()
            ->withOptions(['verify' => true])
            ->withHeaders($this->deviceHeaders())
            ->connectTimeout((int) config('edge.sync.connect_timeout', 10))
            ->timeout((int) config('edge.sync.timeout', 20));
    }

    private function deviceHeaders(): array
    {
        return [
            'X-Edge-Device-ID' => (string) config('edge.sync.device_id'),
            'Authorization' => 'Bearer ' . (string) config('edge.sync.device_secret'),
        ];
    }

    private function requireUrl(?string $url, string $what): string
    {
        if ($url === null || $url === '') {
            throw new RuntimeException('AUTO_UPDATE_NO_ENDPOINT: no Cloud URL for ' . $what . ' (EDGE_CLOUD_BASE_URL is set by edge:local:pair).');
        }
        $this->assertTransport($url);

        return $url;
    }

    private function assertTransport(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return;
        }
        if ($scheme === 'http' && EdgeAutoUpdateSettings::allowHttpCloud()) {
            return;
        }
        throw new RuntimeException('AUTO_UPDATE_INSECURE_URL: only HTTPS Cloud URLs are accepted (EDGE_CLOUD_ALLOW_HTTP is a LAB-only switch).');
    }

    private function sameHostAsCloud(string $url): bool
    {
        $base = EdgeAutoUpdateSettings::cloudBaseUrl();
        if ($base === null) {
            return false;
        }

        return strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower((string) parse_url($base, PHP_URL_HOST))
            && (int) (parse_url($url, PHP_URL_PORT) ?? 0) === (int) (parse_url($base, PHP_URL_PORT) ?? 0);
    }
}
