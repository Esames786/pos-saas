<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Certification-only HTTP proof for the final operator-route permission set. */
class CateringFinalPermissionHttpMySqlTest extends MySqlTenantTestCase
{
    private const ROUTES = [
        'tenant.catering.instructions.index',
        'tenant.catering.documents.bulk-quotations',
        'tenant.catering.documents.bulk-kitchen-sheets',
        'tenant.catering.documents.bulk-address-sheet',
        'tenant.catering.making-adjustment.index',
        'tenant.catering.estimates.email',
        'tenant.catering.final-invoices.email',
    ];

    private string $host;
    private int $ownerId;
    private int $deniedId;
    private int $eventId;
    private int $estimateId;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'cateringfinalperm.'.config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
            $tenantId = $m->table('tenants')->where('tenant_code', 'cateringfinalperm')->value('id');
            if ($tenantId) {
                $m->table('subscriptions')->where('tenant_id', $tenantId)->delete();
                $m->table('tenants')->where('id', $tenantId)->delete();
            }
            $planId = $m->table('plans')->where('code', 'finalperm-catering')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_owner_equivalent_reaches_every_new_surface_without_a_403(): void
    {
        foreach ($this->requests($this->ownerId) as $name => $response) {
            $this->assertNotSame(403, $response->getStatusCode(), "Owner-equivalent unexpectedly got 403 on [{$name}]");
            $this->assertNotSame(401, $response->getStatusCode(), "Owner-equivalent unexpectedly got 401 on [{$name}]");
        }
    }

    public function test_role_without_permissions_is_denied_on_every_new_surface(): void
    {
        foreach ($this->requests($this->deniedId) as $name => $response) {
            $this->assertSame(403, $response->getStatusCode(), "Unpermitted role was not denied on [{$name}]");
        }
    }

    private function requests(int $userId): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::on('tenant')->findOrFail($userId);
        $base = 'http://'.$this->host;

        return [
            self::ROUTES[0] => $this->actingAs($user, 'tenant')->get($base.'/catering/instructions'),
            self::ROUTES[1] => $this->actingAs($user, 'tenant')->get($base.'/catering/documents/bulk/quotations?ids%5B0%5D='.$this->eventId),
            self::ROUTES[2] => $this->actingAs($user, 'tenant')->get($base.'/catering/documents/bulk/kitchen-sheets?ids%5B0%5D='.$this->eventId),
            self::ROUTES[3] => $this->actingAs($user, 'tenant')->get($base.'/catering/documents/bulk/address-sheet?ids%5B0%5D='.$this->eventId),
            self::ROUTES[4] => $this->actingAs($user, 'tenant')->get($base.'/catering/making-adjustment'),
            self::ROUTES[5] => $this->actingAs($user, 'tenant')->post($base.'/catering/estimates/'.$this->estimateId.'/email'),
            self::ROUTES[6] => $this->actingAs($user, 'tenant')->post($base.'/catering/final-invoices/'.$this->invoiceId.'/email'),
        ];
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $old = $m->table('tenants')->where('tenant_code', 'cateringfinalperm')->value('id');
        if ($old) {
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }

        $tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'cateringfinalperm', 'business_name' => 'Catering Permission Certification',
            'owner_name' => 'Owner', 'owner_email' => 'owner@cateringfinalperm.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb, 'db_username' => config('database.connections.tenant.username'),
            'db_password' => null, 'migration_status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = Plan::updateOrCreate(['code' => 'finalperm-catering'], ['name' => 'Final Permission Catering', 'price' => 0, 'is_active' => true]);
        $module = Module::updateOrCreate(['key' => 'catering'], [
            'name' => 'Catering', 'category' => 'Operations', 'route_module_keys' => ['tenant.catering'],
            'is_core' => false, 'is_active' => true,
        ]);
        PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_id' => $module->id], ['is_enabled' => true]);
        Subscription::updateOrCreate(['tenant_id' => $tenantId], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);
    }

    private function seedTenant(): void
    {
        $this->cleanTenant([
            'catering_final_invoices', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'model_has_roles', 'users',
        ]);
        DB::setDefaultConnection('tenant');
        $c = DB::connection('tenant');

        $ownerRole = $this->role($c, 'Owner');
        $deniedRole = $this->role($c, 'No Catering');
        foreach (self::ROUTES as $name) {
            $catalog = DB::connection('master')->table('route_catalogs')->where('route_name', $name)->first();
            $this->assertNotNull($catalog, "Canonical route sync omitted [{$name}]");
            $this->assertSame('tenant.catering', $catalog->module_key, "Wrong entitlement module for [{$name}]");

            // The canonical deploy contract creates every route-catalog permission
            // inside each tenant, then grants the Owner role. A fresh migration by
            // itself intentionally does not stand in for that deployment step.
            $permissionId = $c->table('permissions')->where('name', $name)->where('guard_name', 'tenant')->value('id');
            $permissionId = $permissionId ?: $c->table('permissions')->insertGetId([
                'name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $ownerRole], []);
            $c->table('role_has_permissions')->where('permission_id', $permissionId)->where('role_id', $deniedRole)->delete();
        }

        $this->ownerId = $this->user($c, 'FPOWNER', $ownerRole);
        $this->deniedId = $this->user($c, 'FPDENIED', $deniedRole);

        $this->eventId = $c->table('catering_events')->insertGetId([
            'event_no' => 'EV-FINAL-PERM', 'customer_name' => 'Permission Test',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDay()->toDateString(),
            'pax' => 10, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->estimateId = $c->table('catering_estimates')->insertGetId([
            'catering_event_id' => $this->eventId, 'version_no' => 1, 'status' => 'sent',
            'grand_total' => 1000, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->invoiceId = $c->table('catering_final_invoices')->insertGetId([
            'invoice_no' => 'CI-FINAL-PERM', 'catering_event_id' => $this->eventId,
            'catering_estimate_id' => $this->estimateId, 'snapshot' => json_encode([]),
            'grand_total' => 1000, 'balance_due' => 1000, 'status' => 'issued',
            'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::setDefaultConnection('master');
    }

    private function role($c, string $name): int
    {
        return $c->table('roles')->where('name', $name)->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user($c, string $code, int $roleId): int
    {
        $id = $c->table('users')->insertGetId([
            'name' => $code, 'email' => strtolower($code).'@cateringfinalperm.test', 'password' => bcrypt('x'),
            'employee_code' => $code, 'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $id]);
        return $id;
    }
}
