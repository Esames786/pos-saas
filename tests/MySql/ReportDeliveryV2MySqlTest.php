<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Reports\SalesReportCenterController;
use App\Http\Controllers\Tenant\ShiftController;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Reports\Delivery\ReportDispatcher;
use App\Services\Reports\ReportScheduleService;
use App\Services\Reports\SalesReportEngine;
use App\Services\Reports\ShiftCloseReportTrigger;
use App\Services\Sales\ShiftService;
use App\Support\TenantClock;
use App\Support\TenantFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\ParsesInlineScripts;
use Tests\MySql\Support\TenantFixtures;

/**
 * REPORT-DELIVERY-V2-1 — one "Daily report delivery" card, and the report sent when the day's last
 * shift closes.
 *
 * The promise to the owner was "live hone ke baad kisi ka kaam kharab na ho": a tenant whose Master
 * switch is off keeps both old cards and its fixed-time report; a shift close is never slowed or failed
 * by the report; and the two triggers (last shift closed / safety-net time) can never send a day twice.
 * Every test goes through the real controllers and the real terminating hook.
 */
class ReportDeliveryV2MySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;
    use TenantFixtures;

    private Tenant $tenant;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['report_schedule_runs', 'report_schedules', 'shifts', 'terminals', 'model_has_permissions', 'branches', 'users']);
        view()->share('errors', new ViewErrorBag);
        $this->dropTestTenant();

        $this->tenant = Tenant::create([
            'tenant_code' => 'rdv2test', 'business_name' => 'Test Kitchen', 'owner_email' => 'owner@example.com',
            'status' => 'active', 'report_channels' => ['email', 'whatsapp'], 'report_whatsapp' => ['923001234567', '923211234567'],
            'features' => [TenantFeatures::REPORT_DELIVERY_V2 => true],
        ]);
        app()->instance('tenant', $this->tenant);

        config()->set('services.whatsapp.token', 'test-token');
        config()->set('services.whatsapp.phone_number_id', '100000000000000');
        config()->set('services.whatsapp.template', 'daily_sales_report');
        config()->set('services.whatsapp.language', 'en');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
        Mail::fake();

        $this->branchId = $this->makeBranch(['name' => 'Main']);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant');
        $this->dropTestTenant();
        parent::tearDown();
    }

    private function dropTestTenant(): void
    {
        $m = DB::connection('master');
        $ids = $m->table('tenants')->where('tenant_code', 'rdv2test')->pluck('id');
        $m->table('report_share_links')->whereIn('tenant_id', $ids)->delete();
        $m->table('whatsapp_messages')->whereIn('tenant_id', $ids)->delete();
        $m->table('tenants')->whereIn('id', $ids)->delete();
    }

    private function actAs(array $permissions): User
    {
        $user = User::on('tenant')->find($this->makeUser());
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'tenant');
        }
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::on('tenant')->find($user->id);
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');

        return $user;
    }

    private function owner(): User
    {
        return $this->actAs(array_merge(
            ['tenant.reports.center.index', 'tenant.reports.center.channels', 'tenant.reports.center.schedules.destroy'],
            array_map(fn ($s) => SalesReportCenterController::sectionPermission($s), SalesReportCenterController::SECTIONS),
        ));
    }

    private function page(): string
    {
        return app(SalesReportCenterController::class)->index(Request::create('/reports/center', 'GET'))->render();
    }

    private function save(array $input)
    {
        return app(SalesReportCenterController::class)
            ->saveChannels(Request::create('/reports/center/channels', 'POST', $input + ['v2' => '1']));
    }

    private function schedule(array $attrs = []): int
    {
        return (int) DB::connection('tenant')->table('report_schedules')->insertGetId(array_merge([
            'name' => 'Daily owner report', 'sections' => json_encode(['overview']), 'recipient_emails' => json_encode(['owner@example.com']),
            'delivery_format' => 'csv', 'frequency' => 'daily', 'send_time' => '06:00', 'send_trigger' => 'shift_close',
            'is_active' => true, 'created_at' => now()->subDays(3), 'updated_at' => now(),
        ], $attrs));
    }

    private function openShift(int $userId, string $terminalName): Shift
    {
        $terminal = $this->makeTerminal($this->branchId, ['name' => $terminalName]);

        return app(ShiftService::class)->open(
            Branch::on('tenant')->findOrFail($this->branchId),
            Terminal::on('tenant')->findOrFail($terminal),
            $userId,
            0.0,
        );
    }

    private function closeShift(Shift $shift): void
    {
        $response = app(ShiftController::class)->close(
            Request::create('/shifts/'.$shift->id.'/close', 'POST', ['counted_cash' => '0']),
            Shift::on('tenant')->findOrFail($shift->id),
            app(ShiftService::class),
        );
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('closed', Shift::on('tenant')->find($shift->id)->status, 'the shift itself always closes');

        // The real PHP-FPM order: the response has gone to the cashier, then Laravel runs its
        // terminating callbacks — which is where the report is sent.
        app()->terminate();
        $this->assertSame('0', (string) ini_get('max_execution_time'),
            'the send may raise a web request\'s limit, never arm one on a CLI process (it would kill the run)');
    }

    private function runs(): array
    {
        return DB::connection('tenant')->table('report_schedule_runs')->orderBy('channel')
            ->get(['channel', 'period_key', 'via'])->map(fn ($r) => $r->channel.'|'.$r->period_key.'|'.$r->via)->all();
    }

    // ── the card ───────────────────────────────────────────────────────────────────────────────

    public function test_the_card_shows_only_where_it_is_switched_on_and_only_to_whoever_may_save_it(): void
    {
        $this->owner();
        $html = $this->page();
        $this->assertStringContainsString('Daily report delivery', $html);
        $this->assertStringNotContainsString('Save delivery', $html, 'the new card replaces the old box');
        $this->assertStringContainsString('+92 300 1234567', $html);
        $this->assertStringContainsString('per message', $html, 'the price is on the WhatsApp card');
        $this->assertInlineScriptsParse($html, 'Report Center with the delivery card', 'report-delivery');

        // A role that may open the page but not save delivery: old schedules card, no numbers.
        $this->actAs(['tenant.reports.center.index', SalesReportCenterController::sectionPermission('overview')]);
        $html = $this->page();
        $this->assertStringNotContainsString('Daily report delivery', $html);
        $this->assertStringContainsString('Scheduled sales reports', $html, 'what this role saw before, it still sees');
        $this->assertStringNotContainsString('923001234567', $html);
        $this->assertStringNotContainsString('+92 300 1234567', $html);

        // The switch off in Master: the owner gets exactly the old two cards.
        $this->tenant->features = [TenantFeatures::REPORT_DELIVERY_V2 => false];
        $this->tenant->save();
        $this->owner();
        $html = $this->page();
        $this->assertStringContainsString('Save delivery', $html);
        $this->assertStringContainsString('Scheduled sales reports', $html);
        $this->assertStringNotContainsString('Daily report delivery', $html);
    }

    public function test_saving_keeps_each_name_with_its_number_and_says_what_was_left_out(): void
    {
        $this->owner();
        $response = $this->save([
            'whatsapp_on' => '1', 'email_on' => '1',
            'wa_names' => ['Owner', 'Manager', 'Typo'],
            'wa_numbers' => ['0300-1234567', '92 321 1234567', '12345'],
            'emails' => ['Accounts@Example.com'],
            'when' => 'shift_close', 'fallback_time' => '06:00', 'send_time' => '02:30',
            'preset' => 'short',
        ]);

        $this->assertStringContainsString('1 entry was not a mobile number', (string) $response->getSession()->get('status'));
        $t = $this->tenant->fresh();
        $this->assertSame(['923001234567', '923211234567'], (array) $t->report_whatsapp, 'same plain list every sender reads');
        $this->assertSame(['email', 'whatsapp'], (array) $t->report_channels);
        $this->assertSame('Owner', $t->report_whatsapp_names['923001234567']);
        $this->assertSame('Manager', $t->report_whatsapp_names['923211234567']);

        $main = DB::connection('tenant')->table('report_schedules')->where('frequency', 'daily')->first();
        $this->assertSame('shift_close', $main->send_trigger);
        $this->assertSame('06:00', $main->send_time, 'in shift mode the stored time is the safety net');
        $this->assertSame(['accounts@example.com'], json_decode($main->recipient_emails, true));
        $this->assertSame(['overview', 'cash_bank'], json_decode($main->sections, true));
    }

    public function test_a_save_that_would_deliver_nothing_is_refused_and_changes_nothing(): void
    {
        $this->owner();
        $before = (array) $this->tenant->fresh()->report_whatsapp;

        foreach ([
            ['whatsapp_on' => '1', 'wa_numbers' => ['12345'], 'wa_names' => ['x']],
            ['wa_numbers' => ['0300-1234567']],   // both services off
        ] as $input) {
            try {
                $this->save($input + ['when' => 'time', 'send_time' => '08:00', 'preset' => 'full']);
                $this->fail('expected the save to be refused');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        $this->assertSame($before, (array) $this->tenant->fresh()->report_whatsapp);
        $this->assertSame(0, DB::connection('tenant')->table('report_schedules')->count());
    }

    public function test_weekly_and_monthly_switches_pause_their_report_and_never_delete_it(): void
    {
        $this->owner();
        $base = ['email_on' => '1', 'when' => 'time', 'send_time' => '08:00', 'preset' => 'full'];
        $this->save($base + ['weekly' => '1', 'monthly' => '1']);
        $weekly = DB::connection('tenant')->table('report_schedules')->where('frequency', 'weekly')->first();
        $this->assertSame(1, (int) $weekly->weekday);
        $this->assertTrue((bool) $weekly->is_active);

        $this->save($base + ['monthly' => '1']);
        $this->assertFalse((bool) DB::connection('tenant')->table('report_schedules')->where('id', $weekly->id)->value('is_active'));
        $this->assertSame(3, DB::connection('tenant')->table('report_schedules')->count(), 'switched off, kept — its history stays');
    }

    // ── sending on the last shift close ────────────────────────────────────────────────────────

    public function test_the_days_last_shift_closing_sends_it_once_on_both_services(): void
    {
        $owner = $this->owner();
        $this->schedule();
        $first = $this->openShift($owner->id, 'T1');
        $second = $this->openShift($owner->id, 'T2');
        $day = $first->business_date->toDateString();

        $this->closeShift($first);
        $this->assertSame([], $this->runs(), 'T2 is still open — the day is not over');
        Http::assertSentCount(0);

        $this->closeShift($second);
        $this->assertSame(["email|{$day}|shift_close", "whatsapp|{$day}|shift_close"], $this->runs());
        Http::assertSentCount(2);   // one message per saved number

        // The safety-net run the next morning finds the day already sent.
        $schedule = DB::connection('tenant')->table('report_schedules')->first();
        $nextMorning = \Illuminate\Support\Carbon::parse($day.' 06:05', app(ReportScheduleService::class)->timezone())->addDay();
        $this->assertSame('skipped_already_sent', app(ReportScheduleService::class)->runDue($schedule, $nextMorning));
        // …and so does any later close on the same business day.
        app(ShiftCloseReportTrigger::class)->sendFor([$day]);
        Http::assertSentCount(2);
        $this->assertCount(2, $this->runs());
    }

    public function test_closing_the_whole_branch_at_once_sends_it_too(): void
    {
        $owner = $this->owner();
        $this->schedule();
        $day = $this->openShift($owner->id, 'T1')->business_date->toDateString();
        $this->openShift($owner->id, 'T2');

        $response = app(ShiftController::class)->closeBranch(
            Request::create('/shifts/close-branch', 'POST', ['branch_id' => $this->branchId, 'mode' => 'branch_total', 'branch_counted_cash' => '0']),
            app(ShiftService::class),
        );
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, Shift::on('tenant')->where('status', 'open')->count(), 'both counters closed');
        app()->terminate();

        $this->assertSame(["email|{$day}|shift_close", "whatsapp|{$day}|shift_close"], $this->runs());
        Http::assertSentCount(2);
    }

    public function test_a_tenant_without_the_switch_or_on_a_fixed_time_is_untouched(): void
    {
        $owner = $this->owner();

        // Switch off, even with a schedule that asks for shift close.
        $this->tenant->features = [];
        $this->tenant->save();
        $this->schedule();
        $this->closeShift($this->openShift($owner->id, 'T1'));
        $this->assertSame([], $this->runs());

        // Switch on, but the schedule is on a fixed time — the old behaviour.
        $this->tenant->features = [TenantFeatures::REPORT_DELIVERY_V2 => true];
        $this->tenant->save();
        DB::connection('tenant')->table('report_schedules')->update(['send_trigger' => 'time']);
        $this->closeShift($this->openShift($owner->id, 'T2'));
        $this->assertSame([], $this->runs());
        Http::assertSentCount(0);
    }

    public function test_a_report_that_cannot_be_sent_never_fails_the_shift_close(): void
    {
        $owner = $this->owner();
        $this->schedule();
        app()->instance(ReportScheduleService::class, new class(app(SalesReportEngine::class), app(ReportDispatcher::class), app(TenantClock::class)) extends ReportScheduleService
        {
            public function runForBusinessDay(object $schedule, string $businessDate, string $via = 'shift_close'): string
            {
                throw new \RuntimeException('the report service is down');
            }
        });

        // closeShift() asserts the 302 and the closed status; terminate() must not throw.
        $this->closeShift($this->openShift($owner->id, 'T1'));
        $this->assertSame([], $this->runs(), 'nothing claimed — the safety-net time will send it');
    }

    public function test_the_fixed_time_run_records_why_it_went(): void
    {
        $this->owner();
        $id = $this->schedule(['send_trigger' => 'time', 'send_time' => '08:00']);
        $schedule = DB::connection('tenant')->table('report_schedules')->find($id);
        $at = now(app(ReportScheduleService::class)->timezone())->setTime(8, 5);

        $this->assertSame('sent', app(ReportScheduleService::class)->runDue($schedule, $at));
        $this->assertSame(['time', 'time'], DB::connection('tenant')->table('report_schedule_runs')->pluck('via')->all());
    }
}
