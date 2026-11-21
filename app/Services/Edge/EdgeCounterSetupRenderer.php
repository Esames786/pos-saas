<?php

namespace App\Services\Edge;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Edge 0.9 stream D — renders the per-branch COUNTER SETUP (contract K7 of docs/plans/edge-0.9-impact-map-2026-11-21.md).
 *
 * - renderWindows(): a one-file PowerShell script generalised from the script proven at Khatri on 21 Nov 2026
 *   (docs/operator-khatri/Setup-KhatriCounter.ps1): branch CA -> LocalMachine\Root, idempotent hosts line, three
 *   Public-Desktop shortcuts with the Bingoo icon, a TLS check by name and by IP with FULL certificate validation,
 *   bilingual (English + Roman Urdu) messages, self-unblock, self-elevation that explains non-admin users, window stays
 *   open, exit code 0/1.
 * - phoneOnboarding(): the data for the Android / iPhone page (login URL by IP, branch-CA download route, per-OS steps),
 *   rendered by resources/views/tenant/offline-edge/partials/phone-onboarding.blade.php.
 *
 * Input = the device's LAN profile (contract K5, PUBLIC material only):
 *   lan_hostname, lan_ip, lan_https_port, lan_ca_pem, lan_ca_sha256   (K5 wire names https_port / ca_certificate_pem /
 *   ca_sha256 are accepted as aliases; optional branch_id feeds the CA download route parameter).
 * Every value is validated and the output is pure ASCII, so nothing from the profile can break out of a PowerShell
 * literal, the hosts file or a .url file. The output never contains a certificate-validation bypass.
 *
 * Implements App\Contracts\Edge\CounterSetupRenderer (stream C) — the `implements` clause is added at integration.
 */
class EdgeCounterSetupRenderer
{
    public const TEMPLATE = 'resources/edge/counter-setup/Setup-Counter.ps1.stub';

    public const LOGO = 'public/assets/img/logo-small.png';

    public const ICON_SIZE = 128;

    public const LOGIN_PATH = '/edge/local/login';

    public const STATUS_PATH = '/edge/local/pos/health';

    public const HEALTH_PATH = '/edge/local/health';

    /** Stream C's route that serves the branch CA certificate (parameter `branch`). */
    public const CA_DOWNLOAD_ROUTE = 'tenant.offline-edge.branch.ca';

    public const CA_DOWNLOAD_ROUTE_PARAMETER = 'branch';

    public const CA_MIME_TYPE = 'application/x-x509-ca-cert';

    private const MAX_LABEL = 60;

    private static ?string $iconCache = null;

    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? base_path(), '/\\');
    }

    /**
     * The counter setup script for one branch (ASCII, CRLF line endings).
     *
     * @param  array<string, mixed>  $lanProfile
     */
    public function renderWindows(array $lanProfile, string $onlinePosUrl, string $branchLabel): string
    {
        $p = $this->normalizeLanProfile($lanProfile);
        $online = $this->validateOnlinePosUrl($onlinePosUrl);
        $label = $this->sanitizeLabel($branchLabel);

        $template = @file_get_contents($this->basePath . DIRECTORY_SEPARATOR . self::TEMPLATE);
        if (! is_string($template) || $template === '') {
            throw new RuntimeException('Counter setup template is missing: ' . self::TEMPLATE);
        }

        $hostsLine = $p['lan_ip'] . '  ' . $p['lan_hostname'] . '  # Bingoo Edge branch server';
        $octets = explode('.', $p['lan_ip']);

        $script = strtr($template, [
            '{{BINGOO_BRANCH_LABEL_TEXT}}' => $label,
            '{{BINGOO_BRANCH_LABEL}}' => self::psLiteral($label),
            '{{BINGOO_FILE_NAME}}' => $this->windowsFileName($branchLabel),
            '{{BINGOO_GENERATED_AT}}' => gmdate('Y-m-d H:i') . ' UTC',
            '{{BINGOO_EDGE_IP}}' => $p['lan_ip'],
            '{{BINGOO_EDGE_HOST}}' => $p['lan_hostname'],
            '{{BINGOO_EDGE_PORT}}' => (string) $p['lan_https_port'],
            '{{BINGOO_HOSTS_LINE}}' => $hostsLine,
            '{{BINGOO_LAN_HINT}}' => $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.x',
            '{{BINGOO_URL_ONLINE}}' => self::psLiteral($online),
            '{{BINGOO_URL_OFFLINE}}' => $this->edgeUrl($p['lan_hostname'], $p['lan_https_port'], self::LOGIN_PATH),
            '{{BINGOO_URL_STATUS}}' => $this->edgeUrl($p['lan_hostname'], $p['lan_https_port'], self::STATUS_PATH),
            '{{BINGOO_CHECK_BY_NAME}}' => $this->edgeUrl($p['lan_hostname'], $p['lan_https_port'], self::HEALTH_PATH),
            '{{BINGOO_CHECK_BY_IP}}' => $this->edgeUrl($p['lan_ip'], $p['lan_https_port'], self::HEALTH_PATH),
            '{{BINGOO_CA_NAME}}' => self::psLiteral($p['ca_name']),
            '{{BINGOO_CA_SHA1}}' => $p['ca_sha1'],
            '{{BINGOO_CA_SHA256}}' => $p['ca_sha256'],
            '{{BINGOO_CA_PEM}}' => $p['ca_pem'],
            '{{BINGOO_ICON_B64}}' => base64_encode($this->iconBytes()),
        ]);

        if (str_contains($script, '{{BINGOO_')) {
            throw new RuntimeException('Counter setup template has an unknown placeholder.');
        }
        if (preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $script)) {
            throw new RuntimeException('Counter setup script must be pure ASCII (Windows PowerShell 5.1 reads BOM-less files as ANSI).');
        }

        return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $script));
    }

    /**
     * Data for the phone onboarding page (Android + iPhone): login URL by IP (phones have no hosts file), the branch-CA
     * download route (stream C, parameter `branch`), certificate identity for a visual check, and bilingual steps.
     *
     * @param  array<string, mixed>  $lanProfile
     * @return array<string, mixed>
     */
    public function phoneOnboarding(array $lanProfile): array
    {
        $p = $this->normalizeLanProfile($lanProfile);
        $loginUrl = $this->edgeUrl($p['lan_ip'], $p['lan_https_port'], self::LOGIN_PATH);
        $caName = $p['ca_name'];

        return [
            'branch_server' => [
                'ip' => $p['lan_ip'],
                'hostname' => $p['lan_hostname'],
                'https_port' => $p['lan_https_port'],
            ],
            'login_url' => $loginUrl,
            'status_url' => $this->edgeUrl($p['lan_ip'], $p['lan_https_port'], self::STATUS_PATH),
            'qr_text' => $loginUrl,
            'ca' => [
                'download_route' => self::CA_DOWNLOAD_ROUTE,
                'download_route_parameter' => self::CA_DOWNLOAD_ROUTE_PARAMETER,
                'download_route_parameters' => $p['branch_id'] !== null ? [self::CA_DOWNLOAD_ROUTE_PARAMETER => $p['branch_id']] : [],
                'mime_type' => self::CA_MIME_TYPE,
                'file_name' => 'bingoo-branch-ca.crt',
                'name' => $caName,
                'sha256' => self::colonHex($p['ca_sha256']),
                'sha1' => self::colonHex($p['ca_sha1']),
                'valid_until' => gmdate('Y-m-d', $p['ca_valid_to']),
            ],
            'platforms' => [
                'android' => [
                    'name' => 'Android',
                    'steps' => [
                        self::step(
                            'Connect the phone to the shop Wi-Fi (the same network as the Branch Server, ' . $p['lan_ip'] . ').',
                            'Phone ko dukaan ke Wi-Fi se connect karein (wohi network jis par Branch Server hai, ' . $p['lan_ip'] . ').'
                        ),
                        self::step(
                            'Scan QR 1 with the camera and open it in Chrome: the file bingoo-branch-ca.crt downloads.',
                            'QR 1 camera se scan karke Chrome mein kholein: bingoo-branch-ca.crt file download ho jayegi.'
                        ),
                        self::step(
                            'Open Settings, search "CA certificate" and open it (usually Security -> More security settings -> Encryption & credentials -> Install a certificate -> CA certificate; on Samsung: Security and privacy -> More security settings -> Install from device storage -> CA certificate).',
                            'Settings kholein, "CA certificate" search karein aur kholein (aam tor par Security -> More security settings -> Encryption & credentials -> Install a certificate -> CA certificate; Samsung par: Security and privacy -> More security settings -> Install from device storage -> CA certificate).'
                        ),
                        self::step(
                            'Tap "Install anyway", pick bingoo-branch-ca.crt from Downloads and confirm with the phone screen lock. Its name is "' . $caName . '".',
                            '"Install anyway" dabayein, Downloads se bingoo-branch-ca.crt chunein aur phone ke screen lock se confirm karein. Iska naam "' . $caName . '" hai.'
                        ),
                        self::step(
                            'Scan QR 2: the Bingoo login page opens in Chrome with a padlock and NO warning. Chrome menu -> "Add to Home screen" for one-tap access.',
                            'QR 2 scan karein: Bingoo login page Chrome mein taale (padlock) ke saath BAGHAIR warning ke khulega. Chrome menu -> "Add to Home screen" se ek tap wala icon banayein.'
                        ),
                    ],
                ],
                'ios' => [
                    'name' => 'iPhone / iPad',
                    'steps' => [
                        self::step(
                            'Connect the iPhone to the shop Wi-Fi (the same network as the Branch Server, ' . $p['lan_ip'] . ').',
                            'iPhone ko dukaan ke Wi-Fi se connect karein (wohi network jis par Branch Server hai, ' . $p['lan_ip'] . ').'
                        ),
                        self::step(
                            'Scan QR 1 with the Camera and open it in SAFARI (not Chrome). Tap "Allow" to download the configuration profile.',
                            'QR 1 Camera se scan karke SAFARI mein kholein (Chrome mein nahin). Configuration profile download karne ke liye "Allow" dabayein.'
                        ),
                        self::step(
                            'Open Settings -> "Profile Downloaded" (near the top) -> Install -> enter the iPhone passcode -> Install -> Done.',
                            'Settings -> "Profile Downloaded" (oopar ki taraf) -> Install -> iPhone ka passcode -> Install -> Done.'
                        ),
                        self::step(
                            'Open Settings -> General -> About -> Certificate Trust Settings and switch ON "' . $caName . '" -> Continue. (Without this step Safari still warns.)',
                            'Settings -> General -> About -> Certificate Trust Settings mein "' . $caName . '" ko ON karein -> Continue. (Is step ke baghair Safari warning dega.)'
                        ),
                        self::step(
                            'Scan QR 2: the Bingoo login page opens with a padlock and NO warning. Share -> "Add to Home Screen" for one-tap access.',
                            'QR 2 scan karein: Bingoo login page taale (padlock) ke saath BAGHAIR warning ke khulega. Share -> "Add to Home Screen" se ek tap wala icon banayein.'
                        ),
                    ],
                ],
            ],
            'warnings' => [
                self::step(
                    'If the phone says "Your connection is not private" (or "This connection is not private"), STOP: never tap Advanced / Proceed / Visit website. Install the certificate first (QR 1).',
                    'Agar phone "Your connection is not private" dikhaye to RUK jayein: Advanced / Proceed / Visit website kabhi na dabayein. Pehle certificate install karein (QR 1).'
                ),
                self::step(
                    'Offline login works only inside the shop network. Log in with the employee code and the Edge PIN (the Cloud login does not work there).',
                    'Offline login sirf dukaan ke network ke andar chalta hai. Employee code aur Edge PIN se login karein (Cloud wala login wahan nahin chalta).'
                ),
            ],
        ];
    }

    /** Download file name, e.g. "Setup-Khatri-Biryani-Counter.ps1". */
    public function windowsFileName(string $branchLabel): string
    {
        $slug = Str::slug(Str::ascii($branchLabel));
        $slug = implode('-', array_map('ucfirst', array_filter(explode('-', $slug), fn ($w) => $w !== '')));
        $slug = rtrim(substr($slug, 0, 40), '-');

        return 'Setup-' . ($slug !== '' ? $slug : 'Branch') . '-Counter.ps1';
    }

    /**
     * Validated, canonical LAN profile. Throws InvalidArgumentException with an operator-readable message.
     *
     * @param  array<string, mixed>  $lanProfile
     * @return array{lan_hostname: string, lan_ip: string, lan_https_port: int, ca_pem: string, ca_sha1: string, ca_sha256: string, ca_name: string, ca_valid_to: int, branch_id: int|string|null}
     */
    public function normalizeLanProfile(array $lanProfile): array
    {
        $host = strtolower(trim((string) ($lanProfile['lan_hostname'] ?? '')));
        $ip = trim((string) ($lanProfile['lan_ip'] ?? ''));
        $port = $lanProfile['lan_https_port'] ?? $lanProfile['https_port'] ?? null;
        $pem = (string) ($lanProfile['lan_ca_pem'] ?? $lanProfile['ca_certificate_pem'] ?? '');
        $expectedSha256 = (string) ($lanProfile['lan_ca_sha256'] ?? $lanProfile['ca_sha256'] ?? '');
        $branchId = $lanProfile['branch_id'] ?? null;

        $this->assertHostname($host);
        $this->assertLanIp($ip);
        $port = $this->assertPort($port);
        $ca = $this->parseCaCertificate($pem);

        if (trim($expectedSha256) !== '') {
            $normalized = strtoupper(preg_replace('/[\s:]/', '', $expectedSha256));
            if (! preg_match('/^[0-9A-F]{64}$/', $normalized)) {
                throw new InvalidArgumentException('lan_ca_sha256 must be the SHA-256 fingerprint of the CA certificate (64 hex digits).');
            }
            if (! hash_equals($ca['sha256'], $normalized)) {
                throw new InvalidArgumentException('lan_ca_sha256 does not match the CA certificate in lan_ca_pem.');
            }
        }

        if ($branchId !== null && ! (is_int($branchId) || (is_string($branchId) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $branchId)))) {
            throw new InvalidArgumentException('branch_id must be an id.');
        }

        return [
            'lan_hostname' => $host,
            'lan_ip' => $ip,
            'lan_https_port' => $port,
            'ca_pem' => $ca['pem'],
            'ca_sha1' => $ca['sha1'],
            'ca_sha256' => $ca['sha256'],
            'ca_name' => $ca['name'],
            'ca_valid_to' => $ca['valid_to'],
            'branch_id' => $branchId,
        ];
    }

    /** The Bingoo logo as a 128 px PNG-in-ICO (generated from public/assets/img/logo-small.png with GD, cached per process). */
    public function iconBytes(): string
    {
        if (self::$iconCache !== null) {
            return self::$iconCache;
        }
        if (! function_exists('imagecreatefrompng')) {
            throw new RuntimeException('The PHP GD extension is required to build the counter setup icon.');
        }
        $src = @imagecreatefrompng($this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::LOGO));
        if ($src === false) {
            throw new RuntimeException('Bingoo logo is missing or unreadable: ' . self::LOGO);
        }
        $size = self::ICON_SIZE;
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min($size / $w, $size / $h);
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);

        ob_start();
        imagepng($dst, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        // ICONDIR (reserved, type 1 = icon, 1 image) + ICONDIRENTRY (128 px is stored as 128; PNG payload at offset 22).
        $ico = pack('vvv', 0, 1, 1) . pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($png), 22) . $png;

        return self::$iconCache = $ico;
    }

    // ---- validation ----------------------------------------------------------------------------------------------

    private function assertHostname(string $host): void
    {
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if ($host === '' || strlen($host) > 253 || ! preg_match('/^' . $label . '(?:\.' . $label . ')*$/', $host)) {
            throw new InvalidArgumentException('lan_hostname must be a plain DNS name such as bingoo-edge.local.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || preg_match('/^[0-9.]+$/', $host)) {
            throw new InvalidArgumentException('lan_hostname must be the Branch Server name on the shop network, not localhost or an IP.');
        }
    }

    private function assertLanIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('lan_ip must be an IPv4 address such as 192.168.100.52.');
        }
        // Must be a private shop-network address (10/8, 172.16/12, 192.168/16): never public, loopback or link-local.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) !== false
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException('lan_ip must be a private shop-network address (10.x, 172.16-31.x or 192.168.x).');
        }
    }

    private function assertPort(mixed $port): int
    {
        if (is_string($port) && preg_match('/^\d{1,5}$/', trim($port))) {
            $port = (int) trim($port);
        }
        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('lan_https_port must be a TCP port number (1-65535).');
        }

        return $port;
    }

    /**
     * Exactly one PEM certificate that is a self-signed, unexpired CA. No private key material is accepted.
     *
     * @return array{pem: string, sha1: string, sha256: string, name: string, valid_to: int}
     */
    private function parseCaCertificate(string $pem): array
    {
        if (preg_match('/PRIVATE KEY/i', $pem)) {
            throw new InvalidArgumentException('lan_ca_pem must contain only the PUBLIC CA certificate - never a private key.');
        }
        $blocks = preg_match_all('/-----BEGIN ([A-Z0-9 ]+)-----/', $pem, $kinds);
        if ($blocks !== 1 || $kinds[1][0] !== 'CERTIFICATE'
            || ! preg_match('/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----/', $pem, $m)) {
            throw new InvalidArgumentException('lan_ca_pem must contain exactly one PEM certificate.');
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($der === false || $der === '') {
            throw new InvalidArgumentException('lan_ca_pem is not valid base64.');
        }
        $canonical = "-----BEGIN CERTIFICATE-----\n" . rtrim(chunk_split(base64_encode($der), 64, "\n")) . "\n-----END CERTIFICATE-----";

        $cert = @openssl_x509_read($canonical);
        $info = $cert !== false ? @openssl_x509_parse($cert) : false;
        if ($cert === false || ! is_array($info)) {
            throw new InvalidArgumentException('lan_ca_pem is not a readable X.509 certificate.');
        }
        $basic = (string) ($info['extensions']['basicConstraints'] ?? '');
        if (! preg_match('/CA:TRUE/i', $basic)) {
            throw new InvalidArgumentException('lan_ca_pem is not a CA certificate (basicConstraints CA:TRUE is missing) - use the branch CA, not the server certificate.');
        }
        $keyUsage = $info['extensions']['keyUsage'] ?? null;
        if ($keyUsage !== null && ! str_contains((string) $keyUsage, 'Certificate Sign')) {
            throw new InvalidArgumentException('lan_ca_pem is not allowed to sign certificates (keyUsage lacks Certificate Sign).');
        }
        $publicKey = openssl_pkey_get_public($cert);
        if (($info['subject'] ?? null) != ($info['issuer'] ?? null) || $publicKey === false || openssl_x509_verify($cert, $publicKey) !== 1) {
            throw new InvalidArgumentException('lan_ca_pem must be the self-signed branch ROOT CA.');
        }
        $validTo = (int) ($info['validTo_time_t'] ?? 0);
        if ($validTo <= time()) {
            throw new InvalidArgumentException('The branch CA certificate has expired - issue a new branch CA on the Branch Server.');
        }

        $cn = $info['subject']['CN'] ?? 'Bingoo Edge Branch CA';
        $name = $this->sanitizeLabel(is_array($cn) ? (string) end($cn) : (string) $cn, 'Bingoo Edge Branch CA');

        return [
            'pem' => $canonical,
            'sha1' => strtoupper(hash('sha1', $der)),
            'sha256' => strtoupper(hash('sha256', $der)),
            'name' => $name,
            'valid_to' => $validTo,
        ];
    }

    private function validateOnlinePosUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (strlen($url) > 300 || ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! preg_match('#^https://[A-Za-z0-9.-]+(?::\d{1,5})?(?:/[A-Za-z0-9._~!$&()*+,;=:@%/-]*)?(?:\?[A-Za-z0-9._~!$&()*+,;=:@%/?-]*)?$#', $url)) {
            throw new InvalidArgumentException('The Online POS URL must be a plain https:// address such as https://shop.bingoopos.com/pos.');
        }

        return $url;
    }

    /** Printable ASCII only (PowerShell 5.1 + hosts + .url files), single line, bounded length. */
    private function sanitizeLabel(string $label, string $fallback = 'Branch'): string
    {
        $ascii = Str::ascii($label);
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $ascii) ?? '';
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
        if (strlen($ascii) > self::MAX_LABEL) {
            $ascii = rtrim(substr($ascii, 0, self::MAX_LABEL));
        }

        return $ascii !== '' ? $ascii : $fallback;
    }

    // ---- small helpers -------------------------------------------------------------------------------------------

    private function edgeUrl(string $host, int $port, string $path): string
    {
        return 'https://' . $host . ($port === 443 ? '' : ':' . $port) . $path;
    }

    /** Content for a PowerShell single-quoted literal (the input is already printable ASCII). */
    private static function psLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private static function colonHex(string $hex): string
    {
        return implode(':', str_split(strtoupper($hex), 2));
    }

    /** @return array{en: string, ur: string} */
    private static function step(string $english, string $romanUrdu): array
    {
        return ['en' => $english, 'ur' => $romanUrdu];
    }
}
