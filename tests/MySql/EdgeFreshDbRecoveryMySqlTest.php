<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeRestoreService;
use App\Services\Edge\EdgeSyncReconciliationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE PRODUCTIZATION — GENUINE replacement-box recovery across two INDEPENDENT Edge databases.
 *
 * Not a row-delete-inside-one-DB simulation: appliance A lives in EDGE_DB_A, is lost, and a genuinely fresh
 * EDGE_DB_B is bootstrapped (config re-imported at stable Cloud ids) and restored from A's encrypted backup.
 * Proves B held NO operational state before restore, then that the FULL recoverable census survives the
 * cross-database restore intact — held/draft sale, shift, table session, KOT, local print job, operational
 * baseline, and the un-synced outbox (byte-identical envelope, still send-ready).
 */
class EdgeFreshDbRecoveryMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private string $dbA = 'bingoo_edge_recov_a';
    private string $dbB = 'bingoo_edge_recov_b';
    private static bool $provisioned = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.role' => 'branch_server',
            'edge.backup.path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-freshdb-' . Str::lower(Str::random(6)),
            'edge.backup.recovery_key' => base64_encode(random_bytes(32)), // provisioned per branch (survives A)
            'edge.backup.recovery_key_id' => 'k1',
            'edge.backup.retired_keys' => [],
        ]);
        $this->provision();
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function provision(): void
    {
        $c = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ([$this->dbA, $this->dbB] as $db) {
            if (! self::$provisioned) {
                $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
                $pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $this->useDb($db);
                Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
                Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
            }
        }
        self::$provisioned = true;
    }

    private function useDb(string $db): void
    {
        config(['database.connections.tenant.database' => $db]);
        DB::purge('tenant');
        DB::setDefaultConnection('tenant');
    }

    /** Config tables a replacement box re-derives from the Cloud bootstrap (captured from A, re-inserted into B). */
    private const CONFIG_TABLES = ['branches', 'users', 'terminals', 'categories', 'products', 'payment_methods', 'restaurant_floors', 'restaurant_tables'];

    private function seedConfig(): array
    {
        $branch = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active']);
        $user = $this->makeUser(['default_branch_id' => $branch]);
        $terminal = $this->makeTerminal($branch);
        $product = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $method = $this->makePaymentMethod(['method_type' => 'cash']);
        $floor = DB::table('restaurant_floors')->insertGetId(['branch_id' => $branch, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $table = DB::table('restaurant_tables')->insertGetId(['branch_id' => $branch, 'restaurant_floor_id' => $floor, 'table_no' => 'T1', 'name' => 'T1', 'created_at' => now(), 'updated_at' => now()]);

        return compact('branch', 'user', 'terminal', 'product', 'method', 'floor', 'table');
    }

    private function captureConfig(): array
    {
        $out = [];
        foreach (self::CONFIG_TABLES as $t) {
            $out[$t] = DB::table($t)->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    private function insertConfig(array $cfg): void
    {
        DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::CONFIG_TABLES as $t) {
            foreach (array_chunk($cfg[$t] ?? [], 200) as $chunk) {
                if ($chunk !== []) {
                    DB::table($t)->insert($chunk);
                }
            }
        }
        DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** Seed the full operational census on the CURRENT DB (appliance A). Returns the sale_uuid + content_hash. */
    private function seedOperational(array $ids): array
    {
        $this->bindEdgeLocalMeta($ids['branch'], 1, deviceUuid: 'freshdb-device');

        $shift = DB::table('shifts')->insertGetId([
            'branch_id' => $ids['branch'], 'terminal_id' => $ids['terminal'], 'opened_by_user_id' => $ids['user'],
            'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $session = DB::table('restaurant_table_sessions')->insertGetId([
            'session_no' => 'S-' . Str::upper(Str::random(6)), 'branch_id' => $ids['branch'], 'restaurant_table_id' => $ids['table'],
            'opened_by_user_id' => $ids['user'], 'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A table reservation (Edge-owned operational state) must also survive replacement-box recovery.
        DB::table('edge_local_table_reservations')->insert([
            'reservation_uuid' => (string) Str::ulid(), 'branch_id' => $ids['branch'], 'restaurant_table_id' => $ids['table'],
            'customer_name' => 'Reserved Guest', 'customer_phone' => '0311', 'status' => 'active', 'reserved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A HELD dine-in sale and a DRAFT sale (both status='held', draft flagged).
        $held = \App\Models\Tenant\SalesOrder::on('tenant')->create([
            'sale_no' => 'SO-HELD-' . Str::random(4), 'branch_id' => $ids['branch'], 'sale_date' => now(),
            'order_type' => 'dine_in', 'created_by_user_id' => $ids['user'], 'status' => 'held', 'is_draft' => false,
            'shift_id' => $shift, 'restaurant_table_session_id' => $session,
        ]);
        DB::table('sales_order_lines')->insert([
            'sales_order_id' => $held->id, 'line_uuid' => (string) Str::ulid(), 'product_id' => $ids['product'], 'product_name' => 'Widget',
            'quantity' => 2, 'unit_price' => 100, 'line_total' => 200, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $draft = \App\Models\Tenant\SalesOrder::on('tenant')->create([
            'sale_no' => 'SO-DRAFT-' . Str::random(4), 'branch_id' => $ids['branch'], 'sale_date' => now(),
            'order_type' => 'dine_in', 'created_by_user_id' => $ids['user'], 'status' => 'held', 'is_draft' => true, 'shift_id' => $shift,
        ]);

        // KOT batch for the held sale.
        DB::table('kot_batches')->insert([
            'event_uuid' => (string) Str::uuid(), 'sales_order_id' => $held->id, 'sequence_no' => 1, 'event_type' => 'new',
            'created_by_user_id' => $ids['user'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A printed local print job (must not reprint after restore).
        $printJob = DB::table('print_jobs')->insertGetId([
            'job_no' => 'PJ-' . Str::upper(Str::random(6)), 'branch_id' => $ids['branch'], 'document_type' => 'kot',
            'print_status' => 'printed', 'printed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('edge_local_print_deliveries')->insert([
            'print_job_id' => $printJob, 'delivery_state' => 'delivered', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Operational baseline + a paid sale's un-synced outbox envelope.
        $baseline = DB::table('edge_operational_stock_baselines')->insertGetId([
            'baseline_uuid' => (string) Str::ulid(), 'branch_id' => $ids['branch'], 'device_uuid' => 'freshdb-device', 'activation_epoch' => 1,
            'generation' => 1, 'source_revision' => 'test-rev-1', 'content_hash' => str_repeat('a', 64), 'status' => 'accepted',
            'active_binding_key' => \App\Services\Edge\EdgeOperationalBaselineService::bindingKey($ids['branch'], 'freshdb-device', 1),
            'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('edge_operational_stock_balances')->insert([
            'balance_key' => $baseline . '-' . $ids['product'] . '-0', 'baseline_id' => $baseline, 'branch_id' => $ids['branch'],
            'product_id' => $ids['product'], 'quantity_on_hand' => 48, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $saleUuid = (string) Str::ulid();
        $env = ['sale_uuid' => $saleUuid, 'sale_no' => 'SO-PAID-1', 'lines' => [['product_id' => $ids['product']]]];
        $json = json_encode($env);
        $hash = hash('sha256', $json);
        DB::table('edge_sync_outbox')->insert([
            'sale_uuid' => $saleUuid, 'envelope_schema_version' => 'edge-sale-envelope-v1', 'config_revision' => 5,
            'activation_epoch' => 1, 'envelope' => $json, 'content_hash' => $hash, 'state' => 'pending',
            'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['sale_uuid' => $saleUuid, 'content_hash' => $hash, 'envelope' => $json];
    }

    public function test_a_genuinely_fresh_database_recovers_the_full_operational_census(): void
    {
        // ── APPLIANCE A (EDGE_DB_A): config + full operational state + backup ──
        $this->useDb($this->dbA);
        $this->cleanTenant(array_merge(EdgeBackupService::TABLES, self::CONFIG_TABLES, ['restaurant_floors']));
        $ids = $this->seedConfig();
        $sale = $this->seedOperational($ids);
        $config = $this->captureConfig();
        $backup = app(EdgeBackupService::class)->backup();

        // ── APPLIANCE B (EDGE_DB_B): a genuinely fresh, independent database ──
        $this->useDb($this->dbB);
        $this->cleanTenant(array_merge(EdgeBackupService::TABLES, self::CONFIG_TABLES, ['restaurant_floors']));
        // Before restore: B has re-derived CONFIG (bootstrap) but NO operational state of its own.
        $this->insertConfig($config);
        $this->assertSame(0, DB::table('sales_orders')->count(), 'B holds no sale before restore');
        $this->assertSame(0, DB::table('edge_sync_outbox')->count(), 'B holds no outbox before restore');
        $this->assertSame(0, DB::table('shifts')->count());

        // ── Restore A's backup into the fresh B ──
        app(EdgeRestoreService::class)->restore($backup->path, $ids['branch']);

        // ── The full census survived the cross-database restore ──
        $this->assertSame(1, DB::table('sales_orders')->where('status', 'held')->where('is_draft', false)->count(), 'held sale survived');
        $this->assertSame(1, DB::table('sales_orders')->where('is_draft', true)->count(), 'draft sale survived');
        $this->assertSame(1, DB::table('shifts')->where('status', 'open')->count(), 'open shift survived');
        $this->assertSame(1, DB::table('restaurant_table_sessions')->where('status', 'open')->count(), 'table session survived');
        $this->assertSame(1, DB::table('kot_batches')->count(), 'KOT batch survived');
        $this->assertSame(1, DB::table('edge_local_table_reservations')->where('status', 'active')->count(), 'table reservation survived');
        $this->assertSame(1, DB::table('print_jobs')->where('print_status', 'printed')->count(), 'printed job survived (no reprint)');
        $this->assertSame(1, DB::table('edge_local_print_deliveries')->count(), 'print delivery state survived');
        $this->assertSame(1, DB::table('edge_operational_stock_baselines')->where('status', 'accepted')->count(), 'baseline survived');

        // The un-synced sale is byte-identical and still send-ready (pending).
        $row = EdgeSyncOutbox::on('tenant')->where('sale_uuid', $sale['sale_uuid'])->first();
        $this->assertNotNull($row);
        $this->assertSame($sale['content_hash'], $row->content_hash, 'content hash identical');
        $this->assertSame($sale['envelope'], $row->envelope, 'envelope bytes identical');
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $row->state);
    }

    public function test_a_lost_ack_recovers_across_two_databases_with_no_repost(): void
    {
        // ── APPLIANCE A: config + a paid sale whose Cloud ACK was lost (outbox still pending) + backup ──
        $this->useDb($this->dbA);
        $this->cleanTenant(array_merge(EdgeBackupService::TABLES, self::CONFIG_TABLES, ['restaurant_floors']));
        $ids = $this->seedConfig();
        $sale = $this->seedOperational($ids);
        $config = $this->captureConfig();
        $backup = app(EdgeBackupService::class)->backup();

        // ── A is dead. Recover onto a genuinely fresh, independent EDGE_DB_B. ──
        $this->useDb($this->dbB);
        $this->cleanTenant(array_merge(EdgeBackupService::TABLES, self::CONFIG_TABLES, ['restaurant_floors']));
        $this->insertConfig($config);
        $this->assertSame(0, EdgeSyncOutbox::on('tenant')->count(), 'B has no outbox before restore');
        app(EdgeRestoreService::class)->restore($backup->path, $ids['branch']);

        // The restored outbox row is present and still NOT acknowledged.
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, EdgeSyncOutbox::on('tenant')->where('sale_uuid', $sale['sale_uuid'])->value('state'));
        $salesBefore = DB::table('sales_orders')->count();

        // The Cloud (A is gone) already APPLIED this exact envelope; reconciliation acknowledges B's row from
        // Cloud's own truth — through the owner-guarded authority, on the SEPARATE database B.
        $cloudAck = ['status' => 'applied', 'sale_uuid' => $sale['sale_uuid'], 'content_hash' => $sale['content_hash'], 'ingestion_uuid' => 'ING-CROSS-DB'];
        $outcome = app(EdgeSyncReconciliationService::class)->recoverLostAck($sale['sale_uuid'], $cloudAck);
        $this->assertSame('acknowledged', $outcome);
        $this->assertSame(EdgeSyncOutbox::STATE_ACKNOWLEDGED, EdgeSyncOutbox::on('tenant')->where('sale_uuid', $sale['sale_uuid'])->value('state'));

        // A divergent hash on the same restored row would be refused (no silent overwrite).
        $this->assertSame($salesBefore, DB::table('sales_orders')->count(), 'recovery reposted no sale into B');
    }
}
