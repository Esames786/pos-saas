<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeSigningKeyStore;
use App\Services\Edge\Packaging\PackageChecksums;
use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 (stream E) — SHA256SUMS + Ed25519 detached signature of every distributable script/executable: the fallback
 * while no Authenticode code-signing certificate exists (checked 21 Nov 2026: none on the build host).
 */
class EdgePackageChecksumsTest extends TestCase
{
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            $this->rrmdir($d);
        }
        parent::tearDown();
    }

    private function pkg(): string
    {
        $d = sys_get_temp_dir() . '/edge-sums-' . bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        foreach ([
            'Setup-BingooEdge.cmd' => '@echo off',
            'scripts/Install-EdgeAppliance.ps1' => 'param()',
            'scripts/wizard/Start-BingooEdgeWizard.ps1' => '# wizard',
            'php/php.exe' => 'MZ php', 'php/ext/php_curl.dll' => 'MZ dll', 'php/phar.phar.bat' => '@php', 'php/php.ini' => 'extension_dir = "ext"',
            'mysql/bin/mysqld.exe' => 'MZ mysqld', 'mysql/share/english/errmsg.sys' => 'msgs',
            'gateway/nginx.exe' => 'MZ nginx',
            'templates/edge-launcher.php' => '<?php // launcher', 'templates/README-INSTALL.md' => '# readme',
            'app/artisan' => '<?php', 'app/app/Services/Foo.php' => '<?php // covered by the signed artifact manifest',
            'app/scripts/edge/appliance/include-probe.php' => '<?php // probe', 'app/scripts/edge/Get-EdgeHealth.ps1' => '# health',
            'app/vendor/bin/tool.bat' => '@rem vendor', 'app/vendor/acme/x.php' => '<?php',
        ] as $rel => $body) {
            @mkdir(dirname($d . '/' . $rel), 0777, true);
            file_put_contents($d . '/' . $rel, $body);
        }

        return $d;
    }

    public function test_it_lists_exactly_the_distributable_scripts_and_executables(): void
    {
        $d = $this->pkg();
        $entry = (new PackageChecksums())->write($d);
        $this->assertFalse($entry['signed']);
        $this->assertFileDoesNotExist($d . '/' . PackageChecksums::SIG_FILE);
        $lines = file($d . '/SHA256SUMS', FILE_IGNORE_NEW_LINES);
        $listed = array_map(fn ($l) => substr($l, 66), $lines);
        $this->assertSame([
            'Setup-BingooEdge.cmd', 'app/scripts/edge/Get-EdgeHealth.ps1', 'app/scripts/edge/appliance/include-probe.php',
            'gateway/nginx.exe', 'mysql/bin/mysqld.exe', 'php/ext/php_curl.dll', 'php/phar.phar.bat', 'php/php.exe',
            'scripts/Install-EdgeAppliance.ps1', 'scripts/wizard/Start-BingooEdgeWizard.ps1', 'templates/edge-launcher.php',
        ], $listed);
        $this->assertSame(hash('sha256', 'MZ nginx') . '  gateway/nginx.exe', $lines[3], 'sha256sum text format');
        $this->assertSame(11, $entry['entries']);
        $v = (new PackageChecksums())->verify($d);
        $this->assertTrue($v['ok'], json_encode($v['problems']));
        $this->assertFalse($v['signed']);
        $this->assertNull($v['signature_ok']);
    }

    public function test_the_detached_signature_verifies_with_the_package_key_only(): void
    {
        $d = $this->pkg();
        $kp = EdgeEnrollmentCrypto::generateKeypair();
        $entry = (new PackageChecksums())->write($d, $kp['secret']);
        $this->assertTrue($entry['signed']);
        $this->assertSame(EdgeSigningKeyStore::keyId($kp['public']), $entry['signing_key_id']);
        $sig = json_decode((string) file_get_contents($d . '/SHA256SUMS.sig'), true);
        $this->assertSame('ed25519', $sig['algorithm']);
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($sig['signature']), (string) file_get_contents($d . '/SHA256SUMS'), base64_decode($kp['public'])),
            'a plain libsodium verify over the file bytes (what any third-party tool does) succeeds');

        $ok = (new PackageChecksums())->verify($d, $kp['public']);
        $this->assertTrue($ok['ok']);
        $this->assertTrue($ok['signature_ok']);

        $foreign = (new PackageChecksums())->verify($d, EdgeEnrollmentCrypto::generateKeypair()['public']);
        $this->assertFalse($foreign['ok']);
        $this->assertFalse($foreign['signature_ok']);

        // Editing SHA256SUMS (e.g. to cover a swapped binary) breaks the signature.
        file_put_contents($d . '/php/php.exe', 'MZ trojan');
        $sums = (string) file_get_contents($d . '/SHA256SUMS');
        file_put_contents($d . '/SHA256SUMS', str_replace(hash('sha256', 'MZ php'), hash('sha256', 'MZ trojan'), $sums));
        $forged = (new PackageChecksums())->verify($d, $kp['public']);
        $this->assertFalse($forged['signature_ok']);
        $this->assertSame([], $forged['mismatched'], 'the forged list matches the disk — only the signature catches it');
    }

    public function test_tampered_missing_and_unlisted_files_are_reported(): void
    {
        $d = $this->pkg();
        (new PackageChecksums())->write($d);
        file_put_contents($d . '/gateway/nginx.exe', 'MZ swapped');
        unlink($d . '/scripts/wizard/Start-BingooEdgeWizard.ps1');
        file_put_contents($d . '/scripts/Planted.ps1', 'Invoke-Evil');
        $v = (new PackageChecksums())->verify($d);
        $this->assertFalse($v['ok']);
        $this->assertSame(['gateway/nginx.exe'], $v['mismatched']);
        $this->assertSame(['scripts/wizard/Start-BingooEdgeWizard.ps1'], $v['missing']);
        $this->assertSame(['scripts/Planted.ps1'], $v['unlisted']);
        $this->assertFalse((new PackageChecksums())->verify($this->pkg())['present']);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
