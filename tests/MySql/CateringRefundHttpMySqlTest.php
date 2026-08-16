<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Tenant\Account;
use App\Models\Tenant\CateringRefund;
use App\Models\Tenant\User;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 — the refund endpoint over REAL HTTP.
 *
 * The service tests prove what a refund does. They cannot prove that a
 * double-clicked form pays out once, because the guard that stops it lives in
 * middleware, or that an unprivileged user is turned away, because that gate
 * lives in the routing stack. This is the one catering endpoint that moves money
 * OUT of the business, so both are proved against the live stack — IdentifyTenant,
 * auth:tenant, tenant.subscription.access, route.permission and the duplicate
 * submission guard. Only CSRF is disabled, being orthogonal to all of it.
 *
 * In every refusal case the assertion is not the status code but that no refund
 * exists and the drawer has not moved. A 403 that still paid out would be worse
 * than a 200.
 */
class CateringRefundHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const PERM = 'tenant.catering.refunds.store';

    private string $host;

    private string $refundUri;

    private int $ownerId;

    private int $cashierId;

    private int $cashAccountId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'cateringrefund.'.config('tenancy.tenant_base_domain');

        $this->seedMaster();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();

            // The plan must go too: a leftover catering-enabled plan in the shared
            // master breaks CateringEntitlement, which asserts exactly one plan
            // has catering on.
            $planId = $m->table('plans')->where('code', 'refund-catering')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('subscriptions')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }

            $m->table('tenants')->where('tenant_code', 'cateringrefund')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /** Route absence must fail the suite rather than masquerade as a 404 "pass". */
    public function test_the_refund_route_exists_and_is_gated(): void
    {
        $this->assertTrue(Route::has(self::PERM), 'route ['.self::PERM.'] must be registered');

        $middleware = Route::getRoutes()->getByName(self::PERM)->gatherMiddleware();
        $this->assertContains('route.permission', $middleware, 'money out must be permission-gated');
        $this->assertContains('prevent.duplicate.submit', $middleware,
            'money out must sit behind the duplicate-submission guard');
    }

    /** ACTOR 1 — nobody. */
    public function test_an_unauthenticated_request_cannot_pay_money_out(): void
    {
        $res = $this->postJson($this->refundUri, $this->payload('tok-anon'));

        $res->assertStatus(401);
        $this->assertNoMoneyLeft();
    }

    /** ACTOR 2 — signed in, not permitted. Recording a receipt is not refunding one. */
    public function test_a_user_without_the_refund_permission_cannot_pay_money_out(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $res = $this->actingAs(User::on('tenant')->find($this->cashierId), 'tenant')
            ->postJson($this->refundUri, $this->payload('tok-cashier'));

        $res->assertStatus(403);
        $this->assertNoMoneyLeft();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D. Idempotency — the requirement that matters most for money out.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Kashif's live data already showed one form submitted four times inside two
     * seconds. On this screen that would be four payouts. A disabled button does
     * nothing for a refresh, a back button or a replayed request, so the proof is
     * three identical requests hitting the live stack.
     */
    public function test_three_identical_submissions_pay_out_exactly_once(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $owner = User::on('tenant')->find($this->ownerId);
        $payload = $this->payload('one-time-token-abc');

        for ($i = 0; $i < 3; $i++) {
            $res = $this->actingAs($owner, 'tenant')->post($this->refundUri, $payload);
            $this->assertNotContains($res->getStatusCode(), [401, 403, 404, 500],
                "submission {$i} must reach the stack cleanly; got ".$res->getStatusCode());
        }

        DB::setDefaultConnection('tenant');

        $this->assertSame(1, CateringRefund::count(), 'three presses, one refund');
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')
            ->where('source_type', 'catering_refund')->count(), 'and one posting');
        $this->assertSame(1, DB::connection('tenant')->table('cash_bank_account_transactions')
            ->where('reference_type', 'catering_refund')->count(), 'and one movement out of the drawer');

        // 30,000 received, 10,000 paid back once.
        $this->assertSame(20000.0, $this->drawerBalance(),
            'the drawer must be short by one refund, not three');
    }

    /** A genuinely separate refund is not blocked by the guard. */
    public function test_a_second_deliberate_refund_with_its_own_token_still_lands(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $owner = User::on('tenant')->find($this->ownerId);

        $this->actingAs($owner, 'tenant')->post($this->refundUri, $this->payload('token-first'));
        $this->actingAs($owner, 'tenant')->post($this->refundUri, $this->payload('token-second'));

        DB::setDefaultConnection('tenant');

        $this->assertSame(2, CateringRefund::count(),
            'the guard stops a resubmission, not a second deliberate act');
        $this->assertSame(10000.0, $this->drawerBalance());
    }

    /** Over the limit is refused by the model, and the drawer does not move. */
    public function test_an_over_limit_refund_is_refused_without_moving_money(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->post($this->refundUri, $this->payload('token-toobig', 999999));

        $res->assertSessionHasErrors('refund');
        $this->assertNoMoneyLeft();
    }

    /** @return array<string, mixed> */
    private function payload(string $token, float $amount = 10000): array
    {
        return [
            '_submit_token' => $token,
            'amount' => $amount,
            'refund_date' => now()->toDateString(),
            'reason' => 'Booking cancelled by customer',
            'payment_method_id' => $this->paymentMethodId,
        ];
    }

    private function drawerBalance(): float
    {
        return round((float) DB::connection('tenant')->table('cash_bank_accounts')
            ->where('id', $this->cashAccountId)->value('current_balance'), 2);
    }

    private function assertNoMoneyLeft(): void
    {
        DB::setDefaultConnection('tenant');

        $this->assertSame(0, CateringRefund::count(), 'no refund may exist');
        $this->assertSame(0, DB::connection('tenant')->table('cash_bank_account_transactions')
            ->where('reference_type', 'catering_refund')->count(), 'and nothing may have left the drawer');
        $this->assertSame(30000.0, $this->drawerBalance(), 'the balance must be exactly what came in');
    }

    /** A live tenant on a plan that genuinely enables catering. */
    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenants')->where('tenant_code', 'cateringrefund')->delete();

        $tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'cateringrefund', 'business_name' => 'Catering Refund HTTP',
            'owner_name' => 'Owner', 'owner_email' => 'owner@cateringrefund.test',
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

        $plan = Plan::updateOrCreate(['code' => 'refund-catering'], [
            'name' => 'Refund Catering', 'price' => 0, 'is_active' => true,
        ]);
        PlanModule::where('plan_id', $plan->id)->delete();

        // Created if absent, never silently skipped: a plan without catering would
        // make every authorization assertion below vacuous.
        $module = Module::updateOrCreate(['key' => 'catering'], [
            'name' => 'Catering', 'category' => 'Operations',
            'route_module_keys' => ['tenant.catering'], 'is_core' => false, 'is_active' => true,
        ]);
        PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);

        Subscription::updateOrCreate(['tenant_id' => $tenantId], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);

        $this->assertContains('catering', $plan->fresh()->loadMissing('enabledModules')->enabledModules->pluck('key')->all(),
            'the test plan must actually enable catering, or the authorization proof means nothing');
    }

    /** A booking holding 30,000 with nothing billed — all of it refundable. */
    private function seedTenant(): void
    {
        // permissions/roles are NOT truncated: those rows come from a tenant
        // MIGRATION, so wiping them removes data no later test can restore.
        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders', 'catering_refunds',
            'catering_final_invoices', 'catering_advances', 'catering_cost_snapshots',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_material_rates', 'catering_product_profiles', 'catering_settings',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'payment_methods', 'model_has_roles',
            'products', 'categories', 'customers', 'users', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        (new DefaultChartOfAccountsSeeder)->run();

        $role = function (string $name) use ($c) {
            $id = $c->table('roles')->where('name', $name)->where('guard_name', 'tenant')->value('id');

            return $id ?: $c->table('roles')->insertGetId([
                'name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $ownerRole = $role('Owner');
        $cashierRole = $role('Cashier');

        // The cashier must NOT hold it — clear any grant a previous run left, or
        // this test would silently stop proving anything.
        $permId = $c->table('permissions')->where('name', self::PERM)->where('guard_name', 'tenant')->value('id');
        if (! $permId) {
            $permId = $c->table('permissions')->insertGetId([
                'name' => self::PERM, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        $c->table('role_has_permissions')
            ->where('permission_id', $permId)->where('role_id', $cashierRole)->delete();

        $this->ownerId = $this->userWithRole($c, 'RFOWN', $ownerRole);
        $this->cashierId = $this->userWithRole($c, 'RFCASH', $cashierRole);

        $branchId = $this->makeBranch();
        $productId = $this->makeProduct($this->makeCategory(), ['default_purchase_price' => 400]);
        $c->table('catering_material_rates')->insert([
            'product_id' => $productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->cashAccountId = $c->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $this->cashAccountId]);

        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $branchId, 'customer_name' => 'Refund HTTP Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(10)->toDateString(),
            'pax' => 100,
        ]);
        $estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $productId, 'item_name' => 'Package', 'quantity' => 1, 'rate' => 100000],
        ]);
        $estimates->markSent($event->currentEstimate->refresh());
        $estimates->confirmEvent($event->refresh());

        app(CateringAdvanceService::class)->record($event->refresh(), [
            'amount' => 30000, 'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId, 'reference' => 'ADV-HTTP',
        ]);

        // Cancelled, so nothing will ever be billed and all 30,000 is the
        // customer's — the shape a real refund arrives in.
        $estimates->cancelEvent($event->refresh(), 'Customer cancelled the booking');

        $this->refundUri = 'http://'.$this->host.'/catering/events/'.$event->id.'/refunds';

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWithRole($c, string $code, int $roleId): int
    {
        $uid = $c->table('users')->insertGetId([
            'name' => $code, 'email' => strtolower($code).'@cateringrefund.test',
            'password' => bcrypt('x'), 'employee_code' => $code, 'status' => 'active',
            'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $uid]);

        return $uid;
    }
}
