<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\ManagerApproval;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\User;
use App\Services\Sales\ManagerApprovalService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * RETURN-MANAGER-APPROVAL-1 — a branch may require a manager before money goes back.
 *
 * Posting a return hands cash over, puts stock back on the shelf and writes to the ledger, and it
 * was the one POS action with no approval at all — cancelling a single item already needed one.
 *
 * The first test is the one that matters most on the day this ships: with the branch left alone,
 * a return must post exactly as it does today. The setting defaults to auto for that reason.
 */
class SalesReturnManagerApprovalMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $cashierId;
    private int $managerId;
    private int $saleId;
    private int $lineId;
    private float $lineTotal = 1000.0;

    private string $host;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        // The route only exists on a tenant host, so the request has to arrive on one.
        $this->host = 'retapprove.' . config('tenancy.tenant_base_domain');
        $this->seedMasterTenant();

        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'manager_approvals', 'manager_pins', 'sales_return_lines', 'sales_returns',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'stock_balances',
            'products', 'categories', 'terminals', 'model_has_roles', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->cashierId = $this->makeUser(['employee_code' => 'CA' . Str::random(4), 'name' => 'Cashier']);
        $this->managerId = $this->makeUser(['employee_code' => 'MG' . Str::random(4), 'name' => 'Manager']);

        DB::connection('tenant')->table('users')->whereIn('id', [$this->cashierId, $this->managerId])
            ->update(['default_branch_id' => $this->branchId]);

        DB::connection('tenant')->table('manager_pins')->insert([
            'user_id' => $this->managerId, 'pin_hash' => bcrypt('4321'),
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = $this->makeProduct($this->makeCategory(['name' => 'Food', 'slug' => 'f-' . Str::random(4)]));
        $this->saleId = $this->makeSale($this->branchId, [
            'status' => 'paid', 'order_type' => 'takeaway', 'grand_total' => $this->lineTotal + 500,
        ]);
        $this->lineId = $this->makeSaleLine($this->saleId, $product, [
            'quantity' => 1, 'unit_price' => $this->lineTotal, 'line_total' => $this->lineTotal,
        ]);
        // A second line nobody returns, so the sale stays `partially_returned` after the first
        // return posts. Without it a reused approval would be refused for the wrong reason — the
        // sale would no longer be returnable at all, and the test would prove nothing about reuse.
        $this->makeSaleLine($this->saleId, $product, [
            'quantity' => 1, 'unit_price' => 500, 'line_total' => 500,
        ]);

        $this->grantRoutePermission();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'retapprove')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /** Tenant + domain + a live plan, so the request reaches the controller instead of a 404/403. */
    private function seedMasterTenant(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenants')->where('tenant_code', 'retapprove')->delete();

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'retapprove', 'business_name' => 'Return Approval',
            'owner_name' => 'Owner', 'owner_email' => 'owner@retapprove.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $planId = $m->table('plans')->where('code', 'retapprove-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'retapprove-plan', 'name' => 'Return Approval', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        $key = $m->table('route_catalogs')->where('route_name', 'tenant.sales-returns.store')->value('module_key');
        $module = $key ? \App\Models\Master\Module::forRouteModuleKey($key)->first() : null;
        if ($module) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $module->id, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The cashier must hold the route permission, or EnsureRoutePermission 403s before the gate. */
    private function grantRoutePermission(): void
    {
        $c = DB::connection('tenant');
        $roleId = $c->table('roles')->where('name', 'RetCashier')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'RetCashier', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        foreach (['tenant.sales-returns.store', 'tenant.api.manager-approvals.verify'] as $name) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]
            );
            $pid = $c->table('permissions')->where('name', $name)->where('guard_name', 'tenant')->value('id');
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $pid, 'role_id' => $roleId], []);
        }
        $c->table('model_has_roles')->updateOrInsert(
            ['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $this->cashierId], []
        );
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function setMode(string $mode): void
    {
        DB::connection('tenant')->table('branches')->where('id', $this->branchId)
            ->update(['sales_return_approval_mode' => $mode]);
    }

    private function postReturn(array $overrides = [])
    {
        return $this->actingAs(User::on('tenant')->find($this->cashierId), 'tenant')
            ->post('http://' . $this->host . '/sales-returns', array_merge([
                'sales_order_id' => $this->saleId,
                'refund_method'  => 'cash',
                'refund_amount'  => $this->lineTotal,
                'lines'          => [['sales_order_line_id' => $this->lineId, 'quantity' => 1]],
            ], $overrides));
    }

    /** An approval created the way the screen creates it. */
    private function approval(array $payloadOverrides = [], ?int $requesterId = null): ManagerApproval
    {
        return app(ManagerApprovalService::class)->createApprovalForAuthenticatedManager(
            User::on('tenant')->find($this->managerId),
            'sales_return',
            $requesterId ?? $this->cashierId,
            array_merge([
                'sales_order_id' => $this->saleId,
                'branch_id'      => $this->branchId,
                'refund_method'  => 'cash',
                'refund_amount'  => $this->lineTotal,
            ], $payloadOverrides)
        );
    }

    /** THE assertion for deploy day: an untouched branch behaves exactly as it does today. */
    public function test_a_branch_left_alone_still_posts_returns_without_any_approval(): void
    {
        $this->assertSame(
            Branch::SALES_RETURN_AUTO_APPROVE,
            (string) DB::connection('tenant')->table('branches')->where('id', $this->branchId)
                ->value('sales_return_approval_mode'),
            'the column must default to auto — shipping it must not stop returns anywhere'
        );

        $this->postReturn()->assertRedirect();

        $this->assertSame(1, SalesReturn::on('tenant')->count());
    }

    /** With the branch switched on, a return without approval does not post. */
    public function test_without_approval_the_return_is_refused(): void
    {
        $this->setMode(Branch::SALES_RETURN_MANAGER_REQUIRED);

        $this->postReturn()->assertSessionHasErrors('manager_approval_id');

        $this->assertSame(0, SalesReturn::on('tenant')->count(), 'nothing may be posted');
    }

    /** With a proper approval it posts, and the approval is spent. */
    public function test_a_valid_approval_posts_the_return_and_is_consumed(): void
    {
        $this->setMode(Branch::SALES_RETURN_MANAGER_REQUIRED);
        $approval = $this->approval();

        $this->postReturn(['manager_approval_id' => $approval->id])->assertRedirect();

        $this->assertSame(1, SalesReturn::on('tenant')->count());
        $this->assertNotNull($approval->refresh()->consumed_at, 'the approval must be spent');
    }

    /** The same approval cannot post a second return. */
    public function test_an_approval_cannot_be_used_twice(): void
    {
        $this->setMode(Branch::SALES_RETURN_MANAGER_REQUIRED);
        $approval = $this->approval();

        $this->postReturn(['manager_approval_id' => $approval->id])->assertRedirect();
        $this->postReturn(['manager_approval_id' => $approval->id])->assertSessionHasErrors('manager_approval_id');

        $this->assertSame(1, SalesReturn::on('tenant')->count(), 'only the first one may post');
    }

    /**
     * The attack this binding exists to stop: approve a small refund, then post a large one.
     * Refused twice over — the payload no longer matches, and the service would reject the
     * amount against the lines anyway.
     */
    public function test_an_approval_for_a_small_refund_cannot_post_a_large_one(): void
    {
        $this->setMode(Branch::SALES_RETURN_MANAGER_REQUIRED);
        $approval = $this->approval(['refund_amount' => 100.0]);

        $this->postReturn(['manager_approval_id' => $approval->id, 'refund_amount' => $this->lineTotal])
            ->assertSessionHasErrors('manager_approval_id');

        $this->assertSame(0, SalesReturn::on('tenant')->count());
    }

    /** A refund figure that does not match the lines is refused even with a matching approval. */
    public function test_a_refund_that_does_not_match_the_lines_is_refused(): void
    {
        $this->setMode(Branch::SALES_RETURN_MANAGER_REQUIRED);
        $approval = $this->approval(['refund_amount' => 100.0]);

        $this->postReturn(['manager_approval_id' => $approval->id, 'refund_amount' => 100.0])
            ->assertSessionHasErrors();

        $this->assertSame(0, SalesReturn::on('tenant')->count(),
            'the service computes the refund from the lines and refuses anything else');
    }

    /** One cashier's approval is no use to another. */
    public function test_another_cashiers_approval_is_refused(): void
    {
        $this->setMode(Branch::SALES_RETURN_MANAGER_REQUIRED);
        $other = $this->makeUser(['employee_code' => 'OT' . Str::random(4)]);
        $approval = $this->approval([], $other);

        $this->postReturn(['manager_approval_id' => $approval->id])->assertSessionHasErrors('manager_approval_id');

        $this->assertSame(0, SalesReturn::on('tenant')->count());
    }

    /** An approval older than ten minutes is dead. */
    public function test_an_expired_approval_is_refused(): void
    {
        $this->setMode(Branch::SALES_RETURN_MANAGER_REQUIRED);
        $approval = $this->approval();
        DB::connection('tenant')->table('manager_approvals')->where('id', $approval->id)
            ->update(['approved_at' => now()->subMinutes(11)]);

        $this->postReturn(['manager_approval_id' => $approval->id])->assertSessionHasErrors('manager_approval_id');

        $this->assertSame(0, SalesReturn::on('tenant')->count());
    }

    /** A cancellation approval must never authorise a refund. */
    public function test_a_cancellation_approval_cannot_post_a_return(): void
    {
        $this->setMode(Branch::SALES_RETURN_MANAGER_REQUIRED);
        $approval = app(ManagerApprovalService::class)->createApprovalForAuthenticatedManager(
            User::on('tenant')->find($this->managerId),
            'cancel_held_order',
            $this->cashierId,
            ['sales_order_id' => $this->saleId, 'branch_id' => $this->branchId]
        );

        $this->postReturn(['manager_approval_id' => $approval->id])->assertSessionHasErrors('manager_approval_id');

        $this->assertSame(0, SalesReturn::on('tenant')->count());
    }

    /** Without a refund figure the service skips its own check, so the binding would guard nothing. */
    public function test_a_manager_approved_return_must_state_its_refund_amount(): void
    {
        $this->setMode(Branch::SALES_RETURN_MANAGER_REQUIRED);
        $approval = $this->approval();

        $this->postReturn(['manager_approval_id' => $approval->id, 'refund_amount' => null])
            ->assertSessionHasErrors('refund_amount');

        $this->assertSame(0, SalesReturn::on('tenant')->count());
    }
}
