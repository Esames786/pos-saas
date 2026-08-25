<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Services\Edge\EdgeBuildInfoService;
use App\Services\Edge\EdgeLocalSchemaUpgrader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-SCHEMA-UPGRADE-1 — a LIVE appliance takes new schema forward WITHOUT rebuilding its local DB.
 * Proves: pending-only forward migrations are applied and the applied version recorded; every operational
 * row survives (paid sale, held DRAFT, print job, PENDING and ACKNOWLEDGED outbox rows, shift); a failing
 * migration fails CLOSED (error recorded, version not advanced, nothing lost, nothing rebuilt); an
 * uninitialised box is refused (fresh installs use db-init); and db-init REFUSES a bootstrapped appliance.
 */
class EdgeLocalSchemaUpgradeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const NEW_MIGRATION = '2026_08_25_000001_add_schema_version_to_edge_local_meta';

    private int $branchId;
    private array $seeded = [];
    private string $boomDir;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_ledgers',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'edge_local_meta',
            'printers', 'terminals', 'products', 'categories', 'branches', 'users',
        ]);
        // The upgrader's fail-closed target guard reads the edge_local connection: point it at THIS test DB
        // (its name carries edge+test) on loopback, exactly like a real appliance.
        config(['database.connections.edge_local.database' => $this->tenantDb, 'database.connections.edge_local.host' => '127.0.0.1']);
        $this->asBranchServerRuntime();
        $this->branchId = $this->makeBranch();
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 10);
        $this->seedOperationalData();
        $this->boomDir = storage_path('framework/testing/edge_upgrade_boom_' . Str::random(6));
    }

    protected function tearDown(): void
    {
        if (isset($this->boomDir) && is_dir($this->boomDir)) {
            array_map('unlink', glob($this->boomDir . '/*.php') ?: []);
            @rmdir($this->boomDir);
        }
        try {
            Schema::connection('tenant')->dropIfExists('edge_upgrade_boom_probe');
        } catch (\Throwable $e) {
        }
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function seedOperationalData(): void
    {
        $conn = DB::connection('tenant');
        $userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'UPG' . Str::random(4)]);
        $terminalId = $this->makeTerminal($this->branchId);
        $productId = $this->makeProduct($this->makeCategory());
        $shiftId = $conn->table('shifts')->insertGetId(['branch_id' => $this->branchId, 'terminal_id' => $terminalId, 'opened_by_user_id' => $userId, 'opened_at' => now(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $paidId = $this->makeSale($this->branchId, ['status' => 'paid', 'grand_total' => 300, 'shift_id' => $shiftId, 'edge_sync_state' => 'pending']);
        $this->makeSaleLine($paidId, $productId, ['quantity' => 3, 'unit_price' => 100]);
        $draftId = $this->makeSale($this->branchId, ['status' => 'held', 'is_draft' => 1, 'sale_no' => 'HS-DRAFT-1']);
        $this->makeSaleLine($draftId, $productId, ['quantity' => 1, 'unit_price' => 100]);
        $printJobId = $this->makePrintJob(null, ['branch_id' => $this->branchId]);
        $pendingUuid = (string) Str::ulid();
        $ackedUuid = (string) Str::ulid();
        $env = '{"envelope_schema_version":"edge-sale-envelope-v1"}';
        $pendingId = $conn->table('edge_sync_outbox')->insertGetId(['sale_uuid' => $pendingUuid, 'envelope_schema_version' => 'edge-sale-envelope-v1', 'config_revision' => 10, 'activation_epoch' => 1, 'envelope' => $env, 'content_hash' => hash('sha256', $env . 'p'), 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $ackedId = $conn->table('edge_sync_outbox')->insertGetId(['sale_uuid' => $ackedUuid, 'envelope_schema_version' => 'edge-sale-envelope-v1', 'config_revision' => 9, 'activation_epoch' => 1, 'envelope' => $env, 'content_hash' => hash('sha256', $env . 'a'), 'state' => 'acknowledged', 'acknowledged_at' => now(), 'ack_ingestion_uuid' => 'ing-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->seeded = compact('shiftId', 'paidId', 'draftId', 'printJobId', 'pendingId', 'ackedId', 'pendingUuid', 'ackedUuid');
    }

    private function assertOperationalDataIntact(): void
    {
        $conn = DB::connection('tenant');
        $s = $this->seeded;
        $this->assertSame('paid', $conn->table('sales_orders')->where('id', $s['paidId'])->value('status'), 'paid local sale survives');
        $this->assertSame(1, $conn->table('sales_order_lines')->where('sales_order_id', $s['paidId'])->count());
        $this->assertSame(1, (int) $conn->table('sales_orders')->where('id', $s['draftId'])->value('is_draft'), 'held DRAFT survives with its flag');
        $this->assertTrue($conn->table('print_jobs')->where('id', $s['printJobId'])->exists(), 'print history survives');
        $this->assertSame('open', $conn->table('shifts')->where('id', $s['shiftId'])->value('status'), 'open shift survives');
        $pending = $conn->table('edge_sync_outbox')->where('id', $s['pendingId'])->first();
        $acked = $conn->table('edge_sync_outbox')->where('id', $s['ackedId'])->first();
        $this->assertSame('pending', $pending->state, 'PENDING outbox row survives');
        $this->assertSame($s['pendingUuid'], $pending->sale_uuid);
        $this->assertSame('acknowledged', $acked->state, 'ACKNOWLEDGED outbox row survives (never delete-on-ack)');
        $this->assertSame('ing-1', $acked->ack_ingestion_uuid);
        $this->assertSame(1, $conn->table('edge_local_meta')->count(), 'binding row survives');
    }

    /** Make this DB look like an appliance built BEFORE the newest edge migration shipped. */
    private function simulateOlderAppliance(): void
    {
        $conn = DB::connection('tenant');
        $conn->statement('ALTER TABLE edge_local_meta DROP COLUMN edge_schema_version, DROP COLUMN last_schema_upgrade_at');
        $conn->table('migrations')->where('migration', self::NEW_MIGRATION)->delete();
        $this->assertFalse(Schema::connection('tenant')->hasColumn('edge_local_meta', 'edge_schema_version'));
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function test_upgrade_applies_only_pending_forward_migrations_and_preserves_every_operational_row(): void
    {
        $this->simulateOlderAppliance();
        $upgrader = app(EdgeLocalSchemaUpgrader::class);

        $pending = $upgrader->pending();
        $this->assertSame([], $pending['tenant'], 'tenant schema already current');
        $this->assertSame([self::NEW_MIGRATION], $pending['edge'], 'exactly the new edge migration is pending');

        $result = $upgrader->upgrade();

        $this->assertSame([self::NEW_MIGRATION], $result['applied']);
        $this->assertTrue(Schema::connection('tenant')->hasColumn('edge_local_meta', 'edge_schema_version'));
        $this->assertTrue(DB::connection('tenant')->table('migrations')->where('migration', self::NEW_MIGRATION)->exists());
        $meta = EdgeLocalMeta::current();
        $this->assertSame(app(EdgeBuildInfoService::class)->edgeSchemaVersion(), $meta->edge_schema_version, 'applied version recorded = shipped generation');
        $this->assertNotNull($meta->last_schema_upgrade_at);
        $this->assertNull($meta->last_error);
        $this->assertOperationalDataIntact();

        // Idempotent: a second run has nothing to do and changes nothing.
        $again = $upgrader->upgrade();
        $this->assertSame([], $again['applied']);
        $this->assertOperationalDataIntact();
    }

    public function test_the_command_is_allowlisted_and_upgrades_without_rebuilding(): void
    {
        $this->simulateOlderAppliance();
        $this->assertTrue(\App\Support\EdgeConsoleBoundary::isAllowed('edge:local:schema-upgrade'));

        $this->assertSame(0, Artisan::call('edge:local:schema-upgrade', ['--dry-run' => true]));
        $this->assertStringContainsString(self::NEW_MIGRATION, Artisan::output());
        $this->assertFalse(Schema::connection('tenant')->hasColumn('edge_local_meta', 'edge_schema_version'), 'dry-run applies nothing');

        $this->assertSame(0, Artisan::call('edge:local:schema-upgrade'), Artisan::output());
        $this->assertTrue(Schema::connection('tenant')->hasColumn('edge_local_meta', 'edge_schema_version'));
        $this->assertOperationalDataIntact();
    }

    public function test_a_failing_migration_fails_closed_without_data_loss_or_version_advance(): void
    {
        DB::connection('tenant')->table('edge_local_meta')->update(['edge_schema_version' => 'edge-local-schema@previous']);
        mkdir($this->boomDir, 0777, true);
        file_put_contents($this->boomDir . '/2026_12_31_000001_edge_upgrade_boom_probe.php', <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    protected $connection = 'tenant';
    public function up(): void
    {
        Schema::connection('tenant')->create('edge_upgrade_boom_probe', fn (Blueprint $t) => $t->id());
        throw new RuntimeException('BOOM: simulated mid-upgrade failure');
    }
    public function down(): void {}
};
PHP);

        try {
            app(EdgeLocalSchemaUpgrader::class)->upgrade(null, [$this->boomDir]);
            $this->fail('a failing migration must abort the upgrade');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SCHEMA_UPGRADE_FAILED', $e->getMessage());
            $this->assertStringContainsString('BOOM', $e->getMessage());
        }

        $meta = EdgeLocalMeta::current();
        $this->assertStringContainsString('BOOM', (string) $meta->last_error, 'failure recorded on the binding');
        $this->assertSame('edge-local-schema@previous', $meta->edge_schema_version, 'applied version does NOT advance');
        $this->assertFalse(DB::connection('tenant')->table('migrations')->where('migration', 'like', '%boom_probe')->exists(), 'the failed migration is not recorded as applied');
        $this->assertOperationalDataIntact();
        $this->assertSame('bootstrapped', $meta->runtime_state, 'nothing rebuilt; the appliance stays bound');
    }

    public function test_an_uninitialised_box_is_refused_and_pointed_at_db_init(): void
    {
        DB::connection('tenant')->table('edge_local_meta')->delete();
        try {
            app(EdgeLocalSchemaUpgrader::class)->upgrade();
            $this->fail('no binding => refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SCHEMA_UPGRADE_REFUSED', $e->getMessage());
            $this->assertStringContainsString('db-init', $e->getMessage());
        }
    }

    public function test_db_init_refuses_a_bootstrapped_appliance_even_with_fresh(): void
    {
        // db-init REFUSES a bootstrapped appliance (exit 1), on the plain path AND with --fresh, and never
        // rebuilds: the appliance keeps its data and its binding. (The refusal message names schema-upgrade.)
        $this->assertSame(1, Artisan::call('edge:local:db-init'), 'db-init must refuse a live appliance: ' . Artisan::output());
        $this->assertSame(1, Artisan::call('edge:local:db-init', ['--fresh' => true]), 'a live appliance is never one flag away from a wipe');
        $this->assertOperationalDataIntact();
        $this->assertSame('bootstrapped', EdgeLocalMeta::current()->runtime_state, 'nothing rebuilt; binding intact');
    }
}
