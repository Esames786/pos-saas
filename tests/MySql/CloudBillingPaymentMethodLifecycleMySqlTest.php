<?php

namespace Tests\MySql;

use App\Models\Master\BillingPaymentMethod;
use App\Models\Master\CentralUser;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionPayment;
use App\Models\Master\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CLOUD-BILLING-1A-HARDEN — payment-method lifecycle + central HTTP authorization matrix.
 *
 * Proves (server-side, not tenant-hide-only):
 *   • a method cannot become ACTIVE without complete customer-facing config (account title + number),
 *     enforced on toggle AND on store/update;
 *   • a referenced method is DEACTIVATED, never hard-deleted, so historical payments stay readable;
 *     an unreferenced method may be hard-deleted;
 *   • the central `route.permission` gate: a Super Admin with the permissions can configure methods,
 *     a central user lacking the permission gets 403, and a non-central (tenant / unauthenticated)
 *     caller is bounced to login and mutates nothing.
 *
 * No real account details — placeholders only.
 */
class CloudBillingPaymentMethodLifecycleMySqlTest extends MySqlTenantTestCase
{
    /** The exact per-route permissions the EnsureRoutePermission gate demands (= route names). */
    private const PM_PERMISSIONS = [
        'central.payment-methods.index',
        'central.payment-methods.create',
        'central.payment-methods.store',
        'central.payment-methods.edit',
        'central.payment-methods.update',
        'central.payment-methods.toggle',
        'central.payment-methods.destroy',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('master')->table('subscription_payments')->delete();
        DB::connection('master')->table('billing_payment_methods')->delete();
        DB::connection('master')->table('model_has_roles')->delete();
        DB::connection('master')->table('model_has_permissions')->delete();
        DB::connection('master')->table('central_users')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ── model lifecycle ──────────────────────────────────────────────────────────────────────

    public function test_is_configured_requires_both_account_title_and_number(): void
    {
        $complete = new BillingPaymentMethod(['account_title' => 'Holder', 'account_number' => '0000000000']);
        $noNumber = new BillingPaymentMethod(['account_title' => 'Holder', 'account_number' => null]);
        $noTitle = new BillingPaymentMethod(['account_title' => '', 'account_number' => '0000000000']);

        $this->assertTrue($complete->isConfigured());
        $this->assertFalse($noNumber->isConfigured());
        $this->assertFalse($noTitle->isConfigured());
    }

    public function test_configured_scope_matches_is_configured(): void
    {
        BillingPaymentMethod::create(['code' => 'ok', 'display_name' => 'OK', 'account_title' => 'H', 'account_number' => '1', 'is_active' => true]);
        BillingPaymentMethod::create(['code' => 'no_num', 'display_name' => 'NoNum', 'account_title' => 'H', 'is_active' => true]);
        BillingPaymentMethod::create(['code' => 'no_title', 'display_name' => 'NoTitle', 'account_number' => '1', 'is_active' => true]);

        $this->assertSame(['ok'], BillingPaymentMethod::query()->configured()->pluck('code')->all());
    }

    public function test_has_been_referenced_detects_historical_payment_by_code(): void
    {
        $method = BillingPaymentMethod::create(['code' => 'easypaisa', 'display_name' => 'EasyPaisa', 'account_title' => 'H', 'account_number' => '0000000000', 'is_active' => true]);
        $this->assertFalse($method->hasBeenReferenced());

        [$tenant, $invoice] = $this->makeTenantWithInvoice();
        SubscriptionPayment::create([
            'subscription_invoice_id' => $invoice->id,
            'tenant_id' => $tenant->id,
            'payment_method_code' => 'easypaisa',
            'amount' => 100,
            'currency_code' => 'PKR',
            'payment_date' => now()->toDateString(),
            'status' => 'verified',
        ]);

        $this->assertTrue($method->fresh()->hasBeenReferenced());
    }

    // ── activation completeness guard (HTTP) ─────────────────────────────────────────────────

    public function test_toggle_refuses_to_activate_an_incomplete_method(): void
    {
        $admin = $this->centralUserWith(self::PM_PERMISSIONS);
        $method = BillingPaymentMethod::create(['code' => 'jazzcash', 'display_name' => 'JazzCash', 'is_active' => false]);

        $res = $this->actingAs($admin, 'central')
            ->post(route('central.payment-methods.toggle', $method));

        $res->assertSessionHasErrors('is_active');
        $this->assertFalse($method->fresh()->is_active, 'incomplete method must stay inactive');
    }

    public function test_toggle_activates_a_configured_method_and_can_always_deactivate(): void
    {
        $admin = $this->centralUserWith(self::PM_PERMISSIONS);
        $method = BillingPaymentMethod::create(['code' => 'easypaisa', 'display_name' => 'EasyPaisa', 'account_title' => 'Holder', 'account_number' => '0000000000', 'is_active' => false]);

        $this->actingAs($admin, 'central')->post(route('central.payment-methods.toggle', $method))->assertRedirect();
        $this->assertTrue($method->fresh()->is_active, 'configured method activates');

        $this->actingAs($admin, 'central')->post(route('central.payment-methods.toggle', $method))->assertRedirect();
        $this->assertFalse($method->fresh()->is_active, 'deactivation is always allowed');
    }

    public function test_store_rejects_active_true_without_account_config(): void
    {
        $admin = $this->centralUserWith(self::PM_PERMISSIONS);

        $res = $this->actingAs($admin, 'central')->post(route('central.payment-methods.store'), [
            'code' => 'newpay', 'display_name' => 'NewPay', 'is_active' => '1', 'sort_order' => 0,
        ]);

        $res->assertSessionHasErrors('is_active');
        $this->assertDatabaseMissing('billing_payment_methods', ['code' => 'newpay'], 'master');
    }

    public function test_update_rejects_activating_without_account_config(): void
    {
        $admin = $this->centralUserWith(self::PM_PERMISSIONS);
        $method = BillingPaymentMethod::create(['code' => 'nayapay', 'display_name' => 'NayaPay', 'is_active' => false]);

        $res = $this->actingAs($admin, 'central')->put(route('central.payment-methods.update', $method), [
            'code' => 'nayapay', 'display_name' => 'NayaPay', 'is_active' => '1', 'sort_order' => 0,
        ]);

        $res->assertSessionHasErrors('is_active');
        $this->assertFalse($method->fresh()->is_active);
    }

    // ── delete / deactivate semantics (HTTP) ─────────────────────────────────────────────────

    public function test_destroy_hard_deletes_an_unreferenced_method(): void
    {
        $admin = $this->centralUserWith(self::PM_PERMISSIONS);
        $method = BillingPaymentMethod::create(['code' => 'orphan', 'display_name' => 'Orphan', 'account_title' => 'H', 'account_number' => '1']);

        $this->actingAs($admin, 'central')->delete(route('central.payment-methods.destroy', $method))->assertRedirect();
        $this->assertDatabaseMissing('billing_payment_methods', ['code' => 'orphan'], 'master');
    }

    public function test_destroy_deactivates_a_referenced_method_instead_of_deleting(): void
    {
        $admin = $this->centralUserWith(self::PM_PERMISSIONS);
        $method = BillingPaymentMethod::create(['code' => 'easypaisa', 'display_name' => 'EasyPaisa', 'account_title' => 'H', 'account_number' => '0000000000', 'is_active' => true]);

        [$tenant, $invoice] = $this->makeTenantWithInvoice();
        SubscriptionPayment::create([
            'subscription_invoice_id' => $invoice->id,
            'tenant_id' => $tenant->id,
            'payment_method_code' => 'easypaisa',
            'amount' => 100, 'currency_code' => 'PKR',
            'payment_date' => now()->toDateString(), 'status' => 'verified',
        ]);

        $this->actingAs($admin, 'central')->delete(route('central.payment-methods.destroy', $method))->assertRedirect();

        $fresh = $method->fresh();
        $this->assertNotNull($fresh, 'referenced method must NOT be hard-deleted');
        $this->assertFalse($fresh->is_active, 'referenced method is deactivated instead');
    }

    // ── authorization matrix (HTTP) ──────────────────────────────────────────────────────────

    public function test_central_user_lacking_permission_gets_403(): void
    {
        $weak = $this->centralUserWith([]); // authenticated central user, no payment-method permission
        $method = BillingPaymentMethod::create(['code' => 'easypaisa', 'display_name' => 'EasyPaisa', 'account_title' => 'H', 'account_number' => '0000000000', 'is_active' => false]);

        $this->actingAs($weak, 'central')->get(route('central.payment-methods.index'))->assertForbidden();
        $this->actingAs($weak, 'central')->post(route('central.payment-methods.toggle', $method))->assertForbidden();
        $this->actingAs($weak, 'central')->delete(route('central.payment-methods.destroy', $method))->assertForbidden();

        $this->assertFalse($method->fresh()->is_active, 'no mutation by an unauthorized central user');
    }

    public function test_unauthenticated_caller_is_bounced_and_mutates_nothing(): void
    {
        $method = BillingPaymentMethod::create(['code' => 'easypaisa', 'display_name' => 'EasyPaisa', 'account_title' => 'H', 'account_number' => '0000000000', 'is_active' => false]);

        // No central session at all (a tenant user is likewise not a central user).
        $res = $this->post(route('central.payment-methods.toggle', $method));
        $this->assertContains($res->getStatusCode(), [302, 401, 403], 'must not be allowed through');
        $this->assertFalse($method->fresh()->is_active, 'no mutation without central auth');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function centralUserWith(array $permissions): CentralUser
    {
        foreach (self::PM_PERMISSIONS as $p) {
            Permission::findOrCreate($p, 'central');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = CentralUser::create([
            'name' => 'Admin '.uniqid(),
            'email' => 'admin_'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('PM Admin '.uniqid(), 'central');
            $role->syncPermissions($permissions);
            $user->assignRole($role);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @return array{0: Tenant, 1: SubscriptionInvoice} */
    private function makeTenantWithInvoice(): array
    {
        $tenant = Tenant::create([
            'tenant_code' => 'lc'.substr(uniqid(), -6),
            'business_name' => 'Lifecycle Co',
            'owner_name' => 'Owner',
            'owner_email' => 'owner_'.uniqid().'@test.local',
            'currency_code' => 'PKR',
            'status' => 'active',
        ]);

        $invoice = SubscriptionInvoice::create([
            'invoice_no' => 'INV-LC-'.substr(uniqid(), -6),
            'tenant_id' => $tenant->id,
            'invoice_type' => 'subscription',
            'status' => 'issued',
            'currency_code' => 'PKR',
            'subtotal' => 100, 'total_amount' => 100, 'balance_amount' => 100,
        ]);

        return [$tenant, $invoice];
    }
}
