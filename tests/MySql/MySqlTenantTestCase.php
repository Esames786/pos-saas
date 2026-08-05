<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;

/**
 * MYSQL-TEST-FOUNDATION-1 — authoritative MySQL/MariaDB integration base case.
 *
 * Provisions a DEDICATED tenant test database using the REAL tenant migrations
 * (not a hand-rolled mini-schema), so Edge correctness claims about transactions,
 * row locks, FK behavior, config cutover, UUID idempotency and operational stock
 * are proven against the same engine and schema production uses.
 *
 * SAFETY (fails closed):
 *   - APP_ENV must be "testing".
 *   - The tenant DB name (EDGE_TEST_TENANT_DB) MUST contain "test" — otherwise the
 *     harness refuses to run any destructive migrate/reset, so it can never touch a
 *     production or demo tenant database.
 *
 * The real tenant migration set is applied ONCE per test process (static guard);
 * individual tests clean only the tables they use.
 */
abstract class MySqlTenantTestCase extends TestCase
{
    protected string $tenantDb;
    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is required for the authoritative Edge MySQL suite.');
        }

        $this->tenantDb = (string) env('EDGE_TEST_TENANT_DB', 'pos_test_tenant');
        $this->assertTestDatabaseGuards();

        // Point the tenant connection at the dedicated test database.
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');

        $this->ensureTenantSchema();
    }

    /** Fail closed: never run against a non-test database. */
    protected function assertTestDatabaseGuards(): void
    {
        if (env('APP_ENV') !== 'testing') {
            throw new RuntimeException('Edge MySQL suite refuses to run outside APP_ENV=testing.');
        }
        if (stripos($this->tenantDb, 'test') === false) {
            throw new RuntimeException(
                "Edge MySQL suite refuses to use tenant DB [{$this->tenantDb}] — the name must contain 'test'."
            );
        }
        $masterDb = (string) config('database.connections.master.database');
        if (stripos($masterDb, 'test') === false) {
            throw new RuntimeException(
                "Edge MySQL suite refuses to use master DB [{$masterDb}] — the name must contain 'test'."
            );
        }
    }

    /** Create both test DBs and run the REAL migrations once per process. */
    protected function ensureTenantSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $this->ensureDatabasesExist();

        // Master (central) schema — dedicated test DB, real migrations.
        DB::purge('master');
        $masterCode = Artisan::call('migrate', ['--database' => 'master', '--force' => true]);
        if ($masterCode !== 0) {
            throw new RuntimeException('Master test migrations failed: ' . Artisan::output());
        }

        // Tenant schema — dedicated test DB, REAL tenant migrations (not a fake mini-schema).
        DB::purge('tenant');
        $code = Artisan::call('migrate:fresh', [
            '--database' => 'tenant',
            '--path'     => 'database/migrations/tenant',
            '--force'    => true,
        ]);
        if ($code !== 0) {
            throw new RuntimeException('Tenant test migrations failed: ' . Artisan::output());
        }

        self::$schemaReady = true;
    }

    /** Create both databases via a server-level PDO (no database selected) so the
     *  bootstrap does not depend on either database already existing. */
    protected function ensureDatabasesExist(): void
    {
        $c = config('database.connections.tenant');
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",
            $c['username'], $c['password'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        foreach ([$this->tenantDb, (string) config('database.connections.master.database')] as $db) {
            if (stripos($db, 'test') === false) {
                throw new RuntimeException("Refusing to create non-test database [{$db}].");
            }
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    /** Truncate the given tenant tables (FK-safe), newest child first is the caller's job. */
    protected function cleanTenant(array $tables): void
    {
        $conn = DB::connection('tenant');
        $conn->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) {
            $conn->table($t)->delete();
        }
        $conn->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** A brand-new, independent tenant PDO connection — for genuine two-connection concurrency. */
    protected function independentTenantPdo(): PDO
    {
        $c = config('database.connections.tenant');
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$this->tenantDb};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['username'], $c['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }
}
