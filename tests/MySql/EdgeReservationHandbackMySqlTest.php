<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeTableReservation;
use App\Services\Edge\EdgeReservationHandbackService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — ONLINE POS PARITY: reservation Local-Mode -> Cloud handback.
 *
 * Proves the controlled projection of active Edge reservations into canonical restaurant_tables.reserved_*:
 * an attached-customer reservation resolves by customer_uuid; a walk-in keeps name/phone with a null id;
 * cancelled/seated are not projected; an occupied table and a Cloud-conflicting table FAIL CLOSED (Edge keeps
 * authority, nothing half-projected); an unknown customer_uuid fails closed; and a completed handback is
 * idempotent on replay.
 */
class EdgeReservationHandbackMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $floorId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_table_reservations', 'edge_local_meta', 'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'customers', 'branches', 'users']);
        config(['app.role' => 'branch_server']);
        $this->branchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active']);
        $this->floorId = DB::table('restaurant_floors')->insertGetId(['branch_id' => $this->branchId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function svc(): EdgeReservationHandbackService
    {
        return app(EdgeReservationHandbackService::class);
    }

    private function makeTableRow(string $status = 'available'): int
    {
        return DB::table('restaurant_tables')->insertGetId(['branch_id' => $this->branchId, 'restaurant_floor_id' => $this->floorId, 'table_no' => 'T' . Str::random(3), 'name' => 'T', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeReservation(int $tableId, array $o = []): int
    {
        return DB::table('edge_local_table_reservations')->insertGetId(array_merge([
            'reservation_uuid' => (string) Str::ulid(), 'branch_id' => $this->branchId, 'restaurant_table_id' => $tableId,
            'status' => 'active', 'reserved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    public function test_attached_customer_reservation_projects_and_resolves_by_uuid(): void
    {
        $uuid = (string) Str::ulid();
        $custId = DB::table('customers')->insertGetId(['name' => 'Alice', 'phone' => '0300', 'customer_uuid' => $uuid, 'created_at' => now(), 'updated_at' => now()]);
        $table = $this->makeTableRow();
        $this->makeReservation($table, ['customer_uuid' => $uuid, 'customer_name' => 'Alice', 'customer_phone' => '0300', 'note' => 'window']);

        $result = $this->svc()->handback();
        $this->assertSame(1, $result['projected']);

        $t = DB::table('restaurant_tables')->find($table);
        $this->assertSame('reserved', $t->status);
        $this->assertSame($custId, (int) $t->reserved_customer_id, 'customer resolved by uuid -> Cloud id');
        $this->assertSame('Alice', $t->reserved_name);
        $this->assertSame('window', $t->reservation_note);
        $this->assertSame('handed_back', EdgeTableReservation::where('restaurant_table_id', $table)->value('status'));
    }

    public function test_walk_in_projects_name_phone_with_null_customer_id(): void
    {
        $table = $this->makeTableRow();
        $this->makeReservation($table, ['customer_name' => 'Walk In', 'customer_phone' => '0311']);

        $this->svc()->handback();
        $t = DB::table('restaurant_tables')->find($table);
        $this->assertSame('reserved', $t->status);
        $this->assertNull($t->reserved_customer_id);
        $this->assertSame('Walk In', $t->reserved_name);
    }

    public function test_cancelled_and_seated_reservations_are_not_projected(): void
    {
        $t1 = $this->makeTableRow();
        $t2 = $this->makeTableRow();
        $this->makeReservation($t1, ['status' => 'cancelled', 'customer_name' => 'C']);
        $this->makeReservation($t2, ['status' => 'seated', 'customer_name' => 'S']);

        $this->assertSame(0, $this->svc()->handback()['projected']);
        $this->assertSame('available', DB::table('restaurant_tables')->find($t1)->status);
        $this->assertSame('available', DB::table('restaurant_tables')->find($t2)->status);
    }

    public function test_occupied_table_fails_closed(): void
    {
        $table = $this->makeTableRow('occupied');
        $this->makeReservation($table, ['customer_name' => 'X']);
        $this->expectExceptionMessage('HANDBACK_TABLE_OCCUPIED');
        $this->svc()->handback();
    }

    public function test_cloud_conflict_fails_closed_and_keeps_edge_authority(): void
    {
        $table = $this->makeTableRow('reserved');
        DB::table('restaurant_tables')->where('id', $table)->update(['reserved_at' => now(), 'reserved_name' => 'CloudGuest']);
        $this->makeReservation($table, ['customer_name' => 'EdgeGuest']);

        try {
            $this->svc()->handback();
            $this->fail('a Cloud conflict must fail closed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HANDBACK_CONFLICT', $e->getMessage());
        }
        // Rolled back: the Cloud reservation is untouched, and the Edge reservation is STILL active.
        $this->assertSame('CloudGuest', DB::table('restaurant_tables')->find($table)->reserved_name);
        $this->assertSame('active', EdgeTableReservation::where('restaurant_table_id', $table)->value('status'));
    }

    public function test_unknown_customer_uuid_fails_closed(): void
    {
        $table = $this->makeTableRow();
        $this->makeReservation($table, ['customer_uuid' => (string) Str::ulid(), 'customer_name' => 'Ghost']);
        $this->expectExceptionMessage('HANDBACK_UNKNOWN_CUSTOMER');
        $this->svc()->handback();
    }

    public function test_handback_is_idempotent_on_replay(): void
    {
        $table = $this->makeTableRow();
        $this->makeReservation($table, ['customer_name' => 'Once']);

        $this->assertSame(1, $this->svc()->handback()['projected']);
        $this->assertSame(0, $this->svc()->handback()['projected'], 'a completed handback replays to a no-op');
    }
}
