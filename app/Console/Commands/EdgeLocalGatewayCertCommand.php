<?php

namespace App\Console\Commands;

use App\Support\EdgeApplianceLayout;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * P4 — import the LAN TLS server certificate for the gateway: PFX (from the branch CA via New-EdgeServerCertificate.ps1
 * -ExportPfx) → PEM files the gateway reads (<data-root>/certs/server.crt + server.key). The PFX password comes from a
 * file or a hidden prompt — never argv. The key file is written 0600; the installer additionally restricts the ACL.
 *
 *   php artisan edge:local:gateway-cert C:\path\server.pfx [--password-file=C:\path\pw.txt] [--certs-dir=…]
 *
 * --self-signed <hostname> issues a SELF-SIGNED proof certificate instead (lab / clean-machine proof ONLY; a branch
 * runs the branch-CA certificate so terminals trust it).
 */
class EdgeLocalGatewayCertCommand extends Command
{
    protected $signature = 'edge:local:gateway-cert
        {pfx? : PFX/PKCS#12 file holding the server certificate + private key}
        {--password-file= : File containing the PFX password (read then deleted); otherwise a hidden prompt}
        {--certs-dir= : Destination (default <data-root>/certs)}
        {--self-signed= : LAB ONLY — generate a self-signed certificate for this hostname instead of importing}
        {--ip= : LAB ONLY — additional IP SAN for --self-signed}';

    protected $description = 'Install the gateway TLS certificate (PFX → PEM) under the appliance data root.';

    public function handle(): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:gateway-cert only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        if (! extension_loaded('openssl')) {
            $this->error('The PHP openssl extension is required.');

            return self::FAILURE;
        }
        $dir = rtrim((string) ($this->option('certs-dir') ?: (EdgeApplianceLayout::certsDir() ?? '')), "/\\");
        if ($dir === '') {
            $this->error('A certs directory is required (--certs-dir or a configured data root).');

            return self::FAILURE;
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error('Cannot create ' . $dir);

            return self::FAILURE;
        }

        if ($this->option('self-signed')) {
            [$certPem, $keyPem] = $this->selfSigned((string) $this->option('self-signed'), (string) ($this->option('ip') ?? ''));
            $mode = 'self-signed (LAB ONLY — not for a live branch)';
        } else {
            $pfx = (string) $this->argument('pfx');
            if ($pfx === '' || ! is_file($pfx)) {
                $this->error('PFX file not found: ' . $pfx);

                return self::FAILURE;
            }
            $password = $this->readPassword();
            $certs = [];
            if (! openssl_pkcs12_read((string) file_get_contents($pfx), $certs, $password)) {
                $this->error('Could not read the PFX (wrong password or unsupported format).');

                return self::FAILURE;
            }
            $certPem = (string) $certs['cert'];
            foreach ((array) ($certs['extracerts'] ?? []) as $extra) {
                $certPem .= $extra; // chain: the branch CA public cert
            }
            $keyPem = (string) $certs['pkey'];
            $mode = 'imported from PFX';
        }
        $certPath = $dir . DIRECTORY_SEPARATOR . 'server.crt';
        $keyPath = $dir . DIRECTORY_SEPARATOR . 'server.key';
        file_put_contents($certPath, $certPem);
        file_put_contents($keyPath, $keyPem);
        @chmod($keyPath, 0600);
        $info = openssl_x509_parse($certPem) ?: [];
        $this->info('Gateway certificate installed (' . $mode . ').');
        $this->line('  cert : ' . $certPath);
        $this->line('  key  : ' . $keyPath . ' (restricted)');
        $this->line('  CN   : ' . (string) ($info['subject']['CN'] ?? '?') . ' · valid to ' . (isset($info['validTo_time_t']) ? date('Y-m-d', (int) $info['validTo_time_t']) : '?'));
        $this->line('  SAN  : ' . (string) ($info['extensions']['subjectAltName'] ?? '-'));

        return self::SUCCESS;
    }

    private function readPassword(): string
    {
        $file = (string) ($this->option('password-file') ?? '');
        if ($file !== '') {
            $pw = is_file($file) ? rtrim((string) file_get_contents($file), "\r\n") : '';
            @unlink($file);

            return $pw;
        }

        return (string) $this->secret('PFX password');
    }

    /** @return array{0:string,1:string} [cert PEM, key PEM] */
    private function selfSigned(string $host, string $ip): array
    {
        $san = 'DNS:' . $host . ($ip !== '' ? ',IP:' . $ip : '');
        $cnf = tempnam(sys_get_temp_dir(), 'edgecnf');
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\nreq_extensions=v3_req\n[dn]\n[v3_req]\nsubjectAltName={$san}\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n");
        $cfg = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3_req', 'req_extensions' => 'v3_req'];
        $key = openssl_pkey_new($cfg);
        $csr = openssl_csr_new(['commonName' => $host, 'organizationName' => 'Bingoo Edge (lab)'], $key, $cfg);
        $cert = openssl_csr_sign($csr, null, $key, 730, $cfg);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, $cfg);
        @unlink($cnf);

        return [(string) $certPem, (string) $keyPem];
    }
}
