<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\Packaging\ServerCertificateCheck;
use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 (stream E) — edge:audit-package --server-cert: a Branch Server certificate must satisfy iOS / macOS / Android
 * (<= 825 days, serverAuth EKU, DNS + IP SAN, RSA >= 2048, SHA-2, not a CA). Certificates are minted here with openssl.
 */
class EdgePackageServerCertificateCheckTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** Mint a self-signed certificate with the given extensions (v3_req section). */
    private function mint(int $days, string $extensions, int $bits = 2048, string $digest = 'sha256'): string
    {
        $cnf = tempnam(sys_get_temp_dir(), 'edgecnf');
        $this->files[] = $cnf;
        file_put_contents($cnf, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\nCN = bingoo-edge.local\n[v3_req]\n{$extensions}\n");
        $cfg = ['config' => $cnf, 'digest_alg' => $digest, 'private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3_req'];
        $key = openssl_pkey_new($cfg);
        $csr = openssl_csr_new(['commonName' => 'bingoo-edge.local'], $key, $cfg);
        $x509 = openssl_csr_sign($csr, null, $key, $days, $cfg);
        openssl_x509_export($x509, $pem);

        return $pem;
    }

    private const GOOD = "basicConstraints = CA:FALSE\nextendedKeyUsage = serverAuth\nsubjectAltName = DNS:bingoo-edge.local, IP:192.168.100.52";

    public function test_a_compliant_certificate_passes_in_pem_and_der(): void
    {
        $pem = $this->mint(730, self::GOOD);
        $r = (new ServerCertificateCheck())->inspect($pem, 'bingoo-edge.local', '192.168.100.52');
        $this->assertTrue($r['ok'], implode(' | ', $r['problems']));
        $this->assertSame(730.0, $r['validity_days']);
        $this->assertTrue($r['server_auth_eku']);
        $this->assertSame(['bingoo-edge.local'], $r['san_dns']);
        $this->assertSame(['192.168.100.52'], $r['san_ip']);
        $this->assertSame('rsa', $r['key_type']);
        $this->assertSame(2048, $r['key_bits']);
        // Export-Certificate -Type CERT writes DER: the same check reads it.
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));
        $this->assertTrue((new ServerCertificateCheck())->inspect($der)['ok']);
        $this->assertTrue((new ServerCertificateCheck())->inspect($this->mint(825, self::GOOD))['ok'], '825 days is the limit, inclusive');
    }

    public function test_each_platform_rule_is_enforced(): void
    {
        $check = new ServerCertificateCheck();
        $cases = [
            'VALIDITY_TOO_LONG' => $this->mint(826, self::GOOD),
            'EKU_SERVER_AUTH_MISSING' => $this->mint(730, "basicConstraints = CA:FALSE\nsubjectAltName = DNS:bingoo-edge.local, IP:192.168.100.52"),
            'SAN_IP_MISSING' => $this->mint(730, "extendedKeyUsage = serverAuth\nsubjectAltName = DNS:bingoo-edge.local"),
            'SAN_DNS_MISSING' => $this->mint(730, "extendedKeyUsage = serverAuth\nsubjectAltName = IP:192.168.100.52"),
            'CERT_IS_CA' => $this->mint(730, "basicConstraints = critical, CA:TRUE\nextendedKeyUsage = serverAuth\nsubjectAltName = DNS:bingoo-edge.local, IP:192.168.100.52"),
            'KEY_TOO_WEAK' => $this->mint(730, self::GOOD, 1024),
        ];
        foreach ($cases as $code => $pem) {
            $r = $check->inspect($pem);
            $this->assertFalse($r['ok'], $code);
            $this->assertNotEmpty(array_filter($r['problems'], fn ($p) => str_starts_with($p, $code)), $code . ' expected in: ' . implode(' | ', $r['problems']));
        }
        $r = $check->inspect($this->mint(730, self::GOOD), 'other-host.local', '10.0.0.9');
        $this->assertNotEmpty(array_filter($r['problems'], fn ($p) => str_starts_with($p, 'SAN_HOST_MISMATCH')));
        $this->assertNotEmpty(array_filter($r['problems'], fn ($p) => str_starts_with($p, 'SAN_IP_MISMATCH')));
        $this->assertSame(['CERT_UNREADABLE: not a PEM or DER X.509 certificate'], $check->inspect('garbage')['problems']);
    }
}
