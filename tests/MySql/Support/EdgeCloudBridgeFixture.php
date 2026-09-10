<?php

namespace Tests\MySql\Support;

use App\Services\Edge\OfflineEdgeEntitlementService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PDO;

/**
 * Q test fixture — ONE process, TWO roles, TWO databases, REAL code on both ends.
 *
 * The Cloud side is exercised through the real HTTP kernel (device-authenticated routes, tenancy activation, lease
 * table, official stock) on the Cloud tenant database. The appliance side runs on its OWN database (provisioned here,
 * config rows mirrored the way a bootstrap import does) with its REAL HTTP clients (lease heartbeat/handback, baseline
 * issuance, config refresh, sale ingestion, reconciliation), whose outbound requests are bridged straight into that
 * kernel by an Http fake — the appliance code path is production's, only the wire is in-process. Any endpoint can be
 * made to fail ('down' = connection error, 'error' = HTTP 500) to stage a partition or a flaky WAN.
 */
trait EdgeCloudBridgeFixture
{
    protected string $bridgeTenantCode = 'edgeq';
    protected int $cloudTenantId;
    protected string $cloudDeviceUuid;
    protected string $cloudDeviceSecret = 'q-device-secret';
    /** @var array<string,string> path fragment => 'down' | 'error' */
    protected array $bridgeFailures = [];
    protected string $cloudDb;
    protected string $edgeDb;
    private static bool $edgeDbProvisioned = false;

    /** Cloud DB = the tenant test DB; appliance DB = a per-worktree Edge-local DB (resolver), migrated once per process. */
    protected function provisionTwoDatabases(): void
    {
        $this->cloudDb = (string) config('database.connections.tenant.database');
        $this->edgeDb = EdgeTestDatabases::local('q');
        $c = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (! self::$edgeDbProvisioned) {
            $pdo->exec('DROP DATABASE IF EXISTS `' . $this->edgeDb . '`');
            $pdo->exec('CREATE DATABASE `' . $this->edgeDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $this->useDb($this->edgeDb);
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
            self::$edgeDbProvisioned = true;
        }
        $this->useDb($this->cloudDb);
    }

    protected function useDb(string $db): void
    {
        config(['database.connections.tenant.database' => $db]);
        DB::purge('tenant');
        DB::setDefaultConnection('tenant');
    }

    /** Copy the Cloud's config rows into the appliance DB by identity (what the bootstrap import ships). */
    protected function mirrorConfigToAppliance(array $tables): void
    {
        $rows = [];
        $this->useDb($this->cloudDb);
        foreach ($tables as $t) {
            $rows[$t] = array_map(fn ($r) => (array) $r, DB::connection('tenant')->table($t)->get()->all());
        }
        $this->useDb($this->edgeDb);
        DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) {
            DB::connection('tenant')->table($t)->delete();
            foreach (array_chunk($rows[$t], 200) as $chunk) {
                if ($chunk !== []) {
                    DB::connection('tenant')->table($t)->insert($chunk);
                }
            }
        }
        DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=1');
        $this->useDb($this->cloudDb);
    }

    /** Register the tenant + its (Cloud) database + the paired ACTIVE device + its activation epoch on the master DB. */
    protected function registerCloudTenantAndDevice(int $branchId, int $epoch = 1): void
    {
        $this->cloudDeviceUuid = (string) Str::uuid();
        $m = DB::connection('master');
        $m->table('tenant_databases')->where('db_database', $this->cloudDb)->delete();
        $m->table('tenants')->where('tenant_code', $this->bridgeTenantCode)->delete();
        $this->cloudTenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => $this->bridgeTenantCode, 'business_name' => 'Edge Q', 'owner_name' => 'Owner',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c = config('database.connections.tenant');
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->cloudTenantId, 'db_connection' => 'tenant', 'db_host' => $c['host'], 'db_port' => (int) $c['port'],
            'db_database' => $this->cloudDb, 'db_username' => $c['username'], 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('edge_devices')->where('tenant_id', $this->cloudTenantId)->delete();
        $m->table('edge_branch_activations')->where('tenant_id', $this->cloudTenantId)->delete();
        $m->table('edge_branch_config_revisions')->where('tenant_id', $this->cloudTenantId)->delete();
        $m->table('edge_devices')->insert([
            'public_uuid' => $this->cloudDeviceUuid, 'tenant_id' => $this->cloudTenantId, 'branch_id' => $branchId,
            'installation_uuid' => (string) Str::uuid(), 'device_name' => 'q-box', 'device_secret_hash' => hash('sha256', $this->cloudDeviceSecret),
            'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('edge_branch_activations')->insert([
            'tenant_id' => $this->cloudTenantId, 'branch_id' => $branchId, 'generation' => $epoch,
            'device_public_uuid' => $this->cloudDeviceUuid, 'reason' => 'initial', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function cleanupCloudRegistration(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('edge_devices')->where('tenant_id', $this->cloudTenantId)->delete();
            $m->table('edge_branch_activations')->where('tenant_id', $this->cloudTenantId)->delete();
            $m->table('edge_branch_config_revisions')->where('tenant_id', $this->cloudTenantId)->delete();
            $m->table('tenant_databases')->where('db_database', $this->cloudDb)->delete();
            $m->table('tenants')->where('tenant_code', $this->bridgeTenantCode)->delete();
        } catch (\Throwable $e) {
        }
    }

    /** The appliance's Cloud URLs (any host — the bridge matches on path) + device credentials from config (never CLI). */
    protected function configureApplianceCloudUrls(): void
    {
        $base = 'https://cloud.bingoo.test/api/edge';
        config([
            'edge.authority.heartbeat_url' => $base . '/authority/heartbeat',
            'edge.authority.handback_url' => $base . '/authority/handback',
            'edge.sync.url' => $base . '/sync/sales',
            'edge.sync.reconcile_url' => $base . '/sync/reconcile',
            'edge.sync.baseline_url' => $base . '/sync/baseline',
            'edge.standby.config_refresh_url' => $base . '/config/refresh',
            'edge.sync.device_id' => $this->cloudDeviceUuid,
            'edge.sync.device_secret' => $this->cloudDeviceSecret,
            'edge.sync.connect_timeout' => 2, 'edge.sync.timeout' => 5,
            'app.edge_feature_enabled' => true,
        ]);
        // The commercial entitlement gate is a subscription concern proven elsewhere; here the tenant IS entitled.
        $this->app->instance(OfflineEdgeEntitlementService::class, new class extends OfflineEdgeEntitlementService {
            public function __construct()
            {
            }

            public function featureIsEnabled(): bool
            {
                return true;
            }

            public function tenantHasOfflineEdgeAccess(): bool
            {
                return true;
            }
        });
    }

    /** Bridge every outbound appliance HTTP call into the in-process Cloud kernel (as the Cloud, on the Cloud DB). */
    protected function bridgeCloud(): void
    {
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            foreach ($this->bridgeFailures as $fragment => $mode) {
                if (str_contains($path, $fragment)) {
                    if ($mode === 'down') {
                        throw new ConnectionException('simulated WAN failure to ' . $fragment);
                    }

                    return Http::response(['status' => 'error', 'message' => 'simulated Cloud failure'], 500);
                }
            }
            $previousRole = config('app.role');
            $previousDb = (string) config('database.connections.tenant.database');
            config(['app.role' => null]); // the Cloud answers as the Cloud …
            $this->useDb($this->cloudDb);  // … on the Cloud database
            try {
                $headers = [];
                foreach ($request->headers() as $name => $values) {
                    $headers[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
                }
                $headers['Accept'] = 'application/json';
                $headers['Content-Type'] = 'application/json';
                $response = $this->call('POST', 'http://' . config('tenancy.central_domain') . $path, [], [], [], $this->transformHeadersToServerVars($headers), $request->body());
            } finally {
                config(['app.role' => $previousRole]);
                $this->useDb($previousDb);
            }

            return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
        });
    }

    /** Run $fn as the appliance (Branch Server role, appliance DB). */
    protected function asEdge(callable $fn): mixed
    {
        $prevRole = config('app.role');
        $prevDb = (string) config('database.connections.tenant.database');
        config(['app.role' => 'branch_server']);
        $this->useDb($this->edgeDb);
        try {
            return $fn();
        } finally {
            config(['app.role' => $prevRole]);
            $this->useDb($prevDb);
        }
    }

    /** Run $fn as the Cloud (Cloud DB). */
    protected function asCloud(callable $fn): mixed
    {
        $prevRole = config('app.role');
        $prevDb = (string) config('database.connections.tenant.database');
        config(['app.role' => null]);
        $this->useDb($this->cloudDb);
        try {
            return $fn();
        } finally {
            config(['app.role' => $prevRole]);
            $this->useDb($prevDb);
        }
    }
}
