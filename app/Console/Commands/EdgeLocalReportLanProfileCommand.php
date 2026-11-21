<?php

namespace App\Console\Commands;

use App\Services\Edge\AutoUpdate\EdgeDeviceCloudClient;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * EDGE 0.9 (stream B) — report this Branch Server's LAN profile to the Cloud (contract K5) so the Cloud can generate the
 * per-branch counter setup (CA trust, hosts line, shortcuts) and the phone onboarding page.
 *
 *   php artisan edge:local:report-lan-profile --ca-file=C:\BingooEdge\ca\bingoo-edge-branch-ca.crt [--hostname=] [--lan-ip=] [--https-port=] [--json]
 *
 * PUBLIC material only: hostname, IP, port, the branch CA CERTIFICATE (PEM; a DER .crt from Export-Certificate is converted)
 * and its sha256 fingerprint. A file holding a PRIVATE KEY, or a certificate that is not a CA, is refused before anything is sent.
 */
class EdgeLocalReportLanProfileCommand extends Command
{
    protected $signature = 'edge:local:report-lan-profile
        {--ca-file= : The branch CA public certificate (.crt, DER or PEM)}
        {--hostname= : LAN hostname (default edge.lan.hostname)}
        {--lan-ip= : Reserved LAN IP (default edge.lan.reserved_ip)}
        {--https-port= : Gateway HTTPS port (default edge.gateway.https_port)}
        {--dry-run : Print the profile without sending it}
        {--json : Machine-readable output}';

    protected $description = 'Report the LAN profile (hostname, IP, port, public branch CA certificate) to the Cloud (K5).';

    public function handle(EdgeDeviceCloudClient $cloud): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:report-lan-profile only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        try {
            $profile = self::profile(
                (string) ($this->option('hostname') ?: config('edge.lan.hostname', 'bingoo-edge.local')),
                (string) ($this->option('lan-ip') ?: config('edge.lan.reserved_ip', '')),
                (int) ($this->option('https-port') ?: config('edge.gateway.https_port', 443)),
                (string) ($this->option('ca-file') ?? ''),
            );
        } catch (\RuntimeException $e) {
            $this->error('LAN profile refused: ' . $e->getMessage());

            return self::FAILURE;
        }
        $result = $this->option('dry-run') ? ['sent' => false, 'reason' => 'dry_run'] : $cloud->reportLanProfile($profile);
        $doc = ['profile' => array_diff_key($profile, ['ca_certificate_pem' => true]) + ['ca_certificate' => isset($profile['ca_certificate_pem']) ? 'present' : 'absent']] + $result;
        if ($this->option('json')) {
            $this->line(json_encode($doc, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('K5 LAN profile ' . $profile['lan_hostname'] . ' / ' . ($profile['lan_ip'] ?? '?') . ':' . $profile['https_port'] . ' (CA ' . ($profile['ca_sha256'] ?? 'none') . '): '
                . (($result['sent'] ?? false) ? 'sent' : 'not sent — ' . ($result['reason'] ?? ('HTTP ' . ($result['status'] ?? '?')))));
        }

        return self::SUCCESS;
    }

    /** Build + validate the K5 document (public material only). */
    public static function profile(string $hostname, string $lanIp, int $httpsPort, string $caFile): array
    {
        if ($hostname === '' || ! preg_match('/^[A-Za-z0-9.-]+$/', $hostname)) {
            throw new \RuntimeException('invalid LAN hostname');
        }
        if ($lanIp !== '' && filter_var($lanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \RuntimeException('invalid LAN IP');
        }
        if ($httpsPort < 1 || $httpsPort > 65535) {
            throw new \RuntimeException('invalid HTTPS port');
        }
        $profile = ['lan_hostname' => $hostname, 'lan_ip' => $lanIp !== '' ? $lanIp : null, 'https_port' => $httpsPort];
        if ($caFile !== '') {
            if (! is_file($caFile)) {
                throw new \RuntimeException('CA certificate file not found');
            }
            $raw = (string) file_get_contents($caFile);
            if (stripos($raw, 'PRIVATE KEY') !== false) {
                throw new \RuntimeException('the file holds a PRIVATE KEY — only the public CA certificate may be reported');
            }
            if (str_contains($raw, '-----BEGIN CERTIFICATE-----')) {
                if (! preg_match('/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----/', $raw, $m)) {
                    throw new \RuntimeException('unreadable PEM certificate');
                }
                $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
            } else {
                $der = $raw; // Export-Certificate -Type CERT writes DER
            }
            if ($der === false || $der === '') {
                throw new \RuntimeException('unreadable certificate');
            }
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
            if (extension_loaded('openssl')) {
                $parsed = @openssl_x509_parse($pem);
                if (! is_array($parsed)) {
                    throw new \RuntimeException('not an X.509 certificate');
                }
                $bc = (string) ($parsed['extensions']['basicConstraints'] ?? '');
                if (stripos($bc, 'CA:TRUE') === false) {
                    throw new \RuntimeException('the certificate is not a CA (basicConstraints CA:TRUE missing) — report the BRANCH CA, not the server certificate');
                }
            }
            $profile['ca_certificate_pem'] = $pem;
            $profile['ca_sha256'] = hash('sha256', $der);
        }

        return $profile;
    }
}
