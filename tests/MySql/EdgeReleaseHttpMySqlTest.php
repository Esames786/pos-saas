<?php

namespace Tests\MySql;

use App\Models\Master\EdgeDevice;
use App\Models\Master\EdgeRelease;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgePackageBuilder;
use App\Services\Edge\EdgeSigningKeyStore;
use App\Services\Edge\EdgeUpdatePackageService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeSetupCloudFixture;
use ZipArchive;

/**
 * EDGE 0.9 stream C — K6 releases: `edge:release:publish` registers ONLY a package that passes the existing integrity +
 * boundary audit AND whose signed update package verifies against the configured update PUBLIC key (wrong key, tampered
 * bytes, pinned package, missing key → refused, nothing registered); the zip is exactly the verified file set;
 * GET /api/edge/updates/latest offers a newer release with a 15-minute SIGNED download URL bound to the device, the
 * maintenance window and the Update-now flag; the download re-verifies the zip and refuses expired / tampered / foreign
 * links; entitlement gate 3 and the page's "Download Branch Server Setup" follow the registry.
 */
class EdgeReleaseHttpMySqlTest extends MySqlTenantTestCase
{
    use EdgeSetupCloudFixture;

    public const TENANT_CODE = 'e09crelease';

    private string $work;
    private string $originalStorage;
    private array $releaseKey;
    private EdgeDevice $device;
    private string $secret = 'release-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEdgeCloud();
        $this->work = str_replace('\\', '/', sys_get_temp_dir()) . '/e09c-release-' . Str::random(8);
        @mkdir($this->work . '/storage/app', 0755, true);
        @mkdir($this->work . '/storage/framework/testing', 0755, true);
        $this->originalStorage = storage_path();
        $this->app->useStoragePath($this->work . '/storage');   // release zips land in a throwaway storage root
        $this->releaseKey = EdgeEnrollmentCrypto::generateKeypair();
        config(['edge.update.public_key' => $this->releaseKey['public']]);
        DB::connection('master')->table('edge_releases')->delete();
        $this->device = $this->makeDevice($this->branchA, $this->secret);
        $this->device->forceFill(['app_version' => '0.9.0-edge'])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        try {
            DB::connection('master')->table('edge_releases')->delete();
            $this->cleanupEdgeCloud();
        } catch (\Throwable $e) {
        }
        $this->app->useStoragePath($this->originalStorage);
        $this->rmrf($this->work);
        parent::tearDown();
    }

    /* ── a synthetic edge-package-v1 directory (the real builder layout, tiny files) ─────────────── */

    private function buildPackage(string $version, ?string $signingSecret = null, array $updateOverrides = []): string
    {
        $dir = $this->work . '/pkg-' . $version . '-' . Str::random(4);
        $sentinels = (new \ReflectionClassConstant(EdgePackageBuilder::class, 'EDGE_RUNTIME_SENTINELS'))->getValue();
        $appFiles = [];
        foreach ($sentinels as $s) {
            if ($s === 'app/edge-build-manifest.json') {
                continue;
            }
            $this->writeFile($dir . '/' . $s, "<?php // {$s} {$version}\n");
            $appFiles[] = substr($s, 4);
        }
        $this->writeFile($dir . '/app/config/app.php', "<?php return ['name' => 'Bingoo Edge {$version}', 'build' => '" . Str::random(12) . "'];" . PHP_EOL);
        $appFiles[] = 'config/app.php';
        $hashes = [];
        foreach ($appFiles as $rel) {
            $hashes[$rel] = hash_file('sha256', $dir . '/app/' . $rel);
        }
        ksort($hashes);
        $manifestHash = hash('sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES));
        $this->writeFile($dir . '/app/edge-build-manifest.json', json_encode(['runtime_mode_supported' => 'branch_server', 'edge_app_version' => $version,
            'git_commit' => 'abc1234', 'config_schema' => 'edge-config-v3', 'files' => $hashes, 'manifest_hash' => $manifestHash], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->writeFile($dir . '/scripts/Install-EdgeAppliance.ps1', "# installer {$version}\n");

        $pkg = app(EdgeUpdatePackageService::class)->build($dir . '/app', $signingSecret ?? $this->releaseKey['secret'],
            array_merge(['min_previous_edge_version' => '0.8.5-edge'], $updateOverrides));
        $updateRel = 'update/edge-update-' . $version . '.json';
        $this->writeFile($dir . '/' . $updateRel, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($dir)), '/');
            $files[$rel] = hash_file('sha256', $f->getPathname());
        }
        ksort($files);
        $this->writeFile($dir . '/package-manifest.json', json_encode([
            'package_format_version' => EdgePackageBuilder::FORMAT, 'edge_app_version' => $version, 'artifact_manifest_hash' => $manifestHash,
            'components' => ['update' => ['signed' => true, 'file' => $updateRel, 'edge_app_version' => $version]],
            'file_count' => count($files), 'package_hash' => hash('sha256', json_encode($files)), 'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $dir;
    }

    private function writeFile(string $path, string $content): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    private function rmrf(string $dir): void
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

    private function publish(string $dir, array $opts = []): array
    {
        $code = Artisan::call('edge:release:publish', ['dir' => $dir, '--json' => true] + $opts);
        $out = Artisan::output();

        return [$code, json_decode($out, true) ?: ['raw' => $out]];
    }

    private function latest(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($this->central('/api/edge/updates/latest'), $this->deviceHeaders($this->device, $this->secret));
    }

    /* ── publish ────────────────────────────────────────────────────────── */

    public function test_a_signed_verified_package_is_published_as_exactly_its_verified_file_set(): void
    {
        $dir = $this->buildPackage('0.9.1-edge');
        $this->assertTrue((new EdgePackageBuilder(\App\Services\Edge\EdgeArtifactBuilder::fromConfig(), app(EdgeUpdatePackageService::class)))->verify($dir)['ok'], 'the fixture is a valid package');

        [$code, $out] = $this->publish($dir);
        $this->assertSame(0, $code, json_encode($out));
        $this->assertSame('published', $out['status']);
        $this->assertSame('0.9.1-edge', $out['version']);
        $this->assertSame(EdgeSigningKeyStore::keyId($this->releaseKey['public']), $out['signing_key_id']);

        $r = EdgeRelease::where('version', '0.9.1-edge')->firstOrFail();
        $this->assertSame('stable', $r->channel);
        $this->assertSame('0.8.5-edge', $r->min_from_version);
        $this->assertSame('update/edge-update-0.9.1-edge.json', $r->update_package_file);
        $abs = storage_path('app/' . $r->storage_path);
        $this->assertStringStartsWith(str_replace('\\', '/', $this->work), str_replace('\\', '/', $abs));
        $this->assertSame($r->zip_sha256, hash_file('sha256', $abs));
        $this->assertSame($r->size_bytes, filesize($abs));

        $manifest = json_decode((string) file_get_contents($dir . '/package-manifest.json'), true);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($abs) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        $expected = array_merge(array_keys($manifest['files']), ['package-manifest.json']);
        sort($expected);
        sort($names);
        $this->assertSame($expected, $names, 'package-manifest.json at the root + every listed file, nothing else');

        // An unlisted stray file in the directory is never shipped… (verify() would already refuse it outside app/vendor)
        // and republishing the identical package is idempotent.
        [$code2, $out2] = $this->publish($dir);
        $this->assertSame(0, $code2);
        $this->assertSame('already_published', $out2['status']);
        $this->assertSame(1, EdgeRelease::count());
    }

    public function test_publishing_refuses_a_wrong_key_tampered_bytes_a_pinned_package_and_a_missing_public_key(): void
    {
        $other = EdgeEnrollmentCrypto::generateKeypair();
        [$code, $out] = $this->publish($this->buildPackage('0.9.2-edge', $other['secret']));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('RELEASE_VERIFY_FAILED', $out['error']);
        $this->assertStringContainsString('UPDATE_SIGNATURE_INVALID', $out['error']);

        $tampered = $this->buildPackage('0.9.3-edge');
        file_put_contents($tampered . '/app/config/app.php', "<?php return ['name' => 'evil'];\n");
        [$code, $out] = $this->publish($tampered);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('RELEASE_PACKAGE_INVALID', $out['error']);

        [$code, $out] = $this->publish($this->buildPackage('0.9.4-edge', null, ['target_branch_id' => 7]));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('RELEASE_PINNED_PACKAGE', $out['error']);

        $dir = $this->buildPackage('0.9.5-edge');
        config(['edge.update.public_key' => null]);
        [$code, $out] = $this->publish($dir);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('RELEASE_NO_PUBLIC_KEY', $out['error']);

        $this->assertSame(0, EdgeRelease::count(), 'nothing was registered');
        $this->assertSame([], glob(storage_path('app/edge-releases/stable/*.zip')) ?: [], 'no zip was left behind');

        // Same version, different contents → refused.
        config(['edge.update.public_key' => $this->releaseKey['public']]);
        $this->assertSame(0, $this->publish($this->buildPackage('0.9.6-edge'))[0]);
        [$code, $out] = $this->publish($this->buildPackage('0.9.6-edge'));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('RELEASE_VERSION_EXISTS', $out['error']);
        // --dry-run verifies and registers nothing.
        [$code, $out] = $this->publish($this->buildPackage('0.9.7-edge'), ['--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertSame('verified', $out['status']);
        $this->assertFalse(EdgeRelease::where('version', '0.9.7-edge')->exists());
    }

    /* ── latest + download ──────────────────────────────────────────────── */

    public function test_latest_offers_a_newer_release_with_a_signed_download_that_streams_the_verified_zip(): void
    {
        $this->latest()->assertOk()->assertJsonPath('available', false)->assertJsonPath('reason', 'no_release')
            ->assertJsonPath('maintenance_window', ['start' => '03:00', 'end' => '05:00', 'tz' => 'Asia/Karachi']);

        $this->assertSame(0, $this->publish($this->buildPackage('0.9.1-edge'))[0]);
        $this->assertSame(0, $this->publish($this->buildPackage('0.9.10-edge'))[0]);   // version_compare, not string order
        $r = EdgeRelease::where('version', '0.9.10-edge')->firstOrFail();
        $this->device->forceFill(['maintenance_window_start' => '01:30', 'maintenance_window_end' => '02:15', 'update_now_requested_at' => now()])->save();

        $res = $this->latest()->assertOk();
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $j = $res->json();
        $this->assertTrue($j['available']);
        $this->assertSame('0.9.10-edge', $j['version']);
        $this->assertSame('0.9.0-edge', $j['current_version']);
        $this->assertSame($r->public_uuid, $j['release_id']);
        $this->assertSame($r->manifest_sha256, $j['manifest_sha256']);
        $this->assertSame($r->package_hash, $j['package_hash']);
        $this->assertSame($r->zip_sha256, $j['zip_sha256']);
        $this->assertSame($r->size_bytes, $j['size_bytes']);
        $this->assertSame('0.8.5-edge', $j['min_from_version']);
        $this->assertSame(EdgeSigningKeyStore::keyId($this->releaseKey['public']), $j['signing_key_id']);
        $this->assertTrue($j['update_now']);
        $this->assertSame(['start' => '01:30', 'end' => '02:15', 'tz' => 'Asia/Karachi'], $j['maintenance_window']);
        $this->assertStringContainsString('signature=', $j['download_url']);
        $this->assertStringContainsString('d=' . $this->device->public_uuid, $j['download_url']);

        $dl = $this->get($j['download_url'])->assertOk();
        $this->assertSame($r->zip_sha256, $dl->headers->get('X-Edge-Release-Sha256'));
        $this->assertSame($r->zip_sha256, hash('sha256', (string) file_get_contents($dl->baseResponse->getFile()->getPathname())));
        $this->assertStringContainsString('BingooEdgeSetup-0.9.10-edge.zip', (string) $dl->headers->get('Content-Disposition'));

        // Tampered link, another device's id in a signed link, expired link.
        $this->get(str_replace('signature=', 'signature=00', $j['download_url']))->assertStatus(403);
        $this->get(str_replace('d=' . $this->device->public_uuid, 'd=' . Str::uuid(), $j['download_url']))->assertStatus(403);
        Carbon::setTestNow(now()->addMinutes(16));
        $this->get($j['download_url'])->assertStatus(403);
        Carbon::setTestNow();

        // A revoked device's link is dead even while the signature is valid.
        $fresh = $this->latest()->json('download_url');
        $this->device->forceFill(['status' => EdgeDevice::STATUS_REVOKED, 'active_slot' => null, 'revoked_at' => now()])->save();
        $this->get($fresh)->assertStatus(401);
    }

    public function test_a_zip_changed_on_disk_is_never_served(): void
    {
        $this->assertSame(0, $this->publish($this->buildPackage('0.9.1-edge'))[0]);
        $url = $this->latest()->json('download_url');
        $r = EdgeRelease::firstOrFail();
        $abs = storage_path('app/' . $r->storage_path);
        $bytes = (string) file_get_contents($abs);
        file_put_contents($abs, substr($bytes, 0, -1) . chr(ord(substr($bytes, -1)) ^ 1));   // same size, one bit flipped
        $this->get($url)->assertStatus(409)->assertJsonPath('code', 'EDGE_RELEASE_UNAVAILABLE');
    }

    public function test_up_to_date_and_too_old_devices_get_no_offer(): void
    {
        $this->assertSame(0, $this->publish($this->buildPackage('0.9.1-edge'))[0]);
        $this->device->forceFill(['reported_version' => '0.9.1-edge'])->save();
        $this->latest()->assertOk()->assertJsonPath('available', false)->assertJsonPath('reason', 'up_to_date')->assertJsonPath('latest_version', '0.9.1-edge');
        $this->device->forceFill(['reported_version' => '0.8.2-edge'])->save();
        $this->latest()->assertOk()->assertJsonPath('available', false)->assertJsonPath('reason', 'requires_min_version')->assertJsonPath('min_from_version', '0.8.5-edge');
        $this->getJson($this->central('/api/edge/updates/latest'))->assertStatus(401);
    }

    /* ── gate 3 + the page download ─────────────────────────────────────── */

    public function test_entitlement_gate_3_and_the_page_download_follow_the_registry(): void
    {
        config(['app.edge_installer_path' => null]);
        $tenant = \App\Models\Master\Tenant::findOrFail($this->tenantId);
        app(TenancyManager::class)->activate($tenant);
        $gate = app(OfflineEdgeEntitlementService::class);
        $this->assertFalse($gate->installerIsAvailable());
        $this->assertSame(0, $this->publish($this->buildPackage('0.9.1-edge'))[0]);
        $this->assertTrue($gate->installerIsAvailable());
        $this->assertSame('0.9.1-edge', $gate->installerVersion());
        app(TenancyManager::class)->deactivate();
        config(['database.connections.tenant.database' => $this->tenantDb]);

        $this->grantTo('owner', ['tenant.offline-edge.index', 'tenant.offline-edge.download']);
        $page = $this->actingAsOwner()->get($this->tenantUrl('/settings/offline-edge'))->assertOk();
        $page->assertSee('Download Branch Server Setup')->assertSee('Available v0.9.1-edge')->assertSee(EdgeRelease::firstOrFail()->zip_sha256);
        $dl = $this->actingAsOwner()->get($this->tenantUrl('/settings/offline-edge/download'))->assertOk();
        $this->assertStringContainsString('BingooEdgeSetup-0.9.1-edge.zip', (string) $dl->headers->get('Content-Disposition'));
        $this->assertSame(EdgeRelease::firstOrFail()->zip_sha256, $dl->headers->get('X-Edge-Release-Sha256'));

        // A withdrawn release closes gate 3 again.
        EdgeRelease::query()->update(['status' => EdgeRelease::STATUS_WITHDRAWN]);
        $this->actingAsOwner()->get($this->tenantUrl('/settings/offline-edge'))->assertOk()->assertSee('Not available yet');
    }
}
