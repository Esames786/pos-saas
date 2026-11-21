<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBootstrapService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE-SYNC-ENGINE-1D — the Cloud sync ingestion endpoint proven through the REAL HTTP stack (central
 * domain + AuthenticateEdgeDevice), a thin boundary around 1C. Proves: a valid authenticated device with a
 * valid envelope is ACCEPTED (201 applied, one official Cloud sale); a bad/absent secret never reaches the
 * controller (401); a revoked device is refused; an envelope for a different device is refused (403); a
 * replay returns 200 already_applied with no duplicate. Tenant/branch/device come from the authenticated
 * device — never request-supplied identity alone — and 1C independently re-validates + posts exactly once.
 */
class EdgeSyncIngestionHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const TENANT_CODE = 'edgesynchttp';
    private const EPOCH = 2;

    private string $uri;
    private string $secret = 'sync-device-secret';
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
        $this->uri = 'http://' . config('tenancy.central_domain') . '/api/edge/sync/sales';
        $this->deviceUuid = (string) Str::uuid();

        $this->seedTenantRegistration();   // master: tenant + tenant_databases -> the test tenant DB
        $this->seedTenantData();           // tenant DB: CoA, handed branch, product+stock, cash mapping
        $this->seedDevice();               // master: device + activation epoch
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('edge_devices')->where('public_uuid', $this->deviceUuid)->delete();
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
        $m->table('edge_devices')->where('public_uuid', $this->deviceUuid)->delete();
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => self::TENANT_CODE, 'business_name' => 'Edge Sync HTTP', 'owner_name' => 'Owner',
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
        $this->cleanTenant([
            'edge_inbound_sale_ingestions', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'accounts', 'cash_bank_accounts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories',
            'terminals', 'branches', 'users',
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
        DB::setDefaultConnection('master');
    }

    private function seedDevice(): void
    {
        $m = DB::connection('master');
        $m->table('edge_devices')->insert([
            'public_uuid' => $this->deviceUuid, 'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId,
            'installation_uuid' => (string) Str::uuid(), 'device_name' => 'sync-http', 'device_secret_hash' => hash('sha256', $this->secret),
            'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
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

    private function headers(?string $secret = null): array
    {
        return ['X-Edge-Device-ID' => $this->deviceUuid, 'Authorization' => 'Bearer ' . ($secret ?? $this->secret)];
    }

    public function test_authenticated_device_ingests_one_official_sale(): void
    {
        $env = $this->envelope();
        $res = $this->postJson($this->uri, ['envelope' => $env], $this->headers());

        $res->assertStatus(201)->assertJsonPath('status', 'applied')->assertJsonPath('sale_uuid', $env['sale_uuid']);
        $this->assertNotEmpty($res->json('ingestion_uuid'));
        $this->assertNotEmpty($res->json('official_sale_no'));
        $this->assertSame(1, SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count());
        // Official finance is present (the 1C verifier gated it).
        $saleId = SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->value('id');
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->where('source_id', $saleId)->count());
        $this->assertSame(1, DB::connection('tenant')->table('cash_bank_account_transactions')->count());
    }

    public function test_replay_returns_already_applied_with_no_duplicate(): void
    {
        $env = $this->envelope();
        $this->postJson($this->uri, ['envelope' => $env], $this->headers())->assertStatus(201);
        $res = $this->postJson($this->uri, ['envelope' => $env], $this->headers());

        $res->assertStatus(200)->assertJsonPath('status', 'already_applied');
        $this->assertSame(1, SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count(), 'no duplicate sale');
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count());
    }

    public function test_bad_or_missing_secret_never_reaches_the_controller(): void
    {
        $env = $this->envelope();
        $this->postJson($this->uri, ['envelope' => $env])->assertStatus(401);
        $this->postJson($this->uri, ['envelope' => $env], $this->headers('wrong-secret'))->assertStatus(401);
        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'no ingestion on an unauthenticated request');
    }

    public function test_a_revoked_device_is_refused(): void
    {
        DB::connection('master')->table('edge_devices')->where('public_uuid', $this->deviceUuid)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->postJson($this->uri, ['envelope' => $this->envelope()], $this->headers())->assertStatus(401);
        $this->assertSame(0, SalesOrder::on('tenant')->count());
    }

    public function test_an_envelope_for_a_different_device_is_refused(): void
    {
        $env = $this->envelope(['device_public_uuid' => (string) Str::uuid()]);
        $res = $this->postJson($this->uri, ['envelope' => $env], $this->headers());
        $res->assertStatus(403)->assertJsonPath('failure_code', 'DEVICE_MISMATCH');
        $this->assertSame(0, SalesOrder::on('tenant')->count());
    }
}
