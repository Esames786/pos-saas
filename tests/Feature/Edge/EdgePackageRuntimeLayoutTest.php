<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgePackageBuilder;
use App\Services\Edge\EdgeSigningKeyStore;
use App\Services\Edge\EdgeUpdatePackageService;
use App\Services\Edge\Packaging\PhpIniValidator;
use App\Services\Edge\Packaging\VcRedistPrerequisite;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Edge 0.9 (stream E) — the package builder + edge:audit-package after the Khatri findings (21 Nov 2026):
 *   - a bundled PHP runtime ships a builder-WRITTEN portable php.ini + php/cacert.txt (never the build host's ini);
 *     a runtime that cannot be made portable fails the build and leaves nothing behind;
 *   - --mysql-runtime bundles the server runtime with a manifest entry the audit re-checks;
 *   - the wizard (K8) + root Setup-BingooEdge.cmd are packaged when present (warning in dev, refusal when strict);
 *   - a packaged script with [TimeSpan]::MaxValue fails the build (Windows 10);
 *   - edge:audit-package --public-key verifies the Ed25519 update signature (signature_ok).
 * Builds from the REAL repository like EdgePackageBuilderTest (no vendor, nothing boots); fake runtimes skip the smoke run.
 */
class EdgePackageRuntimeLayoutTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];

    /** @var string[] files planted in the source tree by a test (removed in tearDown) */
    private array $planted = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->planted) as $p) {
            is_dir($p) ? @rmdir($p) : @unlink($p);
        }
        foreach ($this->tempDirs as $d) {
            $this->rrmdir($d);
        }
        parent::tearDown();
    }

    private function tmp(): string
    {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-pkg9-' . Str::lower(Str::random(8));
        $this->tempDirs[] = $d;

        return $d;
    }

    private function builder(): EdgePackageBuilder
    {
        $cfg = (array) config('edge.artifact');
        $cfg['include'] = ['app', 'bootstrap', 'config', 'routes', 'public/index.php', 'artisan', 'composer.json', 'scripts/edge', 'database/migrations'];
        $cfg['runtime_dirs'] = ['bootstrap/cache', 'storage/logs'];

        // The VC++ prerequisite probe is faked (Microsoft-signed 14.44) so the fake bundle below stands in for the real one.
        $vc = new VcRedistPrerequisite(fn (string $exe) => ['status' => 'Valid', 'signer' => 'CN=Microsoft Corporation, O=Microsoft Corporation, L=Redmond, S=Washington, C=US',
            'issuer' => 'CN=Microsoft Code Signing PCA 2011', 'version' => '14.44.35211.0']);

        return new EdgePackageBuilder(new EdgeArtifactBuilder($cfg), app(EdgeUpdatePackageService::class), null, null, null, $vc);
    }

    /** A minimal complete WiX Burn bundle (".wixburn" header with stub + UX + attached container) — stands in for VC_redist.x64.exe. */
    private function fakeVcRedist(): string
    {
        $pe = 'MZ' . str_repeat("\0", 0x3A) . pack('V', 0x40) . "PE\0\0" . pack('vvVVVvv', 0x8664, 1, 0, 0, 0, 0, 0x22)
            . str_pad('.wixburn', 8, "\0") . pack('VVVVVVvvV', 64, 0x1000, 64, 0x200, 0, 0, 0, 0, 0);
        $pe = str_pad($pe, 0x200, "\0") . pack('VV', 0x00F14300, 2) . str_repeat("\x11", 16) . pack('VVVVVVVV', 1024, 0, 0, 0, 1, 2, 500, 4000);
        $file = $this->tmp() . '.exe';
        $this->tempDirs[] = $file;
        file_put_contents($file, str_pad($pe, 1024, "\0") . str_repeat('U', 500) . str_repeat('P', 4000));

        return $file;
    }

    private function build(array $extra = [], array $meta = []): array
    {
        $dest = $this->tmp();
        $summary = $this->builder()->build($dest, array_merge([
            'source_root' => base_path(),
            'artifact_meta' => array_merge(['git_commit' => 'testrev', 'source_dirty' => true, 'build_mode' => 'dev', 'build_timestamp' => now()->toIso8601String(), 'artifact_version' => 'test'], $meta),
        ], $extra));

        return [$dest, $summary];
    }

    /** Assert a build is refused with $code and leaves no half-built package. */
    private function assertBuildRefused(array $extra, string $code, array $meta = []): string
    {
        $dest = $this->tmp();
        try {
            $this->builder()->build($dest, array_merge([
                'source_root' => base_path(),
                'artifact_meta' => array_merge(['git_commit' => 'x', 'build_mode' => 'dev'], $meta),
            ], $extra));
            $this->fail("the build must be refused with {$code}");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($code, $e->getMessage());
        }
        $this->assertFalse(is_dir($dest) && (glob($dest . '/*') ?: []) !== [], 'no half-built package may remain');

        return $e->getMessage();
    }

    /** A Laragon-shaped fake PHP runtime: php.exe placeholder, ext DLL stubs, the laptop php.ini, a CA bundle under extras/ssl. */
    private function fakePhpRuntime(bool $withCa = true, ?string $ini = null): string
    {
        $dir = $this->tmp();
        mkdir($dir . '/ext', 0777, true);
        file_put_contents($dir . '/php.exe', 'MZ fake');
        foreach (['curl', 'fileinfo', 'gd', 'mbstring', 'exif', 'openssl', 'pdo_mysql', 'sodium'] as $ext) {
            file_put_contents($dir . '/ext/php_' . $ext . '.dll', 'dll');
        }
        file_put_contents($dir . '/php.ini', $ini ?? (string) file_get_contents(base_path('tests/Fixtures/edge/PhpIni/laragon-like.ini')));
        file_put_contents($dir . '/php.ini.bak', 'extension_dir = "D:/laragon2/old/ext"');
        file_put_contents($dir . '/php.ini-production', '; stock template');
        if ($withCa) {
            mkdir($dir . '/extras/ssl', 0777, true);
            file_put_contents($dir . '/extras/ssl/cacert.pem', str_repeat("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n", 12));
        }

        return $dir;
    }

    private function fakeMysqlRuntime(): string
    {
        $dir = $this->tmp();
        foreach (['bin/mysqld.exe', 'bin/mysql.exe', 'bin/mysqladmin.exe', 'bin/mysqldump.exe', 'bin/mysqlpump.exe', 'bin/mysqld.pdb', 'share/english/errmsg.sys', 'share/install_rewriter.sql', 'my.ini', 'LICENSE'] as $rel) {
            @mkdir(dirname($dir . '/' . $rel), 0777, true);
            file_put_contents($dir . '/' . $rel, 'x ' . $rel);
        }

        return $dir;
    }

    private function plant(string $rel, string $body): void
    {
        $abs = base_path($rel);
        if (file_exists($abs)) {
            return; // the real file exists (e.g. after integration with stream B): never overwrite or delete it
        }
        $dirs = [];
        for ($d = dirname($abs); ! is_dir($d); $d = dirname($d)) {
            $dirs[] = $d;
        }
        foreach (array_reverse($dirs) as $d) {
            mkdir($d);
            $this->planted[] = $d;
        }
        file_put_contents($abs, $body);
        $this->planted[] = $abs;
    }

    public function test_a_bundled_runtime_ships_the_builder_written_portable_php_ini_and_the_audit_rechecks_it(): void
    {
        $kp = EdgeEnrollmentCrypto::generateKeypair();
        $keyId = EdgeSigningKeyStore::keyId($kp['public']);
        [$dest, $summary] = $this->build(['php_runtime' => $this->fakePhpRuntime(), 'php_smoke' => false,
            'mysql_runtime' => $this->fakeMysqlRuntime(), 'mysql_probe' => false, 'signing_key' => $kp['secret'], 'signing_key_id' => $keyId,
            'vc_redist' => $this->fakeVcRedist()]);

        // prereqs/ — the VC++ runtime installer rides in the package, in the manifest and in SHA256SUMS.
        $this->assertFileExists($dest . '/prereqs/VC_redist.x64.exe');
        $vcEntry = $summary['components']['prereqs']['vc_redist'];
        $this->assertSame('prereqs/VC_redist.x64.exe', $vcEntry['file']);
        $this->assertSame('14.44.35211.0', $vcEntry['version']);
        $this->assertSame(hash_file('sha256', $dest . '/prereqs/VC_redist.x64.exe'), $vcEntry['sha256']);
        $this->assertTrue($vcEntry['burn_complete']);
        $this->assertStringContainsString($vcEntry['sha256'] . "  prereqs/VC_redist.x64.exe\n", (string) file_get_contents($dest . '/SHA256SUMS'));
        $this->assertArrayHasKey('prereqs/VC_redist.x64.exe', json_decode((string) file_get_contents($dest . '/package-manifest.json'), true)['files']);
        $this->assertFileExists($dest . '/scripts/Install-EdgePrereqs.ps1');

        // php/ — the ini is the sanitised one, the CA bundle is cacert.txt, no CA .pem / ini backup came along.
        $ini = (string) file_get_contents($dest . '/php/php.ini');
        $this->assertMatchesRegularExpression('/^extension_dir = "ext"\r?$/m', $ini);
        $this->assertMatchesRegularExpression('/^curl\.cainfo = "__BINGOO_PHP_DIR__\\\\cacert\.txt"\r?$/m', $ini);
        foreach (preg_split('/\r\n|\n/', $ini) as $line) {
            if (trim($line) !== '' && ! in_array(trim($line)[0], [';', '['], true)) {
                $this->assertDoesNotMatchRegularExpression('/laragon|[A-Za-z]:[\\\\\/]/i', $line);
            }
        }
        $this->assertFileExists($dest . '/php/cacert.txt');
        $this->assertFileDoesNotExist($dest . '/php/extras/ssl/cacert.pem');
        $this->assertFileDoesNotExist($dest . '/php/php.ini.bak');
        $this->assertFileExists($dest . '/php/php.ini-production');
        $php = $summary['components']['php'];
        $this->assertTrue($php['php_ini']['written_by_builder']);
        $this->assertSame('ext', $php['php_ini']['extension_dir']);
        $this->assertSame(PhpIniValidator::CA_VALUE, $php['php_ini']['ca_placeholder']);
        $this->assertSame('runtime:extras/ssl/cacert.pem', $php['ca_bundle']['source_kind']);
        $this->assertSame(12, $php['ca_bundle']['certificates']);
        $this->assertContains('extension_dir:set', $php['php_ini']['changes']);
        $this->assertStringNotContainsString('laragon2/tmp', json_encode($php['php_ini']), 'the manifest records keys + actions, never the host values');
        // mysql/ — server runtime only + manifest entry.
        $this->assertFileExists($dest . '/mysql/bin/mysqld.exe');
        $this->assertFileDoesNotExist($dest . '/mysql/bin/mysqld.pdb');
        $this->assertFileDoesNotExist($dest . '/mysql/my.ini');
        $this->assertFileDoesNotExist($dest . '/mysql/share/install_rewriter.sql');
        $this->assertSame(6, $summary['components']['mysql']['file_count']); // server profile: 4 programs + errmsg.sys + LICENSE
        $this->assertFileDoesNotExist($dest . '/mysql/bin/mysqlpump.exe');
        // Signing provenance: a non-release key is recorded as a DEV key.
        $this->assertSame('dev', $summary['components']['update']['signing_key_role']);
        $this->assertNotEmpty(array_filter($summary['warnings'], fn ($w) => str_starts_with($w, 'WIZARD_ABSENT')) ?: (is_dir(base_path('scripts/edge/wizard')) ? ['wizard present'] : []));

        // The audit: everything ok, the signature verified with the product's own crypto.
        $verify = $this->builder()->verify($dest, ['public_key' => $kp['public']]);
        $this->assertTrue($verify['ok'], json_encode($verify['errors']));
        $this->assertTrue($verify['php_ini']['ok']);
        $this->assertTrue($verify['php_ini']['cacert_present']);
        $this->assertTrue($verify['prereqs']['vc_redist']['ok']);
        $this->assertTrue($verify['prereqs']['vc_redist']['manifest_entry']);
        $this->assertTrue($verify['mysql_runtime']['ok']);
        $this->assertSame(6, $verify['mysql_runtime']['file_count']);
        $this->assertTrue($verify['signature_ok']);
        $this->assertTrue($verify['signature']['key_id_matches']);
        $this->assertTrue($verify['signature']['artifact_bytes_match']);
        $this->assertNotEmpty(array_filter($verify['warnings'], fn ($w) => str_starts_with($w, 'DEV_SIGNED')));
        // SHA256SUMS of the distributable scripts/executables, signed with the same key (no Authenticode certificate exists).
        $this->assertFileExists($dest . '/SHA256SUMS');
        $this->assertFileExists($dest . '/SHA256SUMS.sig');
        $this->assertTrue($summary['components']['checksums']['signed']);
        $this->assertSame($keyId, $summary['components']['checksums']['signing_key_id']);
        $sums = (string) file_get_contents($dest . '/SHA256SUMS');
        foreach (['php/php.exe', 'mysql/bin/mysqld.exe', 'scripts/Install-EdgeAppliance.ps1', 'templates/edge-launcher.php'] as $rel) {
            $this->assertStringContainsString(hash_file('sha256', $dest . '/' . $rel) . '  ' . $rel . "\n", $sums);
        }
        $this->assertTrue($verify['checksums']['ok']);
        $this->assertTrue($verify['checksums_signature_ok']);

        // A foreign key refuses the signature.
        $foreign = EdgeEnrollmentCrypto::generateKeypair();
        $bad = $this->builder()->verify($dest, ['public_key' => $foreign['public']]);
        $this->assertFalse($bad['ok']);
        $this->assertFalse($bad['signature_ok']);
        $this->assertFalse($bad['signature']['key_id_matches']);
        $this->assertFalse($bad['checksums_signature_ok']);

        // A tampered signed payload is refused even with the right key.
        $updFile = $dest . '/' . $summary['components']['update']['file'];
        $upd = json_decode((string) file_get_contents($updFile), true);
        $upd['payload']['min_previous_edge_version'] = '0.0.1';
        file_put_contents($updFile, json_encode($upd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $tampered = $this->builder()->verify($dest, ['public_key' => $kp['public']]);
        $this->assertFalse($tampered['signature_ok']);
        $this->assertContains($summary['components']['update']['file'], $tampered['tampered']);

        // A machine-bound php.ini (the 0.8.4 shape) and a tampered mysqld are reported by the audit.
        copy(base_path('tests/Fixtures/edge/PhpIni/laragon-like.ini'), $dest . '/php/php.ini');
        file_put_contents($dest . '/mysql/bin/mysqld.exe', 'swapped');
        $old = $this->builder()->verify($dest);
        $this->assertFalse($old['ok']);
        $this->assertFalse($old['php_ini']['ok']);
        $this->assertNotEmpty(array_filter($old['php_ini']['problems'], fn ($p) => str_starts_with($p, 'EXTENSION_DIR_NOT_RELATIVE')));
        $this->assertNotEmpty(array_filter($old['errors'], fn ($e) => str_contains($e, 'MYSQL_MYSQLD_TAMPERED')));
        $this->assertNull($old['signature_ok'], 'no key given: not checked');
    }

    public function test_a_runtime_that_cannot_be_made_portable_is_refused(): void
    {
        // A required extension disabled in the source ini: sanitize() never enables extensions, so validation refuses.
        $ini = str_replace('extension=sodium', ';extension=sodium', (string) file_get_contents(base_path('tests/Fixtures/edge/PhpIni/laragon-like.ini')));
        $msg = $this->assertBuildRefused(['php_runtime' => $this->fakePhpRuntime(true, $ini), 'php_smoke' => false], 'PACKAGE_PHP_INI_INVALID');
        $this->assertStringContainsString('EXTENSION_NOT_ENABLED: sodium', $msg);
        // No CA bundle anywhere (runtime, option, config, the file the source ini named) — fail closed.
        $noCa = str_replace('D:\\laragon2\\etc\\ssl\\cacert.pem', 'Z:\\nowhere\\cacert.pem', (string) file_get_contents(base_path('tests/Fixtures/edge/PhpIni/laragon-like.ini')));
        $this->assertBuildRefused(['php_runtime' => $this->fakePhpRuntime(false, $noCa), 'php_smoke' => false], 'PACKAGE_PHP_CA_BUNDLE_MISSING');
        // A runtime without php.ini would run on its compiled-in build paths.
        $bare = $this->fakePhpRuntime();
        unlink($bare . '/php.ini');
        $this->assertBuildRefused(['php_runtime' => $bare, 'php_smoke' => false], 'PACKAGE_PHP_INI_MISSING');
    }

    /** The REAL Laragon runtime of this build host: sanitised, its CA bundle found through its own ini, and the packaged php.exe really starts. */
    public function test_the_real_laragon_runtime_is_packaged_portable_and_passes_the_smoke_run(): void
    {
        $runtime = 'D:/laragon2/bin/php/php-8.3.16-Win32-vs16-x64';
        if (! is_file($runtime . '/php.exe') || ! is_file($runtime . '/php.ini')) {
            $this->markTestSkipped('no Laragon PHP 8.3.16 runtime on this machine');
        }
        [$dest, $summary] = $this->build(['php_runtime' => $runtime]);
        $smoke = $summary['components']['php']['smoke'];
        $this->assertTrue($smoke['ran']);
        $this->assertTrue($smoke['ok']);
        $this->assertSame('8.3.16', $smoke['version']);
        $this->assertSame('ext', $smoke['extension_dir']);
        $this->assertDoesNotMatchRegularExpression('/^[^;\r\n]*laragon/im', (string) file_get_contents($dest . '/php/php.ini'));
        $this->assertTrue($this->builder()->verify($dest)['php_ini']['ok']);
    }

    public function test_the_wizard_and_the_root_setup_launcher_are_packaged_when_present(): void
    {
        $this->plant('scripts/edge/wizard/Start-BingooEdgeWizard.ps1', "# test wizard (planted by EdgePackageRuntimeLayoutTest)\nparam()\n");
        $this->plant('scripts/edge/Setup-BingooEdge.cmd', "@echo off\r\nrem test launcher\r\n");
        [$dest, $summary] = $this->build();
        $this->assertFileExists($dest . '/Setup-BingooEdge.cmd', 'K8: the launcher sits at the package root');
        $this->assertFileExists($dest . '/scripts/Setup-BingooEdge.cmd');
        $this->assertFileExists($dest . '/app/scripts/edge/wizard/Start-BingooEdgeWizard.ps1');
        $this->assertFileExists($dest . '/scripts/wizard/Start-BingooEdgeWizard.ps1');
        $this->assertTrue($summary['layout_checks']['wizard']['present']);
        $this->assertTrue($summary['layout_checks']['wizard']['setup_cmd_root']);
        $this->assertSame([], array_values(array_filter($summary['warnings'], fn ($w) => str_starts_with($w, 'WIZARD'))));
        $manifest = json_decode((string) file_get_contents($dest . '/package-manifest.json'), true);
        $this->assertArrayHasKey('Setup-BingooEdge.cmd', $manifest['files'], 'the root launcher is hashed like every other file');
        $this->assertTrue($this->builder()->verify($dest)['wizard']['ok']);
    }

    public function test_a_strict_0_9_release_layout_refuses_a_package_without_the_wizard(): void
    {
        if (is_dir(base_path('scripts/edge/wizard'))) {
            $this->markTestSkipped('the real wizard is present in this tree (after stream B integration)');
        }
        $msg = $this->assertBuildRefused(['strict_layout' => true], 'PACKAGE_LAYOUT_FAILED');
        $this->assertStringContainsString('WIZARD_MISSING', $msg);
        // The same gate is derived for a 0.9+ RELEASE build without any override.
        config(['edge.app_version' => '0.9.0-edge']);
        $this->assertStringContainsString('WIZARD_MISSING', $this->assertBuildRefused([], 'PACKAGE_LAYOUT_FAILED', ['build_mode' => 'release']));
    }

    /** The 0.9 release layout with the REAL tree (stream B's wizard): the VC++ prerequisite is mandatory, then everything passes. */
    public function test_a_strict_0_9_release_package_needs_the_vc_prerequisite_and_then_passes_the_strict_audit(): void
    {
        if (! is_dir(base_path('scripts/edge/wizard')) || ! is_file(base_path('scripts/edge/Setup-BingooEdge.cmd'))) {
            $this->markTestSkipped('stream B wizard not in this tree');
        }
        $msg = $this->assertBuildRefused(['strict_layout' => true, 'php_runtime' => $this->fakePhpRuntime(), 'php_smoke' => false], 'PACKAGE_LAYOUT_FAILED');
        $this->assertStringContainsString('PREREQ_VCREDIST_MISSING', $msg);

        $kp = EdgeEnrollmentCrypto::generateKeypair();
        [$dest, $summary] = $this->build(['strict_layout' => true, 'php_runtime' => $this->fakePhpRuntime(), 'php_smoke' => false,
            'mysql_runtime' => $this->fakeMysqlRuntime(), 'mysql_probe' => false, 'vc_redist' => $this->fakeVcRedist(),
            'signing_key' => $kp['secret'], 'signing_key_id' => EdgeSigningKeyStore::keyId($kp['public'])]);
        $this->assertSame([], array_values(array_filter($summary['warnings'], fn ($w) => ! str_starts_with($w, 'DEV_SIGNED'))), json_encode($summary['warnings']));
        $verify = $this->builder()->verify($dest, ['public_key' => $kp['public'], 'require_signature' => true, 'strict' => true]);
        $this->assertTrue($verify['ok'], json_encode($verify['errors']));
        $this->assertTrue($verify['strict_layout']);
        $this->assertSame('ok', $verify['wizard']['severity']);
        $this->assertSame('ok', $verify['cloud_url']['severity']);
        $this->assertTrue($verify['checksums_signature_ok']);
        $this->assertTrue($verify['prereqs']['vc_redist']['ok']);
        // A strict audit refuses the same package once its VC++ installer is removed (and lists it as missing).
        unlink($dest . '/prereqs/VC_redist.x64.exe');
        $broken = $this->builder()->verify($dest, ['public_key' => $kp['public'], 'strict' => true]);
        $this->assertFalse($broken['ok']);
        $this->assertNotEmpty(array_filter($broken['errors'], fn ($e) => str_starts_with($e, 'PREREQ_VCREDIST_MISSING')));
    }

    public function test_a_packaged_script_with_timespan_maxvalue_fails_the_build(): void
    {
        $this->plant('scripts/edge/Install-EdgeZzProbeTask.ps1', "# probe planted by EdgePackageRuntimeLayoutTest\n\$s = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::MaxValue)\n");
        $msg = $this->assertBuildRefused([], 'PACKAGE_LAYOUT_FAILED');
        $this->assertStringContainsString('SCRIPT_WIN10_INCOMPATIBLE', $msg);
        $this->assertStringContainsString('Install-EdgeZzProbeTask.ps1:2', $msg);
    }

    public function test_the_cloud_rendered_counter_setup_templates_never_ride_in_a_package(): void
    {
        $this->plant('resources/edge/counter-setup/Setup-Counter.ps1.tpl', "# counter template planted by EdgePackageRuntimeLayoutTest\n");
        $cfg = (array) config('edge.artifact');
        $cfg['include'] = ['app', 'bootstrap', 'config', 'routes', 'public/index.php', 'artisan', 'composer.json', 'scripts/edge', 'database/migrations', 'resources/edge'];
        $cfg['runtime_dirs'] = ['bootstrap/cache', 'storage/logs'];
        // Prove the BOUNDARY gate on its own: drop the artifact-level exclusion (config/edge.php also excludes the templates since 0.9).
        $cfg['exclude'] = array_values(array_diff((array) ($cfg['exclude'] ?? []), ['resources/edge/counter-setup']));
        $meta = ['artifact_meta' => ['git_commit' => 'x', 'build_mode' => 'dev'], 'source_root' => base_path()];
        // Without the exclusion `resources` would carry it: the package boundary refuses (Cloud-only sentinel).
        $dest = $this->tmp();
        try {
            (new EdgePackageBuilder(new EdgeArtifactBuilder($cfg), app(EdgeUpdatePackageService::class)))->build($dest, $meta);
            $this->fail('a package carrying resources/edge/counter-setup must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PACKAGE_BOUNDARY_FAILED', $e->getMessage());
            $this->assertStringContainsString('resources\/edge\/counter-setup', $e->getMessage());
        }
        // edge:build-package adds the exclusion: the package builds and the template is absent.
        $this->assertStringContainsString("'resources/edge/counter-setup'", (string) file_get_contents(app_path('Console/Commands/EdgeBuildPackageCommand.php')));
        $cfg['exclude'][] = 'resources/edge/counter-setup';
        $dest2 = $this->tmp();
        (new EdgePackageBuilder(new EdgeArtifactBuilder($cfg), app(EdgeUpdatePackageService::class)))->build($dest2, $meta);
        $this->assertFileDoesNotExist($dest2 . '/app/resources/edge/counter-setup/Setup-Counter.ps1.tpl');
        $this->assertTrue($this->builder()->verify($dest2)['boundary_audit']['ok']);
    }

    public function test_the_audit_command_verifies_the_signature_with_a_given_public_key(): void
    {
        $kp = EdgeEnrollmentCrypto::generateKeypair();
        [$dest] = $this->build(['signing_key' => $kp['secret'], 'signing_key_id' => EdgeSigningKeyStore::keyId($kp['public'])]);
        $this->artisan('edge:audit-package', ['dir' => $dest, '--public-key' => $kp['public']])
            ->expectsOutputToContain('signature VALID')->assertExitCode(0);
        // --server-cert: the Branch Server certificate rules (iOS/Android) checked beside the package.
        $good = $this->mintCertFile(730);
        $this->artisan('edge:audit-package', ['dir' => $dest, '--public-key' => $kp['public'], '--server-cert' => $good, '--server-cert-host' => 'bingoo-edge.local', '--server-cert-ip' => '192.168.100.52'])
            ->expectsOutputToContain('Server certificate: OK')->assertExitCode(0);
        $this->artisan('edge:audit-package', ['dir' => $dest, '--public-key' => $kp['public'], '--server-cert' => $this->mintCertFile(900)])
            ->expectsOutputToContain('VALIDITY_TOO_LONG')->assertExitCode(1);
        $keyFile = $this->tmp() . '.pub';
        $this->tempDirs[] = $keyFile;
        file_put_contents($keyFile, EdgeEnrollmentCrypto::generateKeypair()['public'] . "\n");
        $this->artisan('edge:audit-package', ['dir' => $dest, '--public-key-file' => $keyFile])
            ->expectsOutputToContain('signature INVALID')->assertExitCode(1);
        $this->artisan('edge:audit-package', ['dir' => $dest, '--public-key' => 'not-base64!'])->assertExitCode(1);
        // JSON carries signature_ok for tooling (the lead's release checklist and stream C's edge:release:publish).
        \Illuminate\Support\Facades\Artisan::call('edge:audit-package', ['dir' => $dest, '--public-key' => $kp['public'], '--json' => true]);
        $json = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $this->assertTrue($json['ok']);
        $this->assertTrue($json['signature_ok']);
        $this->assertSame(EdgeSigningKeyStore::keyId($kp['public']), $json['signature']['key_id']);

        // An unsigned package: an explicit key or --require-signature fails it; with no key it is "not checked".
        [$unsigned] = $this->build();
        $this->artisan('edge:audit-package', ['dir' => $unsigned])->expectsOutputToContain('not checked')->assertExitCode(0);
        $this->artisan('edge:audit-package', ['dir' => $unsigned, '--require-signature' => true])->assertExitCode(1);
        $this->artisan('edge:audit-package', ['dir' => $unsigned, '--public-key' => $kp['public']])->assertExitCode(1);
    }

    /** A DER .crt (what Export-Certificate -Type CERT writes) with serverAuth + DNS/IP SAN, valid $days days. */
    private function mintCertFile(int $days): string
    {
        $cnf = $this->tmp() . '.cnf';
        $this->tempDirs[] = $cnf;
        file_put_contents($cnf, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\nCN = bingoo-edge.local\n[v3_req]\nbasicConstraints = CA:FALSE\nextendedKeyUsage = serverAuth\nsubjectAltName = DNS:bingoo-edge.local, IP:192.168.100.52\n");
        $cfg = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3_req'];
        $key = openssl_pkey_new($cfg);
        $x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'bingoo-edge.local'], $key, $cfg), null, $key, $days, $cfg);
        openssl_x509_export($x509, $pem);
        $file = $this->tmp() . '.crt';
        $this->tempDirs[] = $file;
        file_put_contents($file, base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem)));

        return $file;
    }

    private function rrmdir(string $dir): void
    {
        if (is_file($dir)) {
            @unlink($dir);

            return;
        }
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
