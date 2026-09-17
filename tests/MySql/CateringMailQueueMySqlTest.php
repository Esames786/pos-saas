<?php

namespace Tests\MySql;

use App\Jobs\Catering\SendCateringCustomerMailJob;
use App\Mail\Catering\CateringCustomerMail;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringMailService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-MAIL-QUEUE-1 — an operator never waits on somebody else's mail
 * server.
 *
 * Reported from the live trial on 2026-09-17: Record Advance spun forever, and
 * refreshing showed the advance had saved. Both were true. The money commits in
 * its own transaction, and the controller then sent the customer email with
 * Mail::to()->send() — a whole SMTP conversation inside the HTTP request.
 *
 * The proof was in catering_email_logs: rows to an external address with
 * sent_at NULL and error NULL. Claimed, never finished, and no error recorded
 * because the request died before the catch block could run — which ALSO meant
 * the claim was never freed, so that email could never be attempted again.
 */
class CateringMailQueueMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            // catering_settings MUST be cleaned: the switch this file toggles
            // lives there, and it is a per-tenant singleton. Left behind, a test
            // that switches emails off silently disables them for every test
            // after it - and for the next RUN of this file too.
            'catering_settings',
            'catering_email_logs', 'catering_estimate_lines', 'catering_estimates',
            'catering_events', 'products', 'categories', 'customers', 'branches',
        ]);
    }

    /**
     * With a tenant bound — which is every web request — the mail is QUEUED and
     * the request does not touch SMTP.
     */
    public function test_a_request_queues_the_mail_instead_of_sending_it(): void
    {
        Bus::fake();
        $event = $this->eventWithEmail();

        // What IdentifyTenant does on every request.
        app()->instance('tenantId', 7);

        $result = app(CateringMailService::class)
            ->send(CateringCustomerMail::TYPE_ADVANCE_RECEIVED, $event, null, ['advance_amount' => 5000]);

        $this->assertSame('queued', $result, 'the request hands the mail to the queue and returns');

        Bus::assertDispatched(SendCateringCustomerMailJob::class,
            fn (SendCateringCustomerMailJob $job) => $job->tenantId === 7
                && $job->eventId === $event->id
                && $job->recipient === 'diner@example.test');

        // Nothing went near a mail server on this request.
        Mail::assertNothingSent();

        app()->forgetInstance('tenantId');
    }

    /**
     * The claim is still taken synchronously, so two operators pressing at once
     * cannot queue the same email twice.
     */
    public function test_the_claim_is_taken_before_queueing_so_it_cannot_double_send(): void
    {
        Bus::fake();
        $event = $this->eventWithEmail();
        app()->instance('tenantId', 7);

        $mail = app(CateringMailService::class);
        $first = $mail->send(CateringCustomerMail::TYPE_ADVANCE_RECEIVED, $event, null, [], 'advance-1');
        $second = $mail->send(CateringCustomerMail::TYPE_ADVANCE_RECEIVED, $event, null, [], 'advance-1');

        $this->assertSame('queued', $first);
        $this->assertSame('skipped_already_sent', $second);
        Bus::assertDispatchedTimes(SendCateringCustomerMailJob::class, 1);

        app()->forgetInstance('tenantId');
    }

    /**
     * A console context — the reminder scheduler — has no tenant bound, and a
     * job with no tenant id would strand on a worker that cannot know which
     * database to open. There the old inline send is still exactly right:
     * nobody is watching a spinner, and the caller chose its own tenant.
     */
    public function test_a_console_context_still_sends_inline(): void
    {
        Bus::fake();
        $event = $this->eventWithEmail();

        $this->assertFalse(app()->bound('tenantId'), 'no tenant is bound here, as in the scheduler');

        $result = app(CateringMailService::class)
            ->send(CateringCustomerMail::TYPE_EVENT_REMINDER, $event, null);

        $this->assertSame('sent', $result);
        Mail::assertSent(CateringCustomerMail::class, 1);
        Bus::assertNotDispatched(SendCateringCustomerMailJob::class);
    }

    /** No email address is still the cheapest possible answer. */
    public function test_no_recipient_costs_nothing(): void
    {
        Bus::fake();
        $event = $this->eventWithEmail(null);
        app()->instance('tenantId', 7);

        $this->assertSame('skipped_no_recipient', app(CateringMailService::class)
            ->send(CateringCustomerMail::TYPE_ADVANCE_RECEIVED, $event, null));

        Bus::assertNothingDispatched();
        Mail::assertNothingSent();

        app()->forgetInstance('tenantId');
    }

    // ── CATERING-EMAIL-SWITCH-1 ────────────────────────────────────────────

    /** Off means nobody is written to, and nothing is queued. */
    public function test_the_switch_off_stops_customer_emails(): void
    {
        Bus::fake();
        $event = $this->eventWithEmail();
        app()->instance('tenantId', 7);

        \App\Models\Tenant\CateringSetting::tenantDefault()->update(['send_customer_emails' => false]);

        $this->assertSame('skipped_disabled', app(CateringMailService::class)
            ->send(CateringCustomerMail::TYPE_ADVANCE_RECEIVED, $event, null));

        Bus::assertNothingDispatched();
        Mail::assertNothingSent();

        app()->forgetInstance('tenantId');
    }

    /**
     * And it leaves NO TRACE. The claim row is what stops a second send; if
     * switching emails off wrote one, switching them back on would find the
     * door already claimed and that customer would never be written to again.
     * This is the same trap the live tenant was found in for a different reason.
     */
    public function test_switching_off_leaves_no_claim_blocking_a_later_send(): void
    {
        Bus::fake();
        $event = $this->eventWithEmail();
        app()->instance('tenantId', 7);
        $settings = \App\Models\Tenant\CateringSetting::tenantDefault();

        $settings->update(['send_customer_emails' => false]);
        app(CateringMailService::class)->send(CateringCustomerMail::TYPE_ADVANCE_RECEIVED, $event, null, [], 'adv-1');

        $this->assertSame(0, DB::connection('tenant')->table('catering_email_logs')->count(),
            'a switched-off email was never attempted, so nothing should look attempted');

        // Switched back on, the very same email goes.
        $settings->update(['send_customer_emails' => true]);
        $this->assertSame('queued', app(CateringMailService::class)
            ->send(CateringCustomerMail::TYPE_ADVANCE_RECEIVED, $event, null, [], 'adv-1'));

        app()->forgetInstance('tenantId');
    }

    /**
     * A person choosing to send one — the Email to Customer button — still
     * sends. The switch governs what the system does on its own, not what
     * somebody deliberately asks it to do.
     */
    public function test_a_deliberate_send_is_still_honoured_when_the_switch_is_off(): void
    {
        Bus::fake();
        $event = $this->eventWithEmail();
        app()->instance('tenantId', 7);

        \App\Models\Tenant\CateringSetting::tenantDefault()->update(['send_customer_emails' => false]);

        $this->assertSame('queued', app(CateringMailService::class)->send(
            CateringCustomerMail::TYPE_QUOTATION_SENT, $event, null, [], null, 'someone@chosen.test'
        ));

        Bus::assertDispatched(SendCateringCustomerMailJob::class);

        app()->forgetInstance('tenantId');
    }

    /** On by default — no live tenant loses its emails to a migration. */
    public function test_customer_emails_are_on_by_default(): void
    {
        $this->assertTrue((bool) \App\Models\Tenant\CateringSetting::tenantDefault()->send_customer_emails);
    }

    private function eventWithEmail(?string $email = 'diner@example.test'): CateringEvent
    {
        $branchId = $this->makeBranch();

        return app(CateringEstimateService::class)->createEvent([
            'branch_id' => $branchId,
            'customer_name' => 'Mail Queue Customer',
            'customer_email' => $email,
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 50,
        ])->refresh();
    }
}
