<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Http\Controllers\Edge\EdgeLocalCustomerController;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalCustomerService;
use App\Services\Edge\EdgePosRuntimeFactory;
use App\Services\Edge\EdgeSaleEnvelopeBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE PHASE 4 — OFFLINE ADD CUSTOMER, the Branch Server half over the REAL branch_server routes
 * (docs/status/edge-phase4-offline-add-customer.md §1, §2, §5, §6):
 *
 *  - capability negotiation: with NOTHING advertised the shared view renders quick-add / add-address disabled with the hint,
 *    the two routes are null and the endpoints refuse (422 capabilityOff, explicit code, no row); once the Cloud advertised
 *    `customer_create` the SAME controls render enabled, the routes are set and the endpoints work;
 *  - create: canonical customer_uuid (ULID), phone_normalized by THE shared normaliser, local id in the reserved high range,
 *    edge_origin local, Online's response shape; the address rides with an address_uuid;
 *  - duplicate lookup: a known phone in any spelling REUSES the synced customer (reused:true) — no second row;
 *  - immediate use: the new customer is attached to a cash sale at once; the outbox envelope is `edge-sale-envelope-v3` with
 *    the `new_customer` intent (uuid, name, phone, phone_normalized, email, addresses[address_uuid]); a synced customer with
 *    a locally added address travels the same way; a synced customer without local edits stays the v1 `customer` kind;
 *  - gating both ways: capability withdrawn → a sale with a local customer fails closed (422, no sale, no envelope);
 *  - permission 403 {message, permission} = the Online quick-store permission; standby appliance → refused, no row.
 */
class EdgeCustomerQuickStoreHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productId;
    private int $cashMethodId;
    private int $syncedCustomerId;
    private string $syncedCustomerUuid;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'kot_batch_lines', 'kot_batches', 'print_jobs', 'manager_approvals', 'model_has_permissions', 'permissions',
            'customer_uuid_aliases', 'customer_addresses', 'customers', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminal_user', 'terminals', 'branches', 'users',
        ]);
        $now = now();
        $this->branchId = $this->makeBranch(['name' => 'Quick Add Branch', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'QA' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter One']);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'Grills']), ['name' => 'Chicken Tikka', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 250]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);
        // a customer synced from the Cloud book (raw insert, Cloud id, the legacy dashed phone spelling, no match key yet)
        $this->syncedCustomerUuid = (string) Str::ulid();
        $this->syncedCustomerId = (int) DB::connection('tenant')->table('customers')->insertGetId(['customer_uuid' => $this->syncedCustomerUuid, 'code' => 'C-001', 'name' => 'Ahmed Raza', 'phone' => '0300-1234567', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function sale(array $extra = []): array
    {
        return array_merge([
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 250, 'tendered_amount' => 500]],
        ], $extra);
    }

    private function runtime(string $html): array
    {
        $this->assertSame(1, preg_match('/window\.POS_RUNTIME = (\{.*?\});<\/script>/s', $html, $m));

        return json_decode($m[1], true);
    }

    // ── capability negotiation ──────────────────────────────────────────────────────────────────────────────────────

    public function test_nothing_advertised_renders_quick_add_disabled_routes_null_and_the_endpoints_refuse_with_an_explicit_code(): void
    {
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $rt = $this->runtime($html);
        $this->assertFalse($rt['capabilities']['customerCreate']);
        $this->assertFalse($rt['capabilities']['customerAddressCreate']);
        $this->assertNull($rt['routes']['customerQuickStore']);
        $this->assertNull($rt['routes']['customerAddressStore']);
        $this->assertMatchesRegularExpression('/<button[^>]*id="qa-save"[^>]*\sdisabled[^>]*title="' . preg_quote(e(EdgePosRuntimeFactory::LABELS['customerCreate']), '/') . '"/s', $html);

        // Server-side the gate holds even if a page bypasses the null route: explicit code, capabilityOff for the shared JS, no row.
        $r = $this->postJson('/edge/local/pos/customers/quick-store', ['name' => 'Zara Khan', 'phone' => '0301 7654321']);
        $r->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('capabilityOff', true)->assertJsonPath('code', EdgeLocalCustomerController::CODE_CAPABILITY_OFF);
        $this->assertSame(1, DB::connection('tenant')->table('customers')->count(), 'no customer created while not advertised');
        $this->postJson("/edge/local/pos/customers/{$this->syncedCustomerId}/addresses", ['address' => 'House 1, Street 2'])
            ->assertStatus(422)->assertJsonPath('capabilityOff', true);
        $this->assertSame(0, DB::connection('tenant')->table('customer_addresses')->count());
    }

    public function test_once_advertised_the_same_controls_render_enabled_and_the_routes_are_set(): void
    {
        $this->advertiseCloudCapabilities();
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $rt = $this->runtime($html);
        $this->assertTrue($rt['capabilities']['customerCreate']);
        $this->assertTrue($rt['capabilities']['customerAddressCreate']);
        $this->assertSame('/edge/local/pos/customers/quick-store', $rt['routes']['customerQuickStore']);
        $this->assertSame('/edge/local/pos/customers/{customer}/addresses', $rt['routes']['customerAddressStore']);
        $this->assertArrayNotHasKey('capability.customerCreate', $rt['labels']);
        $this->assertMatchesRegularExpression('/<button[^>]*id="qa-save"(?![^>]*\sdisabled)(?![^>]*title=)[^>]*>/s', $html, 'the quick-add control is enabled, no hint');
        $this->assertMatchesRegularExpression('/<button[^>]*id="new-addr-save"(?![^>]*\sdisabled)(?![^>]*title=)[^>]*>/s', $html);
        $this->assertSame(1, preg_match_all('/id="qa-save"/', $html), 'no markup change: the same single control');
    }

    // ── create / duplicate / address ────────────────────────────────────────────────────────────────────────────────

    public function test_quick_store_creates_a_local_customer_with_canonical_identity_match_key_and_address_in_online_shape(): void
    {
        $this->advertiseCloudCapabilities();
        $r = $this->postJson('/edge/local/pos/customers/quick-store', ['name' => 'Zara Khan', 'phone' => '+92 301 7654321', 'address' => 'House 1,  Street 2, DHA'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('reused', false);
        $c = $r->json('customer');
        $this->assertSame('Zara Khan', $c['name']);
        $this->assertTrue(Str::isUlid((string) $c['customer_uuid']), 'canonical identity = customer_uuid (ULID), never the local id');
        $this->assertSame('03017654321', $c['phone_normalized'], 'THE shared normaliser: +92 → 0');
        $this->assertSame('923017654321', $c['phone'], "Online's stored spelling (digits only) is kept for `phone`");
        $this->assertGreaterThanOrEqual(EdgeLocalCustomerService::LOCAL_ID_FLOOR, (int) $c['id'], 'local ids live in the reserved high range');
        $this->assertCount(1, $c['addresses']);
        $this->assertSame('House 1, Street 2, DHA', $c['addresses'][0]['address'], 'whitespace folded like Online');
        $this->assertTrue((bool) $c['addresses'][0]['is_default']);
        $this->assertTrue(Str::isUlid((string) $c['addresses'][0]['address_uuid']));
        $this->assertGreaterThanOrEqual(EdgeLocalCustomerService::LOCAL_ID_FLOOR, (int) $c['addresses'][0]['id']);

        $row = DB::connection('tenant')->table('customers')->find($c['id']);
        $this->assertSame('local', $row->edge_origin);
        $this->assertNotNull($row->edge_created_at);
        $this->assertSame('active', $row->status);
        $this->assertSame('local', DB::connection('tenant')->table('customer_addresses')->where('customer_id', $c['id'])->value('edge_origin'));

        // The new customer is in the lookup at once (immediate local use).
        $found = $this->getJson('/edge/local/pos/customers?q=Zara')->assertOk()->json('customers');
        $this->assertSame([(int) $c['id']], array_column($found, 'id'));
        $this->assertSame($c['customer_uuid'], $found[0]['customer_uuid']);

        // A second local customer gets the next high id (serialised allocation).
        $second = $this->postJson('/edge/local/pos/customers/quick-store', ['name' => 'Bilal', 'phone' => '0302 1111111'])->assertOk()->json('customer');
        $this->assertSame((int) $c['id'] + 1, (int) $second['id']);
    }

    public function test_a_known_phone_in_any_spelling_reuses_the_synced_customer_and_never_duplicates(): void
    {
        $this->advertiseCloudCapabilities();
        foreach (['03001234567', '0300 1234567', '+92 300 1234567', '0092-300-1234567'] as $spelling) {
            $r = $this->postJson('/edge/local/pos/customers/quick-store', ['name' => 'Someone Else', 'phone' => $spelling, 'email' => 'ahmed@example.test'])
                ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('reused', true);
            $this->assertSame($this->syncedCustomerId, (int) $r->json('customer.id'), "spelling [{$spelling}] matches the synced book");
            $this->assertSame($this->syncedCustomerUuid, $r->json('customer.customer_uuid'));
            $this->assertSame('Ahmed Raza', $r->json('customer.name'), 'an existing customer is never renamed');
        }
        $this->assertSame(1, DB::connection('tenant')->table('customers')->count(), 'no duplicate for a known phone');
        $row = DB::connection('tenant')->table('customers')->find($this->syncedCustomerId);
        $this->assertSame('ahmed@example.test', $row->email, 'only the missing email was topped up');
        $this->assertSame('03001234567', $row->phone_normalized, 'the legacy row received its match key on first reuse');
        $this->assertSame('cloud', $row->edge_origin, 'a reused synced customer stays Cloud-owned');
    }

    public function test_add_address_follows_online_rules_and_the_address_carries_its_identity(): void
    {
        $this->advertiseCloudCapabilities();
        $a1 = $this->postJson("/edge/local/pos/customers/{$this->syncedCustomerId}/addresses", ['label' => 'Home', 'address' => 'House 1, Street 2'])->assertOk()->json('address');
        $this->assertTrue((bool) $a1['is_default'], 'the first address becomes the default');
        $this->assertTrue(Str::isUlid((string) $a1['address_uuid']));
        $a2 = $this->postJson("/edge/local/pos/customers/{$this->syncedCustomerId}/addresses", ['label' => 'Office', 'address' => 'Plaza 9'])->assertOk()->json('address');
        $this->assertFalse((bool) $a2['is_default']);
        $again = $this->postJson("/edge/local/pos/customers/{$this->syncedCustomerId}/addresses", ['address' => 'house 1,   street 2'])->assertOk()->json('address');
        $this->assertSame((int) $a1['id'], (int) $again['id'], 'identical text is the same address (Online storeAddressOnce)');
        $this->assertSame(2, DB::connection('tenant')->table('customer_addresses')->where('customer_id', $this->syncedCustomerId)->count());
        $this->assertSame(['local', 'local'], DB::connection('tenant')->table('customer_addresses')->where('customer_id', $this->syncedCustomerId)->orderBy('id')->pluck('edge_origin')->all());
        $this->postJson("/edge/local/pos/customers/{$this->syncedCustomerId}/addresses", ['address' => ''])->assertStatus(422);
        $this->postJson('/edge/local/pos/customers/999999/addresses', ['address' => 'x'])->assertStatus(404);
    }

    // ── immediate use + envelope kind ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_local_customer_is_attached_at_once_and_the_envelope_carries_the_new_customer_intent_on_v3(): void
    {
        $this->advertiseCloudCapabilities();
        $c = $this->postJson('/edge/local/pos/customers/quick-store', ['name' => 'Zara Khan', 'phone' => '0301-7654321', 'address' => 'House 1, Street 2'])->assertOk()->json('customer');

        $sale = $this->postJson('/edge/local/pos/sales', $this->sale(['customer_id' => $c['id'], 'customer_name' => 'Zara K', 'customer_phone' => '0301-7654321']))->assertStatus(201)->json();
        $row = EdgeSyncOutbox::query()->where('sale_uuid', $sale['sale_uuid'])->firstOrFail();
        $this->assertSame(EdgeSaleEnvelopeBuilder::SCHEMA_VERSION_V3, $row->envelope_schema_version);
        $env = $row->envelopeArray();
        $this->assertSame('new_customer', $env['customer']['kind']);
        $this->assertSame($c['customer_uuid'], $env['customer']['customer_uuid']);
        $this->assertArrayNotHasKey('customer_id', $env['customer'], 'never a local numeric id');
        $this->assertSame('Zara K', $env['customer']['name'], "the SALE's snapshot, like the `customer` kind");
        $intent = $env['customer']['new_customer'];
        $this->assertSame('Zara Khan', $intent['name']);
        $this->assertSame('03017654321', $intent['phone']);
        $this->assertSame('03017654321', $intent['phone_normalized']);
        $this->assertNull($intent['email']);
        $this->assertCount(1, $intent['addresses']);
        $this->assertSame($c['addresses'][0]['address_uuid'], $intent['addresses'][0]['address_uuid']);
        $this->assertSame('House 1, Street 2', $intent['addresses'][0]['address']);
        $this->assertTrue($intent['addresses'][0]['is_default']);
        $this->assertSame(hash('sha256', \App\Services\Edge\EdgeCanonicalJson::encode(array_diff_key($env, ['content_hash' => 1]))), $env['content_hash'], 'content_hash covers the intent');
        $this->assertSame((int) $c['id'], (int) DB::connection('tenant')->table('sales_orders')->where('sale_uuid', $sale['sale_uuid'])->value('customer_id'));
    }

    public function test_a_synced_customer_with_a_locally_added_address_travels_as_new_customer_and_without_edits_stays_the_customer_kind(): void
    {
        $this->advertiseCloudCapabilities();
        // no local edits → the frozen v1 `customer` kind (byte shape unchanged)
        $plain = $this->postJson('/edge/local/pos/sales', $this->sale(['customer_id' => $this->syncedCustomerId]))->assertStatus(201)->json();
        $row = EdgeSyncOutbox::query()->where('sale_uuid', $plain['sale_uuid'])->firstOrFail();
        $this->assertSame(EdgeSaleEnvelopeBuilder::SCHEMA_VERSION, $row->envelope_schema_version);
        $this->assertSame('customer', $row->envelopeArray()['customer']['kind']);
        $this->assertArrayNotHasKey('new_customer', $row->envelopeArray()['customer']);

        // a local address on the synced customer → new_customer intent carrying ONLY the local-born address
        $a = $this->postJson("/edge/local/pos/customers/{$this->syncedCustomerId}/addresses", ['label' => 'Home', 'address' => 'House 1, Street 2'])->assertOk()->json('address');
        $withAddress = $this->postJson('/edge/local/pos/sales', $this->sale(['customer_id' => $this->syncedCustomerId]))->assertStatus(201)->json();
        $env = EdgeSyncOutbox::query()->where('sale_uuid', $withAddress['sale_uuid'])->firstOrFail()->envelopeArray();
        $this->assertSame(EdgeSaleEnvelopeBuilder::SCHEMA_VERSION_V3, $env['envelope_schema_version']);
        $this->assertSame('new_customer', $env['customer']['kind']);
        $this->assertSame($this->syncedCustomerUuid, $env['customer']['customer_uuid']);
        $this->assertSame([$a['address_uuid']], array_column($env['customer']['new_customer']['addresses'], 'address_uuid'));
    }

    public function test_capability_withdrawn_a_sale_with_a_local_customer_fails_closed_with_no_sale_and_no_envelope(): void
    {
        $this->advertiseCloudCapabilities();
        $c = $this->postJson('/edge/local/pos/customers/quick-store', ['name' => 'Zara Khan', 'phone' => '0301-7654321'])->assertOk()->json('customer');
        $this->advertiseCloudCapabilities(['customer_create' => false]);   // the Cloud withdrew it (downgrade) — the appliance must not emit v3

        $r = $this->postJson('/edge/local/pos/sales', $this->sale(['customer_id' => $c['id']]));
        $r->assertStatus(422);
        $this->assertStringContainsString('ENVELOPE_UNSUPPORTED', (string) $r->json('message'));
        $this->assertSame(0, DB::connection('tenant')->table('sales_orders')->count(), 'the sale rolled back whole');
        $this->assertSame(0, DB::connection('tenant')->table('edge_sync_outbox')->count(), 'no envelope of an unsupported kind');
        // a walk-in / synced customer sale still works
        $this->postJson('/edge/local/pos/sales', $this->sale(['customer_id' => $this->syncedCustomerId]))->assertStatus(201);
    }

    // ── permission / authority ──────────────────────────────────────────────────────────────────────────────────────

    public function test_the_online_quick_store_permission_gates_both_twins_with_the_403_shape(): void
    {
        $this->advertiseCloudCapabilities();
        $this->revokeEdgePermission($this->userId, EdgeLocalCustomerController::PERMISSION);
        $this->postJson('/edge/local/pos/customers/quick-store', ['name' => 'Zara Khan', 'phone' => '0301-7654321'])
            ->assertStatus(403)->assertJsonPath('permission', 'tenant.pos.customers.quick-store')->assertJsonStructure(['message', 'permission']);
        $this->postJson("/edge/local/pos/customers/{$this->syncedCustomerId}/addresses", ['address' => 'House 1'])
            ->assertStatus(403)->assertJsonPath('permission', 'tenant.pos.customers.quick-store');
        $this->assertSame(1, DB::connection('tenant')->table('customers')->count());
        $this->assertSame(0, DB::connection('tenant')->table('customer_addresses')->count());

        $this->grantEdgePermission($this->userId, EdgeLocalCustomerController::PERMISSION);
        $this->postJson('/edge/local/pos/customers/quick-store', ['name' => 'Zara Khan', 'phone' => '0301-7654321'])->assertOk();
    }

    public function test_a_standby_appliance_refuses_to_add_a_customer_or_address(): void
    {
        $this->advertiseCloudCapabilities();
        // lease mode on (a heartbeat URL is configured) and the Cloud holds the branch → every Edge mutation refuses.
        config(['edge.authority.heartbeat_url' => 'http://127.0.0.1:9/api/edge/authority/heartbeat']);
        DB::connection('tenant')->table('edge_local_meta')->update(['authority_state' => 'standby']);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);

        $r = $this->postJson('/edge/local/pos/customers/quick-store', ['name' => 'Zara Khan', 'phone' => '0301-7654321']);
        $r->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('code', 'STANDBY');
        $this->assertStringContainsString('Local Mode is not active', (string) $r->json('message'));
        $this->postJson("/edge/local/pos/customers/{$this->syncedCustomerId}/addresses", ['address' => 'House 1'])->assertStatus(422)->assertJsonPath('code', 'STANDBY');
        $this->assertSame(1, DB::connection('tenant')->table('customers')->count());
        $this->assertSame(0, DB::connection('tenant')->table('customer_addresses')->count());

        // the writer (local_active) may add again
        DB::connection('tenant')->table('edge_local_meta')->update(['authority_state' => 'local_active']);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);
        $this->postJson('/edge/local/pos/customers/quick-store', ['name' => 'Zara Khan', 'phone' => '0301-7654321'])->assertOk();
    }
}
