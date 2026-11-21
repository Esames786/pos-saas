<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Services\Edge\EdgeLocalSchemaUpgrader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE PHASE 4 — F1 fix (docs/status/edge-phase4-fix-address-identity-skew.md): the tenant migration
 * 2026_10_06_000001_edge_phase4_offline_customer_identity mints `customer_addresses.address_uuid` ONLY on the owner side.
 *
 *  - on a Branch Server the REAL schema-upgrade path (EdgeLocalSchemaUpgrader, what edge:local:update runs on an existing
 *    appliance) applies the migration cleanly and leaves every pre-existing address_uuid NULL (the unique index is there,
 *    nullable); phone_normalized (a derived key, not an identity) is still backfilled; the alias table arrives;
 *  - re-running it on the appliance mints nothing either (idempotent, generate-never for Cloud-owned rows);
 *  - on the Cloud the same migration backfills every NULL row with a ULID once.
 */
class EdgeCustomerIdentityMigrationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const MIGRATION = '2026_10_06_000001_edge_phase4_offline_customer_identity';

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_table_reservations', 'edge_local_meta', 'sale_payments', 'sales_order_lines', 'sales_orders', 'customer_uuid_aliases', 'customer_addresses', 'customers', 'branches']);
        DB::connection('tenant')->statement('ALTER TABLE customers AUTO_INCREMENT = 1');
        DB::connection('tenant')->statement('ALTER TABLE customer_addresses AUTO_INCREMENT = 1');
        // the upgrader's fail-closed target guard reads the edge_local connection: point it at THIS test DB, like a real appliance
        config(['database.connections.edge_local.database' => $this->tenantDb, 'database.connections.edge_local.host' => '127.0.0.1']);
        $this->asBranchServerRuntime();
        $this->branchId = $this->makeBranch();
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 17);
    }

    protected function tearDown(): void
    {
        // safety net for the rest of the process: the schema is left exactly as the real migration set produces it
        config(['app.role' => 'cloud']);
        $this->migration()->up();
        DB::connection('tenant')->table('migrations')->insertOrIgnore(['migration' => self::MIGRATION, 'batch' => 1]);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/tenant/' . self::MIGRATION . '.php');
    }

    /** An appliance bootstrapped BEFORE Phase 4 shipped: the Cloud book is there, the identity columns are not. */
    private function simulatePrePhase4Appliance(): array
    {
        $conn = DB::connection('tenant');
        $this->migration()->down();
        $conn->table('migrations')->where('migration', self::MIGRATION)->delete();
        $this->assertFalse(Schema::connection('tenant')->hasColumn('customer_addresses', 'address_uuid'));
        $this->assertFalse(Schema::connection('tenant')->hasTable('customer_uuid_aliases'));

        $ahmed = (int) $conn->table('customers')->insertGetId(['customer_uuid' => (string) Str::ulid(), 'name' => 'Ahmed Raza', 'phone' => '0300-1234567', 'status' => 'active', 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach (['House 1, Street 2', 'Plaza 9', 'Flat 4'] as $i => $text) {
            $ids[] = (int) $conn->table('customer_addresses')->insertGetId(['customer_id' => $ahmed, 'address' => $text, 'is_default' => $i === 0 ? 1 : 0, 'edge_origin' => 'cloud', 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$ahmed, $ids];
    }

    public function test_the_schema_upgrade_on_a_branch_server_leaves_pre_existing_address_uuid_null_while_the_cloud_backfills_it(): void
    {
        [$ahmed, $ids] = $this->simulatePrePhase4Appliance();
        $upgrader = app(EdgeLocalSchemaUpgrader::class);
        $this->assertSame([self::MIGRATION], $upgrader->pending()['tenant']);

        $result = $upgrader->upgrade();
        $this->assertSame([self::MIGRATION], $result['applied']);
        $this->assertNull(EdgeLocalMeta::current()->last_error);
        $this->assertTrue(Schema::connection('tenant')->hasColumn('customer_addresses', 'address_uuid'));
        $this->assertTrue(Schema::connection('tenant')->hasTable('customer_uuid_aliases'));
        $this->assertContains('customer_addresses_address_uuid_unique', array_map(fn ($r) => strtolower($r->Key_name), DB::connection('tenant')->select('SHOW INDEX FROM `customer_addresses`')), 'the (nullable) unique index is in place');
        $book = DB::connection('tenant')->table('customer_addresses')->orderBy('id')->get();
        $this->assertSame($ids, $book->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([null, null, null], $book->pluck('address_uuid')->all(), 'the appliance minted NOTHING for Cloud-owned rows — the next refresh carries the Cloud uuid');
        $this->assertSame('03001234567', DB::connection('tenant')->table('customers')->where('id', $ahmed)->value('phone_normalized'), 'the derived match key is still backfilled');

        // idempotent on the appliance: a second run (re-execution after an interruption) still mints nothing
        $this->migration()->up();
        $this->assertSame(3, DB::connection('tenant')->table('customer_addresses')->whereNull('address_uuid')->count());
        // a till-born address after the upgrade carries its own identity (minted at creation, never by this migration)
        $local = (string) Str::ulid();
        DB::connection('tenant')->table('customer_addresses')->insert(['address_uuid' => $local, 'customer_id' => $ahmed, 'address' => 'New Street', 'is_default' => 0, 'edge_origin' => 'local', 'created_at' => now(), 'updated_at' => now()]);
        $this->migration()->up();
        $this->assertSame($local, DB::connection('tenant')->table('customer_addresses')->where('address', 'New Street')->value('address_uuid'));

        // the CLOUD side of the same migration: the owner backfills every NULL row exactly once
        config(['app.role' => 'cloud']);
        $this->migration()->up();
        $minted = DB::connection('tenant')->table('customer_addresses')->whereIn('id', $ids)->orderBy('id')->pluck('address_uuid')->all();
        $this->assertCount(3, array_filter($minted, fn ($u) => Str::isUlid((string) $u)));
        $this->assertCount(3, array_unique($minted));
        $this->migration()->up();
        $this->assertSame($minted, DB::connection('tenant')->table('customer_addresses')->whereIn('id', $ids)->orderBy('id')->pluck('address_uuid')->all(), 'generate-once: a re-run never changes a populated identity');
    }
}
