<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeLocalBootstrapImporter;
use App\Services\Edge\EdgeLocalConfigRefreshApplier;
use App\Services\Edge\EdgeLocalCustomerService;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE PHASE 4 — bootstrap export + the refresh applier's customer book, keyed by IDENTITY
 * (docs/status/edge-phase4-offline-add-customer.md §4). ONE MySQL database plays both roles in turn: first the Cloud
 * tenant (the REAL buildSections exports it), then — after the local rows are staged — the appliance the REAL applier
 * refreshes (the edge migrations are applied to it, so edge_origin / the alias table exist).
 *
 *  - export: customers carry phone_normalized (backfilled once for a legacy row), addresses carry address_uuid, the
 *    `customer_uuid_aliases` section ships, the watermark follows an alias;
 *  - THE REFRESH RULE: a payload customer whose uuid lives locally under a DIFFERENT numeric id (an offline-created
 *    customer that reached the Cloud) updates that local row IN PLACE — same local id, edge_origin → cloud, NOT
 *    deactivated, no second row; a local-origin customer the Cloud does not carry is UNTOUCHED (still active); a
 *    Cloud-origin customer the Cloud dropped is deactivated as before; a new Cloud customer inserts by its Cloud id;
 *  - addresses by address_uuid with customer_id MAPPED to the local parent; a local-origin address is kept; a local
 *    copy of an address the Cloud now carries with the same text is merged away;
 *  - THE ALIAS RULE: alias(U → W) merges the local row U into the canonical W — sales, addresses, reservations (by id and
 *    uuid) repointed, duplicate address text removed, the U row deleted, the alias stored locally;
 *  - the importer accepts the alias section on a fresh bootstrap.
 */
class EdgeCustomerRefreshMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private object $svc;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        config(['app.role' => 'branch_server']);
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            // sales_order_line_cancellations FKs void_reasons, which the bootstrap import clears (clearExistingConfig) —
            // a KOT void left by an earlier class in the same process (EdgeCashierPermissionMatrix) must not block it.
            'edge_local_table_reservations', 'edge_local_meta', 'sales_order_line_cancellations', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors',
            'customer_uuid_aliases', 'customer_addresses', 'customers',
            'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'branch_user', 'users',
            'terminals', 'payment_methods', 'branches',
        ]);
        // ONE database plays both sides: earlier tests inserted LOCAL-range ids (≥ LOCAL_ID_FLOOR), which raised the
        // auto-increment counter — pull it back so the "Cloud" rows of this test get ordinary small Cloud ids again.
        DB::connection('tenant')->statement('ALTER TABLE customers AUTO_INCREMENT = 1');
        DB::connection('tenant')->statement('ALTER TABLE customer_addresses AUTO_INCREMENT = 1');
        $this->branchId = $this->makeBranch(['name' => 'Refresh', 'code' => 'RF', 'status' => 'active', 'timezone' => 'Asia/Karachi']);
        DB::connection('tenant')->table('payment_methods')->insert(['code' => 'CASH', 'name' => 'Cash', 'method_type' => 'cash', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->svc = new class(app(OfflineEdgeEntitlementService::class), app(TenancyManager::class), app(EdgePairingService::class)) extends EdgeBootstrapService {
            public function sectionsFor(Tenant $t, Branch $b): array
            {
                return $this->buildSections($t, $b);
            }

            public function watermark(Branch $b): string
            {
                return $this->sourceRevision($b);
            }
        };
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function sections(): array
    {
        $tenant = new Tenant(['tenant_code' => 'refreshp4', 'business_name' => 'Demo', 'currency_code' => 'PKR']);
        $tenant->id = 42;

        return $this->svc->sectionsFor($tenant, Branch::on('tenant')->find($this->branchId));
    }

    private function package(array $sections, int $revision): array
    {
        $summary = [];
        foreach ($sections as $name => $rows) {
            $summary[$name] = ['hash' => hash('sha256', $this->svc->canonicalJson($rows)), 'count' => count($rows)];
        }
        $manifest = [
            'schema_version' => EdgeBootstrapService::SCHEMA_VERSION, 'snapshot_uuid' => 'snap-' . $revision, 'tenant_code' => 'refreshp4', 'tenant_id' => 42,
            'branch_id' => $this->branchId, 'device_public_uuid' => 'test-device-uuid', 'activation_epoch' => 1, 'config_revision' => $revision,
            'config_schema_version' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION, 'source_revision' => 'rev-' . $revision, 'sections' => $summary,
        ];
        $manifest['manifest_hash'] = $this->svc->computeManifestHash(EdgeBootstrapService::SCHEMA_VERSION, 'snap-' . $revision, 42, $this->branchId, 'test-device-uuid', 1, $revision, EdgeBootstrapService::CONFIG_SCHEMA_VERSION, $summary);

        return ['manifest' => $manifest, 'sections' => $sections];
    }

    private function cloudCustomer(string $name, string $phone, ?string $uuid = null, array $extra = []): array
    {
        $uuid ??= (string) Str::ulid();
        $id = (int) DB::connection('tenant')->table('customers')->insertGetId(array_merge([
            'customer_uuid' => $uuid, 'code' => null, 'name' => $name, 'phone' => $phone, 'phone_normalized' => \App\Support\PhoneNormalizer::normalize($phone),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return [$id, $uuid];
    }

    private function address(int $customerId, string $text, bool $default = false, ?string $uuid = null, array $extra = []): array
    {
        $uuid ??= (string) Str::ulid();
        $id = (int) DB::connection('tenant')->table('customer_addresses')->insertGetId(array_merge([
            'address_uuid' => $uuid, 'customer_id' => $customerId, 'label' => null, 'address' => $text, 'is_default' => $default ? 1 : 0, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return [$id, $uuid];
    }

    /** A customer born on THIS appliance (what EdgeLocalCustomerService::quickStore writes). */
    private function localCustomer(string $name, string $phone, ?string $uuid = null): array
    {
        $uuid ??= (string) Str::ulid();
        $id = app(EdgeLocalCustomerService::class)->nextLocalId('customers');
        DB::connection('tenant')->table('customers')->insert([
            'id' => $id, 'customer_uuid' => $uuid, 'code' => null, 'name' => $name, 'phone' => preg_replace('/\D+/', '', $phone),
            'phone_normalized' => \App\Support\PhoneNormalizer::normalize($phone), 'status' => 'active', 'edge_origin' => 'local', 'edge_created_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$id, $uuid];
    }

    private function localAddress(int $customerId, string $text, bool $default = false): array
    {
        $uuid = (string) Str::ulid();
        $id = app(EdgeLocalCustomerService::class)->nextLocalId('customer_addresses');
        DB::connection('tenant')->table('customer_addresses')->insert([
            'id' => $id, 'address_uuid' => $uuid, 'customer_id' => $customerId, 'label' => null, 'address' => $text, 'is_default' => $default ? 1 : 0,
            'edge_origin' => 'local', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$id, $uuid];
    }

    private function apply(array $package): array
    {
        return app(EdgeLocalConfigRefreshApplier::class)->apply($package['manifest'], $package['sections']);
    }

    private function customer(int $id): ?object
    {
        return DB::connection('tenant')->table('customers')->find($id);
    }

    // ── export ──────────────────────────────────────────────────────────────────

    public function test_the_export_carries_the_match_key_the_address_identity_and_the_alias_book_and_backfills_a_legacy_row_once(): void
    {
        // every fixture row is a minute old: a same-second repair could not move a seconds-resolution max(updated_at)
        $old = ['created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()];
        [$keyed, $keyedUuid] = $this->cloudCustomer('Keyed', '03001234567', null, $old);
        $legacyId = (int) DB::connection('tenant')->table('customers')->insertGetId(['customer_uuid' => (string) Str::ulid(), 'name' => 'Legacy', 'phone' => '0300-7654321', 'phone_normalized' => null, 'status' => 'active'] + $old);
        $legacyAddress = (int) DB::connection('tenant')->table('customer_addresses')->insertGetId(['address_uuid' => null, 'customer_id' => $legacyId, 'address' => 'Old Street', 'is_default' => 1] + $old);
        $this->address($keyed, 'House 1', true, null, $old);
        DB::connection('tenant')->table('customer_uuid_aliases')->insert(['alias_uuid' => $alias = (string) Str::ulid(), 'customer_id' => $keyed, 'customer_uuid' => $keyedUuid, 'source_device_uuid' => 'dev-1', 'source' => 'phone_match'] + $old);
        $branch = Branch::on('tenant')->find($this->branchId);
        $before = $this->svc->watermark($branch);

        $s = $this->sections();
        $customers = collect($s['customers'])->keyBy('id');
        $this->assertSame('03001234567', $customers[$keyed]['phone_normalized']);
        $this->assertSame('03007654321', $customers[$legacyId]['phone_normalized'], 'the legacy row received its key at export');
        $this->assertSame('03007654321', DB::connection('tenant')->table('customers')->where('id', $legacyId)->value('phone_normalized'));
        $addresses = collect($s['customer_addresses'])->keyBy('id');
        $this->assertTrue(Str::isUlid((string) $addresses[$legacyAddress]['address_uuid']), 'a legacy address received its identity at export');
        $this->assertSame(['id', 'address_uuid', 'customer_id', 'label', 'address', 'is_default'], array_keys($addresses->first()));
        $this->assertSame([['alias_uuid' => $alias, 'customer_id' => $keyed, 'customer_uuid' => $keyedUuid, 'source_device_uuid' => 'dev-1', 'source' => 'phone_match']], $s['customer_uuid_aliases']);
        $this->assertArrayHasKey('customer_uuid_aliases', $s);
        $this->assertSame('edge-bootstrap-v8', EdgeBootstrapService::SCHEMA_VERSION, 'additive within the unreleased v8 generation — no bump');

        // repairs moved the watermark once; a new alias moves it again; nothing churns afterwards
        $repaired = $this->svc->watermark($branch);
        $this->assertNotSame($before, $repaired);
        $this->sections();
        $this->assertSame($repaired, $this->svc->watermark($branch));
        DB::connection('tenant')->table('customer_uuid_aliases')->insert(['alias_uuid' => (string) Str::ulid(), 'customer_id' => $keyed, 'customer_uuid' => $keyedUuid, 'source' => 'phone_match', 'created_at' => now()->addSecond(), 'updated_at' => now()->addSecond()]);
        $this->assertNotSame($repaired, $this->svc->watermark($branch));
    }

    public function test_a_fresh_bootstrap_imports_the_alias_book(): void
    {
        [$keyed, $keyedUuid] = $this->cloudCustomer('Keyed', '03001234567');
        $this->address($keyed, 'House 1', true);
        DB::connection('tenant')->table('customer_uuid_aliases')->insert(['alias_uuid' => $alias = (string) Str::ulid(), 'customer_id' => $keyed, 'customer_uuid' => $keyedUuid, 'source' => 'phone_match', 'created_at' => now(), 'updated_at' => now()]);
        $package = $this->package($this->sections(), 1);

        // the same database now plays the appliance: empty config, bootstrap import
        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers']);
        $meta = app(EdgeLocalBootstrapImporter::class)->import($package);
        $this->assertSame(EdgeLocalMeta::STATE_BOOTSTRAPPED, $meta->runtime_state);
        $this->assertSame($keyedUuid, DB::connection('tenant')->table('customer_uuid_aliases')->where('alias_uuid', $alias)->value('customer_uuid'));
        $this->assertSame('cloud', DB::connection('tenant')->table('customers')->where('id', $keyed)->value('edge_origin'));
        $this->assertSame('03001234567', DB::connection('tenant')->table('customers')->where('id', $keyed)->value('phone_normalized'));
    }

    // ── THE REFRESH RULE ────────────────────────────────────────────────────────

    public function test_refresh_matches_customers_by_uuid_keeps_the_local_id_never_deactivates_a_local_customer_and_tombstones_only_cloud_rows(): void
    {
        // Cloud book at revision 2: Ahmed (unchanged), Zara (the offline-created customer, now on the Cloud under a SMALL Cloud id),
        // Bilal (new on the Cloud). Cloud dropped: Retired.
        [$ahmed, $ahmedUuid] = $this->cloudCustomer('Ahmed Raza', '03001234567');
        $zaraUuid = (string) Str::ulid();
        [$zaraCloud] = $this->cloudCustomer('Zara Khan', '03017654321', $zaraUuid, ['email' => 'zara@example.test']);
        [$zaraCloudAddress, $zaraAddressUuid] = $this->address($zaraCloud, 'House 1, Street 2', true);
        [$bilal, $bilalUuid] = $this->cloudCustomer('Bilal', '03021111111');
        $package = $this->package($this->sections(), 2);

        // The APPLIANCE before the refresh: Ahmed (synced, Cloud id), Retired (synced, Cloud dropped it), Zara born HERE under a
        // high local id with her own address (same address_uuid the sale carried), Noor born here and not yet on the Cloud.
        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 1);
        DB::connection('tenant')->table('customers')->insert(['id' => $ahmed, 'customer_uuid' => $ahmedUuid, 'name' => 'Ahmed Raza', 'phone' => '03001234567', 'phone_normalized' => '03001234567', 'status' => 'active', 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        $retired = (int) DB::connection('tenant')->table('customers')->insertGetId(['customer_uuid' => (string) Str::ulid(), 'name' => 'Retired', 'phone' => '03009999999', 'phone_normalized' => '03009999999', 'status' => 'active', 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        [$zaraLocal] = $this->localCustomer('Zara Khan', '0301-7654321', $zaraUuid);
        $this->assertNotSame($zaraCloud, $zaraLocal, 'the numeric ids differ between the two databases');
        $zaraLocalAddress = app(EdgeLocalCustomerService::class)->nextLocalId('customer_addresses');
        DB::connection('tenant')->table('customer_addresses')->insert(['id' => $zaraLocalAddress, 'address_uuid' => $zaraAddressUuid, 'customer_id' => $zaraLocal, 'address' => 'House 1, Street 2', 'is_default' => 1, 'edge_origin' => 'local', 'created_at' => now(), 'updated_at' => now()]);
        [$noor] = $this->localCustomer('Noor', '0303-2222222');
        [$noorAddress] = $this->localAddress($noor, 'Flat 4');
        $zaraSale = (int) DB::connection('tenant')->table('sales_orders')->insertGetId(['sale_uuid' => (string) Str::ulid(), 'sale_no' => 'SO-1', 'branch_id' => $this->branchId, 'customer_id' => $zaraLocal, 'order_source' => 'pos', 'order_type' => 'takeaway', 'sale_date' => now(), 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->apply($package);
        $this->assertSame(EdgeLocalConfigRefreshApplier::OUTCOME_APPLIED, $result['outcome']);

        // Zara: matched by UUID, local id KEPT, Cloud fields applied, edge_origin → cloud, ACTIVE, no second row.
        $zara = $this->customer($zaraLocal);
        $this->assertNotNull($zara, 'the local row survives under its local id');
        $this->assertSame('active', $zara->status, 'NEVER deactivated because the numeric id differs');
        $this->assertSame('cloud', $zara->edge_origin, 'the Cloud now owns the row');
        $this->assertSame('zara@example.test', $zara->email, "the Cloud's fields applied in place");
        $this->assertNull($this->customer($zaraCloud), 'the Cloud id is NOT inserted as a second row');
        $this->assertSame(1, DB::connection('tenant')->table('customers')->where('customer_uuid', $zaraUuid)->count());
        $this->assertSame($zaraLocal, (int) DB::connection('tenant')->table('sales_orders')->where('id', $zaraSale)->value('customer_id'), 'history keeps resolving');
        // her address: matched by address_uuid, parent mapped to the LOCAL id, origin → cloud
        $addr = DB::connection('tenant')->table('customer_addresses')->where('address_uuid', $zaraAddressUuid)->get();
        $this->assertCount(1, $addr);
        $this->assertSame($zaraLocalAddress, (int) $addr[0]->id);
        $this->assertSame($zaraLocal, (int) $addr[0]->customer_id);
        $this->assertSame('cloud', $addr[0]->edge_origin);

        // Noor: born here, not on the Cloud yet → untouched (still active, still local, address kept).
        $this->assertSame('active', $this->customer($noor)->status);
        $this->assertSame('local', $this->customer($noor)->edge_origin);
        $this->assertSame('local', DB::connection('tenant')->table('customer_addresses')->where('id', $noorAddress)->value('edge_origin'));

        // Retired: Cloud-origin and gone from the Cloud → inactive (as before). Ahmed unchanged. Bilal inserted by his Cloud id.
        $this->assertSame('inactive', $this->customer($retired)->status);
        $this->assertSame('active', $this->customer($ahmed)->status);
        $this->assertSame($bilalUuid, $this->customer($bilal)->customer_uuid);
        $this->assertSame('cloud', $this->customer($bilal)->edge_origin);
        $this->assertSame(5, DB::connection('tenant')->table('customers')->count(), 'Ahmed, Retired, Zara (under her local id), Noor, Bilal — exactly one row per identity');

        // Same revision again is a no-op; the next revision keeps every rule stable.
        $this->assertSame(EdgeLocalConfigRefreshApplier::OUTCOME_ALREADY_APPLIED, $this->apply($package)['outcome']);
        $this->assertSame('active', $this->customer($zaraLocal)->status);
    }

    public function test_a_local_copy_of_an_address_the_cloud_now_carries_with_the_same_text_is_merged_away(): void
    {
        [$ahmed, $ahmedUuid] = $this->cloudCustomer('Ahmed Raza', '03001234567');
        [$cloudAddress, $cloudAddressUuid] = $this->address($ahmed, 'House 1, Street 2', true);   // the Cloud reused Online's text rule — a DIFFERENT uuid than the till's
        $package = $this->package($this->sections(), 2);

        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 1);
        DB::connection('tenant')->table('customers')->insert(['id' => $ahmed, 'customer_uuid' => $ahmedUuid, 'name' => 'Ahmed Raza', 'phone' => '03001234567', 'phone_normalized' => '03001234567', 'status' => 'active', 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        [$localCopy] = $this->localAddress($ahmed, 'house 1,  street 2', true);
        [$localOther] = $this->localAddress($ahmed, 'Plaza 9');

        $this->apply($package);
        $book = DB::connection('tenant')->table('customer_addresses')->where('customer_id', $ahmed)->orderBy('id')->get();
        $this->assertSame([$cloudAddress, $localOther], $book->pluck('id')->map(fn ($v) => (int) $v)->all(), 'the Cloud address arrived, the same-text local copy merged away, the other local address kept');
        $this->assertSame($cloudAddressUuid, $book[0]->address_uuid);
        $this->assertSame(['cloud', 'local'], $book->pluck('edge_origin')->all());
    }

    public function test_an_id_held_by_a_different_identity_refuses_the_whole_refresh(): void
    {
        [$ahmed, $ahmedUuid] = $this->cloudCustomer('Ahmed Raza', '03001234567');
        $package = $this->package($this->sections(), 2);

        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 1);
        // the Cloud id is held locally by ANOTHER identity (cannot happen with the reserved local range — modelled deliberately)
        DB::connection('tenant')->table('customers')->insert(['id' => $ahmed, 'customer_uuid' => (string) Str::ulid(), 'name' => 'Somebody Else', 'phone' => '03005555555', 'phone_normalized' => '03005555555', 'status' => 'active', 'edge_origin' => 'local', 'created_at' => now(), 'updated_at' => now()]);

        try {
            $this->apply($package);
            $this->fail('one customer must never be overwritten by another');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('REFRESH_CONFLICT', $e->getMessage());
        }
        $this->assertSame('Somebody Else', $this->customer($ahmed)->name, 'nothing changed');
        $this->assertSame(1, (int) EdgeLocalMeta::current()->last_applied_config_revision, 'the revision did not advance');
    }

    // ── THE ALIAS RULE ──────────────────────────────────────────────────────────

    public function test_an_alias_merges_the_local_offline_customer_into_the_canonical_one_and_repoints_every_reference(): void
    {
        // Cloud: Zara Ahmed owns the phone; the appliance's offline "Zara Khan" (uuid U) was MATCHED → alias U → W.
        $w = (string) Str::ulid();
        [$zaraCloud] = $this->cloudCustomer('Zara Ahmed', '03017654321', $w);
        [$officeId, $officeUuid] = $this->address($zaraCloud, 'Plaza 9', true);
        $u = (string) Str::ulid();
        [$homeId, $homeUuid] = $this->address($zaraCloud, 'House 1, Street 2');   // the offline address, created on the Cloud by the ingestion with the till's uuid
        DB::connection('tenant')->table('customer_uuid_aliases')->insert(['alias_uuid' => $u, 'customer_id' => $zaraCloud, 'customer_uuid' => $w, 'source_device_uuid' => 'test-device-uuid', 'source' => 'phone_match', 'created_at' => now(), 'updated_at' => now()]);
        $package = $this->package($this->sections(), 2);

        // Appliance: Zara Ahmed synced (Cloud id, the office address), and the local offline Zara Khan (uuid U) with her local
        // address (same address_uuid as the Cloud's home), a paid sale, a held sale, a reservation by id AND uuid, a table session.
        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 1);
        DB::connection('tenant')->table('customers')->insert(['id' => $zaraCloud, 'customer_uuid' => $w, 'name' => 'Zara Ahmed', 'phone' => '03017654321', 'phone_normalized' => '03017654321', 'status' => 'active', 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('customer_addresses')->insert(['id' => $officeId, 'address_uuid' => $officeUuid, 'customer_id' => $zaraCloud, 'address' => 'Plaza 9', 'is_default' => 1, 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        [$local] = $this->localCustomer('Zara Khan', '0301 7654321', $u);
        $localHome = app(EdgeLocalCustomerService::class)->nextLocalId('customer_addresses');
        DB::connection('tenant')->table('customer_addresses')->insert(['id' => $localHome, 'address_uuid' => $homeUuid, 'customer_id' => $local, 'address' => 'House 1, Street 2', 'is_default' => 1, 'edge_origin' => 'local', 'created_at' => now(), 'updated_at' => now()]);
        [$localDup] = $this->localAddress($local, 'plaza 9');   // typed again at the till — the canonical book already has it
        $paid = (int) DB::connection('tenant')->table('sales_orders')->insertGetId(['sale_uuid' => (string) Str::ulid(), 'sale_no' => 'SO-P', 'branch_id' => $this->branchId, 'customer_id' => $local, 'customer_name' => 'Zara Khan', 'order_source' => 'pos', 'order_type' => 'takeaway', 'sale_date' => now(), 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
        $held = (int) DB::connection('tenant')->table('sales_orders')->insertGetId(['sale_uuid' => (string) Str::ulid(), 'sale_no' => 'SO-H', 'branch_id' => $this->branchId, 'customer_id' => $local, 'order_source' => 'pos', 'order_type' => 'dine_in', 'sale_date' => now(), 'status' => 'held', 'created_at' => now(), 'updated_at' => now()]);
        $floor = (int) DB::connection('tenant')->table('restaurant_floors')->insertGetId(['branch_id' => $this->branchId, 'name' => 'G', 'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $table = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'reserved', 'restaurant_floor_id' => $floor]);
        DB::connection('tenant')->table('restaurant_tables')->where('id', $table)->update(['reserved_customer_id' => $local]);
        $userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RF' . Str::random(4)]);
        $session = (int) DB::connection('tenant')->table('restaurant_table_sessions')->insertGetId(['session_uuid' => (string) Str::ulid(), 'session_no' => 'TS-1', 'branch_id' => $this->branchId, 'restaurant_table_id' => $table, 'opened_by_user_id' => $userId, 'customer_id' => $local, 'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $reservation = (int) DB::connection('tenant')->table('edge_local_table_reservations')->insertGetId(['reservation_uuid' => (string) Str::ulid(), 'branch_id' => $this->branchId, 'restaurant_table_id' => $table, 'customer_id' => $local, 'customer_uuid' => $u, 'customer_name' => 'Zara Khan', 'reserved_for' => now()->addHour(), 'status' => 'active', 'reserved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->apply($package);
        $this->assertSame(EdgeLocalConfigRefreshApplier::OUTCOME_APPLIED, $result['outcome']);

        $this->assertNull($this->customer($local), 'the local offline row is gone — its identity lives on as the alias');
        $this->assertSame(1, DB::connection('tenant')->table('customers')->count());
        $this->assertSame($w, DB::connection('tenant')->table('customer_uuid_aliases')->where('alias_uuid', $u)->value('customer_uuid'), 'the alias is stored locally');
        foreach ([$paid, $held] as $saleId) {
            $this->assertSame($zaraCloud, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('customer_id'), 'sales repointed to the canonical customer');
        }
        $this->assertSame('Zara Khan', DB::connection('tenant')->table('sales_orders')->where('id', $paid)->value('customer_name'), 'the sale keeps its own snapshot');
        $this->assertSame($zaraCloud, (int) DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $session)->value('customer_id'));
        $this->assertSame($zaraCloud, (int) DB::connection('tenant')->table('restaurant_tables')->where('id', $table)->value('reserved_customer_id'));
        $r = DB::connection('tenant')->table('edge_local_table_reservations')->find($reservation);
        $this->assertSame($zaraCloud, (int) $r->customer_id);
        $this->assertSame($w, $r->customer_uuid, 'a reservation handed back later resolves on the Cloud by the canonical uuid');
        $book = DB::connection('tenant')->table('customer_addresses')->where('customer_id', $zaraCloud)->orderBy('id')->get();
        $this->assertSame(['Plaza 9', 'House 1, Street 2'], $book->pluck('address')->all(), 'the moved address joined the book; the typed-again duplicate text is gone');
        // the home address matched by address_uuid: the LOCAL row is kept (same local id), re-parented and now Cloud-owned
        $this->assertSame([$officeId, $localHome], $book->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame($homeUuid, $book[1]->address_uuid);
        $this->assertSame('cloud', $book[1]->edge_origin);
        $this->assertNull(DB::connection('tenant')->table('customer_addresses')->find($homeId), "the Cloud's id for it is not inserted as a second row");
        $this->assertSame(1, $book->where('is_default', 1)->count(), 'exactly one default');
        $this->assertNull(DB::connection('tenant')->table('customer_addresses')->find($localDup), 'the typed-again duplicate is gone');

        // replaying the revision is a no-op; a later revision that still carries the alias changes nothing more
        $this->assertSame(EdgeLocalConfigRefreshApplier::OUTCOME_ALREADY_APPLIED, $this->apply($package)['outcome']);
    }

    // ── IDENTITY OWNERSHIP (F1 fix: docs/status/edge-phase4-fix-address-identity-skew.md) ──────────────────────────────

    /** The REAL Phase 4 tenant migration (its up() is idempotent: only the address_uuid backfill and the index step have work left). */
    private function phase4IdentityMigration(): object
    {
        return require database_path('migrations/tenant/2026_10_06_000001_edge_phase4_offline_customer_identity.php');
    }

    /** Model the appliance side: the same Cloud customers under the same ids (bootstrap wrote them, Cloud uuids and all). */
    private function stageApplianceCustomers(array $customers): void
    {
        foreach ($customers as [$id, $uuid, $name, $phone]) {
            DB::connection('tenant')->table('customers')->insert(['id' => $id, 'customer_uuid' => $uuid, 'name' => $name, 'phone' => $phone, 'phone_normalized' => $phone, 'status' => 'active', 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_a_cloud_origin_address_with_a_null_uuid_adopts_the_cloud_identity_on_refresh_and_keeps_its_id(): void
    {
        // (a) the appliance took the identity column through schema-upgrade AFTER the fix: pre-existing addresses stay NULL
        [$ahmed, $ahmedUuid] = $this->cloudCustomer('Ahmed Raza', '03001234567');
        [$home, $homeUuid] = $this->address($ahmed, 'House 1, Street 2', true);
        [$office, $officeUuid] = $this->address($ahmed, 'Plaza 9');
        $package = $this->package($this->sections(), 2);

        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 1);
        $this->stageApplianceCustomers([[$ahmed, $ahmedUuid, 'Ahmed Raza', '03001234567']]);
        foreach ([[$home, 'House 1, Street 2', 1], [$office, 'Plaza 9', 0]] as [$id, $text, $default]) {
            DB::connection('tenant')->table('customer_addresses')->insert(['id' => $id, 'address_uuid' => null, 'customer_id' => $ahmed, 'address' => $text, 'is_default' => $default, 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        }

        $result = $this->apply($package);
        $this->assertSame(EdgeLocalConfigRefreshApplier::OUTCOME_APPLIED, $result['outcome']);
        $book = DB::connection('tenant')->table('customer_addresses')->orderBy('id')->get();
        $this->assertSame([$home, $office], $book->pluck('id')->map(fn ($v) => (int) $v)->all(), 'the same two rows, ids kept, no duplicate');
        $this->assertSame([$homeUuid, $officeUuid], $book->pluck('address_uuid')->all(), "the Cloud's identities adopted");
        $this->assertSame(['cloud', 'cloud'], $book->pluck('edge_origin')->all());
        $this->assertSame(2, (int) EdgeLocalMeta::current()->last_applied_config_revision, 'the appliance is fresh');
    }

    public function test_the_real_skew_a_cloud_origin_address_minted_a_different_uuid_on_the_appliance_adopts_the_cloud_identity_without_conflict(): void
    {
        // (b) REPRODUCE THE DEFECT: the same pre-existing addresses, identity column backfilled by the migration on BOTH sides.
        $migration = $this->phase4IdentityMigration();
        [$ahmed, $ahmedUuid] = $this->cloudCustomer('Ahmed Raza', '03001234567');
        [$zara, $zaraUuid] = $this->cloudCustomer('Zara Khan', '03017654321');
        $home = (int) DB::connection('tenant')->table('customer_addresses')->insertGetId(['address_uuid' => null, 'customer_id' => $ahmed, 'address' => 'House 1, Street 2', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $office = (int) DB::connection('tenant')->table('customer_addresses')->insertGetId(['address_uuid' => null, 'customer_id' => $zara, 'address' => 'Plaza 9', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        config(['app.role' => 'cloud']);
        $migration->up();   // the Cloud tenant `migrate`: the OWNER mints
        $cloudUuids = DB::connection('tenant')->table('customer_addresses')->orderBy('id')->pluck('address_uuid', 'id')->all();
        $this->assertTrue(Str::isUlid((string) $cloudUuids[$home]) && Str::isUlid((string) $cloudUuids[$office]));
        $package = $this->package($this->sections(), 18);

        // the appliance BEFORE the fix: same rows, migration backfilled per row here too (the pre-fix code path = the Cloud's)
        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 17);
        $this->stageApplianceCustomers([[$ahmed, $ahmedUuid, 'Ahmed Raza', '03001234567'], [$zara, $zaraUuid, 'Zara Khan', '03017654321']]);
        foreach ([[$home, $ahmed, 'House 1, Street 2'], [$office, $zara, 'Plaza 9']] as [$id, $customerId, $text]) {
            DB::connection('tenant')->table('customer_addresses')->insert(['id' => $id, 'address_uuid' => null, 'customer_id' => $customerId, 'address' => $text, 'is_default' => 1, 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        }
        $migration->up();   // still role=cloud: EXACTLY what the pre-fix appliance did at schema-upgrade
        $skewed = DB::connection('tenant')->table('customer_addresses')->orderBy('id')->pluck('address_uuid', 'id')->all();
        $this->assertNotSame($cloudUuids[$home], $skewed[$home], 'the defect: the SAME address holds a different identity on each side');
        $this->assertNotSame($cloudUuids[$office], $skewed[$office]);
        config(['app.role' => 'branch_server']);

        // one offline sale before the refresh binds Ahmed by id (never by address); a till-born address of Zara keeps its own uuid
        $sale = (int) DB::connection('tenant')->table('sales_orders')->insertGetId(['sale_uuid' => (string) Str::ulid(), 'sale_no' => 'SO-1', 'branch_id' => $this->branchId, 'customer_id' => $ahmed, 'order_source' => 'pos', 'order_type' => 'takeaway', 'sale_date' => now(), 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
        [$tillAddress, $tillUuid] = $this->localAddress($zara, 'Flat 4');

        $result = $this->apply($package);   // was: REFRESH_CONFLICT "address id 1 is held locally by a different address identity"
        $this->assertSame(EdgeLocalConfigRefreshApplier::OUTCOME_APPLIED, $result['outcome']);
        $this->assertSame(18, (int) EdgeLocalMeta::current()->last_applied_config_revision, 'the appliance becomes fresh');
        $book = DB::connection('tenant')->table('customer_addresses')->orderBy('id')->get();
        $this->assertSame([$home, $office, $tillAddress], $book->pluck('id')->map(fn ($v) => (int) $v)->all(), 'no duplicate, no deletion, ids kept');
        $this->assertSame([$cloudUuids[$home], $cloudUuids[$office], $tillUuid], $book->pluck('address_uuid')->all(), 'Cloud-owned rows took the Cloud identity; the till-born one kept its own');
        $this->assertSame(['cloud', 'cloud', 'local'], $book->pluck('edge_origin')->all());
        $this->assertSame(['active', 'active'], DB::connection('tenant')->table('customers')->orderBy('id')->pluck('status')->all(), 'nothing deactivated');
        $this->assertSame([$ahmedUuid, $zaraUuid], DB::connection('tenant')->table('customers')->orderBy('id')->pluck('customer_uuid')->all(), 'customers never carried a skew: bootstrap/refresh always wrote the Cloud uuid');
        $this->assertSame($ahmed, (int) DB::connection('tenant')->table('sales_orders')->where('id', $sale)->value('customer_id'));
        $this->assertSame(0, DB::connection('tenant')->table('customer_addresses')->whereNull('address_uuid')->count());

        // a replay is a no-op and the next revision no longer finds anything to adopt
        $this->assertSame(EdgeLocalConfigRefreshApplier::OUTCOME_ALREADY_APPLIED, $this->apply($package)['outcome']);
        $this->assertSame(EdgeLocalConfigRefreshApplier::OUTCOME_APPLIED, $this->apply($this->package($package['sections'], 19))['outcome']);
        $this->assertSame([$cloudUuids[$home], $cloudUuids[$office], $tillUuid], DB::connection('tenant')->table('customer_addresses')->orderBy('id')->pluck('address_uuid')->all());
    }

    public function test_a_local_origin_address_holding_a_different_uuid_under_the_cloud_id_still_refuses_the_whole_refresh(): void
    {
        // (c) a REAL identity clash (impossible with the reserved local id range — modelled deliberately) rolls everything back
        [$ahmed, $ahmedUuid] = $this->cloudCustomer('Ahmed Raza', '03001234567');
        [$home] = $this->address($ahmed, 'House 1, Street 2', true);
        [$bilal, $bilalUuid] = $this->cloudCustomer('Bilal', '03021111111');
        $package = $this->package($this->sections(), 2);

        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 1);
        $this->stageApplianceCustomers([[$ahmed, $ahmedUuid, 'Ahmed Raza', '03001234567']]);
        $clash = (string) Str::ulid();
        DB::connection('tenant')->table('customer_addresses')->insert(['id' => $home, 'address_uuid' => $clash, 'customer_id' => $ahmed, 'address' => 'Somewhere Else', 'is_default' => 1, 'edge_origin' => 'local', 'created_at' => now(), 'updated_at' => now()]);

        try {
            $this->apply($package);
            $this->fail('a local-origin address must never be overwritten by another identity');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('REFRESH_CONFLICT', $e->getMessage());
            $this->assertStringContainsString("address id {$home}", $e->getMessage());
        }
        $row = DB::connection('tenant')->table('customer_addresses')->find($home);
        $this->assertSame($clash, $row->address_uuid, 'nothing changed');
        $this->assertSame('Somewhere Else', $row->address);
        $this->assertNull($this->customer($bilal), 'the WHOLE refresh rolled back: the new Cloud customer of the same revision is not there');
        $this->assertSame(1, (int) EdgeLocalMeta::current()->last_applied_config_revision, 'the revision did not advance');
    }

    public function test_a_cloud_origin_customer_with_a_stale_uuid_adopts_the_cloud_identity_and_its_reservation_follows(): void
    {
        // (e) customers have no real exposure (bootstrap v7/v8 and every refresh write the Cloud uuid) — the same ownership rule
        // is still proven for them, including the one soft uuid reference the appliance holds (a table reservation).
        [$ahmed, $ahmedUuid] = $this->cloudCustomer('Ahmed Raza', '03001234567');
        [$home, $homeUuid] = $this->address($ahmed, 'House 1, Street 2', true);
        $package = $this->package($this->sections(), 2);

        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 1);
        $stale = (string) Str::ulid();
        $this->stageApplianceCustomers([[$ahmed, $stale, 'Ahmed Raza', '03001234567']]);
        DB::connection('tenant')->table('customer_addresses')->insert(['id' => $home, 'address_uuid' => $homeUuid, 'customer_id' => $ahmed, 'address' => 'House 1, Street 2', 'is_default' => 1, 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        $floor = (int) DB::connection('tenant')->table('restaurant_floors')->insertGetId(['branch_id' => $this->branchId, 'name' => 'G', 'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $table = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'reserved', 'restaurant_floor_id' => $floor]);
        $reservation = (int) DB::connection('tenant')->table('edge_local_table_reservations')->insertGetId(['reservation_uuid' => (string) Str::ulid(), 'branch_id' => $this->branchId, 'restaurant_table_id' => $table, 'customer_id' => $ahmed, 'customer_uuid' => $stale, 'customer_name' => 'Ahmed Raza', 'reserved_for' => now()->addHour(), 'status' => 'active', 'reserved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(EdgeLocalConfigRefreshApplier::OUTCOME_APPLIED, $this->apply($package)['outcome']);
        $row = $this->customer($ahmed);
        $this->assertSame($ahmedUuid, $row->customer_uuid, "the Cloud's identity adopted in place");
        $this->assertSame('active', $row->status);
        $this->assertSame(1, DB::connection('tenant')->table('customers')->count(), 'no second row');
        $this->assertSame($ahmedUuid, DB::connection('tenant')->table('edge_local_table_reservations')->where('id', $reservation)->value('customer_uuid'), 'the reservation hands back by the Cloud identity');
        $this->assertSame($homeUuid, DB::connection('tenant')->table('customer_addresses')->where('id', $home)->value('address_uuid'));
    }

    public function test_an_alias_whose_canonical_customer_is_not_on_this_appliance_leaves_the_local_row_untouched(): void
    {
        $w = (string) Str::ulid();
        [$cloudId] = $this->cloudCustomer('Zara Ahmed', '03017654321', $w, ['status' => 'inactive']);   // inactive on the Cloud → not exported
        $u = (string) Str::ulid();
        DB::connection('tenant')->table('customer_uuid_aliases')->insert(['alias_uuid' => $u, 'customer_id' => $cloudId, 'customer_uuid' => $w, 'source' => 'phone_match', 'created_at' => now(), 'updated_at' => now()]);
        $sections = $this->sections();
        $this->assertSame([], $sections['customer_uuid_aliases'], 'an alias of an inactive customer does not ship');
        // model a Cloud that still shipped it (an older export): the applier must cope
        $sections['customer_uuid_aliases'] = [['alias_uuid' => $u, 'customer_id' => $cloudId, 'customer_uuid' => $w, 'source_device_uuid' => null, 'source' => 'phone_match']];
        $package = $this->package($sections, 2);

        $this->cleanTenant(['customer_uuid_aliases', 'customer_addresses', 'customers']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 1);
        [$local] = $this->localCustomer('Zara Khan', '03017654321', $u);

        $this->apply($package);
        $row = $this->customer($local);
        $this->assertNotNull($row);
        $this->assertSame('active', $row->status);
        $this->assertSame('local', $row->edge_origin);
        $this->assertSame(1, DB::connection('tenant')->table('customer_uuid_aliases')->count());
    }
}
