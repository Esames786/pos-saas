<?php

namespace Tests\MySql;

use App\Mail\Billing\FirstInvoiceIssuedMail;
use App\Mail\Billing\PaymentProofReceivedMail;
use App\Mail\Billing\PaymentRejectedMail;
use App\Mail\Billing\PaymentVerifiedMail;
use App\Mail\Billing\SubscriptionActivatedMail;
use App\Mail\Billing\SubscriptionPastDueMail;
use App\Mail\Billing\TrialEndingMail;
use App\Models\Master\Plan;
use App\Models\Master\Subscription;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionPayment;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDomain;
use App\Services\Saas\BillingNotifier;
use App\Services\Saas\SubscriptionLifecycleService;
use App\Services\Saas\SubscriptionTrialTransitionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * CLOUD-BILLING-3A — transactional billing emails: every lifecycle event, sent at-most-once, with a
 * mail failure that never rolls back billing state. Mail::fake only — no real SMTP.
 */
class CloudBillingEmailMySqlTest extends MySqlTenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['billing_notification_log', 'subscription_payments', 'subscription_invoices', 'subscriptions', 'tenant_domains', 'tenants', 'plans'] as $t) {
            DB::connection('master')->table($t)->delete();
        }
        config(['saas.contact.support_email' => 'reviewer@bingoopos.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_billing_mailable_renders(): void
    {
        // Catches Blade errors in the shared template without a full view:cache.
        $mailables = [
            new FirstInvoiceIssuedMail('Acme', 'INV-1', '5000.00', 'PKR', 'Apr 9, 2026', 'https://x/billing'),
            new PaymentProofReceivedMail('Acme', 'INV-1', '5000.00', 'PKR', 'https://c/invoices/1'),
            new PaymentVerifiedMail('Acme', 'INV-1', '5000.00', 'PKR', 'https://x/billing'),
            new PaymentRejectedMail('Acme', 'INV-1', '5000.00', 'PKR', 'https://x/billing', 'blurry image'),
            new TrialEndingMail('Acme', 'Apr 9, 2026', 'https://x/billing'),
            new SubscriptionActivatedMail('Acme', 'Retail', 'May 9, 2026', 'https://x/login'),
            new SubscriptionPastDueMail('Acme', 'https://x/billing'),
        ];
        foreach ($mailables as $m) {
            $this->assertStringContainsString('Acme', $m->render());
        }
    }

    public function test_first_invoice_issued_is_sent_once_and_idempotent(): void
    {
        Mail::fake();
        [$tenant, , $invoice] = $this->scenario();
        $notifier = app(BillingNotifier::class);

        $this->assertTrue($notifier->firstInvoiceIssued($invoice));
        $this->assertFalse($notifier->firstInvoiceIssued($invoice->fresh()), 'second call is a no-op (at-most-once)');

        $this->assertCount(1, Mail::sent(FirstInvoiceIssuedMail::class));
        Mail::assertSent(FirstInvoiceIssuedMail::class, fn ($m) => $m->hasTo($tenant->owner_email));
    }

    public function test_proof_received_notifies_the_reviewer(): void
    {
        Mail::fake();
        [, , $invoice] = $this->scenario();
        $payment = $this->payment($invoice, 'pending');

        app(BillingNotifier::class)->paymentProofReceived($payment);

        Mail::assertSent(PaymentProofReceivedMail::class, fn ($m) => $m->hasTo('reviewer@bingoopos.com'));
    }

    public function test_verified_and_rejected_notify_the_tenant(): void
    {
        Mail::fake();
        [$tenant, , $invoice] = $this->scenario();
        $verified = $this->payment($invoice, 'verified');
        $rejected = $this->payment($invoice, 'rejected');

        app(BillingNotifier::class)->paymentVerified($verified);
        app(BillingNotifier::class)->paymentRejected($rejected, 'wrong amount');

        Mail::assertSent(PaymentVerifiedMail::class, fn ($m) => $m->hasTo($tenant->owner_email));
        Mail::assertSent(PaymentRejectedMail::class, fn ($m) => $m->hasTo($tenant->owner_email));
    }

    public function test_trial_ending_reminder_is_sent_once(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$tenant, $sub] = $this->scenario(trialEndsAt: Carbon::parse('2026-03-03 09:00:00'), withInvoice: false);

        $transitions = app(SubscriptionTrialTransitionService::class);
        $this->assertSame(1, $transitions->notifyTrialsEndingWithin(3));
        $this->assertSame(0, $transitions->notifyTrialsEndingWithin(3), 'no second reminder');

        $this->assertCount(1, Mail::sent(TrialEndingMail::class));
    }

    public function test_activation_emails_the_tenant(): void
    {
        Mail::fake();
        [$tenant, $sub] = $this->scenario(withInvoice: false);
        $sub->update(['status' => 'active', 'current_period_ends_at' => now()->addMonth()]);

        app(BillingNotifier::class)->subscriptionActivated($sub->fresh());

        Mail::assertSent(SubscriptionActivatedMail::class, fn ($m) => $m->hasTo($tenant->owner_email));
    }

    public function test_past_due_sweep_notifies_the_tenant(): void
    {
        Mail::fake();
        [$tenant, $sub] = $this->scenario(withInvoice: false);
        $sub->update(['status' => 'active', 'current_period_ends_at' => now()->subDay()]);

        $result = app(SubscriptionLifecycleService::class)->markExpiredSubscriptionsPastDue();

        $this->assertSame(1, $result['expired']);
        $this->assertSame('past_due', $sub->fresh()->status);
        Mail::assertSent(SubscriptionPastDueMail::class, fn ($m) => $m->hasTo($tenant->owner_email));
    }

    public function test_a_mail_failure_never_rolls_back_or_throws(): void
    {
        // Simulate a dead mail transport: Mail::to(...) throws.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        [, , $invoice] = $this->scenario();

        // Must NOT throw, must still CLAIM the send (billing/accounting side is authoritative).
        $result = app(BillingNotifier::class)->firstInvoiceIssued($invoice);

        $this->assertTrue($result, 'the send was claimed despite the transport failure');
        $this->assertSame(1, DB::connection('master')->table('billing_notification_log')
            ->where('event', 'first_invoice_issued')->where('subject_id', $invoice->id)->count());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Tenant, 1: Subscription, 2: ?SubscriptionInvoice} */
    private function scenario(?Carbon $trialEndsAt = null, bool $withInvoice = true): array
    {
        $plan = Plan::create([
            'code' => 'p'.substr(uniqid(), -6), 'name' => 'Retail', 'price' => 5000,
            'currency_code' => 'PKR', 'billing_period' => 'monthly', 'is_active' => true,
            'is_public' => true, 'is_custom' => false, 'trial_days' => 30, 'display_order' => 0,
            'monthly_price' => 5000, 'yearly_price' => 50000,
        ]);
        $trialEndsAt ??= now()->addDays(30);

        $tenant = Tenant::create([
            'tenant_code' => 't'.substr(uniqid(), -6), 'business_name' => 'Acme',
            'owner_name' => 'Owner', 'owner_email' => 'owner_'.uniqid().'@test.local',
            'currency_code' => 'PKR', 'status' => 'active', 'trial_ends_at' => $trialEndsAt,
        ]);
        TenantDomain::create(['tenant_id' => $tenant->id, 'domain' => $tenant->tenant_code.'.mywebsite.test', 'is_primary' => true, 'status' => 'active']);

        $sub = Subscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'trial',
            'trial_ends_at' => $trialEndsAt, 'current_period_ends_at' => null,
        ]);

        $invoice = $withInvoice
            ? app(\App\Services\Saas\SubscriptionBillingService::class)->ensureFirstInvoice($sub)
            : null;

        return [$tenant, $sub, $invoice];
    }

    private function payment(SubscriptionInvoice $invoice, string $status): SubscriptionPayment
    {
        return SubscriptionPayment::create([
            'subscription_invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id,
            'payment_method_code' => 'easypaisa', 'amount' => 5000, 'currency_code' => 'PKR',
            'payment_date' => now()->toDateString(), 'status' => $status,
        ]);
    }
}
