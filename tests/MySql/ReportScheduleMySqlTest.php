<?php

namespace Tests\MySql;

use App\Mail\SalesReportMail;
use App\Services\Reports\ReportScheduleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * SALES REPORT CENTER (spec AB) — scheduled-send idempotency: the unique (schedule, period) claim
 * makes a scheduler retry unable to email the owner twice; a genuine failure frees the claim for the
 * next tick and records last_failure; the missing-owner-email case is a controlled failure.
 */
class ReportScheduleMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['report_schedule_runs', 'report_schedules', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'payment_methods', 'products', 'categories', 'terminals', 'branches', 'users']);
        $this->makeBranch(); // timezone anchor
        Mail::fake();
    }

    private function makeSchedule(array $attrs = []): object
    {
        $id = DB::connection('tenant')->table('report_schedules')->insertGetId(array_merge([
            'name' => 'Daily EOD', 'sections' => json_encode(['overview', 'order_types']),
            'frequency' => 'daily', 'send_time' => '08:00', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        return DB::connection('tenant')->table('report_schedules')->find($id);
    }

    private function bindTenant(?string $email): void
    {
        $tenant = new \App\Models\Master\Tenant(['tenant_code' => 'ttest', 'business_name' => 'Test Biz', 'owner_email' => $email]);
        app()->instance('tenant', $tenant);
    }

    public function test_due_schedule_sends_once_and_retries_are_no_ops(): void
    {
        $this->bindTenant('owner@example.test');
        $svc = app(ReportScheduleService::class);
        $schedule = $this->makeSchedule();
        $at = Carbon::parse('2026-08-09 08:30', $svc->timezone());

        $this->assertSame('sent', $svc->runDue($schedule, $at));
        // scheduler retry / overlapping tick — the SAME period can never send twice.
        $this->assertSame('skipped_already_sent', $svc->runDue($schedule, $at));
        $this->assertSame('skipped_already_sent', $svc->runDue($schedule, $at->copy()->addMinutes(45)));
        Mail::assertSent(SalesReportMail::class, 1);

        $runs = DB::connection('tenant')->table('report_schedule_runs')->get();
        $this->assertCount(1, $runs);
        $this->assertSame('2026-08-08', $runs[0]->period_key, 'daily covers the PREVIOUS business day');
        $this->assertNotNull(DB::connection('tenant')->table('report_schedules')->value('last_success_at'));

        // the NEXT day is a new period → sends again exactly once.
        $nextDay = $at->copy()->addDay();
        $this->assertSame('sent', $svc->runDue(DB::connection('tenant')->table('report_schedules')->first(), $nextDay));
        Mail::assertSent(SalesReportMail::class, 2);
    }

    public function test_not_due_before_send_time_and_weekly_day_respected(): void
    {
        $this->bindTenant('owner@example.test');
        $svc = app(ReportScheduleService::class);

        $daily = $this->makeSchedule();
        $this->assertSame('skipped_not_due', $svc->runDue($daily, Carbon::parse('2026-08-09 07:59', $svc->timezone())));

        $weekly = $this->makeSchedule(['name' => 'Weekly', 'frequency' => 'weekly', 'weekday' => 1]); // Monday
        $sunday = Carbon::parse('2026-08-09 09:00', $svc->timezone()); // a Sunday
        $this->assertSame('skipped_not_due', $svc->runDue($weekly, $sunday));
        $monday = Carbon::parse('2026-08-10 09:00', $svc->timezone());
        $this->assertSame('sent', $svc->runDue($weekly, $monday));
        $this->assertSame('skipped_already_sent', $svc->runDue(DB::connection('tenant')->table('report_schedules')->where('name', 'Weekly')->first(), $monday->copy()->addHour()));
    }

    public function test_new_daily_schedule_created_after_send_time_waits_until_tomorrow(): void
    {
        $this->bindTenant('owner@example.test');
        $svc = app(ReportScheduleService::class);
        $created = Carbon::parse('2026-08-26 20:00', $svc->timezone());
        $schedule = $this->makeSchedule([
            'send_time' => '00:30',
            'created_at' => $created->copy()->utc()->toDateTimeString(),
        ]);

        $this->assertSame('skipped_not_due', $svc->runDue($schedule, $created->copy()->addMinutes(15)));
        Mail::assertNothingSent();

        $tomorrow = Carbon::parse('2026-08-27 00:30', $svc->timezone());
        $this->assertSame('sent', $svc->runDue($schedule, $tomorrow));
        $this->assertSame('2026-08-26', DB::connection('tenant')->table('report_schedule_runs')->value('period_key'));
    }

    public function test_missing_owner_email_is_a_controlled_failure_that_frees_the_claim(): void
    {
        $this->bindTenant(null);
        $svc = app(ReportScheduleService::class);
        $schedule = $this->makeSchedule();
        $at = Carbon::parse('2026-08-09 09:00', $svc->timezone());

        $this->assertSame('failed', $svc->runDue($schedule, $at));
        Mail::assertNothingSent();
        $this->assertSame(0, DB::connection('tenant')->table('report_schedule_runs')->count(), 'a failed send frees the period claim for the next tick');
        $this->assertStringContainsString('owner_email', (string) DB::connection('tenant')->table('report_schedules')->value('last_failure'));

        // once the email is configured, the SAME period sends.
        $this->bindTenant('owner@example.test');
        $this->assertSame('sent', $svc->runDue(DB::connection('tenant')->table('report_schedules')->first(), $at));
        Mail::assertSent(SalesReportMail::class, 1);
    }

    public function test_daily_a4_uses_previous_day_and_sends_to_all_explicit_recipients(): void
    {
        $this->bindTenant('fallback@example.test');
        $schedule = $this->makeSchedule([
            'sections' => json_encode(['overview', 'categories', 'cash_bank']),
            'recipient_emails' => json_encode(['kashfgulzar@gmail.com', 'uit.mohsin95@gmail.com']),
            'delivery_format' => 'a4_pdf',
            'send_time' => '00:30',
            // Deterministic: created the day BEFORE the fixed send moment, else the back-send guard
            // (schedule created same-day-after-send_time) correctly skips it — which made this test
            // pass or fail depending on the real calendar date it ran on.
            'created_at' => '2026-08-26 00:00:00',
        ]);
        $svc = app(ReportScheduleService::class);

        $this->assertSame('sent', $svc->runDue($schedule, Carbon::parse('2026-08-27 00:30', $svc->timezone())));
        $this->assertSame('2026-08-26', DB::connection('tenant')->table('report_schedule_runs')->value('period_key'));
        Mail::assertSent(SalesReportMail::class, function (SalesReportMail $mail) {
            return $mail->hasTo('kashfgulzar@gmail.com')
                && $mail->hasTo('uit.mohsin95@gmail.com')
                && $mail->pdfFilename === 'sales-report-2026-08-26.pdf'
                && str_starts_with((string) $mail->pdfContent, '%PDF-');
        });
    }
}
