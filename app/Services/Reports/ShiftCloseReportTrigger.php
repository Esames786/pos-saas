<?php

namespace App\Services\Reports;

use App\Models\Tenant\Shift;
use App\Services\Tenancy\TenancyManager;
use App\Support\TenantFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-DELIVERY-V2-1 — send the daily report the moment the day's last shift closes.
 *
 * Why: Kashif Food's last counter closes anywhere between 01:17 and 02:58 PKT, and its report left at a
 * fixed 02:30 — on the late nights the owner read a report missing the last half hour of the day. The
 * day is only final when the last drawer is closed, so that is when the report should leave.
 *
 * What it does NOT do, on purpose:
 *  - It never blocks or fails a shift close. Both close paths call afterClose() after their transaction
 *    has committed, and everything here is caught and logged. The report is sent after the response has
 *    gone back to the cashier (Laravel's terminating hook — PHP-FPM finishes the request first), so
 *    closing a shift is exactly as fast as before.
 *  - It does not use the queue. The `jobs` table lives where the tenant's default connection points, and
 *    on Kashif Kitchen that is how catering mails ended up never queued at all.
 *  - It never double-sends. It claims the same per-channel period as the timed run
 *    (ReportScheduleService::runForBusinessDay), so the schedule's send_time — kept as a safety net for a
 *    day whose shifts are never closed — finds the day already sent and skips.
 *
 * It acts only for a tenant whose "New daily report delivery" switch is on in Bingoo Master AND whose
 * daily schedule says send_trigger = 'shift_close'. Every other tenant is untouched.
 */
final class ShiftCloseReportTrigger
{
    public function __construct(private readonly ReportScheduleService $schedules) {}

    /**
     * Called by both close paths once their transaction has committed. Never throws.
     *
     * @param iterable<Shift> $closedShifts
     */
    public function afterClose(iterable $closedShifts): void
    {
        try {
            if (! app()->bound('tenant') || ! TenantFeatures::on(TenantFeatures::REPORT_DELIVERY_V2)) {
                return;
            }

            $dates = [];
            foreach ($closedShifts as $shift) {
                $date = $shift->business_date?->toDateString();
                if ($date) {
                    $dates[$date] = true;
                }
            }
            if ($dates === [] || $this->schedulesWaitingForShiftClose() === []) {
                return;
            }

            $tenant = app('tenant');
            $dates = array_keys($dates);

            app()->terminating(function () use ($tenant, $dates) {
                try {
                    // The cashier already has the response; give the send room to finish. The nightly
                    // send takes ~15 s for Kashif Food (PDF + seven WhatsApp numbers). Only RAISE a limit
                    // that exists: under CLI (artisan, tests) there is none, and arming one would put a
                    // 3-minute kill on the whole process — the same trap SystemUsageController avoids.
                    $limit = (int) ini_get('max_execution_time');
                    if ($limit > 0 && $limit < 180) {
                        @set_time_limit(180);
                    }
                    @ignore_user_abort(true);

                    if (! app()->bound('tenant') || (int) app('tenant')->id !== (int) $tenant->id) {
                        app(TenancyManager::class)->activate($tenant);
                    }

                    $this->sendFor($dates);
                } catch (\Throwable $e) {
                    Log::warning('Shift-close report could not be sent; the timed safety net will retry.', [
                        'tenant' => $tenant->tenant_code, 'dates' => $dates, 'error' => $e->getMessage(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Shift-close report trigger skipped.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send each business day whose shifts are now ALL closed. Returns what happened per day, per
     * schedule, for the log and the tests.
     *
     * @param list<string> $dates
     * @return array<string, array<int, string>|string>
     */
    public function sendFor(array $dates): array
    {
        $outcome = [];
        $schedules = $this->schedulesWaitingForShiftClose();

        foreach ($dates as $date) {
            // "The last shift of the day": no shift of this business day is still open anywhere in
            // the tenant. The report covers the whole tenant, so one open counter means the day is
            // not over yet — its own close will try again.
            $stillOpen = Shift::query()->where('status', 'open')->whereDate('business_date', $date)->exists();
            if ($stillOpen) {
                $outcome[$date] = 'shifts_still_open';

                continue;
            }

            foreach ($schedules as $schedule) {
                $outcome[$date][(int) $schedule->id] = $this->schedules->runForBusinessDay($schedule, $date, 'shift_close');
            }
        }

        return $outcome;
    }

    /** @return list<object> */
    private function schedulesWaitingForShiftClose(): array
    {
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable('report_schedules') || ! $schema->hasColumn('report_schedules', 'send_trigger')) {
            return [];
        }

        return DB::connection('tenant')->table('report_schedules')
            ->where('is_active', true)
            ->where('frequency', 'daily')
            ->where('send_trigger', 'shift_close')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
