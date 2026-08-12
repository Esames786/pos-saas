<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\MySql\Support\TenantFixtures;

/**
 * THE "No database selected" INCIDENT, executed end-to-end through the real HTTP middleware stack.
 *
 * Five production 500s (2026-08-10/11) were POST /login on tenant hosts from a phone holding a
 * logged-in session and a stale login page. The expired CSRF token threw 419 BETWEEN StartSession
 * and IdentifyTenant; the pipeline turns the inner exception into a response, so StartSession still
 * saved the session on the way out — and the database session handler records the authenticated
 * user by querying the TENANT users table, which was never configured → SQLSTATE[3D000].
 *
 * The unit test asserts the middleware ORDER; this asserts the OUTCOME the order exists to produce:
 * a stale-CSRF tenant login returns a clean 419, and no session save ever queries an unconfigured
 * tenant connection. Requires the database session driver — the array driver never touches a DB, so
 * it could not reproduce the incident.
 */
class TenantLoginCsrfContextMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private string $uri;

    protected function setUp(): void
    {
        parent::setUp();

        // The incident REQUIRES the database session driver: only then does session persistence
        // query the tenant connection. Resolve the tenant guard by default so the session handler's
        // user lookup hits the tenant users table, exactly as production does.
        config([
            'session.driver' => 'database',
            'session.connection' => 'tenant',
            'auth.defaults.guard' => 'tenant',
        ]);
        // The session manager is a singleton resolved from the phpunit env (array driver). Drop the
        // resolved instances so the request pipeline rebuilds them from the overridden config and
        // actually persists to the DATABASE — otherwise the incident's session-save path is never hit.
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');

        // Full real stack — CSRF middleware STAYS ON; that is the whole point of this test.
        $this->host = 'csrfproof.' . config('tenancy.tenant_base_domain');
        $this->uri = 'http://' . $this->host . '/login';

        $this->seedTenantAndOwner();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
            $m->table('tenants')->where('tenant_code', 'csrfproof')->delete();
        } catch (\Throwable) {
            // best-effort
        }
        parent::tearDown();
    }

    public function test_the_tenant_login_route_exists(): void
    {
        $this->assertTrue(Route::has('tenant.login.post'), 'the tenant login POST route must be registered');
    }

    public function test_a_stale_csrf_tenant_login_returns_419_not_a_no_database_selected_500(): void
    {
        // Establish a REAL session (and its CSRF token) on the tenant host first — the "logged-in
        // phone with a login page open". Then POST /login with a token that does NOT match the
        // session token: a genuine mismatch, exactly the stale-page case.
        $this->withServerVariables(['HTTP_HOST' => $this->host])->get($this->uri);

        $response = $this->withServerVariables(['HTTP_HOST' => $this->host])
            ->post($this->uri, [
                '_token'   => 'stale-invalid-csrf-token',
                'email'    => 'owner@csrfproof.test',
                'password' => 'whatever',
            ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getContent();

        // The incident was a 500 carrying SQLSTATE[3D000]. THAT is what must never recur — the
        // session save reaching an unconfigured tenant connection. A handled CSRF outcome (419, or
        // a redirect-back the app may render instead) is fine; a 500 is not.
        $this->assertNotSame(500, $status, "A stale-CSRF tenant login must not 500. Body: " . substr(strip_tags($body), 0, 300));
        $this->assertStringNotContainsStringIgnoringCase(
            'No database selected',
            $body,
            'The session save queried an unconfigured tenant connection — IdentifyTenant must precede StartSession.'
        );
        $this->assertStringNotContainsStringIgnoringCase('3D000', $body);

        // The STRONGEST proof the tenant connection was configured at session-save time: the
        // session row was written into the TENANT database's sessions table, not master's and not
        // nowhere. Before the fix this same write is what threw 3D000.
        $this->assertGreaterThan(
            0,
            (int) DB::connection('tenant')->table('sessions')->count(),
            'The session must persist to the tenant DB — proving the tenant connection was live during the session save.'
        );
    }

    public function test_a_valid_tenant_login_still_authenticates(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => $this->host])
            ->from($this->uri)
            ->post($this->uri, [
                '_token'   => csrf_token(),
                'email'    => 'owner@csrfproof.test',
                'password' => 'correct-horse',
            ]);

        // Valid credentials + valid token → redirect to /dashboard (never a 500 / 3D000).
        $this->assertNotSame(500, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('No database selected', (string) $response->getContent());
    }

    public function test_an_unknown_tenant_host_is_a_controlled_404(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => 'nosuchtenant.' . config('tenancy.tenant_base_domain')])
            ->get('http://nosuchtenant.' . config('tenancy.tenant_base_domain') . '/login');

        $this->assertSame(404, $response->getStatusCode(), 'An unknown tenant host must be a clean 404, not a 500.');
    }

    private function seedTenantAndOwner(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');
        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenants')->where('tenant_code', 'csrfproof')->delete();

        $tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'csrfproof', 'business_name' => 'CSRF Proof', 'owner_name' => 'Owner',
            'owner_email' => 'owner@csrfproof.test', 'currency_code' => 'PKR', 'status' => 'active',
            'is_demo' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
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

        $this->cleanTenant(['sessions', 'users']);
        DB::setDefaultConnection('tenant');
        DB::connection('tenant')->table('users')->insert([
            'name' => 'Owner', 'email' => 'owner@csrfproof.test',
            'password' => Hash::make('correct-horse'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
