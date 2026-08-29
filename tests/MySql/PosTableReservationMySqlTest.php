<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\RestaurantTableController;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MySql\Support\TenantFixtures;

/**
 * TABLE-RESERVATION-1 — reserve a free dine-in table (attach a customer or type a walk-in) + time +
 * note, view the details, and cancel it. Gated on the existing table-open permission (any dine-in
 * operator). A table with an open session cannot be reserved.
 */
class PosTableReservationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const PERM = 'tenant.restaurant.table-sessions.open';

    private int $branchId;
    private int $tableId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'customers',
            'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'users', 'branches',
        ]);
        $this->branchId = $this->makeBranch();
        $this->tableId  = $this->makeTable($this->branchId, ['table_no' => 'T1']);
    }

    private function permittedUser(): User
    {
        $uid = $this->makeUser();
        Permission::findOrCreate(self::PERM, 'tenant');
        $u = User::on('tenant')->find($uid);
        $u->givePermissionTo(self::PERM);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::on('tenant')->find($uid);
    }

    private function ctl(): RestaurantTableController
    {
        return app(RestaurantTableController::class);
    }

    private function table(): RestaurantTable
    {
        return RestaurantTable::on('tenant')->findOrFail($this->tableId);
    }

    public function test_reserve_with_customer_then_details_then_unreserve(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $custId = DB::connection('tenant')->table('customers')->insertGetId([
            'name' => 'Ali', 'phone' => '0300', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $req = Request::create('/x', 'POST', ['reserved_customer_id' => $custId, 'reserved_for' => '2026-08-29 20:00', 'reservation_note' => '6 guests, window']);
        $this->assertTrue($this->ctl()->reserve($req, $this->table())->getData(true)['ok']);

        $t = $this->table();
        $this->assertSame('reserved', $t->status);
        $this->assertSame($custId, (int) $t->reserved_customer_id);
        $this->assertSame('Ali', $t->reserved_name, 'the attached customer name is snapshotted');
        $this->assertSame('0300', $t->reserved_phone);
        $this->assertSame('6 guests, window', $t->reservation_note);
        $this->assertNotNull($t->reserved_at);

        $d = $this->ctl()->reservation($this->table())->getData(true)['reservation'];
        $this->assertSame('Ali', $d['name']);
        $this->assertSame('6 guests, window', $d['note']);

        $this->assertTrue($this->ctl()->unreserve($this->table())->getData(true)['ok']);
        $t = $this->table();
        $this->assertSame('available', $t->status);
        $this->assertNull($t->reserved_customer_id);
        $this->assertNull($t->reservation_note);
    }

    public function test_walk_in_reserve_without_a_customer_record(): void
    {
        Auth::guard('tenant')->login($this->permittedUser());
        $this->ctl()->reserve(Request::create('/x', 'POST', ['reserved_name' => 'Walk In Sara', 'reserved_phone' => '0311']), $this->table());

        $t = $this->table();
        $this->assertSame('reserved', $t->status);
        $this->assertNull($t->reserved_customer_id);
        $this->assertSame('Walk In Sara', $t->reserved_name);
    }

    public function test_reserve_is_blocked_when_the_table_has_an_open_session(): void
    {
        $user = $this->permittedUser();
        Auth::guard('tenant')->login($user);
        DB::connection('tenant')->table('restaurant_table_sessions')->insert([
            'session_no' => 'TS-' . uniqid(), 'branch_id' => $this->branchId, 'restaurant_table_id' => $this->tableId,
            'opened_by_user_id' => $user->id, 'guest_count' => 2, 'status' => 'open', 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->ctl()->reserve(Request::create('/x', 'POST', ['reserved_name' => 'X']), $this->table());
        $this->assertFalse($resp->getData(true)['ok']);
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        Auth::guard('tenant')->login(User::on('tenant')->find($this->makeUser())); // no permission granted
        try {
            $this->ctl()->unreserve($this->table());
            $this->fail('a user without the table-open permission must be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
