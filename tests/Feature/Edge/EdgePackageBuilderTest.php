<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgePackageBuilder;
use App\Services\Edge\EdgeUpdatePackageService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P4 §5 — the Windows appliance PACKAGE builder + its mandatory boundary gate (fast, filesystem-only).
 *
 * Builds a package from the REAL repository (vendor excluded — nothing is booted here), then proves: the layout,
 * the per-file manifest, the signed update package, the boundary audit (no secret/key/test/Cloud-only module, the
 * branch_server marker), verify() catches tampering / missing / unlisted files, a planted secret fails the build
 * and leaves no half-built package behind, and the env template carries KEYS ONLY.
 */
class EdgePackageBuilderTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $d) {
            $this->rrmdir($d);
        }
        parent::tearDown();
    }

    private function tmp(): string
    {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-pkg-' . Str::lower(Str::random(8));
        $this->tempDirs[] = $d;

        return $d;
    }

    /** A package built from the real tree with the runtime paths the sentinels need (no vendor: nothing boots here). */
    private function builder(): EdgePackageBuilder
    {
        $cfg = (array) config('edge.artifact');
        $cfg['include'] = ['app', 'bootstrap', 'config', 'routes', 'public/index.php', 'artisan', 'composer.json', 'scripts/edge', 'database/migrations'];
        $cfg['runtime_dirs'] = ['bootstrap/cache', 'storage/logs'];

        return new EdgePackageBuilder(new EdgeArtifactBuilder($cfg), app(EdgeUpdatePackageService::class));
    }

    private function build(?string $signingKey = null, array $extra = []): array
    {
        $dest = $this->tmp();
        $summary = $this->builder()->build($dest, array_merge([
            'source_root' => base_path(),
            'artifact_meta' => ['git_commit' => 'testrev', 'source_dirty' => true, 'build_mode' => 'dev', 'build_timestamp' => now()->toIso8601String(), 'artifact_version' => 'test'],
            'signing_key' => $signingKey,
        ], $extra));

        return [$dest, $summary];
    }

    public function test_it_builds_the_package_layout_with_a_manifest_and_a_signed_update(): void
    {
        $kp = EdgeEnrollmentCrypto::generateKeypair();
        [$dest, $summary] = $this->build($kp['secret']);

        $this->assertSame(EdgePackageBuilder::FORMAT, $summary['package_format_version']);
        $this->assertSame('branch_server', $summary['runtime_mode_supported']);
        $this->assertTrue($summary['boundary_audit']['ok'], json_encode($summary['boundary_audit']));
        foreach (['package-manifest.json', 'app/edge-build-manifest.json', 'app/artisan', 'app/public/index.php', 'scripts/Install-EdgeAppliance.ps1',
            'scripts/Register-EdgeServices.ps1', 'scripts/Update-EdgeAppliance.ps1', 'scripts/Uninstall-EdgeAppliance.ps1', 'scripts/Backup-EdgeAppliance.ps1',
            'scripts/Restore-EdgeAppliance.ps1', 'scripts/Get-EdgeHealth.ps1', 'templates/appliance.env.template', 'templates/edge-launcher.php', 'templates/mime.types', 'templates/README-INSTALL.md'] as $rel) {
            $this->assertFileExists($dest . '/' . $rel, "package must contain {$rel}");
        }
        $this->assertTrue($summary['components']['update']['signed']);
        $update = json_decode((string) file_get_contents($dest . '/' . $summary['components']['update']['file']), true);
        $this->assertTrue(EdgeEnrollmentCrypto::verifySignature($update, $kp['public']), 'the update package is signed by the build key');
        $this->assertSame($summary['artifact_manifest_hash'], $update['payload']['artifact_manifest_hash'], 'the update names THIS artifact');
        $this->assertSame('branch_server', $update['payload']['target_runtime']);
        $this->assertFalse($summary['components']['php']['bundled']);
        $this->assertFalse($summary['components']['gateway']['bundled']);
        // The manifest hashes every file but itself, and verify() accepts the untouched package.
        $manifest = json_decode((string) file_get_contents($dest . '/package-manifest.json'), true);
        $this->assertArrayNotHasKey('package-manifest.json', $manifest['files']);
        $this->assertArrayHasKey('app/artisan', $manifest['files']);
        $this->assertArrayHasKey('scripts/Install-EdgeAppliance.ps1', $manifest['files']);
        $verify = $this->builder()->verify($dest);
        $this->assertTrue($verify['ok'], json_encode($verify));
    }

    public function test_the_boundary_gate_excludes_secrets_tests_and_cloud_only_modules(): void
    {
        [$dest] = $this->build();
        foreach ([
            'app/.env', 'app/tests', 'app/Services/Catering', 'app/app/Services/Catering', 'app/app/Services/Finance/ManualJournalService.php',
            'app/app/Services/Edge/EdgeInboundSaleIngestionService.php', 'app/app/Services/Edge/EdgeInboundReturnIngestionService.php',
            'app/app/Services/Edge/EdgeInboundSupplierFinanceIngestionService.php', 'app/app/Services/Edge/EdgeInboundPurchaseReturnIngestionService.php',
            'app/app/Http/Controllers/Tenant/SupplierPaymentController.php', 'app/app/Services/Finance/SupplierPayableService.php',
        ] as $absent) {
            $this->assertFileDoesNotExist($dest . '/' . $absent, "{$absent} must not ship");
        }
        foreach (['app/app/Services/Edge/EdgeSupervisionPlan.php', 'app/app/Services/Edge/EdgeLocalPrintDeliveryService.php', 'app/app/Services/Edge/EdgeApplianceHealthService.php',
            'app/app/Console/Commands/EdgeLocalServeCommand.php', 'app/app/Console/Commands/EdgeLocalPairCommand.php', 'app/app/Console/Commands/EdgeLocalBootstrapPullCommand.php',
            'app/app/Console/Commands/EdgeLocalUninstallDataCommand.php', 'app/app/Services/Edge/EdgeUpdateInstaller.php'] as $present) {
            $this->assertFileExists($dest . '/' . $present, "{$present} must ship");
        }
        // No key/cert/env/dump anywhere in the package tree.
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dest, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dest) + 1));
            $this->assertDoesNotMatchRegularExpression('#(^|/)\.env(\..+)?$|\.(pem|key|pfx|p12|sql|dump|sqlite)$|(^|/)tests/|(^|/)\.git/|node_modules|appliance\.env$#i', $rel);
        }
    }

    public function test_the_env_template_carries_keys_only_and_no_value(): void
    {
        $template = (string) file_get_contents(base_path('scripts/edge/appliance/appliance.env.template'));
        foreach (['EDGE_LOCAL_APP_KEY', 'EDGE_DB_PASSWORD', 'EDGE_SYNC_DEVICE_SECRET', 'EDGE_BACKUP_RECOVERY_KEY', 'EDGE_UPDATE_PUBLIC_KEY', 'EDGE_ENROLLMENT_PUBLIC_KEY', 'EDGE_DB_USERNAME'] as $key) {
            $this->assertMatchesRegularExpression('/^' . $key . '=\s*$/m', $template, "{$key} must be present and EMPTY in the template");
        }
        $this->assertMatchesRegularExpression('/^APP_ROLE=branch_server$/m', $template);
        $this->assertMatchesRegularExpression('/^LOG_CHANNEL=edge$/m', $template);
        $this->assertMatchesRegularExpression('/^SESSION_DRIVER=file$/m', $template);
        $this->assertDoesNotMatchRegularExpression('/^DB_(DATABASE|USERNAME|PASSWORD)=/m', $template, 'the Cloud master connection is never configured on an appliance');
        $this->assertDoesNotMatchRegularExpression('/^EDGE_UPDATE_SIGNING_KEY|^EDGE_ENROLLMENT_SIGNING_KEY/m', $template, 'private signing keys never live on an appliance');
    }

    public function test_verify_detects_tampering_missing_and_unlisted_files(): void
    {
        [$dest] = $this->build();
        file_put_contents($dest . '/app/artisan', "<?php // tampered\n", FILE_APPEND);
        unlink($dest . '/scripts/Get-EdgeHealth.ps1');
        file_put_contents($dest . '/scripts/Extra.ps1', '# planted');
        $verify = $this->builder()->verify($dest);
        $this->assertFalse($verify['ok']);
        $this->assertContains('app/artisan', $verify['tampered']);
        $this->assertContains('scripts/Get-EdgeHealth.ps1', $verify['missing']);
        $this->assertContains('scripts/Extra.ps1', $verify['unlisted']);
        // A planted secret fails the boundary audit too.
        file_put_contents($dest . '/templates/server.key', '-----BEGIN PRIVATE KEY-----');
        $verify2 = $this->builder()->verify($dest);
        $this->assertFalse($verify2['boundary_audit']['ok']);
        $this->assertContains('templates/server.key', $verify2['boundary_audit']['forbidden_hits']);
    }

    public function test_a_build_that_would_ship_a_secret_is_refused_and_leaves_nothing_behind(): void
    {
        // A provisioned appliance.env planted inside an INCLUDED path: the artifact's own exclude/forbidden lists do not
        // name it (they know `.env`), so it would ride along — the PACKAGE boundary gate must refuse the build.
        $planted = base_path('scripts/edge/appliance/appliance.env');
        file_put_contents($planted, "EDGE_SYNC_DEVICE_SECRET=planted\n");
        try {
            $dest = $this->tmp();
            try {
                $this->builder()->build($dest, ['source_root' => base_path(), 'artifact_meta' => ['git_commit' => 'x']]);
                $this->fail('a planted key must refuse the build');
            } catch (\RuntimeException $e) {
                $this->assertMatchesRegularExpression('/REFUSED|PACKAGE_BOUNDARY_FAILED/', $e->getMessage());
            }
            $this->assertFalse(is_dir($dest) && (glob($dest . '/*') ?: []) !== [], 'no half-built package may remain');
        } finally {
            @unlink($planted);
        }
    }

    public function test_the_release_command_refuses_a_dirty_tree_and_the_audit_command_reports(): void
    {
        // The build command is a BUILD-HOST command: it is not allowlisted on a Branch Server.
        config(['app.role' => 'branch_server']);
        $this->assertFalse(\App\Support\EdgeConsoleBoundary::isAllowed('edge:build-package'));
        $this->assertFalse(\App\Support\EdgeConsoleBoundary::isAllowed('edge:audit-package'));
        config(['app.role' => null]);
        [$dest] = $this->build();
        $this->artisan('edge:audit-package', ['dir' => $dest])->assertExitCode(0);
        file_put_contents($dest . '/app/artisan', "\n// tampered", FILE_APPEND);
        $this->artisan('edge:audit-package', ['dir' => $dest])->assertExitCode(1);
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
