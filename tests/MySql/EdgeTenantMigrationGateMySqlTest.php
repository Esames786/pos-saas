<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Services\Edge\EdgeLocalSchemaUpgrader;
use App\Services\Edge\EdgeTenantMigrationManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\MySql\Support\EdgeGateProbeSubstitute;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OWNER DECISION D7 — the appliance schema upgrader operates ONLY from the explicit approved migration set.
 *
 * On the isolated Edge-local test DB: the plan honours the manifest (every pending tenant migration carries its
 * classification + decision; the hash is stable); an UNCLASSIFIED tenant migration present in the runtime aborts the
 * upgrade with a clear code BEFORE touching the database (no migration, no probe table, no bookkeeping write, version
 * unchanged); a CLOUD_ONLY file, if ever present, is SKIPPED WITH RECORD and never executed (its body would throw);
 * `edge:local:schema-upgrade --dry-run --json` prints the exact plan document and exits non-zero on a blocker.
 */
class EdgeTenantMigrationGateMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const CLOUD_ONLY_PLANTED = '2026_10_16_000001_add_branding_to_web_settings';   // a CLOUD_ONLY manifest entry
    private const UNCLASSIFIED_PLANTED = '2026_12_31_000001_edge_unclassified_probe';
    private const OWNER_REVIEW_PLANTED = '2026_12_31_000002_edge_owner_review_probe';      // OWNER DECISION 16 Nov 2026 (item 5)
    private const SUBSTITUTE_PLANTED = EdgeGateProbeSubstitute::MIGRATION;                 // OWNER DECISION 16 Nov 2026 (item 3)

    private string $fixtureDir;
    private int $paidId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_sync_outbox', 'print_jobs', 'sales_order_lines', 'sales_orders', 'shifts', 'edge_local_meta', 'products', 'categories', 'branches', 'users']);
        config(['database.connections.edge_local.database' => $this->tenantDb, 'database.connections.edge_local.host' => '127.0.0.1']);
        $this->asBranchServerRuntime();
        $branchId = $this->makeBranch();
        $this->bindEdgeLocalMeta($branchId, 1, 42, 'test-device-uuid', 10);
        DB::connection('tenant')->table('edge_local_meta')->update(['edge_schema_version' => 'edge-local-schema@before-gate', 'last_error' => null]);
        $productId = $this->makeProduct($this->makeCategory());
        $this->paidId = $this->makeSale($branchId, ['status' => 'paid', 'grand_total' => 120]);
        $this->makeSaleLine($this->paidId, $productId, ['quantity' => 1, 'unit_price' => 120]);
        $this->fixtureDir = storage_path('framework/testing/edge_gate_test_' . Str::lower(Str::random(6)));
        mkdir($this->fixtureDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixtureDir . '/*.php') ?: []);
        @rmdir($this->fixtureDir);
        try {
            Schema::connection('tenant')->dropIfExists('edge_unclassified_probe');
            Schema::connection('tenant')->dropIfExists('edge_owner_review_probe');
            Schema::connection('tenant')->dropIfExists(EdgeGateProbeSubstitute::TABLE);
            DB::connection('tenant')->table('migrations')->whereIn('migration', [self::CLOUD_ONLY_PLANTED, self::UNCLASSIFIED_PLANTED, self::OWNER_REVIEW_PLANTED, self::SUBSTITUTE_PLANTED])->delete();
        } catch (\Throwable $e) {
        }
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function plant(string $name, string $body): void
    {
        file_put_contents($this->fixtureDir . '/' . $name . '.php', <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    protected \$connection = 'tenant';
    public function up(): void
    {
        {$body}
    }
    public function down(): void {}
};
PHP);
    }

    /** The command may print human lines after the JSON document (e.g. the RELEASE BLOCKER error): take the last JSON line. */
    private function lastJson(string $output): array
    {
        foreach (array_reverse(preg_split("/?
/", trim($output))) as $line) {
            if (str_starts_with(trim($line), "{")) {
                return json_decode(trim($line), true);
            }
        }
        $this->fail("no JSON document in the command output: " . $output);
    }

    private function upgrader(): EdgeLocalSchemaUpgrader
    {
        return app(EdgeLocalSchemaUpgrader::class)->withAdditionalTenantPaths([$this->fixtureDir]);
    }

    /** A planted OWNER_REVIEW_REQUIRED entry with the given OWNER_APPROVAL (+ overrides), installed on top of the test manifest. */
    private function installReviewEntry(string $name, string $approval, array $overrides = []): void
    {
        EdgeTenantMigrationManifest::overrideForTests(EdgeTenantMigrationManifest::load()->withEntriesForTests([$name => array_merge([
            'MIGRATION' => $name,
            'CLASSIFICATION' => EdgeTenantMigrationManifest::EDGE_REQUIRED,
            'TABLES_CHANGED' => ['edge_probe'],
            'EDGE_CODE_REFERENCES' => ['none'],
            'ADDITIVE_ONLY' => false,
            'DATA_REWRITE' => false,
            'LOCK_RISK' => 'none',
            'CLOUD_ONLY_CLASS_DEPENDENCY' => 'none',
            'PACKAGE_DECISION' => EdgeTenantMigrationManifest::PACKAGE_SHIP_AND_RUN,
            'UPGRADE_TEST' => 'Tests\\MySql\\EdgeTenantMigrationGateMySqlTest',
            'OWNER_REVIEW_REQUIRED' => true,
            'OWNER_APPROVAL' => $approval,
        ], $overrides)]));
    }

    private function assertUpgradeRefusedWithOwnerApprovalPending(array $names): void
    {
        try {
            $this->upgrader()->upgrade();
            $this->fail('an owner-approval-pending migration must refuse the upgrade');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SCHEMA_UPGRADE_OWNER_APPROVAL_PENDING', $e->getMessage());
            $this->assertStringNotContainsString('UNCLASSIFIED', $e->getMessage(), 'the operator reads the real reason');
            foreach ($names as $n) {
                $this->assertStringContainsString($n, $e->getMessage());
            }
        }
        $this->assertNothingChanged();
        $this->assertFalse(DB::connection('tenant')->table('migrations')->whereIn('migration', $names)->exists(), 'nothing recorded');
    }

    private function assertNothingChanged(): void
    {
        $meta = EdgeLocalMeta::current();
        $this->assertSame('edge-local-schema@before-gate', $meta->edge_schema_version, 'applied version must not advance');
        $this->assertNull($meta->last_error, 'a blocker is reported, not written to the appliance DB');
        $this->assertSame('paid', DB::connection('tenant')->table('sales_orders')->where('id', $this->paidId)->value('status'));
        $this->assertSame('bootstrapped', $meta->runtime_state);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function test_the_plan_honours_the_manifest_and_a_clean_appliance_has_nothing_pending(): void
    {
        $plan = app(EdgeLocalSchemaUpgrader::class)->plan();
        $this->assertSame(EdgeTenantMigrationManifest::PLAN_FORMAT, $plan['format']);
        $this->assertSame([], $plan['pending']['tenant'], 'the test appliance is current');
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_OK, $plan['verdict']);
        $this->assertSame(EdgeTenantMigrationManifest::planHash($plan['entries']), $plan['plan_hash']);
        $this->assertSame(app(EdgeLocalSchemaUpgrader::class)->plan()['plan_hash'], $plan['plan_hash'], 'deterministic');
        $this->assertSame(EdgeTenantMigrationManifest::load()->version(), $plan['manifest_version']);
    }

    public function test_an_unclassified_tenant_migration_aborts_the_upgrade_before_touching_the_database(): void
    {
        $this->plant(self::UNCLASSIFIED_PLANTED, "Schema::connection('tenant')->create('edge_unclassified_probe', fn (Blueprint \$t) => \$t->id());");

        $plan = $this->upgrader()->plan();
        $this->assertSame([self::UNCLASSIFIED_PLANTED], $plan['blockers']);
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_RELEASE_BLOCKER, $plan['verdict']);
        $entry = array_column($plan['entries'], null, 'migration')[self::UNCLASSIFIED_PLANTED];
        $this->assertSame(EdgeTenantMigrationManifest::DECISION_ABORT_UNCLASSIFIED, $entry['decision']);
        $this->assertSame(EdgeTenantMigrationManifest::UNCLASSIFIED, $entry['classification']);

        try {
            $this->upgrader()->upgrade();
            $this->fail('an unclassified tenant migration must abort the upgrade');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SCHEMA_UPGRADE_UNCLASSIFIED_MIGRATION', $e->getMessage());
            $this->assertStringContainsString('RELEASE BLOCKER', $e->getMessage());
            $this->assertStringContainsString(self::UNCLASSIFIED_PLANTED, $e->getMessage());
        }
        $this->assertFalse(Schema::connection('tenant')->hasTable('edge_unclassified_probe'), 'the unclassified migration never ran');
        $this->assertFalse(DB::connection('tenant')->table('migrations')->where('migration', self::UNCLASSIFIED_PLANTED)->exists());
        $this->assertNothingChanged();

        // The command reports it the same way and exits non-zero on the dry-run (RELEASE BLOCKER), applying nothing.
        app()->instance(EdgeLocalSchemaUpgrader::class, $this->upgrader());
        $this->assertSame(1, Artisan::call('edge:local:schema-upgrade', ['--dry-run' => true, '--json' => true]));
        $json = $this->lastJson(Artisan::output());
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_RELEASE_BLOCKER, $json['verdict']);
        $this->assertSame([self::UNCLASSIFIED_PLANTED], $json['blockers']);
        $this->assertSame(1, Artisan::call('edge:local:schema-upgrade'));
        $this->assertStringContainsString('SCHEMA_UPGRADE_UNCLASSIFIED_MIGRATION', Artisan::output());
        $this->assertFalse(Schema::connection('tenant')->hasTable('edge_unclassified_probe'));
    }

    public function test_a_cloud_only_tenant_migration_present_on_the_appliance_is_skipped_with_record_and_never_executed(): void
    {
        $this->assertSame(EdgeTenantMigrationManifest::CLOUD_ONLY, EdgeTenantMigrationManifest::load()->classification(self::CLOUD_ONLY_PLANTED));
        $this->plant(self::CLOUD_ONLY_PLANTED, "throw new \\RuntimeException('CLOUD_ONLY BODY EXECUTED — must never happen on an appliance');");

        $plan = $this->upgrader()->plan();
        $this->assertSame([self::CLOUD_ONLY_PLANTED], $plan['skip']);
        $this->assertSame([], $plan['blockers']);
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_OK, $plan['verdict']);
        $this->assertSame(EdgeTenantMigrationManifest::DECISION_SKIP_CLOUD_ONLY, array_column($plan['entries'], 'decision', 'migration')[self::CLOUD_ONLY_PLANTED]);

        $result = $this->upgrader()->upgrade();
        $this->assertSame([self::CLOUD_ONLY_PLANTED], $result['skipped'], 'skipped WITH RECORD');
        $this->assertSame([], $result['applied']);
        $this->assertSame($plan['plan_hash'], $result['plan_hash']);
        $this->assertFalse(DB::connection('tenant')->table('migrations')->where('migration', self::CLOUD_ONLY_PLANTED)->exists(), 'never recorded as ran');
        $meta = EdgeLocalMeta::current();
        $this->assertNull($meta->last_error);
        $this->assertNotSame('edge-local-schema@before-gate', $meta->edge_schema_version, 'the upgrade itself completed');

        // Still skipped on the next run (defensive, idempotent), and the command prints the record.
        app()->instance(EdgeLocalSchemaUpgrader::class, $this->upgrader());
        $this->assertSame(0, Artisan::call('edge:local:schema-upgrade', ['--dry-run' => true]));
        $this->assertStringContainsString('SKIP_CLOUD_ONLY', Artisan::output());
        $this->assertSame(0, Artisan::call('edge:local:schema-upgrade', ['--json' => true]));
        $json = $this->lastJson(Artisan::output());
        $this->assertSame('applied', $json['result']);
        $this->assertSame([self::CLOUD_ONLY_PLANTED], $json['skipped']);
    }

    // ── OWNER DECISION 16 Nov 2026 (item 5): OWNER_REVIEW_REQUIRED is enforced at plan / upgrade time, fail closed ──

    public function test_the_real_manifest_blocks_every_owner_approval_pending_migration_before_touching_the_database(): void
    {
        EdgeTenantMigrationManifest::overrideForTests(null); // the REAL file, no test grant
        $pending = EdgeTenantMigrationManifest::load()->ownerApprovalPending();
        if ($pending === []) {
            $this->assertSame(EdgeTenantMigrationManifest::VERDICT_OK, app(EdgeLocalSchemaUpgrader::class)->plan()['verdict']);
            $this->markTestSkipped('no owner approval is pending in the real manifest — nothing to block');
        }
        $conn = DB::connection('tenant');
        $ran = $conn->table('migrations')->whereIn('migration', $pending)->get(); // the test DB ran the canonical bodies (migrate:fresh)
        $this->assertCount(count($pending), $ran);
        try {
            $conn->table('migrations')->whereIn('migration', $pending)->delete(); // make them pending, as on an appliance being upgraded
            $plan = app(EdgeLocalSchemaUpgrader::class)->plan();
            $this->assertSame($pending, $plan['blockers']);
            $this->assertSame(EdgeTenantMigrationManifest::VERDICT_RELEASE_BLOCKER, $plan['verdict']);
            foreach ($pending as $n) {
                $this->assertSame(EdgeTenantMigrationManifest::DECISION_ABORT_OWNER_APPROVAL_PENDING, $plan['blocker_reasons'][$n]);
                $this->assertNotContains($n, $plan['run']);
                $this->assertNotContains($n, $plan['substitute']);
            }
            try {
                app(EdgeLocalSchemaUpgrader::class)->upgrade();
                $this->fail('the real manifest must refuse the upgrade while an owner approval is pending');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('SCHEMA_UPGRADE_OWNER_APPROVAL_PENDING', $e->getMessage());
                foreach ($pending as $n) {
                    $this->assertStringContainsString($n, $e->getMessage());
                }
            }
            $this->assertNothingChanged();
            $this->assertFalse($conn->table('migrations')->whereIn('migration', $pending)->exists(), 'nothing recorded');
            $this->assertSame(1, Artisan::call('edge:local:schema-upgrade', ['--dry-run' => true, '--json' => true]), 'the dry run exits non-zero');
            $out = Artisan::output();
            $this->assertSame($pending, $this->lastJson($out)['blockers']);
            $this->assertStringContainsString('OWNER_APPROVAL_PENDING', $out);
        } finally {
            foreach ($ran as $r) {
                $conn->table('migrations')->insert(['migration' => $r->migration, 'batch' => $r->batch]);
            }
        }
    }

    public function test_a_reviewed_but_unapproved_planted_migration_blocks_and_only_an_explicit_approval_lets_it_run(): void
    {
        $this->plant(self::OWNER_REVIEW_PLANTED, "Schema::connection('tenant')->create('edge_owner_review_probe', fn (Blueprint \$t) => \$t->id());");

        $this->installReviewEntry(self::OWNER_REVIEW_PLANTED, 'PENDING owner review 16 Nov 2026');
        $plan = $this->upgrader()->plan();
        $this->assertSame([self::OWNER_REVIEW_PLANTED], $plan['blockers']);
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_RELEASE_BLOCKER, $plan['verdict']);
        $entry = array_column($plan['entries'], null, 'migration')[self::OWNER_REVIEW_PLANTED];
        $this->assertSame(EdgeTenantMigrationManifest::DECISION_ABORT_OWNER_APPROVAL_PENDING, $entry['decision']);
        $this->assertSame(EdgeTenantMigrationManifest::EDGE_REQUIRED, $entry['classification'], 'classified, reviewed — and still blocked');
        $this->assertTrue($entry['owner_review_required']);
        $this->assertSame('PENDING owner review 16 Nov 2026', $entry['owner_approval']);
        $this->assertUpgradeRefusedWithOwnerApprovalPending([self::OWNER_REVIEW_PLANTED]);
        $this->assertFalse(Schema::connection('tenant')->hasTable('edge_owner_review_probe'), 'the body never ran');

        $this->installReviewEntry(self::OWNER_REVIEW_PLANTED, 'owner-decision:2026-11-16 probe approved for the gate test');
        $plan = $this->upgrader()->plan();
        $this->assertSame([], $plan['blockers']);
        $this->assertContains(self::OWNER_REVIEW_PLANTED, $plan['run']);
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_OK, $plan['verdict']);
        $result = $this->upgrader()->upgrade();
        $this->assertContains(self::OWNER_REVIEW_PLANTED, $result['applied']);
        $this->assertSame([], $result['substituted']);
        $this->assertTrue(Schema::connection('tenant')->hasTable('edge_owner_review_probe'));
        $this->assertTrue(DB::connection('tenant')->table('migrations')->where('migration', self::OWNER_REVIEW_PLANTED)->exists());
        $this->assertSame([], $this->upgrader()->upgrade()['applied'], 'never re-applied');
    }

    // ── OWNER DECISION 16 Nov 2026 (item 3): an Edge substitute runs INSTEAD of the canonical body, under the canonical name ──

    public function test_a_run_edge_substitute_migration_records_the_canonical_name_and_executes_only_the_substitute_body(): void
    {
        $this->plant(self::SUBSTITUTE_PLANTED, "throw new \\RuntimeException('CANONICAL BODY EXECUTED — the Edge substitute must run instead');");
        $substitute = ['DATA_REWRITE' => true, 'EDGE_EXECUTION' => EdgeTenantMigrationManifest::EXECUTION_SUBSTITUTE, 'EDGE_SUBSTITUTE' => EdgeGateProbeSubstitute::class];

        // a substitute still needs the owner's approval
        $this->installReviewEntry(self::SUBSTITUTE_PLANTED, 'PENDING', $substitute);
        $plan = $this->upgrader()->plan();
        $this->assertSame([self::SUBSTITUTE_PLANTED], $plan['blockers']);
        $this->assertSame([], $plan['substitute']);
        $this->assertUpgradeRefusedWithOwnerApprovalPending([self::SUBSTITUTE_PLANTED]);
        $this->assertFalse(Schema::connection('tenant')->hasTable(EdgeGateProbeSubstitute::TABLE));

        $this->installReviewEntry(self::SUBSTITUTE_PLANTED, 'owner-decision:2026-11-16 substitute approved for the gate test', $substitute);
        $plan = $this->upgrader()->plan();
        $this->assertSame([], $plan['blockers']);
        $this->assertSame([self::SUBSTITUTE_PLANTED], $plan['substitute']);
        $this->assertNotContains(self::SUBSTITUTE_PLANTED, $plan['run'], 'never handed to the migrator');
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_OK, $plan['verdict']);
        $entry = array_column($plan['entries'], null, 'migration')[self::SUBSTITUTE_PLANTED];
        $this->assertSame(EdgeTenantMigrationManifest::DECISION_RUN_EDGE_SUBSTITUTE, $entry['decision']);
        $this->assertSame(EdgeGateProbeSubstitute::class, $entry['edge_substitute']);

        app()->instance(EdgeLocalSchemaUpgrader::class, $this->upgrader());
        $this->assertSame(0, Artisan::call('edge:local:schema-upgrade', ['--dry-run' => true]));
        $this->assertStringContainsString('RUN_EDGE_SUBSTITUTE', Artisan::output(), 'the dry run names the Edge execution — nothing silent');

        $result = $this->upgrader()->upgrade();
        $this->assertContains(self::SUBSTITUTE_PLANTED, $result['applied']);
        $this->assertSame([self::SUBSTITUTE_PLANTED => EdgeGateProbeSubstitute::class], $result['substituted']);
        $this->assertSame($plan['plan_hash'], $result['plan_hash']);
        $this->assertTrue(Schema::connection('tenant')->hasTable(EdgeGateProbeSubstitute::TABLE), 'the substitute body ran');
        $this->assertTrue(DB::connection('tenant')->table('migrations')->where('migration', self::SUBSTITUTE_PLANTED)->exists(), 'recorded under the CANONICAL name — history aligned, never re-applied');
        $meta = EdgeLocalMeta::current();
        $this->assertNull($meta->last_error);
        $this->assertNotSame('edge-local-schema@before-gate', $meta->edge_schema_version);
        $this->assertSame([], $this->upgrader()->upgrade()['applied'], 'idempotent');
        $this->assertSame([], $this->upgrader()->plan()['substitute']);
    }

    public function test_the_dry_run_json_is_the_exact_plan_document_the_updater_hashes(): void
    {
        $this->plant(self::CLOUD_ONLY_PLANTED, "throw new \\RuntimeException('never');");
        app()->instance(EdgeLocalSchemaUpgrader::class, $this->upgrader());
        $this->assertSame(0, Artisan::call('edge:local:schema-upgrade', ['--dry-run' => true, '--json' => true]));
        $json = $this->lastJson(Artisan::output());
        foreach (['format', 'manifest_version', 'entries', 'run', 'substitute', 'skip', 'blockers', 'blocker_reasons', 'plan_hash', 'verdict', 'edge_schema_version', 'pending'] as $key) {
            $this->assertArrayHasKey($key, $json, $key);
        }
        $this->assertSame($this->upgrader()->plan()['plan_hash'], $json['plan_hash']);
        $this->assertSame(EdgeTenantMigrationManifest::planHash($json['entries']), $json['plan_hash'], 'the hash is recomputable from the printed entries');
        $this->assertSame(['migration', 'group', 'classification', 'decision', 'tables', 'owner_review_required', 'owner_approval', 'edge_substitute'], array_keys($json['entries'][0]));
    }

    public function test_the_test_seam_refuses_a_non_test_directory(): void
    {
        $this->expectException(RuntimeException::class);
        app(EdgeLocalSchemaUpgrader::class)->withAdditionalTenantPaths([database_path('migrations/tenant')]);
    }
}
