<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

/**
 * EDGE-LOCAL-AUTH-1 (Section 9) — the Cloud enrollment-issue route is permission-gated. EnsureRoute
 * Permission enforces `$user->can($routeName)` (permission == route name), so an authorized actor
 * (Owner, granted the permission — the deploy grants Owner ALL tenant permissions) passes and an
 * ordinary cashier without it is denied. Unauthenticated is redirected by the same middleware.
 */
class EdgeEnrollmentAuthzMySqlTest extends MySqlTenantTestCase
{
    private const PERM = 'tenant.offline-edge.enrollment.issue';

    public function test_route_is_permission_gated_and_only_granted_role_passes(): void
    {
        // The route exists and carries the route.permission gate (structural default-deny).
        $route = Route::getRoutes()->getByName(self::PERM);
        $this->assertNotNull($route, 'the enrollment-issue route must be registered on Cloud');
        $this->assertContains('route.permission', $route->gatherMiddleware(), 'route must be permission-gated');

        $this->cleanTenant(['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users']);
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $c = DB::connection('tenant');
        $permId = $c->table('permissions')->insertGetId(['name' => self::PERM, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $ownerRole = $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $cashierRole = $c->table('roles')->insertGetId(['name' => 'Cashier', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $c->table('role_has_permissions')->insert(['permission_id' => $permId, 'role_id' => $ownerRole]); // Owner only

        $owner = $this->makeUserWithRole('OWN1', $ownerRole);
        $cashier = $this->makeUserWithRole('CASH1', $cashierRole);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Owner (granted) passes the gate; cashier (not granted) is denied — exactly what EnsureRoute
        // Permission enforces (403 vs pass).
        $this->assertTrue($owner->fresh()->can(self::PERM), 'Owner with the permission must pass the enrollment gate');
        $this->assertFalse($cashier->fresh()->can(self::PERM), 'a cashier without the permission must be denied (403)');

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
    }

    private function makeUserWithRole(string $code, int $roleId): User
    {
        $c = DB::connection('tenant');
        $uid = $c->table('users')->insertGetId([
            'name' => $code, 'email' => strtolower($code) . '@x.test', 'password' => bcrypt('x'),
            'employee_code' => $code, 'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $uid]);

        return User::on('tenant')->find($uid);
    }
}
