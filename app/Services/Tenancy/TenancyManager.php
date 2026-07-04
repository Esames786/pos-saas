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
        // PROD-FIX: fall back to the boot-time config template password, NOT
        // env() — runtime env() is null under `config:cache`. Captured once
        // (static) because this method overwrites the tenant config entry.
        static $templatePassword = null;
        $templatePassword ??= config('database.connections.tenant.password') ?? '';

        config(['database.connections.tenant' => array_merge(
            config('database.connections.tenant', []),
            [
                'host'     => $database->db_host,
                'port'     => (int) $database->db_port,
                'database' => $database->db_database,
                'username' => $database->db_username,
                'password' => $database->db_password ?? $templatePassword,
            ]
        )]);

        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    public function deactivate(): void
    {
        app()->forgetInstance('tenant');
        app()->forgetInstance('tenantId');

        DB::disconnect('tenant');
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
    }
}
