<?php

namespace App\Services\Edge\Cloud;

use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeSigningKeyStore;
use App\Support\TenantClock;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * EDGE 0.9 — the Cloud's operator-facing facts about a Branch Server device: the install/update status it reports (K4),
 * its PUBLIC LAN profile (K5), the auto-update maintenance window (K6) and the public setup material the wizard needs
 * (central Cloud URL, enrollment PUBLIC key, update key id). Nothing here touches pairing, the authority lease, the
 * Cloud fence or ingestion; nothing here ever returns or logs a private key.
 */
class DeviceProfileService
{
    public const DEFAULT_WINDOW_START = '03:00';
    public const DEFAULT_WINDOW_END   = '05:00';

    /** K4 phases the wizard / auto-updater may report. */
    public const PHASES = ['installing', 'paired', 'bootstrapped', 'enrolled', 'ready', 'updating', 'updated', 'update_failed', 'rolled_back'];

    /** Phases that close an "Update now" request (the appliance acted on it). */
    private const UPDATE_OUTCOME_PHASES = ['updated', 'update_failed', 'rolled_back'];

    private const MAX_PEM_BYTES = 16384;

    /* ── public setup material ──────────────────────────────────────────── */

    /**
     * The CENTRAL Cloud URL (the Edge API host) — never a tenant subdomain (Khatri lesson, 21 Nov). Scheme from app.url; a
     * NON-default port of app.url is kept when app.url's host IS the central domain (a Cloud on http://127.0.0.1:9831 says so;
     * CP5 pre-flight, 22 Nov). https://bingoopos.com (no port, or :443) stays exactly https://bingoopos.com.
     */
    public function cloudUrl(): string
    {
        $parts = parse_url((string) config('app.url')) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? '')) ?: 'https';
        $central = (string) config('tenancy.central_domain');
        $url = $scheme . '://' . $central;

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $default = $scheme === 'http' ? 80 : 443;
        if ($port !== null && $port !== $default && strcasecmp((string) ($parts['host'] ?? ''), $central) === 0) {
            $url .= ':' . $port;
        }

        return $url;
    }

    /** The enrollment PUBLIC key (base64) derived from the Cloud's private signing key, or null when not configured. */
    public function enrollmentPublicKey(): ?string
    {
        $secret = (string) config('edge.enrollment.signing_key');
        if ($secret === '' || ! EdgeEnrollmentCrypto::available()) {
            return null;
        }
        $sk = base64_decode($secret, true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }

        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($sk));
    }

    /**
     * The enrollment key id users compare with the Offline Counter wizard: the first 16 hex (upper case) of SHA-256 over the RAW
     * 32-byte public key — the same value stream D's wizard shows (key_sha256 prefix). Null when no signing key is configured.
     */
    public function enrollmentKeyId(): ?string
    {
        $pk = $this->enrollmentPublicKey();

        return $pk === null ? null : strtoupper(substr(hash('sha256', (string) base64_decode($pk, true)), 0, 16));
    }

    /** "AB:CD:…" — the CA fingerprint the way the Offline Counter wizard displays it. */
    public static function colonHex(?string $hex): string
    {
        return $hex ? implode(':', str_split(strtoupper($hex), 2)) : '';
    }

    /** The key id (fingerprint) of the configured update PUBLIC key, or null. */
    public function updateKeyId(): ?string
    {
        $pk = (string) config('edge.update.public_key');
        if ($pk === '') {
            return null;
        }
        $raw = base64_decode($pk, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        return EdgeSigningKeyStore::keyId($pk);
    }

    /* ── K6 maintenance window ──────────────────────────────────────────── */

    /**
     * The device's auto-update window in the branch BUSINESS timezone (TenantClock: branch → Asia/Karachi). The tenant of
     * the branch must be active when $branch is not given.
     *
     * @return array{start:string, end:string, tz:string, default:bool}
     */
    public function maintenanceWindow(?EdgeDevice $device, ?Branch $branch = null): array
    {
        $start = $device?->maintenance_window_start;
        $end = $device?->maintenance_window_end;
        $isDefault = ! (self::isClock($start) && self::isClock($end));
        if ($branch === null && $device !== null) {
            try {
                $branch = Branch::find((int) $device->branch_id);
            } catch (\Throwable $e) {
                $branch = null;
            }
        }

        return [
            'start'   => $isDefault ? self::DEFAULT_WINDOW_START : (string) $start,
            'end'     => $isDefault ? self::DEFAULT_WINDOW_END : (string) $end,
            'tz'      => app(TenantClock::class)->businessTimezone($branch),
            'default' => $isDefault,
        ];
    }

    public function setMaintenanceWindow(EdgeDevice $device, ?string $start, ?string $end, int $userId): void
    {
        if ($start === null && $end === null) {
            $device->forceFill(['maintenance_window_start' => null, 'maintenance_window_end' => null])->save();
        } else {
            if (! self::isClock($start) || ! self::isClock($end) || $start === $end) {
                throw EdgeSetupException::of(EdgeSetupException::LAN_PROFILE_INVALID, 'The maintenance window must be two different times (HH:MM).');
            }
            $device->forceFill(['maintenance_window_start' => $start, 'maintenance_window_end' => $end])->save();
        }
        Log::info('[edge-setup-audit] edge.device.maintenance_window', [
            'tenant_id' => $device->tenant_id, 'branch_id' => $device->branch_id, 'device_uuid' => $device->public_uuid,
            'start' => $device->maintenance_window_start, 'end' => $device->maintenance_window_end, 'user_id' => $userId,
        ]);
    }

    public static function isClock(?string $v): bool
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;
    }

    /* ── K6 update now ──────────────────────────────────────────────────── */

    public function requestUpdateNow(EdgeDevice $device, int $userId): void
    {
        $device->forceFill(['update_now_requested_at' => now()])->save();
        Log::info('[edge-setup-audit] edge.device.update_now_requested', [
            'tenant_id' => $device->tenant_id, 'branch_id' => $device->branch_id, 'device_uuid' => $device->public_uuid, 'user_id' => $userId,
        ]);
    }

    /* ── K4 device status ───────────────────────────────────────────────── */

    /** @param array{phase:string, version?:?string, ok:bool, message?:?string} $in */
    public function recordStatus(EdgeDevice $device, array $in): EdgeDevice
    {
        $phase = (string) $in['phase'];
        $attrs = [
            'install_phase'       => $phase,
            'install_ok'          => (bool) $in['ok'],
            'install_message'     => isset($in['message']) && $in['message'] !== null ? Str::limit(trim((string) $in['message']), 490, '…') : null,
            // Server time: the shop PC clock may be wrong (Khatri, 21 Nov: 12 h off) — the appliance's `at` is informational only.
            'install_reported_at' => now(),
        ];
        if (isset($in['version']) && is_string($in['version']) && trim($in['version']) !== '') {
            $attrs['reported_version'] = trim($in['version']);
        }
        if (in_array($phase, self::UPDATE_OUTCOME_PHASES, true) && $device->update_now_requested_at !== null) {
            $attrs['update_now_requested_at'] = null;   // the appliance acted on the owner's request
        }
        $device->forceFill($attrs)->save();

        Log::info('[edge-setup-audit] edge.device.status_reported', [
            'tenant_id' => $device->tenant_id, 'branch_id' => $device->branch_id, 'device_uuid' => $device->public_uuid,
            'phase' => $phase, 'ok' => (bool) $in['ok'], 'version' => $attrs['reported_version'] ?? $device->reported_version,
        ]);

        return $device;
    }

    /* ── K5 LAN profile ─────────────────────────────────────────────────── */

    /**
     * Validate and store the appliance's PUBLIC LAN profile. The certificate must be ONE X.509 CA certificate
     * (basicConstraints CA:TRUE, currently valid) whose SHA-256 fingerprint (over the DER bytes) equals ca_sha256.
     *
     * @param array{lan_hostname:string, lan_ip:string, https_port:int, ca_certificate_pem:string, ca_sha256:string} $in
     */
    public function recordLanProfile(EdgeDevice $device, array $in): EdgeDevice
    {
        [$pem, $sha] = $this->validateCaCertificate((string) $in['ca_certificate_pem'], (string) $in['ca_sha256']);
        $hostname = strtolower(trim((string) $in['lan_hostname']));
        if (! self::isHostname($hostname)) {
            throw EdgeSetupException::of(EdgeSetupException::LAN_PROFILE_INVALID, 'lan_hostname is not a valid host name.', ['field' => 'lan_hostname']);
        }
        $ip = trim((string) $in['lan_ip']);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw EdgeSetupException::of(EdgeSetupException::LAN_PROFILE_INVALID, 'lan_ip is not a valid IP address.', ['field' => 'lan_ip']);
        }
        $port = (int) $in['https_port'];
        if ($port < 1 || $port > 65535) {
            throw EdgeSetupException::of(EdgeSetupException::LAN_PROFILE_INVALID, 'https_port is out of range.', ['field' => 'https_port']);
        }

        $device->forceFill([
            'lan_hostname' => $hostname, 'lan_ip' => $ip, 'lan_https_port' => $port,
            'lan_ca_pem' => $pem, 'lan_ca_sha256' => $sha, 'lan_reported_at' => now(),
        ])->save();

        Log::info('[edge-setup-audit] edge.device.lan_profile_reported', [
            'tenant_id' => $device->tenant_id, 'branch_id' => $device->branch_id, 'device_uuid' => $device->public_uuid,
            'lan_hostname' => $hostname, 'lan_ip' => $ip, 'https_port' => $port, 'ca_sha256' => $sha,
        ]);

        return $device;
    }

    /**
     * @return array{0:string, 1:string} [normalised PEM, lowercase hex sha256 of the DER]
     */
    public function validateCaCertificate(string $pem, string $claimedSha): array
    {
        $fail = fn (string $why) => EdgeSetupException::of(EdgeSetupException::LAN_PROFILE_INVALID, $why, ['field' => 'ca_certificate_pem']);
        if ($pem === '' || strlen($pem) > self::MAX_PEM_BYTES) {
            throw $fail('ca_certificate_pem is empty or too large.');
        }
        if (stripos($pem, 'PRIVATE KEY') !== false) {
            throw $fail('ca_certificate_pem must contain public material only.');
        }
        if (substr_count($pem, '-----BEGIN CERTIFICATE-----') !== 1 || substr_count($pem, '-----END CERTIFICATE-----') !== 1) {
            throw $fail('ca_certificate_pem must contain exactly one PEM certificate.');
        }
        if (! function_exists('openssl_x509_read')) {
            throw EdgeSetupException::of(EdgeSetupException::NOT_CONFIGURED, 'The Cloud cannot validate certificates (openssl missing).');
        }
        $cert = @openssl_x509_read($pem);
        if ($cert === false) {
            throw $fail('ca_certificate_pem is not a readable X.509 certificate.');
        }
        $info = openssl_x509_parse($cert);
        $bc = is_array($info) ? (string) ($info['extensions']['basicConstraints'] ?? '') : '';
        if (! preg_match('/\bCA:TRUE\b/i', $bc)) {
            throw $fail('ca_certificate_pem is not a CA certificate (basicConstraints CA:TRUE required).');
        }
        $now = time();
        if (! isset($info['validFrom_time_t'], $info['validTo_time_t']) || (int) $info['validTo_time_t'] <= $now || (int) $info['validFrom_time_t'] > $now + 86400) {
            throw $fail('ca_certificate_pem is not currently valid.');
        }
        $sha = strtolower((string) openssl_x509_fingerprint($cert, 'sha256'));
        $claimed = strtolower(str_replace([':', ' ', '-'], '', trim($claimedSha)));
        if (! preg_match('/^[0-9a-f]{64}$/', $claimed)) {
            throw EdgeSetupException::of(EdgeSetupException::LAN_PROFILE_INVALID, 'ca_sha256 must be a SHA-256 hex fingerprint.', ['field' => 'ca_sha256']);
        }
        if (! hash_equals($sha, $claimed)) {
            throw EdgeSetupException::of(EdgeSetupException::LAN_PROFILE_INVALID, 'ca_sha256 does not match the certificate (SHA-256 over the DER bytes).', ['field' => 'ca_sha256']);
        }
        $out = '';
        openssl_x509_export($cert, $out);

        return [trim($out) . "\n", $sha];
    }

    public static function isHostname(string $h): bool
    {
        return strlen($h) >= 1 && strlen($h) <= 253
            && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $h) === 1;
    }

    /**
     * K7 — the LAN profile array handed to the counter-setup renderer (PUBLIC material only).
     *
     * @return array{lan_hostname:?string, lan_ip:?string, https_port:?int, ca_certificate_pem:?string, ca_sha256:?string, device_uuid:string, branch_id:int, reported_at:?string}
     */
    public function lanProfile(EdgeDevice $device): array
    {
        return [
            'lan_hostname'       => $device->lan_hostname,
            'lan_ip'             => $device->lan_ip,
            'https_port'         => $device->lan_https_port,
            'ca_certificate_pem' => $device->lan_ca_pem,
            'ca_sha256'          => $device->lan_ca_sha256,
            'device_uuid'        => (string) $device->public_uuid,
            'branch_id'          => (int) $device->branch_id,
            'reported_at'        => optional($device->lan_reported_at)->toIso8601String(),
        ];
    }
}
