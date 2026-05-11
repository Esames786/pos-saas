<?php

namespace App\Services\Tenancy;

use App\Models\Master\Tenant;
use App\Models\Master\TenantDatabase;
use Illuminate\Support\Facades\DB;

class TenancyManager
{
    public function activate(Tenant $tenant): void
    {
        $tenant->loadMissing('database');

        if (!$tenant->database) {
            abort(500, 'Tenant database configuration missing.');
        }

        $this->configureTenantConnection($tenant->database);

        DB::setDefaultConnection('tenant');

        app()->instance('tenant', $tenant);
        app()->instance('tenantId', $tenant->id);
    }

    public function configureTenantConnection(TenantDatabase $database): void
    {
        config(['database.connections.tenant' => array_merge(
            config('database.connections.tenant', []),
            [
                'host'     => $database->db_host,
                'port'     => (int) $database->db_port,
                'database' => $database->db_database,
                'username' => $database->db_username,
                'password' => $database->db_password ?? env('TENANT_DB_PASSWORD', ''),
            ]
        )]);

        DB::purge('tenant');
    }
}
