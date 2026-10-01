<?php

namespace App\Services\Reports;

use App\Services\Reports\Delivery\ReportDelivery;
use App\Services\Reports\Delivery\ReportDispatcher;
use App\Support\TenantClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SALES REPORT CENTER (spec AA/AB) — scheduled owner reports, Cloud-only, EMAIL-only, idempotent.
 *
 * The reporting period is the schedule's cadence period that ENDED most recently before the send
 * moment (daily 08:00 sends YESTERDAY's-completed business day = the day that just closed at the
 * send time — concretely the CURRENT business date at send time minus one cadence isn't intuitive
 * for restaurants, so the contract is: the period is the business day/week/month CONTAINING
 * (send moment − 1 minute) shifted back one step; i.e. daily sends cover the PREVIOUS business day).
 * Idempotency = report_schedule_runs unique (schedule, period_key): a scheduler retry cannot email
 * the owner twice for the same period. Recipient = tenants.owner_email (controlled error if absent).
 */
class ReportScheduleService
{
    public function __construct(
        private readonly SalesReportEngine $engine,
        private readonly ReportDispatcher $dispatcher,
        private readonly TenantClock $clock,
    ) {}

    /** The branch-anchored timezone used for all schedule time math (no tenant-level tz exists). */
    public function timezone(): string
    {
        $branch = \App\Models\Tenant\Branch::query()->where('status', 'active')->orderBy('id')->first();

        return $this->clock->businessTimezone($branch);
    }

    /** TRUE when the schedule is due at $now (tenant tz) AND its period has not been sent yet. */
    public function isDue(object $schedule, Carbon $nowTz): bool
    {
        if (! $schedule->is_active) {
            return false;
        }
        [$h, $m] = array_map('intval', explode(':', $schedule->send_time));
        if ($nowTz->hour < $h || ($nowTz->hour === $h && $nowTz->minute < $m)) {
            return false; // send time not reached today
        }
        // A schedule created after today's send time starts tomorrow. Without this guard, creating
        // a new 00:30 schedule in the afternoon would immediately back-send the day before the
        // operator intended, then send again at the next midnight boundary.
        if (! empty($schedule->created_at)) {
            $createdTz = Carbon::parse($schedule->created_at, config('app.timezone'))->setTimezone($nowTz->getTimezone());
            if ($createdTz->isSameDay($nowTz) && $createdTz->format('H:i') > $schedule->send_time) {
                return false;
            }
        }

        return match ($schedule->frequency) {
            'daily' => true,
            'weekly' => (int) $nowTz->isoWeekday() === (int) $schedule->weekday,
            'monthly' => (int) $nowTz->day === min((int) $schedule->day_of_month, (int) $nowTz->endOfMonth()->day), // month-end-safe
            default => false,
        };
    }

    /** The period covered by a send at $nowTz (the PREVIOUS completed cadence period). */
    public function period(object $schedule, Carbon $nowTz): array
    {
        return match ($schedule->frequency) {
            'daily' => [
                'from' => $nowTz->copy()->subDay()->toDateString(),
                'to' => $nowTz->copy()->subDay()->toDateString(),
                'key' => $nowTz->copy()->subDay()->toDateString(),
            ],
            'weekly' => [
                'from' => $nowTz->copy()->subWeek()->startOfWeek()->toDateString(),
                'to' => $nowTz->copy()->subWeek()->endOfWeek()->toDateString(),
                'key' => $nowTz->copy()->subWeek()->format('o\WW'),
            ],
            'monthly' => [
                'from' => $nowTz->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'to' => $nowTz->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                'key' => $nowTz->copy()->subMonthNoOverflow()->format('Y-m'),
            ],
            default => throw new RuntimeException('Unknown schedule frequency.'),
        };
    }

    /**
     * Run one schedule if due. Returns 'sent' | 'skipped_not_due' | 'skipped_already_sent' | 'failed'.
     * IDEMPOTENT: the unique run row is claimed BEFORE the send — a concurrent/retried dispatcher
     * loses the insert and skips; a genuine send failure frees the claim for the next tick.
     *
     * WHATSAPP-REPORT-CHANNEL-1 — the claim is now PER CHANNEL, and this is the whole reason the
     * method was split. The old shape claimed (schedule, period) once for the send as a whole. Add a
     * second channel to that and the first failure takes the other channel down with it: email goes
     * out, WhatsApp throws, the catch frees the shared claim, and the next tick sends the EMAIL again.
     * The owner would get two copies a day while `last_failure` talked about WhatsApp — two facts
     * almost nobody would connect.
     *
     * So each channel claims its own period and frees only its own. The return value keeps its old
     * four-way contract for the dispatcher command: any failure reports 'failed' so the tick is
     * retried, and a retry then skips whatever already went out.
     */
    public function runDue(object $schedule, ?Carbon $nowTz = null): string
    {
        $nowTz = $nowTz ?: now($this->timezone());
        if (! $this->isDue($schedule, $nowTz)) {
            return 'skipped_not_due';
        }
        $period = $this->period($schedule, $nowTz);

        $results = [];
        foreach ($this->channelsFor($schedule) as $channel) {
            $results[] = $this->runChannel($schedule, $period, $channel);
        }

        if (in_array('failed', $results, true)) {
            return 'failed';
        }

        return in_array('sent', $results, true) ? 'sent' : 'skipped_already_sent';
    }

    /**
     * Which channels this schedule delivers on.
     *
     * Only email exists today, so this returns exactly what the service has always done. The branch /
     * tenant `report_channels` settings are read here when the WhatsApp channel lands; keeping that
     * out of this step is deliberate, so the per-channel claim can be proven on its own against
     * behaviour that has not moved.
     *
     * Protected, not private: this and deliver() are the two seams the WhatsApp channel replaces in
     * step 3, and they are what a test substitutes to exercise two channels before one exists.
     *
     * @return list<string>
     */
    protected function channelsFor(object $schedule): array
    {
        // A schedule has no branch — report_schedules carries no branch_id, so the nightly report
        // covers the whole tenant. NULL asks the dispatcher for the tenant-level answer.
        return $this->dispatcher->channelsFor(null);
    }

    /** Claim, send and record ONE channel. Returns 'sent' | 'skipped_already_sent' | 'failed'. */
    private function runChannel(object $schedule, array $period, string $channel): string
    {
        $claimed = DB::connection('tenant')->table('report_schedule_runs')->insertOrIgnore([
            'report_schedule_id' => $schedule->id,
            'period_key' => $period['key'],
            'channel' => $channel,
            'status' => 'sent',
            'created_at' => now(),
        ]);
        if ($claimed === 0) {
            return 'skipped_already_sent'; // unique(schedule, period, channel) — retry can never double-send
        }

        try {
            $this->deliver($schedule, $period, $channel);

            $update = ['last_run_at' => now(), 'last_success_at' => now(), 'updated_at' => now()];

            // Clear the failure only if it is OURS. Blanket-clearing here would erase a sibling
            // channel's failure the moment this one succeeded, and the schedule would look healthy
            // while half its delivery was silently broken.
            $current = (string) DB::connection('tenant')->table('report_schedules')
                ->where('id', $schedule->id)->value('last_failure');
            if ($current === '' || str_starts_with($current, $channel.':')) {
                $update['last_failure'] = null;
            }

            DB::connection('tenant')->table('report_schedules')->where('id', $schedule->id)->update($update);

            return 'sent';
        } catch (\Throwable $e) {
            // free THIS channel's claim so the next tick retries it — and only it.
            DB::connection('tenant')->table('report_schedule_runs')
                ->where('report_schedule_id', $schedule->id)
                ->where('period_key', $period['key'])
                ->where('channel', $channel)
                ->delete();

            // Name the channel in the failure. Without it "the report failed" and "the report arrived
            // twice" read as unrelated complaints.
            DB::connection('tenant')->table('report_schedules')->where('id', $schedule->id)
                ->update([
                    'last_run_at' => now(),
                    'last_failure' => mb_substr($channel.': '.$e->getMessage(), 0, 500),
                    'updated_at' => now(),
                ]);

            return 'failed';
        }
    }

    /** Build the report and hand it to the channel that will carry it. */
    protected function deliver(object $schedule, array $period, string $channel): void
    {
        $delivery = new ReportDelivery(
            businessName: app()->bound('tenant') ? (string) app('tenant')->business_name : 'Bingoo POS',
            label: $period['from'].' to '.$period['to'],
            filters: $this->engine->normalizeFilters(['date_from' => $period['from'], 'date_to' => $period['to']]),
            sections: (array) json_decode($schedule->sections, true),
            format: (string) ($schedule->delivery_format ?? 'csv'),
            fileName: 'sales-report-'.$period['from'].'.pdf',
        );

        $this->dispatcher->send($channel, $delivery, $this->recipientsFor($channel, $schedule));
    }

    /**
     * Who this channel addresses.
     *
     * Email keeps the schedule's OWN recipient list — that is what it has always used, and moving it
     * to a tenant-wide setting would silently change who gets the nightly report.
     *
     * @return list<string>
     */
    private function recipientsFor(string $channel, object $schedule): array
    {
        if ($channel === 'email') {
            return $this->recipients($schedule);
        }

        // WhatsApp numbers are a TENANT setting, not the schedule's. A schedule carries no
        // branch_id, so the nightly report has no branch to ask.
        if ($channel === 'whatsapp') {
            return $this->dispatcher->whatsappRecipients(null);
        }

        return [];
    }

    /** @return list<string> */
    private function recipients(object $schedule): array
    {
        $configured = property_exists($schedule, 'recipient_emails')
            ? (array) json_decode((string) ($schedule->recipient_emails ?? '[]'), true)
            : [];
        $fallback = app()->bound('tenant') ? app('tenant')?->owner_email : null;
        $emails = array_values(array_unique(array_filter(
            array_map(fn ($email) => strtolower(trim((string) $email)), $configured ?: [$fallback]),
            fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));

        if ($emails === []) {
            throw new RuntimeException('No valid scheduled-report recipient email is configured (owner_email fallback is also empty).');
        }

        return $emails;
    }
}
