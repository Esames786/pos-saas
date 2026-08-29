<?php

namespace App\Services\Reports;

use App\Mail\SalesReportMail;
use App\Support\TenantClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
        private readonly SalesReportExporter $exporter,
        private readonly SalesReportDocumentService $document,
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
     */
    public function runDue(object $schedule, ?Carbon $nowTz = null): string
    {
        $nowTz = $nowTz ?: now($this->timezone());
        if (! $this->isDue($schedule, $nowTz)) {
            return 'skipped_not_due';
        }
        $period = $this->period($schedule, $nowTz);

        $claimed = DB::connection('tenant')->table('report_schedule_runs')->insertOrIgnore([
            'report_schedule_id' => $schedule->id,
            'period_key' => $period['key'],
            'status' => 'sent',
            'created_at' => now(),
        ]);
        if ($claimed === 0) {
            return 'skipped_already_sent'; // unique(schedule, period) — retry can never double-send
        }

        try {
            $recipients = $this->recipients($schedule);
            $filters = $this->engine->normalizeFilters(['date_from' => $period['from'], 'date_to' => $period['to']]);
            $selected = (array) json_decode($schedule->sections, true);
            $business = app()->bound('tenant') ? (string) app('tenant')->business_name : 'Bingoo POS';
            $label = $period['from'].' to '.$period['to'];

            if (($schedule->delivery_format ?? 'csv') === 'a4_pdf') {
                $mail = new SalesReportMail(
                    $business,
                    $label,
                    [],
                    $this->document->pdf($filters, $selected),
                    'sales-report-'.$period['from'].'.pdf',
                    $selected,
                );
            } else {
                $mail = new SalesReportMail($business, $label, $this->exporter->sections($filters, $selected));
            }
            Mail::to($recipients)->send($mail);

            DB::connection('tenant')->table('report_schedules')->where('id', $schedule->id)
                ->update(['last_run_at' => now(), 'last_success_at' => now(), 'last_failure' => null, 'updated_at' => now()]);

            return 'sent';
        } catch (\Throwable $e) {
            // free the period claim so the NEXT tick retries; record the failure.
            DB::connection('tenant')->table('report_schedule_runs')
                ->where('report_schedule_id', $schedule->id)->where('period_key', $period['key'])->delete();
            DB::connection('tenant')->table('report_schedules')->where('id', $schedule->id)
                ->update(['last_run_at' => now(), 'last_failure' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);

            return 'failed';
        }
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
