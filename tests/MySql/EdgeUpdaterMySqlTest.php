<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeUpdateInstaller;
use App\Services\Edge\EdgeUpdatePackageService;
use App\Services\Edge\EdgeUpdateVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE PRODUCTIZATION (O) — signed appliance updater + safe rollback.
 *
 * Proves: only an asymmetrically-SIGNED, untampered, version/schema/product-valid package is accepted, and
 * every refusal happens BEFORE any mutation; a verified pre-update backup is taken; the runtime is switched
 * atomically via versioned directories + a `current` pointer (never overwritten in place); a schema-upgrade
 * failure after the switch rolls the pointer back; and the local DB state (a pending outbox sale) survives a
 * successful update. Uses TEST signing keys — REAL_SIGNING_KEY_REQUIRED for a real pilot.
 */
class EdgeUpdaterMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private array $tempDirs = [];
    private string $signSecret;
    private string $installRoot;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_updates', 'edge_local_backups', 'edge_sync_outbox', 'edge_local_meta']);

        $kp = EdgeEnrollmentCrypto::generateKeypair();
        $this->signSecret = $kp['secret'];
        $this->installRoot = $this->tmp();

        config([
            'app.role' => 'branch_server',
            'edge.update.public_key' => $kp['public'],
            'edge.update.install_root' => $this->installRoot,
            'edge.update.allow_downgrade' => false,
            'edge.backup.path' => $this->tmp(),
            'edge.backup.recovery_key' => base64_encode(random_bytes(32)),
            'edge.backup.recovery_key_id' => 'k1',
            'edge.backup.retired_keys' => [],
        ]);
        $this->bindEdgeLocalMeta(7, 1);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $d) {
            $this->rrmdir($d);
        }
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function tmp(): string
    {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-upd-' . Str::lower(Str::random(8));
        mkdir($d, 0775, true);
        $this->tempDirs[] = $d;

        return $d;
    }

    private function makeArtifact(): string
    {
        $src = $this->tmp();
        mkdir($src . '/app', 0775, true);
        file_put_contents($src . '/app/A.php', '<?php // app class');
        file_put_contents($src . '/composer.json', '{}');
        $art = $this->tmp();
        (new EdgeArtifactBuilder(['include' => ['app', 'composer.json'], 'exclude' => [], 'forbidden' => []]))->build($src, $art, ['git_commit' => 'rev1']);

        return $art;
    }

    private function package(string $art, array $overrides = []): array
    {
        return app(EdgeUpdatePackageService::class)->build($art, $this->signSecret, array_merge(['edge_app_version' => '0.2.0-edge'], $overrides));
    }

    private function installer(): EdgeUpdateInstaller
    {
        return app(EdgeUpdateInstaller::class);
    }

    private function currentVersion(): ?string
    {
        return $this->installer()->currentPointer($this->installRoot);
    }

    // ── happy path ────────────────────────────────────────────────────────────────

    public function test_a_valid_signed_package_installs_atomically_with_a_pre_update_backup(): void
    {
        $art = $this->makeArtifact();
        $result = $this->installer()->install($this->package($art), $art, 'supervisor:test');

        $this->assertSame('applied', $result['result']);
        $this->assertSame('0.2.0-edge', $this->currentVersion(), 'the active pointer switched to the new version');
        $this->assertFileExists($this->installRoot . '/versions/0.2.0-edge/app/A.php', 'the new artifact is staged');
        $this->assertFileExists((string) $result['pre_update_backup'], 'a pre-update backup was taken');

        $audit = DB::table('edge_local_updates')->latest('id')->first();
        $this->assertSame('applied', $audit->result);
        $this->assertSame('0.2.0-edge', $audit->to_version);
    }

    public function test_a_pending_outbox_sale_survives_a_successful_update(): void
    {
        $u = (string) Str::ulid();
        EdgeSyncOutbox::create(['sale_uuid' => $u, 'envelope_schema_version' => 'v1', 'config_revision' => 1, 'activation_epoch' => 1, 'envelope' => '{}', 'content_hash' => str_repeat('a', 64), 'state' => 'pending']);

        $art = $this->makeArtifact();
        $this->installer()->install($this->package($art), $art, 'supervisor:test');

        $this->assertSame('pending', EdgeSyncOutbox::where('sale_uuid', $u)->value('state'), 'the un-synced sale survived the code switch');
    }

    // ── verification refusals (zero mutation) ────────────────────────────────────

    public function test_an_invalid_signature_is_refused_before_any_mutation(): void
    {
        $art = $this->makeArtifact();
        $pkg = $this->package($art);
        $pkg['signature'] = base64_encode(random_bytes(64)); // wrong signature

        try {
            $this->installer()->install($pkg, $art, 'supervisor:test');
            $this->fail('an invalid signature must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('UPDATE_SIGNATURE_INVALID', $e->getMessage());
        }
        $this->assertNull($this->currentVersion(), 'nothing was activated');
        $this->assertSame('refused', DB::table('edge_local_updates')->latest('id')->value('result'));
    }

    public function test_a_tampered_artifact_is_refused(): void
    {
        $art = $this->makeArtifact();
        $pkg = $this->package($art);
        file_put_contents($art . '/app/A.php', '<?php // TAMPERED'); // change bytes after signing

        $this->expectExceptionMessage('UPDATE_ARTIFACT_TAMPERED');
        $this->installer()->install($pkg, $art, 'supervisor:test');
    }

    public function test_a_package_for_the_wrong_product_is_refused(): void
    {
        $art = $this->makeArtifact();
        $this->expectExceptionMessage('UPDATE_WRONG_PRODUCT');
        $this->installer()->install($this->package($art, ['target_runtime' => 'cloud']), $art, 'supervisor:test');
    }

    public function test_an_incompatible_schema_is_refused(): void
    {
        $art = $this->makeArtifact();
        $this->expectExceptionMessage('UPDATE_SCHEMA_INCOMPATIBLE');
        $this->installer()->install($this->package($art, ['schema_generation' => 'some-foreign-schema']), $art, 'supervisor:test');
    }

    public function test_a_downgrade_is_refused(): void
    {
        $art = $this->makeArtifact();
        $this->expectExceptionMessage('UPDATE_DOWNGRADE_REFUSED');
        $this->installer()->install($this->package($art, ['edge_app_version' => '0.0.5-edge']), $art, 'supervisor:test');
    }

    public function test_a_pre_update_backup_failure_refuses_the_update(): void
    {
        config(['edge.backup.recovery_key' => '']); // no recovery key -> backup cannot seal -> refuse
        $art = $this->makeArtifact();

        try {
            $this->installer()->install($this->package($art), $art, 'supervisor:test');
            $this->fail('a failed pre-update backup must refuse the update');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('UPDATE_PREUPDATE_BACKUP_FAILED', $e->getMessage());
        }
        $this->assertNull($this->currentVersion(), 'nothing was activated');
    }

    // ── rollback after the switch ────────────────────────────────────────────────

    public function test_a_schema_failure_after_the_switch_rolls_the_pointer_back(): void
    {
        // Pre-existing active version (a previous good runtime).
        file_put_contents($this->installRoot . '/current', 'v-previous');

        $art = $this->makeArtifact();
        $failing = new class(app(EdgeUpdateVerifier::class), app(EdgeUpdatePackageService::class), app(EdgeBackupService::class)) extends EdgeUpdateInstaller {
            protected function applySchemaUpgrade(): string
            {
                throw new \RuntimeException('forced schema upgrade failure');
            }
        };

        try {
            $failing->install($this->package($art), $art, 'supervisor:test');
            $this->fail('the schema failure should abort the update');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('UPDATE_SCHEMA_UPGRADE_FAILED', $e->getMessage());
            $this->assertStringContainsString('rollback=reverted_runtime', $e->getMessage());
        }
        $this->assertSame('v-previous', $this->currentVersion(), 'the runtime pointer reverted to the previous version');
        $audit = DB::table('edge_local_updates')->latest('id')->first();
        $this->assertSame('rolled_back', $audit->result);
        $this->assertSame('reverted_runtime', $audit->rollback_result);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
