<?php

namespace App\Services\Catering;

use App\Mail\Catering\CateringCustomerMail;
use App\Models\Tenant\CateringEmailLog;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

        try {
            $estimate?->loadMissing('lines');
            Mail::to($recipient)->send(new CateringCustomerMail($emailType, $businessName, $event, $estimate, $context));

            CateringEmailLog::query()
                ->where('catering_event_id', $event->id)
                ->where('email_type', $emailType)
                ->where('dedupe_key', $dedupeKey)
                ->update(['sent_at' => now(), 'error' => null]);

            return 'sent';
        } catch (Throwable $e) {
            report($e);
            // Free the claim so a later retry can attempt the send again.
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
