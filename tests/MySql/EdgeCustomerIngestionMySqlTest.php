<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerAddress;
use App\Models\Tenant\CustomerUuidAlias;
use App\Models\Tenant\EdgeInboundSaleIngestion;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeInboundSaleIngestionService;
use App\Services\Edge\EdgeSyncSender;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/** A Cloud WITHOUT Phase 4 (speaks v1 + v2 only) — the deploy-ordering double. */
class PreCustomerCloudSaleIngestionService extends EdgeInboundSaleIngestionService
{
    protected function supportedEnvelopeSchemas(): array
    {
        return [self::ENVELOPE_SCHEMA_V1, self::ENVELOPE_SCHEMA_V2];
    }
}

/**
 * EDGE PHASE 4 — the CLOUD half: deterministic create-or-match of an offline-created customer inside the sale ingestion
 * (docs/status/edge-phase4-offline-add-customer.md §3), against the REAL Cloud authorities (FEFO, GL, finance verifier):
 *
 *  - new: the customer is created WITH the offline customer_uuid as its canonical identity, phone_normalized, addresses by
 *    address_uuid; the sale links to it;
 *  - phone match: an EXISTING Cloud customer with the same normalized phone (any spelling, incl. a legacy dashed one) → NO
 *    duplicate; `customer_uuid_aliases[offline_uuid → canonical]` recorded; name kept, empty email topped up; the address
 *    is added once; the sale links to the existing customer;
 *  - replay idempotent: the same envelope twice → already_applied, one customer, one alias, one address, one sale; a SECOND
 *    sale of the same offline customer lands on the alias (no second alias, no duplicate); a later `customer` kind
 *    carrying the alias uuid resolves to the canonical customer;
 *  - gating: new_customer on a non-v3 envelope → ENVELOPE_INVALID (terminal, no write); an OLD Cloud answers a v3 envelope
 *    SCHEMA_UNSUPPORTED and the REAL sender parks the row under a bounded backoff (never failed_permanent); after the Cloud
 *    "upgrades" the SAME immutable row applies once.
 */
class EdgeCustomerIngestionMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const TENANT_ID = 7781;
    private const DEVICE_UUID = 'edge-device-phase4-customer';
    private const EPOCH = 2;

    private int $branchId;
    private int $productId;
    private int $userId;
    private int $terminalId;
    private int $cashMethodId;
    private string $url = 'https://cloud.example.test/api/edge/sync/sales';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_inbound_sale_ingestions',
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'accounts', 'cash_bank_accounts',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders',
            'customer_uuid_aliases', 'customer_addresses', 'customers', 'payment_methods', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);
        (new DefaultChartOfAccountsSeeder())->run();

        $this->branchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);

        $conn = DB::connection('tenant');
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "p4-{$this->branchId}-{$this->productId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$this->productId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 500, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        $accountId = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $accountId, 'current_balance' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('payment_methods')->where('id', $this->cashMethodId)->update(['cash_bank_account_id' => $cbId]);

        DB::connection('master')->table('edge_devices')->where('public_uuid', self::DEVICE_UUID)->delete();
        DB::connection('master')->table('edge_devices')->insert([
            'public_uuid' => self::DEVICE_UUID, 'tenant_id' => self::TENANT_ID, 'branch_id' => $this->branchId,
            'installation_uuid' => (string) Str::uuid(), 'device_secret_hash' => hash('sha256', 'x'),
            'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('master')->table('edge_branch_activations')->where('tenant_id', self::TENANT_ID)->where('branch_id', $this->branchId)->delete();
        DB::connection('master')->table('edge_branch_activations')->insert([
            'tenant_id' => self::TENANT_ID, 'branch_id' => $this->branchId, 'generation' => self::EPOCH,
            'device_public_uuid' => self::DEVICE_UUID, 'reason' => 'initial', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('master')->table('edge_devices')->where('public_uuid', self::DEVICE_UUID)->delete();
            DB::connection('master')->table('edge_branch_activations')->where('tenant_id', self::TENANT_ID)->delete();
        } catch (\Throwable $e) {
        }
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function envelope(array $customer, string $schema = EdgeInboundSaleIngestionService::ENVELOPE_SCHEMA_V3): array
    {
        $env = [
            'envelope_schema_version' => $schema,
            'tenant_id' => self::TENANT_ID, 'tenant_code' => 'p4tenant', 'branch_id' => $this->branchId,
            'device_public_uuid' => self::DEVICE_UUID, 'activation_epoch' => self::EPOCH,
            'config_revision' => 3, 'config_schema_version' => 'edge-config-v1',
            'sale_uuid' => (string) Str::ulid(), 'sale_no' => 'SO-EDGE-' . Str::random(6),
            'client_uuid' => (string) Str::uuid(), 'business_date' => now()->toDateString(),
            'sale_date' => now()->toIso8601String(), 'completed_at' => now()->toIso8601String(), 'created_at' => now()->toIso8601String(),
            'order_type' => 'takeaway', 'order_source' => 'pos', 'vehicle_number' => null,
            'terminal_id' => $this->terminalId, 'terminal_code' => 'T1', 'user_id' => $this->userId, 'employee_code' => 'E1',
            'restaurant_waiter_id' => null,
            'shift' => ['shift_uuid' => (string) Str::ulid(), 'business_date' => now()->toDateString(), 'opened_at' => now()->toIso8601String(), 'terminal_id' => $this->terminalId, 'opened_by_user_id' => $this->userId],
            'table_session' => null, 'kot_events' => [],
            'customer' => $customer,
            'delivery' => null,
            'totals' => ['subtotal' => 200.0, 'discount_amount' => 0.0, 'discount_type' => 'none', 'discount_value' => 0.0, 'promo_code' => null,
                'tax_amount' => 0.0, 'service_charge_amount' => 0.0, 'delivery_charge_amount' => 0.0, 'tip_amount' => 0.0,
                'grand_total' => 200.0, 'paid_amount' => 200.0, 'change_amount' => 0.0],
            'lines' => [['line_uuid' => (string) Str::ulid(), 'line_kind' => 'standard', 'product_id' => $this->productId, 'product_variant_id' => null, 'combo_id' => null,
                'product_name' => 'Widget', 'quantity' => 2.0, 'unit_price' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => 200.0, 'modifiers' => []]],
            'payments' => [['payment_uuid' => (string) Str::ulid(), 'payment_method_id' => $this->cashMethodId, 'method_type' => 'cash',
                'amount' => 200.0, 'tendered_amount' => 200.0, 'change_amount' => 0.0, 'transaction_ref' => null, 'paid_at' => now()->toIso8601String()]],
            'operational_stock' => ['posted' => true, 'baseline_uuid' => (string) Str::ulid()],
            'local_state' => ['edge_sync_state' => 'pending', 'edge_activation_epoch' => self::EPOCH, 'inventory_posted' => false, 'is_draft' => false],
        ];

        return $this->hashed($env);
    }

    private function hashed(array $env): array
    {
        unset($env['content_hash']);
        $env['content_hash'] = hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($env));

        return $env;
    }

    /** The `new_customer` block exactly as EdgeSaleEnvelopeBuilder emits it. */
    private function newCustomer(string $uuid, array $intent = [], array $top = []): array
    {
        return array_merge([
            'kind' => 'new_customer',
            'customer_uuid' => $uuid,
            'name' => 'Zara K',
            'phone' => '0301-7654321',
            'new_customer' => array_merge([
                'name' => 'Zara Khan', 'phone' => '03017654321', 'phone_normalized' => '03017654321', 'email' => 'zara@example.test',
                'addresses' => [['address_uuid' => (string) Str::ulid(), 'label' => 'Home', 'address' => 'House 1, Street 2', 'is_default' => true]],
            ], $intent),
        ], $top);
    }

    private function ingest(array $env): array
    {
        return app(EdgeInboundSaleIngestionService::class)->ingest($env);
    }

    // ── create ──────────────────────────────────────────────────────────────────

    public function test_a_new_offline_customer_is_created_with_its_offline_uuid_as_canonical_identity_and_the_sale_links_to_it(): void
    {
        $uuid = (string) Str::ulid();
        $block = $this->newCustomer($uuid);
        $env = $this->envelope($block);
        $ack = $this->ingest($env);
        $this->assertSame('applied', $ack['status'], json_encode($ack));

        $this->assertSame(1, Customer::on('tenant')->count());
        $c = Customer::on('tenant')->where('customer_uuid', $uuid)->firstOrFail();
        $this->assertSame('Zara Khan', $c->name);
        $this->assertSame('03017654321', $c->phone);
        $this->assertSame('03017654321', $c->phone_normalized);
        $this->assertSame('zara@example.test', $c->email);
        $this->assertSame('active', $c->status);
        $this->assertSame(0, CustomerUuidAlias::on('tenant')->count(), 'a created customer needs no alias');
        $a = CustomerAddress::on('tenant')->where('customer_id', $c->id)->get();
        $this->assertCount(1, $a);
        $this->assertSame($block['new_customer']['addresses'][0]['address_uuid'], $a[0]->address_uuid);
        $this->assertSame('House 1, Street 2', $a[0]->address);
        $this->assertTrue((bool) $a[0]->is_default);

        $sale = SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->firstOrFail();
        $this->assertSame((int) $c->id, (int) $sale->customer_id);
        $this->assertSame('Zara K', $sale->customer_name, "the sale keeps its own snapshot");
        $this->assertSame('0301-7654321', $sale->customer_phone);
        $this->assertSame('synced', $sale->edge_sync_state);
        $this->assertTrue((bool) $sale->inventory_posted, 'v3 ⊇ v2: the official posting is unchanged');
    }

    public function test_a_name_only_customer_is_created_and_the_same_envelope_twice_is_already_applied(): void
    {
        $uuid = (string) Str::ulid();
        $env = $this->envelope($this->newCustomer($uuid, ['phone' => null, 'phone_normalized' => null, 'email' => null, 'addresses' => []], ['phone' => null]));
        $this->assertSame('applied', $this->ingest($env)['status']);
        $this->assertSame('already_applied', $this->ingest($env)['status']);
        $this->assertSame(1, Customer::on('tenant')->count());
        $this->assertNull(Customer::on('tenant')->where('customer_uuid', $uuid)->value('phone_normalized'));
        $this->assertSame(1, SalesOrder::on('tenant')->count());
    }

    // ── match ───────────────────────────────────────────────────────────────────

    public function test_an_existing_cloud_phone_binds_the_sale_records_an_alias_and_never_creates_a_duplicate(): void
    {
        // the Cloud already knows this phone — a LEGACY dashed spelling, no match key (pre-Phase-4 row, raw insert)
        $existingUuid = (string) Str::ulid();
        $existingId = (int) DB::connection('tenant')->table('customers')->insertGetId(['customer_uuid' => $existingUuid, 'code' => 'C-77', 'name' => 'Zara Ahmed', 'phone' => '0301-7654321', 'email' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('customer_addresses')->insert(['address_uuid' => (string) Str::ulid(), 'customer_id' => $existingId, 'label' => 'Office', 'address' => 'Plaza 9', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $offlineUuid = (string) Str::ulid();
        $block = $this->newCustomer($offlineUuid);   // the appliance's +92/0301 spelling → the same key
        $env = $this->envelope($block);
        $this->assertSame('applied', $this->ingest($env)['status']);

        $this->assertSame(1, Customer::on('tenant')->count(), 'NO duplicate customer');
        $c = Customer::on('tenant')->findOrFail($existingId);
        $this->assertSame('Zara Ahmed', $c->name, 'the existing name is kept (never renamed)');
        $this->assertSame('zara@example.test', $c->email, 'only the empty email was topped up');
        $this->assertSame($existingUuid, $c->customer_uuid, 'the canonical identity never changes');
        $alias = CustomerUuidAlias::on('tenant')->findOrFail($offlineUuid);
        $this->assertSame($existingId, (int) $alias->customer_id);
        $this->assertSame($existingUuid, $alias->customer_uuid);
        $this->assertSame(self::DEVICE_UUID, $alias->source_device_uuid);
        $this->assertSame('phone_match', $alias->source);
        $addresses = CustomerAddress::on('tenant')->where('customer_id', $existingId)->orderBy('id')->get();
        $this->assertSame(['Plaza 9', 'House 1, Street 2'], $addresses->pluck('address')->all(), 'the offline address is added once');
        $this->assertSame([true, false], $addresses->map(fn ($a) => (bool) $a->is_default)->all(), "the Cloud's default is not demoted");
        $this->assertSame($block['new_customer']['addresses'][0]['address_uuid'], $addresses[1]->address_uuid);
        $this->assertSame($existingId, (int) SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->value('customer_id'), 'the sale links to the canonical customer');

        // replay → already_applied, nothing more
        $this->assertSame('already_applied', $this->ingest($env)['status']);
        $this->assertSame(1, Customer::on('tenant')->count());
        $this->assertSame(1, CustomerUuidAlias::on('tenant')->count());
        $this->assertSame(2, CustomerAddress::on('tenant')->count());
        $this->assertSame(1, SalesOrder::on('tenant')->count());

        // a SECOND sale of the same offline customer (the appliance still carries it as new_customer) → the alias resolves
        $second = $this->envelope($this->newCustomer($offlineUuid, ['addresses' => $block['new_customer']['addresses']]));
        $this->assertSame('applied', $this->ingest($second)['status']);
        $this->assertSame(1, Customer::on('tenant')->count());
        $this->assertSame(1, CustomerUuidAlias::on('tenant')->count(), 'no second alias');
        $this->assertSame(2, CustomerAddress::on('tenant')->count(), 'the address_uuid is created once');
        $this->assertSame($existingId, (int) SalesOrder::on('tenant')->where('sale_uuid', $second['sale_uuid'])->value('customer_id'));

        // after a refresh the appliance may carry the offline uuid as a plain `customer` kind → still the canonical customer
        $third = $this->envelope(['kind' => 'customer', 'customer_uuid' => $offlineUuid, 'name' => 'Zara', 'phone' => '03017654321'], EdgeInboundSaleIngestionService::ENVELOPE_SCHEMA_V1);
        $this->assertSame('applied', $this->ingest($third)['status']);
        $this->assertSame($existingId, (int) SalesOrder::on('tenant')->where('sale_uuid', $third['sale_uuid'])->value('customer_id'));
        $this->assertSame(3, SalesOrder::on('tenant')->count());
    }

    public function test_a_retry_after_a_rolled_back_attempt_lands_on_the_same_customer(): void
    {
        $uuid = (string) Str::ulid();
        $env = $this->envelope($this->newCustomer($uuid));
        // the branch forbids negative stock and the official book is empty → INSUFFICIENT_STOCK exception (rolled back whole)
        DB::connection('tenant')->table('stock_balances')->update(['quantity_on_hand' => 0]);
        $first = $this->ingest($env);
        $this->assertSame('exception', $first['status']);
        $this->assertSame('INSUFFICIENT_STOCK', $first['failure_code']);
        $this->assertSame(0, Customer::on('tenant')->count(), 'the customer created inside the rolled-back attempt is gone (no partial write)');
        $this->assertSame(0, CustomerAddress::on('tenant')->count());

        DB::connection('tenant')->table('stock_balances')->update(['quantity_on_hand' => 500]);
        $this->assertSame('applied', $this->ingest($env)['status']);
        $this->assertSame(1, Customer::on('tenant')->where('customer_uuid', $uuid)->count());
        $this->assertSame(1, CustomerAddress::on('tenant')->count());
        $this->assertSame(1, SalesOrder::on('tenant')->count());
    }

    // ── gating ──────────────────────────────────────────────────────────────────

    public function test_new_customer_on_a_non_v3_envelope_is_refused_terminally_with_no_write(): void
    {
        $env = $this->envelope($this->newCustomer((string) Str::ulid()), EdgeInboundSaleIngestionService::ENVELOPE_SCHEMA_V1);
        $ack = $this->ingest($env);
        $this->assertSame('refused', $ack['status']);
        $this->assertSame('ENVELOPE_INVALID', $ack['failure_code']);
        $this->assertTrue(\App\Services\Edge\EdgeIngestionVerdicts::isTerminal($ack['failure_code']));
        $this->assertSame(0, Customer::on('tenant')->count());
        $this->assertSame(0, SalesOrder::on('tenant')->count());

        // an intent whose phone_normalized disagrees with THE shared normaliser is refused (one rule on both sides)
        $bad = $this->envelope($this->newCustomer((string) Str::ulid(), ['phone_normalized' => '923017654321']));
        $this->assertSame('CUSTOMER_INVALID', $this->ingest($bad)['failure_code']);
        // an unknown plain `customer` uuid still fails closed (never a phone guess for a synced identity)
        $unknown = $this->envelope(['kind' => 'customer', 'customer_uuid' => (string) Str::ulid(), 'name' => 'X', 'phone' => '03017654321'], EdgeInboundSaleIngestionService::ENVELOPE_SCHEMA_V1);
        $this->assertSame('CUSTOMER_UNKNOWN', $this->ingest($unknown)['failure_code']);
        $this->assertSame(0, Customer::on('tenant')->count());
    }

    public function test_an_old_cloud_refuses_v3_schema_unsupported_the_sender_parks_the_row_and_the_same_row_applies_after_the_upgrade(): void
    {
        $this->bindEdgeLocalMeta($this->branchId, 1, self::TENANT_ID, self::DEVICE_UUID);
        config([
            'edge.sync.url' => $this->url, 'edge.sync.device_id' => self::DEVICE_UUID, 'edge.sync.device_secret' => 'secret',
            'edge.sync.schema_retry_base_seconds' => 60, 'edge.sync.schema_retry_max_seconds' => 900,
        ]);
        $uuid = (string) Str::ulid();
        $env = $this->envelope($this->newCustomer($uuid));
        $row = EdgeSyncOutbox::create([
            'sale_uuid' => $env['sale_uuid'], 'envelope_schema_version' => $env['envelope_schema_version'], 'config_revision' => 3,
            'activation_epoch' => self::EPOCH, 'envelope' => app(EdgeBootstrapService::class)->canonicalJson($env), 'content_hash' => $env['content_hash'], 'state' => 'pending',
        ]);

        $cloud = PreCustomerCloudSaleIngestionService::class;
        Http::fake([$this->url => function ($request) use (&$cloud) {
            $ack = app($cloud)->ingest((array) data_get($request->data(), 'envelope'));

            return Http::response($ack, $ack['status'] === 'refused' ? 422 : 201);
        }]);
        $sender = app(EdgeSyncSender::class);

        $this->assertSame('retry', $sender->sendNext('worker-1'));
        $fresh = $row->fresh();
        $this->assertSame('leased', $fresh->state, 'parked under a backoff lease — NOT failed_permanent');
        $this->assertStringStartsWith('backoff:', (string) $fresh->lease_owner);
        $this->assertStringContainsString('SCHEMA_UNSUPPORTED', (string) $fresh->last_error);
        $this->assertSame('refused', EdgeInboundSaleIngestion::query()->where('sale_uuid', $env['sale_uuid'])->value('status'));
        $this->assertSame(0, Customer::on('tenant')->count(), 'the old Cloud wrote nothing');
        $this->assertSame(0, SalesOrder::on('tenant')->count());

        // the Cloud is upgraded; the backoff elapses; the SAME immutable bytes apply exactly once
        $cloud = EdgeInboundSaleIngestionService::class;
        DB::connection('tenant')->table('edge_sync_outbox')->where('id', $row->id)->update(['lease_expires_at' => now()->subSecond()]);
        $this->assertSame('acknowledged', $sender->sendNext('worker-1'));
        $this->assertSame('acknowledged', $row->fresh()->state);
        $this->assertSame($env['content_hash'], $row->fresh()->content_hash, 'the envelope bytes never changed');
        $this->assertSame(1, Customer::on('tenant')->where('customer_uuid', $uuid)->count());
        $this->assertSame(1, SalesOrder::on('tenant')->count());
        $this->assertSame('already_applied', $this->ingest($env)['status']);
        $this->assertSame(1, Customer::on('tenant')->count());
    }
}
