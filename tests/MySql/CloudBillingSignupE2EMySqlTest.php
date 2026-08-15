<?php

namespace Tests\MySql;

use App\Models\Master\Plan;
use App\Models\Master\Subscription;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\Tenant;
use App\Services\Saas\SubscriptionBillingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * CLOUD-BILLING E2E — the REAL self-signup flow (real SelfSignupService + real TenantProvisioner,
 * which creates and migrates an actual throwaway tenant database) for both monthly and yearly,
 * through the whole free-trial lifecycle:
 *   signup → first invoice (due at trial end, priced by cycle) → early full payment keeps trial →
 *   advance the clock past trial end → saas:process-trial-transitions activates exactly once →
 *   period = 1 / 12 calendar months → re-run is idempotent (no duplicate invoice/email).
 *
 * The provisioned tenant databases are dropped in tearDown. No real payment/account data.
 */
class CloudBillingSignupE2EMySqlTest extends MySqlTenantTestCase
{
    /** @var array<int, string> */
    private array $provisionedDbs = [];

    private ?string $priorDefault = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorDefault = config('database.default');
        foreach (['subscription_payments', 'subscription_invoices', 'subscriptions', 'tenant_domains', 'tenant_databases', 'tenants', 'plans'] as $t) {
            DB::connection('master')->table($t)->delete();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        // Return to master, then drop every throwaway tenant DB this test provisioned.
        app(\App\Services\Tenancy\TenancyManager::class)->deactivate();
        DB::setDefaultConnection($this->priorDefault ?? 'master');
        foreach ($this->provisionedDbs as $db) {
            DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$db}`");
        }
        parent::tearDown();
    }

    public function test_yearly_signup_full_lifecycle(): void
    {
        $this->runLifecycle('yearly', expectedAmount: '50000.00', months: 12);
    }

    public function test_monthly_signup_full_lifecycle(): void
    {
        $this->runLifecycle('monthly', expectedAmount: '5000.00', months: 1);
    }

    private function runLifecycle(string $billing, string $expectedAmount, int $months): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-03-10 09:00:00');

        $plan = $this->publicPlan();
        $code = 'e2e'.$billing.substr(uniqid(), -5);
        $this->rememberDb($code);

        // ── REAL signup over the public HTTP endpoint: request → controller → SelfSignupService →
        // real TenantProvisioner (provisions an actual tenant DB) → first invoice → first-invoice email.
        $response = $this->post(route('public.trial.store'), [
            'business_name' => strtoupper($billing).' E2E Co',
            'tenant_code' => $code,
            'owner_name' => 'Owner',
            'owner_email' => $code.'@test.local',
            'owner_phone' => '',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'plan_id' => $plan->id,
            'currency_code' => 'PKR',
            'billing_period' => $billing,
            'website' => '', // honeypot must be empty
        ]);
        $response->assertRedirect(url('/trial/success'));

        // Provisioning deactivates tenancy; make sure master is the default for the assertions below.
        DB::setDefaultConnection('master');

        // Tenant is really provisioned + active.
        $tenant = Tenant::where('tenant_code', $code)->firstOrFail();
        $this->assertSame('active', $tenant->status);
        $sub = $tenant->subscription;
        $this->assertSame('trial', $sub->status);
        $this->assertSame($billing, $sub->billing_period);

        // First invoice: exactly one, priced by cycle, due at trial end, period = N calendar months.
        $invoice = SubscriptionInvoice::where('tenant_id', $tenant->id)->get();
        $this->assertCount(1, $invoice, 'exactly one first invoice');
        $invoice = $invoice->first();
        $trialEnd = Carbon::parse('2026-03-10 09:00:00')->addDays(30); // 2026-04-09
        $this->assertSame($expectedAmount, $invoice->total_amount);
        $this->assertSame($trialEnd->toDateString(), $invoice->due_date->toDateString());
        $this->assertSame($trialEnd->toDateString(), $invoice->period_start->toDateString());
        $this->assertSame($trialEnd->copy()->addMonthsNoOverflow($months)->toDateString(), $invoice->period_end->toDateString());
        Mail::assertSent(\App\Mail\Billing\FirstInvoiceIssuedMail::class);

        // ── Early full payment (still in trial) keeps the trial ──
        Carbon::setTestNow('2026-03-20 12:00:00');
        app(SubscriptionBillingService::class)->recordPayment($invoice, [
            'amount' => (float) $expectedAmount, 'status' => 'verified', 'payment_method_code' => 'easypaisa',
        ]);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('trial', $sub->fresh()->status, 'paying early never shortens the trial');

        // ── At/after trial end the scheduled command activates exactly once ──
        Carbon::setTestNow('2026-04-09 09:00:01');
        Artisan::call('saas:process-trial-transitions');
        $active = $sub->fresh();
        $this->assertSame('active', $active->status);
        $this->assertSame($invoice->fresh()->period_end->toDateString(), $active->current_period_ends_at->toDateString());
        Mail::assertSent(\App\Mail\Billing\SubscriptionActivatedMail::class);

        // ── Idempotent: another run changes nothing, no duplicate invoice/email ──
        Carbon::setTestNow('2026-04-10 00:00:00');
        Artisan::call('saas:process-trial-transitions');
        $this->assertSame(1, SubscriptionInvoice::where('tenant_id', $tenant->id)->count(), 'no duplicate invoice');
        $this->assertSame(1, Subscription::where('id', $sub->id)->where('status', 'active')->count());
        $this->assertCount(1, Mail::sent(\App\Mail\Billing\SubscriptionActivatedMail::class), 'no duplicate activation email');
    }

    private function publicPlan(): Plan
    {
        return Plan::create([
            'code' => 'plan'.substr(uniqid(), -6),
            'name' => 'E2E Retail',
            'price' => 5000,
            'currency_code' => 'PKR',
            'billing_period' => 'monthly',
            'is_active' => true,
            'is_public' => true,
            'is_custom' => false,
            'trial_days' => 30,
            'display_order' => 0,
            'monthly_price' => 5000,
            'yearly_price' => 50000,
        ]);
    }

    private function rememberDb(string $tenantCode): void
    {
        $safe = strtolower(preg_replace('/[^a-z0-9_]/', '_', $tenantCode));
        $this->provisionedDbs[] = 'pos_tenant_'.trim($safe, '_');
    }
}
