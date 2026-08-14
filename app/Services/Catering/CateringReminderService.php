<?php

namespace App\Services\Catering;

use App\Mail\Catering\CateringCustomerMail;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringEventReminder;
use App\Models\Tenant\CateringSetting;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-SLICE-3: upcoming-event reminders (spec §12).
 *
 * Offsets: d7 / d3 / d1 / same_day relative to event_date, configured per
 * tenant in catering_settings. Idempotency: the unique
 * (catering_event_id, reminder_key) row is claimed via insertOrIgnore before
 * sending — the report_schedule_runs pattern. Recipients: the internal
 * reminder address from settings, plus the customer for same-day/d1
 * courtesy reminders via CateringMailService (its own idempotency claim).
 */
class CateringReminderService
{
    private const OFFSET_DAYS = ['d7' => 7, 'd3' => 3, 'd1' => 1, 'same_day' => 0];

    public function __construct(
        private readonly CateringMailService $mail,
    ) {}

    /** @return array{sent: int, skipped: int} */
    public function dispatchDue(): array
    {
        $settings = CateringSetting::tenantDefault();
        $offsets = array_intersect(
            $settings->reminder_offsets ?: CateringSetting::DEFAULT_REMINDER_OFFSETS,
            array_keys(self::OFFSET_DAYS)
        );

        $sent = 0;
        $skipped = 0;
        $today = now()->startOfDay();

        $events = CateringEvent::query()
            ->whereNotIn('status', [CateringEvent::STATUS_CANCELLED, CateringEvent::STATUS_CLOSED, CateringEvent::STATUS_COMPLETED])
            ->whereDate('event_date', '>=', $today)
            ->whereDate('event_date', '<=', $today->copy()->addDays(8))
            ->get();

        foreach ($events as $event) {
            foreach ($offsets as $key) {
                $dueDate = $event->event_date->copy()->subDays(self::OFFSET_DAYS[$key]);
                if (! $dueDate->isSameDay($today)) {
                    continue;
                }

                $claimed = DB::connection('tenant')->table('catering_event_reminders')->insertOrIgnore([
                    'catering_event_id' => $event->id,
                    'reminder_key' => $key,
                    'due_date' => $dueDate->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($claimed === 0) {
                    $skipped++;

                    continue;
                }

                $recipient = $settings->reminder_recipient_email;
                if ($recipient) {
                    $this->mail->send(
                        CateringCustomerMail::TYPE_EVENT_REMINDER,
                        $event,
                        $event->currentEstimate,
                        ['reminder_key' => $key],
                        'reminder-'.$key.'-internal',
                        $recipient,
                    );
                }

                // Customer courtesy reminder close to the event only.
                if (in_array($key, ['d1', 'same_day'], true) && $event->customer_email) {
                    $this->mail->send(
                        CateringCustomerMail::TYPE_EVENT_REMINDER,
                        $event,
                        $event->currentEstimate,
                        ['reminder_key' => $key],
                        'reminder-'.$key,
                    );
                }

                CateringEventReminder::query()
                    ->where('catering_event_id', $event->id)
                    ->where('reminder_key', $key)
                    ->update(['sent_at' => now(), 'sent_to' => $recipient]);

                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
