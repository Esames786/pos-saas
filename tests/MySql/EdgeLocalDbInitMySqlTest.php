<?php

namespace Tests\MySql;

use App\Support\EdgeConsoleBoundary;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * EDGE-LOCAL-RUNTIME-1 (matrix B + section 12) — edge:local:db-init provisions the Edge-local schema
 * on a Branch Server while raw `migrate` stays CLI-denied (no self-conflict), and the local runtime
 * works with the Cloud master DB deliberately unreachable.
 */
class EdgeLocalDbInitMySqlTest extends MySqlTenantTestCase
{
    private string $edgeDb = 'pos_test_edge_local';

    private function dropEdgeDb(): void
    {
        $c = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `{$this->edgeDb}`");
    }

    public function test_db_init_provisions_edge_schema_while_raw_migrate_stays_denied(): void
    {
        // Raw migrate/reset are denied on a Branch Server...
        config(['app.role' => 'branch_server']);
        $this->assertFalse(EdgeConsoleBoundary::isAllowed('migrate'));
        $this->assertFalse(EdgeConsoleBoundary::isAllowed('migrate:fresh'));
        $this->assertFalse(EdgeConsoleBoundary::isAllowed('db:wipe'));
        $this->assertTrue(EdgeConsoleBoundary::isAllowed('edge:local:db-init'));

        config(['database.connections.edge_local.database' => $this->edgeDb]);
        $this->dropEdgeDb();

        // ...yet the guarded command still builds the schema (it uses the Migrator directly).
        $code = Artisan::call('edge:local:db-init');
        $this->assertSame(0, $code, Artisan::output());

        config(['database.connections.tenant.database' => $this->edgeDb]);
        DB::purge('tenant');
        $this->assertTrue(Schema::connection('tenant')->hasTable('edge_local_meta'), 'edge_local_meta must exist');
        $this->assertTrue(Schema::connection('tenant')->hasTable('branches'), 'real tenant schema must be present');

        // Restore for the next test.
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
    }

    public function test_local_runtime_works_with_master_unreachable(): void
    {
        config(['app.role' => 'branch_server', 'database.connections.edge_local.database' => $this->edgeDb]);
        $this->dropEdgeDb();
        $this->assertSame(0, Artisan::call('edge:local:db-init'), Artisan::output());

        $originalMaster = config('database.connections.master.database');
        try {
            // Point the Cloud master at a database that does not exist.
            config(['database.connections.master.database' => 'nonexistent_master_db_xyz']);
            DB::purge('master');

            // The local status/readiness surface must still work — it never queries master.
            config(['database.connections.tenant.database' => $this->edgeDb]);
            DB::purge('tenant');
            // Exit 0 with the master DB pointed at a nonexistent database proves the local status/
            // readiness surface never touches master.
            $code = Artisan::call('edge:local:status', ['--json' => true]);
            $this->assertSame(0, $code, 'edge:local:status must work with the Cloud master unreachable');
        } finally {
            config(['database.connections.master.database' => $originalMaster]);
            DB::purge('master');
            config(['database.connections.tenant.database' => $this->tenantDb]);
            DB::purge('tenant');
        }
    }
}
