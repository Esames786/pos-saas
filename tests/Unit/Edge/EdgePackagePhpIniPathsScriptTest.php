<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\Packaging\PhpIniValidator;
use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 (stream E) — scripts/edge/appliance/Set-EdgePhpIniPaths.ps1, the INSTALLER side of the portable php.ini
 * contract: replaces "__BINGOO_PHP_DIR__" with the installed php folder, checks cacert.txt + ext\, proves php.exe trusts
 * the bundle; idempotent; refuses a relative folder or a missing CA bundle. Runs the real script with Windows PowerShell.
 */
class EdgePackagePhpIniPathsScriptTest extends TestCase
{
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows PowerShell only');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            $this->rrmdir($d);
        }
        parent::tearDown();
    }

    private function script(): string
    {
        return dirname(__DIR__, 3) . '\\scripts\\edge\\appliance\\Set-EdgePhpIniPaths.ps1';
    }

    /** @return array{0:int, 1:string} */
    private function runScript(array $args): array
    {
        $cmd = array_merge(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $this->script()], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($p), (string) $out];
    }

    private function phpDir(): string
    {
        $d = sys_get_temp_dir() . '\\edge-phpdir ' . bin2hex(random_bytes(4)); // a space, like "C:\Program Files\..."
        $this->dirs[] = $d;
        mkdir($d . '\\ext', 0777, true);
        $ini = "[PHP]\r\nextension_dir = \"ext\"\r\nextension=curl\r\n[curl]\r\ncurl.cainfo = \"" . PhpIniValidator::CA_VALUE . "\"\r\n[openssl]\r\nopenssl.cafile = \"" . PhpIniValidator::CA_VALUE . "\"\r\n";
        file_put_contents($d . '\\php.ini', $ini);
        file_put_contents($d . '\\cacert.txt', "-----BEGIN CERTIFICATE-----\n");

        return $d;
    }

    public function test_the_placeholder_is_replaced_once_and_the_script_is_idempotent(): void
    {
        $dir = $this->phpDir();
        [$code, $out] = $this->runScript(['-PhpDir', $dir . '\\', '-NoProbe']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('PHP INI READY', $out);
        $ini = (string) file_get_contents($dir . '\\php.ini');
        $this->assertStringNotContainsString(PhpIniValidator::PHP_DIR_PLACEHOLDER, $ini);
        $this->assertSame(2, substr_count($ini, '"' . $dir . '\\cacert.txt"'), 'both CA directives name the installed bundle (no trailing-slash doubling)');
        $this->assertStringStartsWith('[PHP]', $ini, 'written without a BOM');
        [$code2, $out2] = $this->runScript(['-PhpDir', $dir, '-NoProbe']);
        $this->assertSame(0, $code2, $out2);
        $this->assertStringContainsString('already finished', $out2);
    }

    public function test_it_refuses_a_relative_folder_a_missing_bundle_or_a_foreign_ca_path(): void
    {
        $dir = $this->phpDir();
        [$code, $out] = $this->runScript(['-PhpDir', 'php', '-NoProbe']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('absolute path', $out);

        unlink($dir . '\\cacert.txt');
        [$code, $out] = $this->runScript(['-PhpDir', $dir, '-NoProbe']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('cacert.txt', $out);
        $this->assertStringContainsString(PhpIniValidator::PHP_DIR_PLACEHOLDER, (string) file_get_contents($dir . '\\php.ini'), 'nothing is written when refused');

        file_put_contents($dir . '\\cacert.txt', 'x');
        file_put_contents($dir . '\\php.ini', "extension_dir = \"ext\"\r\ncurl.cainfo = \"D:\\laragon2\\etc\\ssl\\cacert.pem\"\r\nopenssl.cafile = \"D:\\laragon2\\etc\\ssl\\cacert.pem\"\r\n");
        [$code, $out] = $this->runScript(['-PhpDir', $dir, '-NoProbe']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('curl.cainfo', $out);
    }

    /** With a real PHP runtime the script proves php.exe now trusts the installed bundle. */
    public function test_the_probe_confirms_the_real_runtime_trusts_the_installed_bundle(): void
    {
        $src = 'D:\\laragon2\\bin\\php\\php-8.3.16-Win32-vs16-x64';
        if (! is_file($src . '\\php.exe')) {
            $this->markTestSkipped('no Laragon PHP 8.3.16 runtime on this machine');
        }
        $dir = sys_get_temp_dir() . '\\edge-phpreal ' . bin2hex(random_bytes(4));
        $this->dirs[] = $dir;
        mkdir($dir . '\\ext', 0777, true);
        foreach (glob($src . '\\*.{exe,dll}', GLOB_BRACE) ?: [] as $f) {
            copy($f, $dir . '\\' . basename($f));
        }
        foreach (['curl', 'openssl', 'mbstring'] as $ext) {
            copy($src . '\\ext\\php_' . $ext . '.dll', $dir . '\\ext\\php_' . $ext . '.dll');
        }
        file_put_contents($dir . '\\php.ini', "[PHP]\r\nextension_dir = \"ext\"\r\nextension=curl\r\nextension=mbstring\r\nextension=openssl\r\ncurl.cainfo = \"" . PhpIniValidator::CA_VALUE . "\"\r\nopenssl.cafile = \"" . PhpIniValidator::CA_VALUE . "\"\r\n");
        file_put_contents($dir . '\\cacert.txt', "-----BEGIN CERTIFICATE-----\n");
        [$code, $out] = $this->runScript(['-PhpDir', $dir]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('PHP INI READY', $out);
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
