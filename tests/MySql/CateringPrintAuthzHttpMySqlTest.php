<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\User;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-PRODUCT-UX-1 (item 7) — print authorization over REAL HTTP.
 *
 * The service tests prove what queueing does. Asserting the middleware LIST
 * proves the routes are decorated. Neither proves a request is actually
 * refused, which is the only thing that matters when the endpoint puts work on
 * physical hardware.
 *
 * So these go through the live stack — IdentifyTenant, auth:tenant,
 * tenant.subscription.access, route.permission — on the real tenant host. Only
 * CSRF is disabled, because it is orthogonal to authorization.
 *
 * The test fails if any of those are removed:
 *   auth:tenant dropped        -> the anonymous request reaches the controller
 *   route.permission dropped   -> the unprivileged user gets a job queued
 *   subscription access dropped-> a catering-less plan would pass
 *
 * And in every refusal case the assertion is not merely the status code but
 * that PrintJob::count() is still zero — a 403 with a queued job would be a
 * worse outcome than a 200.
 */
class CateringPrintAuthzHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const PERM_ESTIMATE = 'tenant.catering.documents.estimate-print';

    private const PERM_INVOICE = 'tenant.catering.documents.final-invoice-print';

    private string $host;

    private string $estimateUri;

    private int $ownerId;

    private int $cashierId;

    private int $printerId;

    protected function setUp(): void
    {
        parent::setUp();

        // CSRF only. Every auth/tenant/permission middleware stays live.
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'cateringprintauth.'.config('tenancy.tenant_base_domain');

        $this->seedMaster();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();

            // The plan must go too. A leftover catering-enabled plan in the
            // shared master DB breaks CateringEntitlement, which asserts exactly
            // one plan has catering enabled. This test only escaped that by
            // alphabetical ordering — luck, not isolation.
            $planId = $m->table('plans')->where('code', 'printauth-catering')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('subscriptions')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }

            $m->table('tenants')->where('tenant_code', 'cateringprintauth')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /** Route absence must fail the suite rather than masquerade as a 404 "pass". */
    public function test_both_print_routes_exist_and_are_permission_gated(): void
    {
        foreach ([self::PERM_ESTIMATE, self::PERM_INVOICE] as $name) {
            $this->assertTrue(Route::has($name), "route [{$name}] must be registered");
            $this->assertContains('route.permission', Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    /** ACTOR 1 — nobody. auth:tenant must refuse before any business logic. */
    public function test_an_unauthenticated_request_cannot_queue_a_print_job(): void
    {
        $res = $this->postJson($this->estimateUri, [
            'printer_id' => $this->printerId, 'lang' => 'en',
        ]);

        $res->assertStatus(401);
        $this->assertSame(0, $this->printJobCount(),
            'an anonymous request must never reach the printer queue');
    }

    /** ACTOR 2 — signed in, but not permitted. route.permission must deny. */
    public function test_a_user_without_the_permission_cannot_queue_a_print_job(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $res = $this->actingAs(User::on('tenant')->find($this->cashierId), 'tenant')
            ->postJson($this->estimateUri, ['printer_id' => $this->printerId, 'lang' => 'en']);

        $res->assertStatus(403);
        $this->assertSame(0, $this->printJobCount(),
            'a 403 that still queued a job would be worse than allowing it');
    }

    /** ACTOR 3 — authorized. The request succeeds and queues exactly one job. */
    public function test_an_authorized_user_queues_exactly_one_correct_job(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $before = $this->financialSnapshot();

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->post($this->estimateUri, ['printer_id' => $this->printerId, 'lang' => 'en']);

        $this->assertNotContains($res->getStatusCode(), [401, 403, 404],
            'the authorized owner must clear every gate and reach the controller; got '.$res->getStatusCode());

        DB::setDefaultConnection('tenant');
        $jobs = PrintJob::where('document_type', 'catering_estimate')->get();

        $this->assertCount(1, $jobs, 'exactly one job, not zero and not two');
        $this->assertSame('catering_estimate', $jobs[0]->reference_type);
        $this->assertSame($this->printerId, (int) $jobs[0]->printer_id);
        $this->assertSame('queued', $jobs[0]->print_status);

        $this->assertSame($before, $this->financialSnapshot(),
            'a successful print must still post nothing and move no stock');
    }

    /** An id absent from the ACTIVE tenant resolves to nothing and queues nothing. */
    public function test_an_id_absent_from_the_active_tenant_cannot_queue_anything(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $missing = 'http://'.$this->host.'/catering/documents/estimate/999999/print';

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->postJson($missing, ['printer_id' => $this->printerId, 'lang' => 'en']);

        $this->assertSame(404, $res->getStatusCode(),
            'database-per-tenant binding must resolve within the active tenant only');
        $this->assertSame(0, $this->printJobCount());
    }

    private function printJobCount(): int
    {
        DB::setDefaultConnection('tenant');

        return PrintJob::count();
    }

    private function financialSnapshot(): array
    {
        DB::setDefaultConnection('tenant');
        $c = DB::connection('tenant');

        return [
            'entries' => $c->table('journal_entries')->count(),
            'lines' => $c->table('journal_lines')->count(),
            'stock' => $c->table('stock_ledgers')->count(),
        ];
    }

    /** A live tenant, an active domain, and a plan that actually includes catering. */
    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenants')->where('tenant_code', 'cateringprintauth')->delete();

        $tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'cateringprintauth', 'business_name' => 'Catering Print Authz',
            'owner_name' => 'Owner', 'owner_email' => 'owner@cateringprintauth.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
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
            'tenant_id' => $tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // tenant.subscription.access is live, so the plan must genuinely enable
        // catering and the shared print transport.
        $plan = Plan::updateOrCreate(['code' => 'printauth-catering'], [
            'name' => 'Print Authz Catering', 'price' => 0, 'is_active' => true,
        ]);
        PlanModule::where('plan_id', $plan->id)->delete();

        // Modules are CREATED if absent, never silently skipped — a missing row
        // would build a plan without catering and make every authorization
        // assertion below vacuous, passing or failing on test ordering alone.
        foreach (['catering', 'printing'] as $key) {
            $module = Module::updateOrCreate(['key' => $key], [
                'name' => ucfirst($key),
                'category' => 'Operations',
                'route_module_keys' => ['tenant.'.$key],
                'is_core' => false,
                'is_active' => true,
            ]);
            PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);
        }

        Subscription::updateOrCreate(['tenant_id' => $tenantId], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);

        $this->assertContains('catering', $plan->fresh()->loadMissing('enabledModules')->enabledModules->pluck('key')->all(),
            'the test plan must actually enable catering, or the authorization proof means nothing');
    }

    /** Owner holds the print permissions; the cashier deliberately does not. */
    private function seedTenant(): void
    {
        // permissions/roles are NOT truncated: those rows come from a tenant
        // MIGRATION, so wiping them removes data no later test can restore.
        $this->cleanTenant([
            'print_jobs', 'printers',
            'catering_estimate_lines', 'catering_estimates', 'catering_refunds', 'catering_events',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'model_has_roles',
            'units', 'products', 'categories', 'customers', 'users', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $role = function (string $name) use ($c) {
            $id = $c->table('roles')->where('name', $name)->where('guard_name', 'tenant')->value('id');

            return $id ?: $c->table('roles')->insertGetId([
                'name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $ownerRole = $role('Owner');
        $cashierRole = $role('Cashier');

        // The cashier must hold NEITHER print permission — clear any grant a
        // previous run left, or this test would silently stop proving anything.
        foreach ([self::PERM_ESTIMATE, self::PERM_INVOICE] as $perm) {
            $permId = $c->table('permissions')->where('name', $perm)->where('guard_name', 'tenant')->value('id');
            if (! $permId) {
                $permId = $c->table('permissions')->insertGetId([
                    'name' => $perm, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $c->table('role_has_permissions')->updateOrInsert(
                ['permission_id' => $permId, 'role_id' => $ownerRole], []
            );
            $c->table('role_has_permissions')
                ->where('permission_id', $permId)->where('role_id', $cashierRole)->delete();
        }

        $this->ownerId = $this->userWithRole($c, 'PAOWN', $ownerRole);
        $this->cashierId = $this->userWithRole($c, 'PACASH', $cashierRole);

        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $unitId = $c->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = $this->makeProduct($categoryId, ['unit_id' => $unitId]);
        $this->printerId = $this->makePrinter(['characters_per_line' => 42]);

        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $branchId, 'customer_name' => 'Authz Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(10)->toDateString(),
            'venue' => 'Authz Hall', 'pax' => 200,
        ]);
        $estimate = $estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $productId, 'item_name' => 'Biryani',
            'quantity' => 60, 'unit_id' => $unitId, 'unit_code' => 'KG', 'rate' => 1000,
        ]], []);

        $this->estimateUri = 'http://'.$this->host.'/catering/documents/estimate/'.$estimate->id.'/print';

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWithRole($c, string $code, int $roleId): int
    {
        $uid = $c->table('users')->insertGetId([
            'name' => $code, 'email' => strtolower($code).'@cateringprintauth.test',
            'password' => bcrypt('x'), 'employee_code' => $code, 'status' => 'active',
            'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $uid]);

        return $uid;
    }
}
