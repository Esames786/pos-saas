<?php

namespace Tests\MySql;

use App\Models\Master\Plan;
use App\Models\Master\Subscription;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\Tenant;
use App\Services\Saas\SelfSignupService;
use App\Services\Saas\SubscriptionBillingService;
use App\Services\Saas\SubscriptionTrialTransitionService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CLOUD-BILLING-1B — trial-safe automatic first invoice + deterministic trial→active transition.
 *
 * Locked free-trial contract proven here (all master-only, Carbon::setTestNow for the clock):
 *   • signup creates exactly ONE first invoice, due at trial_ends_at, paid period STARTING at
 *     trial_ends_at (not signup, not payment time); idempotent on origin_key;
 *   • a provisioning failure leaves NO tenant / subscription / invoice (atomic);
 *   • paying EARLY (before trial end) marks the invoice paid but keeps status=trial — the promised
 *     trial is never shortened;
 *   • at/after trial end the scheduled transition activates exactly once (no double-activate, no
 *     period extension); a LATE payment after trial end activates immediately;
 *   • demo tenants are never transitioned.
 *
 * No real payment/account data.
 */
class CloudBillingFirstInvoiceMySqlTest extends MySqlTenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['subscription_payments', 'subscription_invoices', 'subscriptions', 'tenant_domains', 'tenants', 'plans'] as $t) {
            DB::connection('master')->table($t)->delete();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── signup: exactly one first invoice, atomic ────────────────────────────────────────────

    public function test_signup_creates_exactly_one_first_invoice_with_trial_end_period(): void
    {
        Carbon::setTestNow('2026-03-10 09:00:00');
        $plan = $this->plan(['trial_days' => 30, 'monthly_price' => 5000]);

        $this->mock(TenantProvisioner::class, function ($m) {
            $m->shouldReceive('provisionTenant')->andReturnUsing(fn ($tenant) => $tenant);
        });

        $tenant = app(SelfSignupService::class)->registerTrial($this->signupData($plan));

        $invoices = SubscriptionInvoice::where('tenant_id', $tenant->id)->get();
        $this->assertCount(1, $invoices, 'exactly one first invoice');
        $invoice = $invoices->first();

        $trialEnd = Carbon::parse('2026-03-10 09:00:00')->addDays(30)->toDateString(); // 2026-04-09
        $this->assertSame('subscription', $invoice->invoice_type);
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('signup:'.$tenant->subscription->id, $invoice->origin_key);
        $this->assertSame('5000.00', $invoice->total_amount);
        $this->assertSame($trialEnd, $invoice->due_date->toDateString(), 'due at trial end');
        $this->assertSame($trialEnd, $invoice->period_start->toDateString(), 'paid period STARTS at trial end');
        $this->assertSame('2026-05-09', $invoice->period_end->toDateString(), 'monthly = +1 calendar month');
        $this->assertSame('trial', $tenant->subscription->status);
    }

    public function test_provisioning_failure_leaves_no_tenant_subscription_or_invoice(): void
    {
        $plan = $this->plan();

        $this->mock(TenantProvisioner::class, function ($m) {
            $m->shouldReceive('provisionTenant')->andThrow(new \RuntimeException('provision boom'));
        });

        try {
            app(SelfSignupService::class)->registerTrial($this->signupData($plan));
            $this->fail('registerTrial should have rethrown the provisioning failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('provision boom', $e->getMessage());
        }

        $this->assertSame(0, Tenant::count(), 'no orphan tenant');
        $this->assertSame(0, Subscription::count(), 'no orphan subscription');
        $this->assertSame(0, SubscriptionInvoice::count(), 'no orphan invoice/obligation');
    }

    public function test_ensure_first_invoice_is_idempotent(): void
    {
        $sub = $this->trialSubscription($this->plan(['monthly_price' => 3000]), now()->addDays(14));
        $billing = app(SubscriptionBillingService::class);

        $a = $billing->ensureFirstInvoice($sub);
        $b = $billing->ensureFirstInvoice($sub->fresh());

        $this->assertNotNull($a);
        $this->assertSame($a->id, $b->id, 'same invoice returned, never a duplicate');
        $this->assertSame(1, SubscriptionInvoice::where('subscription_id', $sub->id)->count());
    }

    public function test_unpriced_custom_plan_gets_no_auto_invoice(): void
    {
        $plan = $this->plan(['monthly_price' => null, 'is_custom' => true]);
        $sub = $this->trialSubscription($plan, now()->addDays(14));

        $this->assertNull(app(SubscriptionBillingService::class)->ensureFirstInvoice($sub));
        $this->assertSame(0, SubscriptionInvoice::count());
    }

    // ── early payment keeps trial; transition activates once ──────────────────────────────────

    public function test_early_full_payment_keeps_subscription_in_trial(): void
    {
        Carbon::setTestNow('2026-03-01 10:00:00');
        $trialEnd = Carbon::parse('2026-03-31 10:00:00');
        $sub = $this->trialSubscription($this->plan(['monthly_price' => 5000]), $trialEnd);
        $invoice = app(SubscriptionBillingService::class)->ensureFirstInvoice($sub);

        // Pay in full, still inside the trial window.
        Carbon::setTestNow('2026-03-05 12:00:00');
        app(SubscriptionBillingService::class)->recordPayment($invoice, [
            'amount' => 5000, 'status' => 'verified', 'payment_method_code' => 'easypaisa',
        ]);

        $this->assertSame('paid', $invoice->fresh()->status, 'invoice is paid');
        $this->assertSame('trial', $sub->fresh()->status, 'subscription STAYS trial — trial not shortened');
        $this->assertNull($sub->fresh()->current_period_ends_at, 'no active period while still in trial');
    }

    public function test_trial_transition_activates_exactly_once_at_trial_end(): void
    {
        Carbon::setTestNow('2026-03-01 10:00:00');
        $trialEnd = Carbon::parse('2026-03-31 10:00:00');
        $sub = $this->trialSubscription($this->plan(['monthly_price' => 5000]), $trialEnd);
        $invoice = app(SubscriptionBillingService::class)->ensureFirstInvoice($sub);

        // paid early
        Carbon::setTestNow('2026-03-10 09:00:00');
        app(SubscriptionBillingService::class)->recordPayment($invoice, ['amount' => 5000, 'status' => 'verified', 'payment_method_code' => 'easypaisa']);

        $transitions = app(SubscriptionTrialTransitionService::class);

        // just before trial end -> still trial
        Carbon::setTestNow('2026-03-31 09:59:59');
        $transitions->processDueTrialTransitions();
        $this->assertSame('trial', $sub->fresh()->status, 'still trial one second before end');

        // at/after trial end -> active exactly once, period = invoice.period_end
        Carbon::setTestNow('2026-03-31 10:00:01');
        $r1 = $transitions->processDueTrialTransitions();
        $this->assertSame(1, $r1['activated']);
        $active = $sub->fresh();
        $this->assertSame('active', $active->status);
        $this->assertSame($invoice->fresh()->period_end->toDateString(), $active->current_period_ends_at->toDateString());

        // idempotent: run again -> nothing changes
        Carbon::setTestNow('2026-04-01 00:00:00');
        $r2 = $transitions->processDueTrialTransitions();
        $this->assertSame(0, $r2['activated'], 'no double activation');
        $this->assertSame($active->current_period_ends_at->toDateString(), $sub->fresh()->current_period_ends_at->toDateString(), 'period not extended');
    }

    public function test_late_full_payment_after_trial_end_activates_immediately(): void
    {
        Carbon::setTestNow('2026-03-01 10:00:00');
        $trialEnd = Carbon::parse('2026-03-31 10:00:00');
        $sub = $this->trialSubscription($this->plan(['monthly_price' => 5000]), $trialEnd);
        $invoice = app(SubscriptionBillingService::class)->ensureFirstInvoice($sub);

        // Trial has ended, still unpaid -> transition leaves it waiting.
        Carbon::setTestNow('2026-04-05 10:00:00');
        $this->assertSame(1, app(SubscriptionTrialTransitionService::class)->processDueTrialTransitions()['waiting_unpaid']);
        $this->assertSame('trial', $sub->fresh()->status);

        // Late full payment -> activates immediately (no waiting for the next daily run).
        app(SubscriptionBillingService::class)->recordPayment($invoice, ['amount' => 5000, 'status' => 'verified', 'payment_method_code' => 'easypaisa']);
        $active = $sub->fresh();
        $this->assertSame('active', $active->status, 'late payment activates at once');
        $this->assertSame($invoice->fresh()->period_end->toDateString(), $active->current_period_ends_at->toDateString(), 'paid period still starts at trial end, not payment date');
    }

    public function test_demo_tenant_trial_is_never_transitioned(): void
    {
        Carbon::setTestNow('2026-03-01 10:00:00');
        $trialEnd = Carbon::parse('2026-03-31 10:00:00');
        $sub = $this->trialSubscription($this->plan(['monthly_price' => 5000]), $trialEnd, isDemo: true);
        $invoice = app(SubscriptionBillingService::class)->ensureFirstInvoice($sub);
        app(SubscriptionBillingService::class)->recordPayment($invoice, ['amount' => 5000, 'status' => 'verified', 'payment_method_code' => 'easypaisa']);

        Carbon::setTestNow('2026-04-05 10:00:00');
        $r = app(SubscriptionTrialTransitionService::class)->processDueTrialTransitions();

        $this->assertSame(1, $r['demo_skipped']);
        $this->assertSame(0, $r['activated']);
        $this->assertSame('trial', $sub->fresh()->status, 'demo tenants self-renew; never auto-activated');
    }

    // ── calendar month-end proof (monthly) ───────────────────────────────────────────────────

    public function test_month_end_trial_start_rolls_to_short_month(): void
    {
        Carbon::setTestNow('2026-01-31 08:00:00'); // trial_ends_at will land on Jan 31 + 0 (explicit below)
        $sub = $this->trialSubscription($this->plan(['monthly_price' => 5000]), Carbon::parse('2026-01-31 08:00:00'));
        $invoice = app(SubscriptionBillingService::class)->ensureFirstInvoice($sub);

        $this->assertSame('2026-01-31', $invoice->period_start->toDateString());
        $this->assertSame('2026-02-28', $invoice->period_end->toDateString(), 'Jan 31 + 1 month -> Feb 28 (no overflow into March)');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

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

    private function trialSubscription(Plan $plan, Carbon $trialEndsAt, bool $isDemo = false): Subscription
    {
        $tenant = Tenant::create([
            'tenant_code' => 't'.substr(uniqid(), -6),
            'business_name' => 'Trial Co',
            'owner_name' => 'Owner',
            'owner_email' => 'o_'.uniqid().'@test.local',
            'currency_code' => 'PKR',
            'status' => 'active',
            'is_demo' => $isDemo,
            'trial_ends_at' => $trialEndsAt,
        ]);

        return Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trial',
            'trial_ends_at' => $trialEndsAt,
            'current_period_ends_at' => null,
        ]);
    }

    private function signupData(Plan $plan): array
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
        ];
    }
}
