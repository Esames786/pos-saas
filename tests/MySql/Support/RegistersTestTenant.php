<?php

namespace Tests\MySql\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Map a disposable tenant code to THIS test's own tenant database in master, so a command that looks a tenant up by
 * code (tenant:reset-transactions) runs against the test database and nothing else. Master is put back afterwards,
 * including another test's mapping of the same database, so the next test file sees master as it was.
 */
trait RegistersTestTenant
{
    private ?string $registeredTenantCode = null;

    protected function registerTestTenant(string $prefix): string
    {
        if ($this->registeredTenantCode) {
            return $this->registeredTenantCode;
        }
        if (stripos($this->tenantDb, 'test') === false) {
            throw new \RuntimeException('refusing to register a tenant on a database whose name has no "test"');
        }
        $master = DB::connection('master');
        $previous = $master->table('tenant_databases')->where('db_database', $this->tenantDb)->first();
        $code = $prefix . substr(md5($this->tenantDb), 0, 6);
        $master->table('tenants')->updateOrInsert(['tenant_code' => $code], ['business_name' => 'Disposable Test Tenant', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()]);
        $tenantId = $master->table('tenants')->where('tenant_code', $code)->value('id');
        $cfg = config('database.connections.tenant');
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenant_databases')->insert([
            'tenant_id' => $tenantId, 'db_connection' => 'tenant', 'db_host' => $cfg['host'] ?? '127.0.0.1', 'db_port' => $cfg['port'] ?? 3306,
            'db_database' => $this->tenantDb, 'db_username' => $cfg['username'] ?? 'root',
            'db_password' => Crypt::encryptString((string) ($cfg['password'] ?? '')),
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->beforeApplicationDestroyed(function () use ($master, $tenantId, $previous) {
            $master->table('tenant_databases')->where('tenant_id', $tenantId)->delete();
            $master->table('tenants')->where('id', $tenantId)->delete();
            if ($previous && (int) $previous->tenant_id !== (int) $tenantId) {
                $master->table('tenant_databases')->insert((array) $previous);
            }
        });

        return $this->registeredTenantCode = $code;
    }

    /** Every row of each table, in key order, reduced to one fingerprint per table — "unchanged" means byte-identical. */
    protected function tableFingerprints(array $tables, string $connection = 'tenant'): array
    {
        $c = DB::connection($connection);
        $schema = $c->getSchemaBuilder();
        $out = [];
        foreach ($tables as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            $query = $c->table($table);
            foreach ($schema->hasColumn($table, 'id') ? ['id'] : $schema->getColumnListing($table) as $column) {
                $query->orderBy($column);   // a pivot has no id: order by every column so ties cannot reorder
            }
            $rows = $query->get()->map(fn ($r) => (array) $r)->all();
            $out[$table] = count($rows) . ':' . md5(json_encode($rows));
        }

        return $out;
    }
}
