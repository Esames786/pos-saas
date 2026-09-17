<?php

namespace App\Services\Catering;

use App\Jobs\Catering\SendCateringCustomerMailJob;
use App\Models\Tenant\CateringEmailLog;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringSetting;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * CATERING-SLICE-3: customer email dispatch with claim-before-send
 * idempotency (the report_schedule_runs pattern): the unique
 * (event, email_type, dedupe_key) row is claimed via insertOrIgnore BEFORE
 * sending; 0 rows inserted ⇒ already sent ⇒ skip. A hard failure clears the
 * claim so the next attempt can retry. Recipient comes from the event —
 * never hardcoded (spec §11). EMAIL ONLY in V1; no WhatsApp/SMS.
 */
class CateringMailService
{
    /** @return string 'sent' | 'skipped_already_sent' | 'skipped_no_recipient' | 'failed' */
    public function send(
        string $emailType,
        CateringEvent $event,
        ?CateringEstimate $estimate = null,
        array $context = [],
        ?string $dedupeKey = null,
        ?string $recipientOverride = null,
    ): string {
        $recipient = $recipientOverride ?: $event->customer_email;
        if (empty($recipient)) {
            return 'skipped_no_recipient';
        }

        // CATERING-EMAIL-SWITCH-1 — the tenant's own answer to "should we
        // write to customers at all?".
        //
        // Checked AFTER the recipient and BEFORE the claim row, so switching
        // emails off leaves no trace in catering_email_logs: nothing was
        // attempted, so nothing should look attempted. Switch it back on and
        // the next quotation sends normally, because no claim is standing in
        // the way.
        //
        // A recipientOverride is a DELIBERATE act — the Email to Customer
        // button, someone choosing to send this one now — and it is honoured
        // even when the automatic emails are off. The switch governs what the
        // system does on its own, not what a person asks it to do.
        $emailsOn = CateringSetting::tenantDefault()->send_customer_emails ?? true;

        if ($recipientOverride === null && ! $emailsOn) {
            return 'skipped_disabled';
        }

        $dedupeKey = $dedupeKey ?? ($estimate ? 'q'.$estimate->version_no : 'event');

        $claimed = DB::connection('tenant')->table('catering_email_logs')->insertOrIgnore([
            'catering_event_id' => $event->id,
            'email_type' => $emailType,
            'dedupe_key' => $dedupeKey,
            'recipient' => $recipient,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            return 'skipped_already_sent';
        }

        $businessName = $this->businessName();

        // CATERING-MAIL-QUEUE-1 — QUEUED, never sent from the request.
        //
        // This used to be `Mail::to($recipient)->send(...)`: a whole SMTP
        // conversation between the operator pressing a button and the page
        // coming back. On the live trial (2026-09-17) recording an advance
        // spun forever and the advance turned out to have saved — both true,
        // because the money commits in its own transaction and the mail ran
        // afterwards inside the same request. The customer's own domain sent
        // fine; an external address did not, and nothing timed out quickly
        // enough for anyone to notice which.
        //
        // Operators must never wait on someone else's mail server. The claim
        // row above is still taken HERE, synchronously, so two operators
        // cannot both queue the same email; the job marks it sent, or deletes
        // it so a later attempt is possible.
        // The job carries a tenant id because the worker serves every tenant
        // and must be told which database to open. A console context —
        // the reminder scheduler, a test — has no tenant bound in the
        // container, and queueing without one would strand the job. There,
        // the old inline send is still exactly right: nobody is watching a
        // spinner, and the caller has already chosen its own tenant.
        $tenantId = app()->bound('tenantId') ? (int) app('tenantId') : null;

        try {
            if ($tenantId === null) {
                $estimate?->loadMissing('lines');
                \Illuminate\Support\Facades\Mail::to($recipient)->send(
                    new \App\Mail\Catering\CateringCustomerMail($emailType, $businessName, $event, $estimate, $context)
                );

                CateringEmailLog::query()
                    ->where('catering_event_id', $event->id)
                    ->where('email_type', $emailType)
                    ->where('dedupe_key', $dedupeKey)
                    ->update(['sent_at' => now(), 'error' => null]);

                return 'sent';
            }

            SendCateringCustomerMailJob::dispatch(
                $tenantId,
                $emailType,
                $event->id,
                $estimate?->id,
                $context,
                $dedupeKey,
                $recipient,
                $businessName,
            );

            return 'queued';
        } catch (Throwable $e) {
            report($e);
            // Could not even reach the queue. Free the claim exactly as the
            // direct send used to, so nothing is left blocking a retry.
            CateringEmailLog::query()
                ->where('catering_event_id', $event->id)
                ->where('email_type', $emailType)
                ->where('dedupe_key', $dedupeKey)
                ->whereNull('sent_at')
                ->delete();

            return 'failed';
        }
    }

    private function businessName(): string
    {
        try {
            return app('tenant')->business_name ?? config('saas.brand_name', 'Bingoo');
        } catch (Throwable) {
            return config('saas.brand_name', 'Bingoo');
        }
    }
}
