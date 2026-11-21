<?php

namespace Tests\MySql\Support;

use App\Models\Master\EdgeDevice;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeEnrollmentCrypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * EDGE 0.9 stream C — a live tenant on the REAL Cloud HTTP stack for the setup / device / release / live-state tests:
 * master tenant + tenant DB link + active domain + an active subscription (so the subscription gate passes; the Offline
 * Edge entitlement + rollout gates are opened with the APP_ENV=testing seam edge.testing.assume_entitled), a fresh
 * enrollment keypair, two active branches and a small user set. Everything is keyed by the using class's TENANT_CODE and
 * removed again in tearDown.
 */
trait EdgeSetupCloudFixture
{
    protected string $host;
    protected int $tenantId;
    protected int $branchA;
    protected int $branchB;
    protected array $users = [];
    protected array $enrollmentKeys = [];

    protected function tenantCode(): string
    {
        return static::TENANT_CODE;
    }

    protected function central(string $path): string
    {
        return 'http://' . config('tenancy.central_domain') . $path;
    }

    protected function tenantUrl(string $path): string
    {
        return 'http://' . $this->host . $path;
    }

    protected function seedEdgeCloud(array $permissions = []): void
    {
        $this->host = $this->tenantCode() . '.' . config('tenancy.tenant_base_domain');
        config(['edge.testing.assume_entitled' => true]);
        $this->enrollmentKeys = EdgeEnrollmentCrypto::generateKeypair();
        config(['edge.enrollment.signing_key' => $this->enrollmentKeys['secret']]);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $this->cleanupEdgeCloud();
        $m = DB::connection('master');
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => $this->tenantCode(), 'business_name' => 'Edge 0.9 C ' . $this->tenantCode(), 'owner_name' => 'Owner',
            'owner_email' => 'owner@' . $this->tenantCode() . '.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'), 'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb, 'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $planId = $m->table('plans')->insertGetId([
            'code' => 'e09c_' . $this->tenantCode(), 'name' => 'E09C plan', 'price' => 0, 'currency_code' => 'PKR',
            'billing_period' => 'monthly', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The new Offline Edge routes must not be mapped to a module this throwaway plan lacks.
        $m->table('route_catalogs')->where('route_name', 'like', 'tenant.offline-edge.%')->delete();

        $this->cleanTenant(['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles',
            'branch_user', 'users', 'terminals', 'branches']);
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');
        $t = ['created_at' => now(), 'updated_at' => now()];
        $this->branchA = $c->table('branches')->insertGetId(['name' => 'Alpha Branch', 'code' => 'AL', 'status' => 'active', 'timezone' => 'Asia/Karachi'] + $t);
        $this->branchB = $c->table('branches')->insertGetId(['name' => 'Bravo Branch', 'code' => 'BR', 'status' => 'active', 'timezone' => 'Asia/Dubai'] + $t);

        $mk = function (string $key, ?string $code, int $branch, string $status = 'active') use ($c, $t) {
            $this->users[$key] = $c->table('users')->insertGetId([
                'name' => ucfirst($key), 'email' => $key . '@' . $this->tenantCode() . '.test', 'password' => bcrypt('x'),
                'employee_code' => $code, 'status' => $status, 'locale' => 'en', 'default_branch_id' => $branch,
            ] + $t);
        };
        $mk('owner', 'OWN1', $this->branchA);
        $mk('cashier', 'CSH1', $this->branchA);
        $mk('manager', 'MGR1', $this->branchB);          // branch B by default …
        $c->table('branch_user')->insert(['branch_id' => $this->branchA, 'user_id' => $this->users['manager'], 'is_active' => 1] + $t); // … assigned to A
        $mk('inactive', 'OLD1', $this->branchA, 'inactive');
        $mk('nocode', null, $this->branchA);
        $mk('bravo', 'BRV1', $this->branchB);            // not on branch A at all

        foreach ($permissions as $perm) {
            $pid = $c->table('permissions')->insertGetId(['name' => $perm, 'guard_name' => 'tenant'] + $t);
            $c->table('model_has_permissions')->insert(['permission_id' => $pid, 'model_type' => User::class, 'model_id' => $this->users['owner']]);
        }
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function grantTo(string $userKey, array $permissions): void
    {
        $c = DB::connection('tenant');
        foreach ($permissions as $perm) {
            $pid = $c->table('permissions')->where('name', $perm)->value('id')
                ?: $c->table('permissions')->insertGetId(['name' => $perm, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
            $c->table('model_has_permissions')->insert(['permission_id' => $pid, 'model_type' => User::class, 'model_id' => $this->users[$userKey]]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function makeDevice(int $branchId, string $secret, string $status = EdgeDevice::STATUS_READY, ?string $installation = null, bool $activate = true): EdgeDevice
    {
        $device = EdgeDevice::create([
            'public_uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'branch_id' => $branchId,
            'installation_uuid' => $installation ?? (string) Str::uuid(), 'device_name' => 'e09c-box-' . $branchId,
            'device_secret_hash' => hash('sha256', $secret), 'status' => $status, 'active_slot' => EdgeDevice::ACTIVE_SLOT,
            'paired_at' => now(),
        ]);
        if ($activate) {
            DB::connection('master')->table('edge_branch_activations')->insert([
                'tenant_id' => $this->tenantId, 'branch_id' => $branchId,
                'generation' => 1 + (int) DB::connection('master')->table('edge_branch_activations')->where('tenant_id', $this->tenantId)->where('branch_id', $branchId)->max('generation'),
                'edge_device_id' => $device->id, 'device_public_uuid' => $device->public_uuid, 'reason' => 'initial',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $device;
    }

    protected function deviceHeaders(EdgeDevice $device, string $secret): array
    {
        return ['X-Edge-Device-ID' => $device->public_uuid, 'Authorization' => 'Bearer ' . $secret, 'Accept' => 'application/json'];
    }

    protected function actingAsOwner(): static
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->users['owner']), 'tenant');
    }

    protected function cleanupEdgeCloud(): void
    {
        $m = DB::connection('master');
        $ids = $m->table('tenants')->where('tenant_code', $this->tenantCode())->pluck('id')->all();
        foreach ($ids as $id) {
            foreach (['edge_setup_sessions', 'edge_pairing_codes', 'edge_devices', 'edge_branch_activations', 'subscriptions', 'tenant_domains', 'tenant_databases'] as $table) {
                $m->table($table)->where('tenant_id', $id)->delete();
            }
            $m->table('tenants')->where('id', $id)->delete();
        }
        $m->table('plans')->where('code', 'e09c_' . $this->tenantCode())->delete();
        if (isset($this->host)) {
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
        }
    }
}
