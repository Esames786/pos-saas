<?php

namespace Tests\MySql;

use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeTenantMigrationManifest;
use App\Services\Edge\EdgeUpdateInstaller;
use App\Services\Edge\EdgeUpdatePackageService;
use App\Services\Edge\EdgeUpdateVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OWNER DECISION D7 — "the updater dry-run must print the exact migration plan BEFORE applying it; the installed LAB
 * update stops if the actual migration plan differs from the pre-install report."
 *
 * The installer computes the plan from the NEW runtime before the pointer switch (seam `migrationPlan()`; the real
 * child-process path is driven end to end by EdgeCleanMachineInstallMySqlTest through Update-EdgeAppliance.ps1):
 *   - dryRun() verifies the package, returns the plan + hash and records result=dry_run with the plan;
 *   - a non-empty plan without --expected-plan-hash is UNCONFIRMED (nothing switched);
 *   - a differing hash is a MISMATCH (nothing switched, previous version active, audit row carries both hashes);
 *   - the approved hash installs, and the audit row records plan hash + plan (run / skip entries);
 *   - a RELEASE_BLOCKER plan (unclassified migration in the new runtime) stops the update;
 *   - an empty plan needs no hash (nothing to approve).
 */
class EdgeUpdatePlanHashMySqlTest extends MySqlTenantTestCase
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
        file_put_contents($this->installRoot . DIRECTORY_SEPARATOR . 'current', '0.1.0-edge');
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
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-plan-' . Str::lower(Str::random(8));
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

    private function package(string $art): array
    {
        return app(EdgeUpdatePackageService::class)->build($art, $this->signSecret, ['edge_app_version' => '0.2.0-edge']);
    }

    /** The plan the NEW runtime would print: one EDGE_REQUIRED canonical migration + one CLOUD_ONLY file skipped with record. */
    private function samplePlan(): array
    {
        $m = EdgeTenantMigrationManifest::load();

        return $m->plan(['tenant' => ['2026_10_19_000001_add_medicine_fields_to_products', '2026_10_16_000001_add_branding_to_web_settings'], 'edge' => []]);
    }

    private function emptyPlan(): array
    {
        return EdgeTenantMigrationManifest::load()->plan(['tenant' => [], 'edge' => []]);
    }

    private function installer(array $plan): EdgeUpdateInstaller
    {
        return new class(app(EdgeUpdateVerifier::class), app(EdgeUpdatePackageService::class), app(EdgeBackupService::class), $plan) extends EdgeUpdateInstaller {
            public function __construct(EdgeUpdateVerifier $v, EdgeUpdatePackageService $p, EdgeBackupService $b, private array $plan)
            {
                parent::__construct($v, $p, $b);
            }

            protected function migrationPlan(string $runtimeDir): array
            {
                return $this->plan;
            }

            protected function applySchemaUpgrade(string $versionDir): string
            {
                return (string) config('edge.config_schema');
            }
        };
    }

    private function lastAudit(): object
    {
        return DB::connection('tenant')->table('edge_local_updates')->orderByDesc('id')->first();
    }

    private function currentVersion(): string
    {
        return trim((string) file_get_contents($this->installRoot . DIRECTORY_SEPARATOR . 'current'));
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function test_the_dry_run_verifies_the_package_prints_the_exact_plan_and_records_the_pre_install_verdict(): void
    {
        $art = $this->makeArtifact();
        $plan = $this->samplePlan();
        $verdict = $this->installer($plan)->dryRun($this->package($art), $art, 'supervisor:test');

        $this->assertSame('dry_run', $verdict['result']);
        $this->assertSame('0.2.0-edge', $verdict['to_version']);
        $this->assertSame($plan['plan_hash'], $verdict['plan_hash']);
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_OK, $verdict['verdict']);
        $this->assertSame(['2026_10_19_000001_add_medicine_fields_to_products'], $verdict['plan']['run']);
        $this->assertSame(['2026_10_16_000001_add_branding_to_web_settings'], $verdict['plan']['skip']);
        $this->assertSame('0.1.0-edge', $this->currentVersion(), 'a dry-run never switches');
        $this->assertSame([], glob($this->installRoot . '/versions/*') ?: [], 'a dry-run never stages');

        $row = $this->lastAudit();
        $this->assertSame('dry_run', $row->result);
        $this->assertNull($row->failure_code);
        $this->assertSame($plan['plan_hash'], $row->migration_plan_hash);
        $recorded = json_decode($row->migration_plan, true);
        $this->assertSame(EdgeTenantMigrationManifest::PLAN_FORMAT, $recorded['format']);
        $this->assertSame(['2026_10_16_000001_add_branding_to_web_settings'], $recorded['skip']);
        $this->assertStringContainsString('(dry-run)', (string) $row->performed_by);
    }

    public function test_a_tampered_package_is_refused_by_the_dry_run_too(): void
    {
        $art = $this->makeArtifact();
        $pkg = $this->package($art);
        file_put_contents($art . '/app/A.php', '<?php // tampered');
        try {
            $this->installer($this->samplePlan())->dryRun($pkg, $art, 'supervisor:test');
            $this->fail('tampered artifact must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('UPDATE_ARTIFACT_TAMPERED', $e->getMessage());
        }
        $this->assertSame('refused', $this->lastAudit()->result);
    }

    public function test_a_non_empty_plan_without_the_approved_hash_is_unconfirmed_and_nothing_is_switched(): void
    {
        $art = $this->makeArtifact();
        try {
            $this->installer($this->samplePlan())->install($this->package($art), $art, 'supervisor:test');
            $this->fail('must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('UPDATE_MIGRATION_PLAN_UNCONFIRMED', $e->getMessage());
        }
        $this->assertSame('0.1.0-edge', $this->currentVersion(), 'previous version stays active');
        $row = $this->lastAudit();
        $this->assertSame('failed', $row->result);
        $this->assertSame('UPDATE_MIGRATION_PLAN_UNCONFIRMED', $row->failure_code);
        $this->assertSame('none', $row->rollback_result);
        $this->assertSame($this->samplePlan()['plan_hash'], $row->migration_plan_hash);
        $this->assertNull($row->expected_plan_hash);
    }

    public function test_a_plan_that_differs_from_the_pre_install_report_stops_the_update_before_the_switch(): void
    {
        $art = $this->makeArtifact();
        $approved = $this->emptyPlan()['plan_hash'];   // the report said "nothing pending"; the new runtime now has a plan
        try {
            $this->installer($this->samplePlan())->install($this->package($art), $art, 'supervisor:test', $approved);
            $this->fail('must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('UPDATE_MIGRATION_PLAN_MISMATCH', $e->getMessage());
            $this->assertStringContainsString($approved, $e->getMessage());
        }
        $this->assertSame('0.1.0-edge', $this->currentVersion());
        $row = $this->lastAudit();
        $this->assertSame('UPDATE_MIGRATION_PLAN_MISMATCH', $row->failure_code);
        $this->assertSame($this->samplePlan()['plan_hash'], $row->migration_plan_hash);
        $this->assertSame($approved, $row->expected_plan_hash);
    }

    public function test_the_approved_hash_installs_and_the_audit_row_records_the_plan(): void
    {
        $art = $this->makeArtifact();
        $plan = $this->samplePlan();
        $result = $this->installer($plan)->install($this->package($art), $art, 'supervisor:test', $plan['plan_hash']);

        $this->assertSame('applied', $result['result']);
        $this->assertSame($plan['plan_hash'], $result['plan_hash']);
        $this->assertSame(['2026_10_19_000001_add_medicine_fields_to_products'], $result['migrations_run']);
        $this->assertSame(['2026_10_16_000001_add_branding_to_web_settings'], $result['migrations_skipped']);
        $this->assertSame('0.2.0-edge', $this->currentVersion());
        $row = $this->lastAudit();
        $this->assertSame('applied', $row->result);
        $this->assertSame($plan['plan_hash'], $row->migration_plan_hash);
        $this->assertSame($plan['plan_hash'], $row->expected_plan_hash);
        $recorded = json_decode($row->migration_plan, true);
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_OK, $recorded['verdict']);
        $this->assertCount(2, $recorded['entries']);
    }

    public function test_an_unclassified_migration_in_the_new_runtime_is_a_release_blocker_even_with_a_hash(): void
    {
        $art = $this->makeArtifact();
        $plan = EdgeTenantMigrationManifest::load()->plan(['tenant' => ['2026_12_31_000001_unclassified_probe'], 'edge' => []]);
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_RELEASE_BLOCKER, $plan['verdict']);
        try {
            $this->installer($plan)->install($this->package($art), $art, 'supervisor:test', $plan['plan_hash']);
            $this->fail('must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('UPDATE_MIGRATION_PLAN_BLOCKED', $e->getMessage());
            $this->assertStringContainsString('2026_12_31_000001_unclassified_probe', $e->getMessage());
        }
        $this->assertSame('0.1.0-edge', $this->currentVersion());
        $this->assertSame('UPDATE_MIGRATION_PLAN_BLOCKED', $this->lastAudit()->failure_code);
        // and the dry-run says so up front
        $verdict = $this->installer($plan)->dryRun($this->package($art), $art, 'supervisor:test');
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_RELEASE_BLOCKER, $verdict['verdict']);
        $this->assertSame('UPDATE_MIGRATION_PLAN_BLOCKED', $this->lastAudit()->failure_code);
    }

    public function test_an_empty_plan_needs_no_hash(): void
    {
        $art = $this->makeArtifact();
        $result = $this->installer($this->emptyPlan())->install($this->package($art), $art, 'supervisor:test');
        $this->assertSame('applied', $result['result']);
        $this->assertSame([], $result['migrations_run']);
        $this->assertSame($this->emptyPlan()['plan_hash'], $this->lastAudit()->migration_plan_hash);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_link($p)) {
                @unlink($p) || @rmdir($p);
            } elseif (is_dir($p)) {
                $this->rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
