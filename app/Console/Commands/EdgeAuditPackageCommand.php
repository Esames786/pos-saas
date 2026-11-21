<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgePackageBuilder;
use App\Services\Edge\Packaging\ServerCertificateCheck;
use Illuminate\Console\Command;

/**
 * P4 §5 — verify an appliance package on disk: every listed file present and unchanged, no unlisted file, the
 * boundary audit still clean (no secrets/keys/tests/Cloud-only modules; the branch_server artifact marker present).
 *
 * Edge 0.9 — also: the packaged php/php.ini is portable (relative extension_dir, no build-host path, CA placeholder,
 * php/cacert.txt present), the setup wizard + root Setup-BingooEdge.cmd (warning in dev, error in a 0.9+ release),
 * the MySQL runtime manifest entry, Windows 10 task durations, the central -CloudUrl — and, with a public key, the
 * Ed25519 SIGNATURE of the update manifest (signature_ok), checked with the appliance's own verification primitive.
 *
 *   php artisan edge:audit-package D:\out\BingooEdge-0.9.0 [--json] [--public-key=<base64> | --public-key-file=<file>]
 *       [--require-signature] [--strict-layout]
 *
 * Without --public-key(-file) the configured edge.update.public_key is used when set; otherwise the signature is reported
 * as not checked (signature_ok null) unless --require-signature makes that a failure.
 */
class EdgeAuditPackageCommand extends Command
{
    protected $signature = 'edge:audit-package {dir : Package directory} {--json : Emit JSON}
        {--public-key= : Base64 Ed25519 update PUBLIC key to verify the signed update manifest against (else edge.update.public_key)}
        {--public-key-file= : File holding that base64 public key}
        {--require-signature : Fail when the signature cannot be checked (no key / unsigned package)}
        {--strict-layout : Apply the 0.9 RELEASE layout gates whatever the package build mode}
        {--server-cert= : Also check a Branch Server TLS certificate file (.crt PEM or DER): <= 825 days, serverAuth EKU, DNS + IP SAN, RSA>=2048, SHA-2}
        {--server-cert-host= : Expected DNS SAN in --server-cert (e.g. bingoo-edge.local)}
        {--server-cert-ip= : Expected IP SAN in --server-cert (the reserved LAN IP)}';

    protected $description = 'Verify a built Bingoo Edge appliance package (integrity + boundary + portable runtime + signature).';

    public function handle(): int
    {
        $publicKey = trim((string) ($this->option('public-key') ?? ''));
        $keyFile = (string) ($this->option('public-key-file') ?? '');
        if ($publicKey === '' && $keyFile !== '') {
            if (! is_file($keyFile)) {
                $this->error("--public-key-file [{$keyFile}] does not exist.");

                return self::FAILURE;
            }
            $publicKey = trim((string) file_get_contents($keyFile));
        }
        $source = 'option';
        if ($publicKey === '') {
            $publicKey = trim((string) config('edge.update.public_key', ''));
            $source = 'config';
        }
        $packages = new EdgePackageBuilder(\App\Services\Edge\EdgeArtifactBuilder::fromConfig(), app(\App\Services\Edge\EdgeUpdatePackageService::class));
        $result = $packages->verify((string) $this->argument('dir'), [
            'public_key' => $publicKey !== '' ? $publicKey : null,
            'public_key_source' => $source,
            'require_signature' => (bool) $this->option('require-signature'),
            'strict' => $this->option('strict-layout') ? true : null,
        ]);
        $certFile = (string) ($this->option('server-cert') ?? '');
        if ($certFile !== '') {
            $cert = is_file($certFile)
                ? (new ServerCertificateCheck())->inspect((string) file_get_contents($certFile), $this->option('server-cert-host') ?: null, $this->option('server-cert-ip') ?: null)
                : ['ok' => false, 'problems' => ['CERT_FILE_MISSING: ' . $certFile]];
            $cert['file'] = $certFile;
            $result['server_certificate'] = $cert;
            if (! $cert['ok']) {
                $result['ok'] = false;
                $result['errors'][] = 'SERVER_CERTIFICATE: ' . implode(' | ', $cert['problems']);
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(($result['ok'] ? '<info>PACKAGE OK</info>' : '<error>PACKAGE FAILED</error>') . ' — ' . ($result['edge_app_version'] ?? '?') . ' · files ' . ($result['file_count'] ?? 0)
                . ' · signature ' . $this->signatureLabel($result)
                . (isset($result['checksums']) ? ' · SHA256SUMS ' . ($result['checksums']['present'] ? $result['checksums']['entries'] . ' entries' . ($result['checksums']['signature_ok'] === null ? '' : ($result['checksums']['signature_ok'] ? ', signature VALID' : ', signature INVALID')) : 'absent') : ''));
            if (isset($result['server_certificate']['validity_days'])) {
                $c = $result['server_certificate'];
                $this->line('Server certificate: ' . ($c['ok'] ? 'OK' : 'FAILED') . ' — CN ' . ($c['subject_cn'] ?? '?') . ' · ' . $c['validity_days'] . ' days (max ' . $c['max_validity_days'] . ') · serverAuth ' . ($c['server_auth_eku'] ? 'yes' : 'NO')
                    . ' · SAN DNS ' . (implode(',', $c['san_dns']) ?: 'none') . ' · SAN IP ' . (implode(',', $c['san_ip']) ?: 'none'));
            }
            foreach (['missing', 'unlisted', 'tampered'] as $k) {
                if (! empty($result[$k])) {
                    $this->warn(strtoupper($k) . ': ' . implode(', ', array_slice($result[$k], 0, 20)));
                }
            }
            if (isset($result['boundary_audit']) && ! $result['boundary_audit']['ok']) {
                $this->warn('BOUNDARY: ' . json_encode($result['boundary_audit']));
            }
            foreach ((array) ($result['errors'] ?? []) as $e) {
                $this->error('ERROR: ' . $e);
            }
            foreach ((array) ($result['warnings'] ?? []) as $w) {
                $this->warn('WARNING: ' . $w);
            }
            if (isset($result['error'])) {
                $this->error($result['error']);
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function signatureLabel(array $result): string
    {
        $sig = $result['signature'] ?? null;
        if (! is_array($sig) || ! $sig['checked']) {
            return 'not checked (no public key)';
        }

        return ($sig['signature_ok'] ? 'VALID' : 'INVALID') . ' (key ' . ($sig['key_id'] ?? '?') . (! empty($result['signing_key_role']) ? ', ' . $result['signing_key_role'] . ' key' : '') . ')';
    }
}
