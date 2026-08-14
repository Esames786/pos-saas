<?php

namespace Tests\MySql;

use App\Http\Controllers\PublicSiteController;
use App\Models\Master\Plan;
use App\Models\Master\SubscriptionInvoice;
use App\Services\Saas\BillingPeriodResolver;
use App\Services\Saas\SelfSignupService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CLOUD-BILLING-2 — billing period (monthly / yearly) flows pricing → URL → form → service →
 * subscription → first invoice, and the yearly canonical contract + calendar period semantics.
 *
 * Contract proven:
 *   • yearly invoice = monthly_price × 10, entitlement = 12 CALENDAR months;
 *   • monthly invoice = monthly_price, entitlement = 1 CALENDAR month;
 *   • the backend recomputes the amount from the plan + chosen cycle — it never trusts a client price;
 *   • ?billing=yearly (direct, no-JS URL) is honoured; anything else is monthly;
 *   • calendar math is correct at month-end, across February / leap day, and at the year boundary.
 */
class CloudBillingPeriodMySqlTest extends MySqlTenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['subscription_payments', 'subscription_invoices', 'subscriptions', 'tenant_domains', 'tenants', 'plans'] as $t) {
            DB::connection('master')->table($t)->delete();
        }
        config(['saas.public_site_mode' => 'live']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── end-to-end billing period at signup ──────────────────────────────────────────────────

    public function test_yearly_signup_sets_period_and_a_ten_month_invoice_over_twelve_months(): void
    {
        Carbon::setTestNow('2026-03-10 09:00:00');
        $plan = $this->plan(['trial_days' => 30, 'monthly_price' => 5000, 'yearly_price' => 50000]);
        $this->fakeProvisioner();

        $tenant = app(SelfSignupService::class)->registerTrial($this->signupData($plan, 'yearly'));

        $this->assertSame('yearly', $tenant->subscription->billing_period);
        $invoice = SubscriptionInvoice::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('50000.00', $invoice->total_amount, 'yearly = monthly_price × 10');

        $trialEnd = Carbon::parse('2026-04-09'); // 2026-03-10 + 30 days
        $this->assertSame($trialEnd->toDateString(), $invoice->period_start->toDateString());
        $this->assertSame($trialEnd->copy()->addMonthsNoOverflow(12)->toDateString(), $invoice->period_end->toDateString(), '12 calendar months');
        $this->assertSame('2027-04-09', $invoice->period_end->toDateString());
    }

    public function test_monthly_signup_is_one_month_and_one_month_price(): void
    {
        Carbon::setTestNow('2026-03-10 09:00:00');
        $plan = $this->plan(['trial_days' => 30, 'monthly_price' => 5000]);
        $this->fakeProvisioner();

        $tenant = app(SelfSignupService::class)->registerTrial($this->signupData($plan, 'monthly'));

        $this->assertSame('monthly', $tenant->subscription->billing_period);
        $invoice = SubscriptionInvoice::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('5000.00', $invoice->total_amount);
        $this->assertSame('2026-05-09', $invoice->period_end->toDateString(), '+1 calendar month');
    }

    public function test_backend_recomputes_amount_from_plan_ignoring_any_client_price(): void
    {
        Carbon::setTestNow('2026-03-10 09:00:00');
        $plan = $this->plan(['monthly_price' => 7000]);
        $this->fakeProvisioner();

        // A hostile client injects a bogus price/amount alongside the real fields.
        $data = $this->signupData($plan, 'yearly') + ['price' => 1, 'amount' => 1, 'total_amount' => 1];
        $tenant = app(SelfSignupService::class)->registerTrial($data);

        $invoice = SubscriptionInvoice::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('70000.00', $invoice->total_amount, 'server uses plan monthly_price × 10, never client input');
    }

    // ── URL → controller (direct, no-JS) ─────────────────────────────────────────────────────

    public function test_direct_url_billing_yearly_is_honoured_and_garbage_falls_back_to_monthly(): void
    {
        $plan = $this->plan(['code' => 'retail_starter']);

        $yearly = app(PublicSiteController::class)->trialCreate(
            Request::create('/start-trial', 'GET', ['plan' => $plan->code, 'billing' => 'yearly'])
        );
        $this->assertSame('yearly', $yearly->getData()['selectedBilling']);

        $garbage = app(PublicSiteController::class)->trialCreate(
            Request::create('/start-trial', 'GET', ['plan' => $plan->code, 'billing' => 'lifetime'])
        );
        $this->assertSame('monthly', $garbage->getData()['selectedBilling']);

        $none = app(PublicSiteController::class)->trialCreate(
            Request::create('/start-trial', 'GET', ['plan' => $plan->code])
        );
        $this->assertSame('monthly', $none->getData()['selectedBilling'], 'default is monthly');
    }

    // ── resolver: canonical amount + calendar period semantics ───────────────────────────────

    public function test_yearly_amount_is_ten_months_not_twelve(): void
    {
        $plan = $this->plan(['monthly_price' => 3000]);
        $resolver = app(BillingPeriodResolver::class);

        $this->assertSame(3000.0, $resolver->invoiceAmount($plan, 'monthly'));
        $this->assertSame(30000.0, $resolver->invoiceAmount($plan, 'yearly'));
    }

    public function test_calendar_period_edges(): void
    {
        $r = app(BillingPeriodResolver::class);

        // month-end: Jan 31 + 1 month -> Feb 28 (2026 is not a leap year), never March.
        $this->assertSame('2026-02-28', $r->periodEnd(Carbon::parse('2026-01-31'), 'monthly')->toDateString());
        // leap day: Feb 29 2024 + 12 months -> Feb 28 2025 (no overflow to March).
        $this->assertSame('2025-02-28', $r->periodEnd(Carbon::parse('2024-02-29'), 'yearly')->toDateString());
        // leap target: Feb 28 2027 + 12 months -> Feb 28 2028 (2028 is leap, but day stays 28).
        $this->assertSame('2028-02-28', $r->periodEnd(Carbon::parse('2027-02-28'), 'yearly')->toDateString());
        // year boundary monthly: Dec 15 -> Jan 15 next year.
        $this->assertSame('2027-01-15', $r->periodEnd(Carbon::parse('2026-12-15'), 'monthly')->toDateString());
        // year boundary yearly: Dec 31 2025 + 12 months -> Dec 31 2026.
        $this->assertSame('2026-12-31', $r->periodEnd(Carbon::parse('2025-12-31'), 'yearly')->toDateString());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function fakeProvisioner(): void
    {
        $this->mock(TenantProvisioner::class, function ($m) {
            $m->shouldReceive('provisionTenant')->andReturnUsing(fn ($tenant) => $tenant);
        });
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'code' => 'p'.substr(uniqid(), -6),
            'name' => 'Test Plan',
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
        ], $overrides));
    }

    private function signupData(Plan $plan, string $billingPeriod): array
    {
        return [
            'business_name' => 'Signup Co',
            'tenant_code' => 'sc'.substr(uniqid(), -6),
            'owner_name' => 'Owner',
            'owner_email' => 'signup_'.uniqid().'@test.local',
            'owner_phone' => null,
            'password' => 'password123',
            'plan_id' => $plan->id,
            'currency_code' => 'PKR',
            'billing_period' => $billingPeriod,
        ];
    }
}
