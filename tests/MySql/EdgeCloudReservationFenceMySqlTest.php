<?php

namespace Tests\MySql;

use App\Exceptions\BranchLocalEdgeException;
use App\Http\Controllers\Tenant\RestaurantTableController;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — ONLINE POS PARITY: the Cloud-side reservation fence. While a branch is handed to its Branch
 * Server (Local Mode active), the CLOUD must not mutate that branch's reservations — the same split-brain
 * fence as sales (BranchOperatingModeService::assertSaleMutationAllowed). Proven server-side, not by hiding a
 * button: Cloud reserve/unreserve are REFUSED on a Local-Mode branch and ALLOWED on a normal Cloud branch.
 */
class EdgeCloudReservationFenceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const PERM = 'tenant.restaurant.table-sessions.open';

    private int $branchId;
    private int $tableId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        // A CLOUD instance (NOT a branch server).
        config(['app.role' => null]);
        $this->cleanTenant(['restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'branches', 'users']);

        $this->branchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active']);
        $floor = DB::table('restaurant_floors')->insertGetId(['branch_id' => $this->branchId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $this->tableId = DB::table('restaurant_tables')->insertGetId(['branch_id' => $this->branchId, 'restaurant_floor_id' => $floor, 'table_no' => 'T1', 'name' => 'T1', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

        $uid = $this->makeUser(['default_branch_id' => $this->branchId]);
        Permission::findOrCreate(self::PERM, 'tenant');
        User::on('tenant')->find($uid)->givePermissionTo(self::PERM);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($uid), 'tenant');
    }

    private function controller(): RestaurantTableController
    {
        return app(RestaurantTableController::class);
    }

    private function table(): RestaurantTable
    {
        return RestaurantTable::on('tenant')->find($this->tableId);
    }

    public function test_cloud_reserve_is_refused_while_the_branch_is_in_local_mode(): void
    {
        $this->expectException(BranchLocalEdgeException::class);
        $this->controller()->reserve(Request::create('/x', 'POST', ['reserved_name' => 'Guest']), $this->table());
    }

    public function test_cloud_unreserve_is_refused_while_the_branch_is_in_local_mode(): void
    {
        $this->expectException(BranchLocalEdgeException::class);
        $this->controller()->unreserve($this->table());
    }

    public function test_cloud_reserve_is_allowed_when_the_branch_is_not_in_local_mode(): void
    {
        // Return the branch to ordinary Cloud authority.
        DB::table('branches')->where('id', $this->branchId)->update(['sales_operating_mode' => 'online', 'local_edge_status' => 'inactive']);

        $resp = $this->controller()->reserve(Request::create('/x', 'POST', ['reserved_name' => 'Guest']), $this->table());
        $this->assertTrue($resp->getData(true)['ok']);
        $this->assertSame('reserved', $this->table()->status);
    }
}
