<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeRestoreService;
use App\Services\Edge\EdgeSyncReconciliationService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE PRODUCTIZATION — restore + sync RECOVERY across the real authenticated boundary (§N).
 *
 * Proves the disaster-recovery invariant end to end: an un-synced local sale, backed up and then lost, is
 * RESTORED and syncs to the Cloud EXACTLY ONCE (a second send returns already_applied — no duplicate); and a
 * backup taken AFTER the Cloud applied but BEFORE the local ACK converges on restore (reconciliation
 * acknowledges from Cloud's own truth, no repost).
 */
class EdgeRestoreSyncRecoveryHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const TENANT_CODE = 'edgerestore';
    private const EPOCH = 2;

    private string $ingestUri;
    private string $reconcileUri;
    private string $secret = 'restore-device-secret';
    private string $backupDir;
    private int $tenantId;
    private string $deviceUuid;
    private int $branchId;
    private int $productId;
    private int $userId;
    private int $terminalId;
    private int $cashMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        $base = 'http://' . config('tenancy.central_domain') . '/api/edge/sync/';
        $this->ingestUri = $base . 'sales';
        $this->reconcileUri = $base . 'reconcile';
        $this->deviceUuid = (string) Str::uuid();
        $this->backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-restore-test-' . Str::lower(Str::random(8));
        config([
            'edge.backup.path' => $this->backupDir,
            'edge.backup.recovery_key' => base64_encode(random_bytes(32)),
            'edge.backup.recovery_key_id' => 'k1',
            'edge.backup.retired_keys' => [],
        ]);

        $this->seedTenantRegistration();
        $this->seedTenantData();
        $this->seedDevice();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->backupDir)) {
            foreach (glob($this->backupDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->backupDir);
        }
        try {
            $m = DB::connection('master');
            $m->table('edge_devices')->where('public_uuid', $this->deviceUuid)->delete();
            $m->table('edge_branch_activations')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
            $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();
        } catch (\Throwable $e) {
        }
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function seedTenantRegistration(): void
    {
        $m = DB::connection('master');
        $m->table('edge_devices')->where('public_uuid', $this->deviceUuid)->delete();
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => self::TENANT_CODE, 'business_name' => 'Edge Restore', 'owner_name' => 'Owner',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c = config('database.connections.tenant');
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant', 'db_host' => $c['host'], 'db_port' => (int) $c['port'],
            'db_database' => $this->tenantDb, 'db_username' => $c['username'], 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenantData(): void
    {
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->cleanTenant([
            'edge_local_backups', 'edge_sync_outbox', 'edge_local_meta', 'edge_inbound_sale_ingestions',
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'accounts', 'cash_bank_accounts',
            'stock_ledgers', 'stock_balances', 'inventory_batches', 'sale_payments', 'sales_order_lines',
            'sales_orders', 'payment_methods', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);
        (new DefaultChartOfAccountsSeeder())->run();

        $this->branchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);

        $conn = DB::connection('tenant');
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$this->productId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$this->productId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 50, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        $accountId = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $accountId, 'current_balance' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('payment_methods')->where('id', $this->cashMethodId)->update(['cash_bank_account_id' => $cbId]);

        // Appliance binding (used by backup/restore identity).
        $this->bindEdgeLocalMeta($this->branchId, self::EPOCH, tenantId: $this->tenantId, deviceUuid: $this->deviceUuid);
        config(['app.role' => null]);
        DB::setDefaultConnection('master');
    }

    private function seedDevice(): void
    {
        $m = DB::connection('master');
        $m->table('edge_devices')->insert([
            'public_uuid' => $this->deviceUuid, 'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId,
            'installation_uuid' => (string) Str::uuid(), 'device_name' => 'restore', 'device_secret_hash' => hash('sha256', $this->secret),
            'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('edge_branch_activations')->insert([
            'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId, 'generation' => self::EPOCH,
            'device_public_uuid' => $this->deviceUuid, 'reason' => 'initial', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function envelope(): array
    {
        $line = ['line_uuid' => (string) Str::ulid(), 'line_kind' => 'standard', 'product_id' => $this->productId, 'product_variant_id' => null, 'combo_id' => null, 'product_name' => 'Widget', 'quantity' => 2.0, 'unit_price' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => 200.0, 'modifiers' => []];
        $payment = ['payment_uuid' => (string) Str::ulid(), 'payment_method_id' => $this->cashMethodId, 'method_type' => 'cash', 'amount' => 200.0, 'tendered_amount' => 200.0, 'change_amount' => 0.0, 'transaction_ref' => null, 'paid_at' => now()->toIso8601String()];
        $env = [
            'envelope_schema_version' => 'edge-sale-envelope-v1', 'tenant_id' => $this->tenantId, 'tenant_code' => self::TENANT_CODE,
            'branch_id' => $this->branchId, 'device_public_uuid' => $this->deviceUuid, 'activation_epoch' => self::EPOCH,
            'config_revision' => 5, 'config_schema_version' => 'edge-config-v1', 'sale_uuid' => (string) Str::ulid(),
            'sale_no' => 'SO-EDGE-' . Str::random(6), 'client_uuid' => (string) Str::uuid(), 'business_date' => now()->toDateString(),
            'sale_date' => now()->toIso8601String(), 'completed_at' => now()->toIso8601String(), 'created_at' => now()->toIso8601String(),
            'order_type' => 'takeaway', 'order_source' => 'pos', 'vehicle_number' => null, 'terminal_id' => $this->terminalId,
            'terminal_code' => 'T1', 'user_id' => $this->userId, 'employee_code' => 'E1', 'restaurant_waiter_id' => null,
            'shift' => ['shift_uuid' => (string) Str::ulid(), 'business_date' => now()->toDateString(), 'opened_at' => now()->toIso8601String(), 'terminal_id' => $this->terminalId, 'opened_by_user_id' => $this->userId],
            'table_session' => null, 'kot_events' => [], 'customer' => ['kind' => 'walk_in', 'name' => null, 'phone' => null],
            'totals' => ['subtotal' => 200.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'service_charge_amount' => 0.0, 'tip_amount' => 0.0, 'grand_total' => 200.0, 'paid_amount' => 200.0, 'change_amount' => 0.0],
            'lines' => [$line], 'payments' => [$payment],
            'operational_stock' => ['posted' => true, 'baseline_uuid' => (string) Str::ulid()],
            'local_state' => ['edge_sync_state' => 'pending', 'edge_activation_epoch' => self::EPOCH, 'inventory_posted' => false, 'is_draft' => false],
        ];
        $env['content_hash'] = hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($env));

        return $env;
    }

    private function headers(): array
    {
        return ['X-Edge-Device-ID' => $this->deviceUuid, 'Authorization' => 'Bearer ' . $this->secret];
    }

    private function seedOutbox(array $env): void
    {
        EdgeSyncOutbox::on('tenant')->create([
            'sale_uuid' => $env['sale_uuid'], 'envelope_schema_version' => 'edge-sale-envelope-v1',
            'config_revision' => 5, 'activation_epoch' => self::EPOCH, 'envelope' => json_encode($env),
            'content_hash' => $env['content_hash'], 'state' => EdgeSyncOutbox::STATE_PENDING,
        ]);
    }

    /** Run an Edge-side service under branch_server, then restore cloud/null role for HTTP. */
    private function asEdge(callable $fn): mixed
    {
        config(['app.role' => 'branch_server']);
        app()->forgetInstance(EdgeBranchContext::class);
        try {
            return $fn();
        } finally {
            config(['app.role' => null]);
        }
    }

    public function test_an_unsynced_sale_survives_loss_then_restore_then_syncs_exactly_once(): void
    {
        $env = $this->envelope();
        $this->seedOutbox($env);

        // Back up the appliance (captures the un-synced sale), then lose the local operational state.
        $backup = $this->asEdge(fn () => app(EdgeBackupService::class)->backup());
        DB::connection('tenant')->table('edge_sync_outbox')->delete();
        $this->assertSame(0, EdgeSyncOutbox::on('tenant')->count());

        // Recover the replacement box.
        $this->asEdge(fn () => app(EdgeRestoreService::class)->restore($backup->path, $this->branchId));
        $restored = EdgeSyncOutbox::on('tenant')->where('sale_uuid', $env['sale_uuid'])->first();
        $this->assertNotNull($restored, 'the un-synced sale survived the restore');

        // Sync the restored envelope: Cloud applies exactly once; a re-send is already_applied, no duplicate.
        $restoredEnv = json_decode($restored->envelope, true);
        $this->postJson($this->ingestUri, ['envelope' => $restoredEnv], $this->headers())->assertStatus(201)->assertJsonPath('status', 'applied');
        $this->postJson($this->ingestUri, ['envelope' => $restoredEnv], $this->headers())->assertStatus(200)->assertJsonPath('status', 'already_applied');

        $this->assertSame(1, SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count(), 'exactly one official sale');
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count());
    }

    public function test_a_backup_after_cloud_apply_before_local_ack_converges_on_restore(): void
    {
        $env = $this->envelope();
        $this->seedOutbox($env);

        // Cloud applied the sale, but the local ACK was lost — the outbox row is still pending.
        $this->postJson($this->ingestUri, ['envelope' => $env], $this->headers())->assertStatus(201);
        DB::setDefaultConnection('master');

        // Back up (captures the still-pending outbox), then lose it, then restore.
        $backup = $this->asEdge(fn () => app(EdgeBackupService::class)->backup());
        DB::connection('tenant')->table('edge_sync_outbox')->delete();
        $this->asEdge(fn () => app(EdgeRestoreService::class)->restore($backup->path, $this->branchId));
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, EdgeSyncOutbox::on('tenant')->where('sale_uuid', $env['sale_uuid'])->value('state'));

        // Reconcile: Cloud already-applied truth converges the restored row with no second sale.
        $recon = $this->postJson($this->reconcileUri, ['sale_uuids' => [$env['sale_uuid']]], $this->headers());
        DB::setDefaultConnection('tenant');
        app(EdgeSyncReconciliationService::class)->recoverLostAck($env['sale_uuid'], $recon->json('statuses.' . $env['sale_uuid']));

        $this->assertSame(EdgeSyncOutbox::STATE_ACKNOWLEDGED, EdgeSyncOutbox::on('tenant')->where('sale_uuid', $env['sale_uuid'])->value('state'));
        $this->assertSame(1, SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count(), 'no second sale from recovery');
    }
}
