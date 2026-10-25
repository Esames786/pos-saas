<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeUpdateInstaller;
use App\Support\EdgeApplianceLayout;
use Tests\TestCase;

/**
 * PACKAGING-DRYRUN-IMMUTABLE-1 (0.8.1) — the 0.8.0 pre-install dry-runs booted the signed artifact's artisan in place, and
 * Laravel wrote its package-discovery caches (bootstrap/cache/packages.php, services.php) INTO the immutable release directory
 * (two unlisted files that failed `edge:audit-package` on 23 Oct 2026). Every artisan run the installer performs against a
 * runtime directory now carries Laravel's APP_*_CACHE overrides pointing at a scratch directory under the appliance's writable
 * area, keyed by the runtime directory — nothing generated can land inside a signed artifact again.
 */
class EdgeUpdateInstallerCacheIsolationTest extends TestCase
{
    public function test_every_bootstrap_cache_file_is_redirected_to_a_scratch_directory_outside_the_runtime(): void
    {
        $runtime = 'C:\\Program Files\\Bingoo Edge\\runtime\\versions\\0.8.1-edge';
        $env = EdgeUpdateInstaller::isolatedCacheEnv($runtime);

        $this->assertSame(['APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'], array_keys($env));
        $scratch = dirname($env['APP_PACKAGES_CACHE']);
        foreach ($env as $key => $path) {
            $this->assertSame($scratch, dirname($path), "{$key} lives in the same scratch directory");
            $this->assertStringStartsNotWith(strtolower($runtime), strtolower($path), "{$key} never points inside the runtime directory");
        }
        $this->assertStringStartsWith(rtrim(EdgeApplianceLayout::logsDir(), '/\\') . DIRECTORY_SEPARATOR . 'runtime-cache', $scratch, 'the scratch lives under the appliance logs/data area (writable, outside every runtime)');
        $this->assertDirectoryExists($scratch, 'created on first use so Laravel can write there at once');
        $this->assertSame('packages.php', basename($env['APP_PACKAGES_CACHE']));
        $this->assertSame('services.php', basename($env['APP_SERVICES_CACHE']));
    }

    public function test_two_runtime_directories_get_two_scratch_directories_and_the_same_one_is_stable(): void
    {
        $a = EdgeUpdateInstaller::isolatedCacheEnv('D:/staged/BingooEdge-0.8.1-edge/app');
        $b = EdgeUpdateInstaller::isolatedCacheEnv('D:/staged/BingooEdge-0.8.2-edge/app');
        $a2 = EdgeUpdateInstaller::isolatedCacheEnv('D:\\staged\\BingooEdge-0.8.1-edge\\app'); // same dir, other separators / case

        $this->assertNotSame(dirname($a['APP_PACKAGES_CACHE']), dirname($b['APP_PACKAGES_CACHE']), 'a dry-run of one artifact never reuses another artifact\'s caches');
        $this->assertSame(dirname($a['APP_PACKAGES_CACHE']), dirname($a2['APP_PACKAGES_CACHE']), 'the key normalises separators and case');
    }
}
