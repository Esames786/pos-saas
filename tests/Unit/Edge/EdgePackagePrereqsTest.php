<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\Packaging\VcRedistPrerequisite;
use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 (stream E) — the VC++ 2015-2022 x64 runtime prerequisite (prereqs/VC_redist.x64.exe) and
 * scripts/edge/Install-EdgePrereqs.ps1. A clean Windows has no VC++ runtime, so php.exe / mysqld.exe would not start.
 *
 * The builder accepts only an Authenticode-valid Microsoft bundle whose WiX Burn payload container is complete (the
 * C:\ProgramData\Package Cache copy is a 630 KB stub without its 18 MB container). The script NEVER runs an installer in
 * these tests: they use -CheckOnly or a fake unsigned installer that is refused before elevation / execution.
 */
class EdgePackagePrereqsTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $p) {
            is_dir($p) ? @rmdir($p) : @unlink($p);
        }
        parent::tearDown();
    }

    /** A minimal PE image with a ".wixburn" section declaring stub + UX + attached container sizes. */
    private function fakeBundle(bool $complete, int $attached = 4000): string
    {
        $peOff = 0x40;
        $dataOff = 0x200;
        $pe = 'MZ' . str_repeat("\0", 0x3A) . pack('V', $peOff);
        $pe .= "PE\0\0" . pack('vvVVVvv', 0x8664, 1, 0, 0, 0, 0, 0x22);                  // COFF: 1 section, no optional header
        $pe .= str_pad('.wixburn', 8, "\0") . pack('VVVVVVvvV', 64, 0x1000, 64, $dataOff, 0, 0, 0, 0, 0);
        $pe = str_pad($pe, $dataOff, "\0");
        $stub = 1024;
        $ux = 500;
        $pe .= pack('VV', 0x00F14300, 2) . str_repeat("\x11", 16) . pack('VVVVVVVV', $stub, 0, 0, 0, 1, 2, $ux, $attached);
        $pe = str_pad($pe, $stub, "\0");
        $pe .= str_repeat('U', $ux) . ($complete ? str_repeat('P', $attached) : '');
        $base = (string) tempnam(sys_get_temp_dir(), 'vcr');
        @unlink($base);
        $f = $base . '.exe';
        $this->paths[] = $f;
        file_put_contents($f, $pe);

        return $f;
    }

    private function microsoft(string $status = 'Valid', string $version = '14.44.35211.0'): callable
    {
        return fn (string $exe) => ['status' => $status, 'signer' => 'CN=Microsoft Corporation, O=Microsoft Corporation, L=Redmond, S=Washington, C=US',
            'issuer' => 'CN=Microsoft Code Signing PCA 2011, O=Microsoft Corporation', 'version' => $version];
    }

    public function test_the_burn_layout_tells_a_complete_bundle_from_a_package_cache_stub(): void
    {
        $full = VcRedistPrerequisite::burnLayout($this->fakeBundle(true));
        $this->assertTrue($full['is_bundle']);
        $this->assertTrue($full['complete']);
        $this->assertSame(1024 + 500 + 4000, $full['expected_bytes']);
        $stub = VcRedistPrerequisite::burnLayout($this->fakeBundle(false));
        $this->assertTrue($stub['is_bundle']);
        $this->assertFalse($stub['complete']);
        $notPe = tempnam(sys_get_temp_dir(), 'vcr');
        $this->paths[] = $notPe;
        file_put_contents($notPe, 'MZ not really');
        $this->assertFalse(VcRedistPrerequisite::burnLayout($notPe)['is_bundle']);
    }

    public function test_only_a_complete_valid_microsoft_14x_bundle_is_accepted(): void
    {
        $ok = (new VcRedistPrerequisite($this->microsoft()))->inspect($this->fakeBundle(true));
        $this->assertTrue($ok['ok'], implode(' | ', $ok['problems']));
        $cases = [
            'VCREDIST_INCOMPLETE' => [$this->fakeBundle(false), $this->microsoft()],
            'VCREDIST_SIGNATURE_INVALID' => [$this->fakeBundle(true), $this->microsoft('HashMismatch')],
            'VCREDIST_SIGNER_NOT_MICROSOFT' => [$this->fakeBundle(true), fn () => ['status' => 'Valid', 'signer' => 'CN=Evil Corp, O=Microsoft Corporation Ltd', 'issuer' => null, 'version' => '14.44.1.0']],
            'VCREDIST_VERSION_UNEXPECTED' => [$this->fakeBundle(true), $this->microsoft('Valid', '12.0.40660.0')],
        ];
        foreach ($cases as $code => [$file, $probe]) {
            $r = (new VcRedistPrerequisite($probe))->inspect($file);
            $this->assertFalse($r['ok'], $code);
            $this->assertNotEmpty(array_filter($r['problems'], fn ($p) => str_starts_with($p, $code)), $code . ': ' . implode(' | ', $r['problems']));
        }
        // bundle() refuses and copies nothing.
        $dest = sys_get_temp_dir() . '/edge-prereq-' . bin2hex(random_bytes(4));
        try {
            (new VcRedistPrerequisite($this->microsoft()))->bundle($this->fakeBundle(false), $dest);
            $this->fail('an incomplete bundle must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PACKAGE_PREREQ_REFUSED', $e->getMessage());
        }
        $this->assertFileDoesNotExist($dest . '/' . VcRedistPrerequisite::FILE);
        $entry = (new VcRedistPrerequisite($this->microsoft()))->bundle($this->fakeBundle(true), $dest);
        $this->paths[] = $dest;
        $this->paths[] = $dest . '/prereqs';
        $this->paths[] = $dest . '/' . VcRedistPrerequisite::FILE;
        $this->assertSame(VcRedistPrerequisite::FILE, $entry['file']);
        $this->assertSame('14.44.35211.0', $entry['version']);
        $this->assertSame(hash_file('sha256', $dest . '/' . VcRedistPrerequisite::FILE), $entry['sha256']);
    }

    /** The real files on this build host (when present): the official download passes, the Package Cache stub is refused. */
    public function test_the_real_official_installer_passes_and_the_package_cache_stub_fails(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows only');
        }
        $official = 'D:/BingooEdgeLab-e09/e/prereqs/VC_redist.x64.exe';
        $stub = 'C:/ProgramData/Package Cache/{0e3bb569-69d6-4c34-bff9-c2f81db5e5f0}/VC_redist.x64.exe';
        if (! is_file($official)) {
            $this->markTestSkipped('official VC_redist.x64.exe not staged on this machine');
        }
        $r = (new VcRedistPrerequisite())->inspect($official);
        $this->assertTrue($r['ok'], implode(' | ', $r['problems']));
        $this->assertSame('Valid', $r['authenticode']['status']);
        $this->assertStringStartsWith('14.', (string) $r['version']);
        if (is_file($stub)) {
            $s = (new VcRedistPrerequisite())->inspect($stub);
            $this->assertFalse($s['ok']);
            $this->assertNotEmpty(array_filter($s['problems'], fn ($p) => str_starts_with($p, 'VCREDIST_INCOMPLETE')), implode(' | ', $s['problems']));
        }
    }

    /** @return array{0:int, 1:string} */
    private function script(array $args): array
    {
        $p = proc_open(array_merge(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', dirname(__DIR__, 3) . '\\scripts\\edge\\Install-EdgePrereqs.ps1'], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($p), (string) $out];
    }

    public function test_the_install_script_detects_and_refuses_without_ever_installing(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows PowerShell only');
        }
        [$code, $out] = $this->script(['-CheckOnly', '-MinimumVersion', '14.0']);
        $this->assertContains($code, [0, 2], $out);
        $this->assertMatchesRegularExpression('/PREREQS (OK|MISSING)/', $out);
        // Forced "older than required": check-only reports 2 and changes nothing.
        [$code, $out] = $this->script(['-CheckOnly', '-MinimumVersion', '99.0']);
        $this->assertSame(2, $code, $out);
        $this->assertStringContainsString('nothing changed', $out);
        // Not check-only, required runtime missing, no installer -> 1 with the manual instruction.
        $root = sys_get_temp_dir() . '\\edge-prereq-pkg-' . bin2hex(random_bytes(4));
        mkdir($root);
        $this->paths[] = $root;
        [$code, $out] = $this->script(['-PackageRoot', $root, '-MinimumVersion', '99.0']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('carries no prereqs\\VC_redist.x64.exe', $out);
        // A fake (unsigned) installer is refused BEFORE elevation or execution.
        mkdir($root . '\\prereqs');
        $this->paths[] = $root . '\\prereqs';
        $fake = $root . '\\prereqs\\VC_redist.x64.exe';
        copy($this->fakeBundle(true), $fake);
        $this->paths[] = $fake;
        [$code, $out] = $this->script(['-PackageRoot', $root, '-MinimumVersion', '99.0']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('not a valid Microsoft-signed installer', $out);
    }

    public function test_the_script_is_valid_powershell_5_1(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows PowerShell only');
        }
        $path = dirname(__DIR__, 3) . '\\scripts\\edge\\Install-EdgePrereqs.ps1';
        $p = proc_open(['powershell.exe', '-NoProfile', '-Command', '$e=$null;$t=$null;[void][System.Management.Automation.Language.Parser]::ParseFile(\'' . $path . '\',[ref]$t,[ref]$e);"PARSE_ERRORS=" + $e.Count'], [1 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($p);
        $this->assertStringContainsString('PARSE_ERRORS=0', (string) $out);
        $this->assertDoesNotMatchRegularExpression('/[^\x00-\x7F]/', (string) file_get_contents($path), 'PS 5.1: ASCII only');
    }
}
