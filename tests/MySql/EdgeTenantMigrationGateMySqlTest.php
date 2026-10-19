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
            DB::connection('tenant')->table('migrations')->whereIn('migration', [self::CLOUD_ONLY_PLANTED, self::UNCLASSIFIED_PLANTED])->delete();
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

    public function test_the_dry_run_json_is_the_exact_plan_document_the_updater_hashes(): void
    {
        $this->plant(self::CLOUD_ONLY_PLANTED, "throw new \\RuntimeException('never');");
        app()->instance(EdgeLocalSchemaUpgrader::class, $this->upgrader());
        $this->assertSame(0, Artisan::call('edge:local:schema-upgrade', ['--dry-run' => true, '--json' => true]));
        $json = $this->lastJson(Artisan::output());
        foreach (['format', 'manifest_version', 'entries', 'run', 'skip', 'blockers', 'plan_hash', 'verdict', 'edge_schema_version', 'pending'] as $key) {
            $this->assertArrayHasKey($key, $json, $key);
        }
        $this->assertSame($this->upgrader()->plan()['plan_hash'], $json['plan_hash']);
        $this->assertSame(EdgeTenantMigrationManifest::planHash($json['entries']), $json['plan_hash'], 'the hash is recomputable from the printed entries');
        $this->assertSame(['migration', 'group', 'classification', 'decision', 'tables', 'owner_review_required'], array_keys($json['entries'][0]));
    }

    public function test_the_test_seam_refuses_a_non_test_directory(): void
    {
        $this->expectException(RuntimeException::class);
        app(EdgeLocalSchemaUpgrader::class)->withAdditionalTenantPaths([database_path('migrations/tenant')]);
    }
}
