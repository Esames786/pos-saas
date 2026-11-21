<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeSyncReconciliationService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRODUCTIZATION GATE 0 — the REAL authenticated Cloud reconciliation path (central domain +
 * AuthenticateEdgeDevice), and lost-ACK / hash-divergence recovery proven end-to-end across that HTTP
 * boundary. Proves: a device reads ONLY its own ingestion truth (a foreign device sees nothing); a lost ACK
 * is recovered by fetching the Cloud status and acknowledging the local row with NO second sale / stock /
 * COGS / GL / cash movement; and a divergent Cloud hash leaves the local row untouched.
 */
class EdgeSyncReconciliationHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const TENANT_CODE = 'edgerecon';
    private const EPOCH = 2;

    private string $ingestUri;
    private string $reconcileUri;
    private string $secret = 'recon-device-secret';
    private string $foreignSecret = 'foreign-device-secret';
    private int $tenantId;
    private string $deviceUuid;
    private string $foreignDeviceUuid;
    private int $branchId;
    private int $foreignBranchId;
    private int $productId;
    private int $userId;
    private int $terminalId;
    private int $cashMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestUri = 'http://' . config('tenancy.central_domain') . '/api/edge/sync/sales';
        $this->reconcileUri = 'http://' . config('tenancy.central_domain') . '/api/edge/sync/reconcile';
        $this->deviceUuid = (string) Str::uuid();
        $this->foreignDeviceUuid = (string) Str::uuid();

        $this->seedTenantRegistration();
        $this->seedTenantData();
        $this->seedDevices();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('edge_devices')->whereIn('public_uuid', [$this->deviceUuid, $this->foreignDeviceUuid])->delete();
            $m->table('edge_branch_activations')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
            $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();
        } catch (\Throwable $e) {
        }
        parent::tearDown();
    }

    private function seedTenantRegistration(): void
    {
        $m = DB::connection('master');
        $m->table('edge_devices')->whereIn('public_uuid', [$this->deviceUuid, $this->foreignDeviceUuid])->delete();
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => self::TENANT_CODE, 'business_name' => 'Edge Recon', 'owner_name' => 'Owner',
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
        // The Edge outbox lives in the tenant test DB — ensure the edge schema is present.
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_inbound_sale_ingestions', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'accounts', 'cash_bank_accounts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories',
            'terminals', 'branches', 'users',
        ]);
        (new DefaultChartOfAccountsSeeder())->run();

        $this->branchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active', 'allow_negative_stock' => 0]);
        $this->foreignBranchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active', 'allow_negative_stock' => 0]);
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
        DB::setDefaultConnection('master');
    }

    private function seedDevices(): void
    {
        $m = DB::connection('master');
        // The foreign device is a real authenticating device on ITS OWN branch — proving cross-device/branch scope.
        foreach ([[$this->deviceUuid, $this->secret, 'recon', $this->branchId], [$this->foreignDeviceUuid, $this->foreignSecret, 'foreign', $this->foreignBranchId]] as [$uuid, $secret, $name, $branch]) {
            $m->table('edge_devices')->insert([
                'public_uuid' => $uuid, 'tenant_id' => $this->tenantId, 'branch_id' => $branch,
                'installation_uuid' => (string) Str::uuid(), 'device_name' => $name, 'device_secret_hash' => hash('sha256', $secret),
                'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $m->table('edge_branch_activations')->insert([
            'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId, 'generation' => self::EPOCH,
            'device_public_uuid' => $this->deviceUuid, 'reason' => 'initial', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function envelope(array $overrides = []): array
    {
        $line = ['line_uuid' => (string) Str::ulid(), 'line_kind' => 'standard', 'product_id' => $this->productId, 'product_variant_id' => null, 'combo_id' => null, 'product_name' => 'Widget', 'quantity' => 2.0, 'unit_price' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => 200.0, 'modifiers' => []];
        $payment = ['payment_uuid' => (string) Str::ulid(), 'payment_method_id' => $this->cashMethodId, 'method_type' => 'cash', 'amount' => 200.0, 'tendered_amount' => 200.0, 'change_amount' => 0.0, 'transaction_ref' => null, 'paid_at' => now()->toIso8601String()];
        $env = array_merge([
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
        ], $overrides);
        unset($env['content_hash']);
        $env['content_hash'] = hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($env));

        return $env;
    }

    private function headers(string $deviceUuid, string $secret): array
    {
        return ['X-Edge-Device-ID' => $deviceUuid, 'Authorization' => 'Bearer ' . $secret];
    }

    /** Seed a local outbox row (as the appliance would after a local sale) with the given hash + state. */
    private function seedOutbox(array $env, string $state = EdgeSyncOutbox::STATE_PENDING, ?string $hash = null): EdgeSyncOutbox
    {
        return EdgeSyncOutbox::on('tenant')->create([
            'sale_uuid' => $env['sale_uuid'], 'envelope_schema_version' => 'edge-sale-envelope-v1',
            'config_revision' => 5, 'activation_epoch' => self::EPOCH, 'envelope' => json_encode($env),
            'content_hash' => $hash ?? $env['content_hash'], 'state' => $state,
        ]);
    }

    private function ingestOnce(array $env): void
    {
        $this->postJson($this->ingestUri, ['envelope' => $env], $this->headers($this->deviceUuid, $this->secret))->assertStatus(201);
        DB::setDefaultConnection('master');
    }

    // ── the authenticated read path + scope ──────────────────────────────────────

    public function test_a_device_reads_only_its_own_ingestion_truth(): void
    {
        $env = $this->envelope();
        $this->ingestOnce($env);

        // The owning device sees its applied sale.
        $mine = $this->postJson($this->reconcileUri, ['sale_uuids' => [$env['sale_uuid']]], $this->headers($this->deviceUuid, $this->secret));
        $mine->assertStatus(200)
            ->assertJsonPath('statuses.' . $env['sale_uuid'] . '.status', 'applied')
            ->assertJsonPath('statuses.' . $env['sale_uuid'] . '.content_hash', $env['content_hash']);

        // A DIFFERENT authenticated device on the same tenant/branch sees NOTHING for that sale.
        $foreign = $this->postJson($this->reconcileUri, ['sale_uuids' => [$env['sale_uuid']]], $this->headers($this->foreignDeviceUuid, $this->foreignSecret));
        $foreign->assertStatus(200)->assertExactJson([
            'device_public_uuid' => $this->foreignDeviceUuid,
            'branch_id' => $this->foreignBranchId,
            'statuses' => [],
        ]);
    }

    public function test_an_unauthenticated_reconcile_query_is_refused(): void
    {
        $this->postJson($this->reconcileUri, ['sale_uuids' => ['x']])->assertStatus(401);
        $this->postJson($this->reconcileUri, ['sale_uuids' => ['x']], $this->headers($this->deviceUuid, 'wrong'))->assertStatus(401);
    }

    // ── lost-ACK network recovery (no repost) ────────────────────────────────────

    public function test_lost_ack_is_recovered_over_the_network_with_no_second_effect(): void
    {
        $env = $this->envelope();
        // The appliance recorded a local outbox row, sent the sale, Cloud applied it — but the ACK was lost.
        $this->seedOutbox($env, EdgeSyncOutbox::STATE_PENDING);
        $this->ingestOnce($env);

        $saleCount = SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count();
        $glCount = DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count();
        $cashCount = DB::connection('tenant')->table('cash_bank_account_transactions')->count();
        $this->assertSame(1, $saleCount);

        // Edge fetches the Cloud status over the REAL HTTP boundary, then recovers locally.
        $res = $this->postJson($this->reconcileUri, ['sale_uuids' => [$env['sale_uuid']]], $this->headers($this->deviceUuid, $this->secret));
        $res->assertStatus(200);
        $cloudAck = $res->json('statuses.' . $env['sale_uuid']);

        DB::setDefaultConnection('tenant');
        $outcome = app(EdgeSyncReconciliationService::class)->recoverLostAck($env['sale_uuid'], $cloudAck);
        $this->assertSame('acknowledged', $outcome);

        $this->assertSame(EdgeSyncOutbox::STATE_ACKNOWLEDGED, EdgeSyncOutbox::on('tenant')->where('sale_uuid', $env['sale_uuid'])->value('state'));
        // NOT ONE additional business effect was produced by recovery.
        $this->assertSame($saleCount, SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count());
        $this->assertSame($glCount, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count());
        $this->assertSame($cashCount, DB::connection('tenant')->table('cash_bank_account_transactions')->count());
    }

    public function test_a_divergent_cloud_hash_leaves_the_local_row_untouched(): void
    {
        $env = $this->envelope();
        $this->ingestOnce($env); // Cloud applied with the real hash.
        // The local outbox row for the same sale carries a DIFFERENT hash (divergence).
        $this->seedOutbox($env, EdgeSyncOutbox::STATE_PENDING, hash: str_repeat('d', 64));

        $res = $this->postJson($this->reconcileUri, ['sale_uuids' => [$env['sale_uuid']]], $this->headers($this->deviceUuid, $this->secret));
        $cloudAck = $res->json('statuses.' . $env['sale_uuid']);

        DB::setDefaultConnection('tenant');
        try {
            app(EdgeSyncReconciliationService::class)->recoverLostAck($env['sale_uuid'], $cloudAck);
            $this->fail('a divergent hash must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RECONCILE_HASH_DIVERGENCE', $e->getMessage());
        }
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, EdgeSyncOutbox::on('tenant')->where('sale_uuid', $env['sale_uuid'])->value('state'));
    }
}
