<?php

namespace Tests\Feature\Edge;

use Tests\TestCase;

/**
 * PACKAGING-DRYRUN-IMMUTABLE-1 (0.8.1, owner §5) — a Branch Server runtime never writes Laravel's bootstrap caches into its own
 * directory. The 0.8.0 pre-install dry-runs booted the signed package in place and Laravel wrote bootstrap/cache/packages.php +
 * services.php INTO the artifact (two unlisted files that failed `edge:audit-package` on 23 Oct 2026). bootstrap/app.php now
 * redirects every bootstrap cache of a runtime started with BINGOO_EDGE_ENV_DIR to <data-root>/logs/runtime-cache/<sha12> —
 * proven here with a REAL child process booting this tree's artisan under a scratch data root.
 */
class EdgeRuntimeCacheIsolationTest extends TestCase
{
    public function test_a_runtime_booted_with_an_appliance_env_dir_writes_its_bootstrap_caches_under_the_data_root_not_into_itself(): void
    {
        // the scratch must sit on the SAME drive as the runtime (Laravel only accepts a relative cache path; every supported
        // appliance layout keeps install root and data root on one drive) - so the test data root lives under storage/framework/testing
        $dataRoot = base_path("storage/framework/testing/edge-cache-iso-" . uniqid());
        $configDir = $dataRoot . DIRECTORY_SEPARATOR . "config";
        mkdir($configDir, 0775, true);
        file_put_contents($configDir . DIRECTORY_SEPARATOR . 'appliance.env', "APP_ROLE=branch_server\nAPP_ENV=testing\nLOG_CHANNEL=stderr\n");

        $base = base_path();
        $ownPackages = $base . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'packages.php';
        $ownServices = $base . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'services.php';
        $before = [is_file($ownPackages) ? filemtime($ownPackages) : null, is_file($ownServices) ? filemtime($ownServices) : null];
        clearstatcache();

        $cmd = [PHP_BINARY, $base . DIRECTORY_SEPARATOR . 'artisan', '--version'];
        $env = array_merge(getenv(), ['BINGOO_EDGE_ENV_DIR' => $configDir]);
        foreach (['APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $k) {
            unset($env[$k]);
        }
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, "the runtime booted under the scratch data root: {$out}\n{$err}");
        $this->assertStringContainsString('Laravel Framework', $out);

        $expected = $dataRoot . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'runtime-cache' . DIRECTORY_SEPARATOR
            . substr(sha1(strtolower(str_replace('\\', '/', (string) realpath($base)))), 0, 12);
        $this->assertDirectoryExists($expected, 'the scratch directory keyed by the runtime path was created');
        $this->assertFileExists($expected . DIRECTORY_SEPARATOR . 'packages.php', 'the package-discovery cache went to the data root');
        $this->assertFileExists($expected . DIRECTORY_SEPARATOR . 'services.php');

        clearstatcache();
        $after = [is_file($ownPackages) ? filemtime($ownPackages) : null, is_file($ownServices) ? filemtime($ownServices) : null];
        $this->assertSame($before, $after, 'nothing was written into the runtime\'s own bootstrap/cache — the signed artifact stays byte-identical');

        // cleanup
        foreach (glob($expected . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($expected);
        @rmdir(dirname($expected));
        @rmdir(dirname($expected, 2));
        @unlink($configDir . DIRECTORY_SEPARATOR . 'appliance.env');
        @rmdir($configDir);
        @rmdir($dataRoot);
    }

    public function test_a_cloud_host_without_the_appliance_env_dir_is_unaffected(): void
    {
        $base = base_path();
        $cmd = [PHP_BINARY, $base . DIRECTORY_SEPARATOR . 'artisan', '--version'];
        $env = getenv();
        unset($env['BINGOO_EDGE_ENV_DIR']);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base, $env);
        $out = stream_get_contents($pipes[1]);
        $code = proc_close($proc);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Laravel Framework', $out);
        $this->assertDirectoryDoesNotExist(dirname(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'runtime-cache', 'no scratch directory is invented for a Cloud host');
    }
}
