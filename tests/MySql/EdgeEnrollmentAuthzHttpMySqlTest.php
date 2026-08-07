<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

/**
 * EDGE-LOCAL-AUTH-FINAL-PROOF-1 (Section 4/2) — the Cloud enrollment-issue authorization proven through
 * the REAL HTTP/middleware stack (IdentifyTenant -> auth:tenant -> route.permission), not a synthetic
 * $user->can() call. A live tenant + active domain are seeded in the master DB so IdentifyTenant resolves
 * and binds the tenant connection exactly as production does; the request then hits the actual route on
 * the tenant host (`{sub}.{tenant_base_domain}` — derived from config, never hard-coded).
 *
 * Only CSRF is bypassed (orthogonal to authorization); every auth/tenant/permission middleware stays live,
 * so the test FAILS if `route.permission` is removed, the route disappears, or the guard wiring breaks:
 *   - route absence           -> test_the_enrollment_route_exists fails
 *   - route.permission removed -> the cashier reaches the controller (422) instead of 403 -> fails
 *   - tenant guard broken      -> the Owner no longer authenticates -> not 422 -> fails
 *
 * No signing key is inserted: the authorized Owner PASSES authorization and the issuer then fails CLOSED
 * with the controlled business response (422 — no active Branch Server device). A bare 404 is explicitly
 * NOT accepted as an Owner "pass" (that would hide a routing/tenant-resolution regression).
 */
class EdgeEnrollmentAuthzHttpMySqlTest extends MySqlTenantTestCase
{
    private const PERM = 'tenant.offline-edge.enrollment.issue';

    private string $host;
    private string $uri;
    private int $ownerId;
    private int $cashierId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        // CSRF is orthogonal to authorization — disable ONLY it; IdentifyTenant/auth:tenant/route.permission stay real.
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        // Tenant host derived from the SAME config the route group uses — never a hard-coded suffix.
        $this->host = 'authproofedge.' . config('tenancy.tenant_base_domain');
        $this->uri = 'http://' . $this->host . '/settings/offline-edge/enrollment-assertions';

        $this->seedTenantAndUsers();
    }

    protected function tearDown(): void
    {
        // Deterministic master cleanup so the shared MySQL master DB is not contaminated for later tests.
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
            $m->table('tenants')->where('tenant_code', 'authproofedge')->delete();
        } catch (\Throwable $e) {
            // best-effort; never mask the real test outcome
        }
        parent::tearDown();
    }

    public function test_the_enrollment_route_exists(): void
    {
        // Route absence must fail the suite rather than masquerade as an Owner "404 pass".
        $this->assertTrue(Route::has(self::PERM), 'the Cloud enrollment-issue route must be registered');
        $route = Route::getRoutes()->getByName(self::PERM);
        $this->assertContains('route.permission', $route->gatherMiddleware(), 'route must be permission-gated');
    }

    public function test_unauthenticated_request_is_refused_by_auth(): void
    {
        $res = $this->postJson($this->uri, ['user_id' => $this->cashierId, 'branch_id' => $this->branchId]);
        // auth:tenant refuses (401 for a JSON request) before any authorization/business logic runs.
        $res->assertStatus(401);
    }

    public function test_authenticated_cashier_without_permission_gets_403(): void
    {
        $cashier = User::on('tenant')->find($this->cashierId);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $res = $this->actingAs($cashier, 'tenant')
            ->postJson($this->uri, ['user_id' => $this->cashierId, 'branch_id' => $this->branchId]);
        $res->assertStatus(403); // route.permission denies — the real middleware, not a synthetic can()
    }

    public function test_authenticated_owner_passes_authorization_then_issuer_fails_closed(): void
    {
        $owner = User::on('tenant')->find($this->ownerId);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $res = $this->actingAs($owner, 'tenant')
            ->postJson($this->uri, ['user_id' => $this->cashierId, 'branch_id' => $this->branchId]);
        $code = $res->getStatusCode();
        // Owner clears AUTHORIZATION (never 401/403) AND actually reaches the controller — which, with no
        // active device / no signing key, fails CLOSED with a controlled 422. A bare 404 is NOT accepted.
        $this->assertNotContains($code, [401, 403, 404], "Owner must pass authorization and reach the controller; got {$code}: " . $res->getContent());
        $res->assertStatus(422);
    }

    private function seedTenantAndUsers(): void
    {
        // ── master: a live tenant + active domain so IdentifyTenant resolves to THIS test's tenant DB ──
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');
        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenants')->where('tenant_code', 'authproofedge')->delete();

        $tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'authproofedge', 'business_name' => 'Auth Proof Edge', 'owner_name' => 'Owner',
            'owner_email' => 'owner@authproofedge.test', 'currency_code' => 'PKR', 'status' => 'active',
            'is_demo' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // db_password stays NULL: the `encrypted` cast rejects a raw value, and TenancyManager falls back
        // to the boot-time template password (matches the root/no-password test server).
        $master->table('tenant_databases')->insert([
            'tenant_id' => $tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $master->table('tenant_domains')->insert([
            'tenant_id' => $tenantId, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── tenant DB: Owner (granted the permission) + cashier (not granted) + a branch ──
        $this->cleanTenant(['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users', 'branches']);
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $c = DB::connection('tenant');
        $this->branchId = $c->table('branches')->insertGetId(['name' => 'Main', 'code' => 'MN', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => now(), 'updated_at' => now()]);
        $permId = $c->table('permissions')->insertGetId(['name' => self::PERM, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $ownerRole = $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $cashierRole = $c->table('roles')->insertGetId(['name' => 'Cashier', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $c->table('role_has_permissions')->insert(['permission_id' => $permId, 'role_id' => $ownerRole]);

        $this->ownerId = $this->makeUser($c, 'OWN1', $ownerRole);
        $this->cashierId = $this->makeUser($c, 'CASH1', $cashierRole);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeUser($c, string $code, int $roleId): int
    {
        $uid = $c->table('users')->insertGetId([
            'name' => $code, 'email' => strtolower($code) . '@authproofedge.test', 'password' => bcrypt('x'),
            'employee_code' => $code, 'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $uid]);

        return $uid;
    }
}
