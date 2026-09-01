<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController;
use App\Http\Controllers\Tenant\Catering\CateringEventController;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringCalendarService;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-OPERATOR-UI-1 — the diary answers the operator's questions.
 *
 * Next Action is a LABEL over existing lifecycle facts, never a new state
 * machine; the calendar shows a COUNT per date, not a crowd of dots; the KPI
 * balance comes from the same refund-aware position authority the workspace
 * uses; search matches what an operator holds when the phone rings; and a bulk
 * print run moves nothing at all.
 */
class CateringDashboardParityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringCalendarService $calendar;

    private int $branchId;

    private int $productId;

    private int $unitId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();
        View::share('errors', new \Illuminate\Support\ViewErrorBag);
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true); // nullable => guests pass too

        $this->cleanTenant([
            'catering_estimate_line_instruction', 'catering_instructions',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_final_invoices', 'catering_advances',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'stock_ledgers',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->calendar = app(CateringCalendarService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'unit_id' => $this->unitId]);
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->productId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'recipe']
        );
        // Send-readiness fails closed without an effective Catering rate.
        \App\Models\Tenant\CateringMaterialRate::create([
            'product_id' => $this->productId, 'rate' => 400, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $cashAccountId = DB::connection('tenant')->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => \App\Models\Tenant\Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 500000, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $cashAccountId]);
    }

    private function booking(string $date, float $rate = 400, string $customer = 'Diary Customer', ?string $phone = null): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => $customer,
            'customer_phone' => $phone,
            'booking_date' => now()->toDateString(),
            'event_date' => $date,
            'pax' => 30,
            'venue' => 'Shadman Hall',
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 10, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => $rate,
        ]]);

        return $event->refresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Next Action reads lifecycle facts.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_next_action_walks_the_lifecycle(): void
    {
        $event = $this->booking(now()->addDays(2)->toDateString());
        $this->assertSame('Quotation Draft', $this->calendar->nextAction($event->fresh()));

        $this->estimates->markSent($event->currentEstimate);
        $this->assertSame('Awaiting Customer Acceptance', $this->calendar->nextAction($event->fresh()));

        $this->estimates->markAccepted($event->currentEstimate->refresh());
        $this->assertSame('Booking Confirmation Pending', $this->calendar->nextAction($event->fresh()));

        $this->estimates->confirmEvent($event->refresh());
        $this->assertSame('Production Pending', $this->calendar->nextAction($event->fresh()));

        $this->estimates->cancelEvent($event->refresh(), 'walkthrough over');
        $this->assertSame('Cancelled', $this->calendar->nextAction($event->fresh()));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Next 7 days + KPI cards.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_next_seven_days_lists_only_the_coming_week(): void
    {
        $today = $this->booking(now()->toDateString(), 400, 'Today Customer');
        $inWeek = $this->booking(now()->addDays(6)->toDateString(), 400, 'Week Customer');
        $beyond = $this->booking(now()->addDays(12)->toDateString(), 400, 'Beyond Customer');
        $cancelled = $this->booking(now()->addDays(3)->toDateString(), 400, 'Cancelled Customer');
        $this->estimates->cancelEvent($cancelled, 'no show');

        $numbers = array_column($this->calendar->nextDays(7), 'event_no');

        $this->assertContains($today->event_no, $numbers);
        $this->assertContains($inWeek->event_no, $numbers);
        $this->assertNotContains($beyond->event_no, $numbers);
        $this->assertNotContains($cancelled->event_no, $numbers);
    }

    public function test_kpis_count_facts_and_use_the_shared_balance_authority(): void
    {
        $today = $this->booking(now()->toDateString());               // draft, today, 4000 quoted
        $confirmed = $this->booking(now()->addDays(3)->toDateString()); // will be confirmed, no release
        $this->estimates->markSent($confirmed->currentEstimate);
        $this->estimates->markAccepted($confirmed->currentEstimate->refresh());
        $this->estimates->confirmEvent($confirmed->refresh());

        // 1,500 received on the confirmed booking: outstanding = 4000 + 2500.
        app(CateringAdvanceService::class)->record($confirmed->refresh(), [
            'amount' => 1500, 'payment_method_id' => $this->paymentMethodId,
            'received_date' => now()->toDateString(),
        ]);

        $k = $this->calendar->kpis();

        $this->assertSame(1, $k['today']);
        $this->assertSame(2, $k['next7']);
        $this->assertSame(1, $k['drafts'], 'only the unfinalized quotation counts as awaiting finalization');
        $this->assertSame(1, $k['production_pending']);
        $this->assertEqualsWithDelta(6500.0, $k['outstanding_balance'], 0.01,
            'the KPI balance must equal the workspace position: 4000 draft + (4000 - 1500)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Calendar presentation: a COUNT per date, and a listing modal.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_busy_date_renders_one_count_pill_with_the_day_listing(): void
    {
        $date = now()->addDays(2)->toDateString();
        $a = $this->booking($date, 400, 'First Booking', '0300-1111111');
        $b = $this->booking($date, 400, 'Second Booking', '0300-2222222');

        $html = View::make('tenant.partials.catering-calendar', [
            'cateringCalendar' => $this->calendar->window(),
            'selectedBranch' => null,
        ])->render();

        $this->assertStringContainsString('&bull; 2', $html,
            'the date carries ONE indicator with the count, not a dot per booking');
        $this->assertStringContainsString('calDayModal', $html);
        $this->assertStringContainsString('data-events=', $html);
        // Both bookings ride the day payload — number, phone and next action included.
        $this->assertStringContainsString($a->event_no, $html);
        $this->assertStringContainsString($b->event_no, $html);
        $this->assertStringContainsString('0300-1111111', $html);
        $this->assertStringContainsString('Quotation Draft', $html);
        $this->assertStringContainsString('Next Action', $html);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Event search.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_event_list_searches_number_customer_phone_and_venue(): void
    {
        $target = $this->booking(now()->addDays(4)->toDateString(), 400, 'Sheikh Ahmed', '0321-9998877');
        $this->booking(now()->addDays(5)->toDateString(), 400, 'Someone Else', '0300-0000000');

        $search = function (string $q) {
            $view = app(CateringEventController::class)->index(Request::create('/catering/events', 'GET', ['q' => $q]));

            return collect($view->getData()['events']->items())->pluck('event_no')->all();
        };

        $this->assertSame([$target->event_no], $search($target->event_no));
        $this->assertSame([$target->event_no], $search('Sheikh Ahm'));
        $this->assertSame([$target->event_no], $search('9998877'));
        $this->assertCount(2, $search('Shadman'), 'venue matches both bookings');
        $this->assertSame([], $search('no-such-thing'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bulk documents move nothing.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_bulk_quotations_and_address_sheet_render_without_mutating_anything(): void
    {
        $a = $this->booking(now()->addDays(2)->toDateString(), 400, 'Bulk A');
        $b = $this->booking(now()->addDays(2)->toDateString(), 400, 'Bulk B');

        $db = DB::connection('tenant');
        $before = [
            $db->table('journal_lines')->count(),
            $db->table('stock_ledgers')->count(),
            $db->table('catering_estimates')->where('status', 'draft')->count(),
        ];

        $controller = app(CateringBulkDocumentController::class);

        $quotations = $controller->quotations(
            Request::create('/x', 'GET', ['ids' => [$a->id, $b->id]]),
            app(\App\Services\Catering\CateringFinancialPositionService::class)
        )->render();
        $this->assertStringContainsString('Bulk A', $quotations);
        $this->assertStringContainsString('Bulk B', $quotations);
        $this->assertStringContainsString('DRAFT — NOT YET ISSUED', $quotations,
            'a draft stays visibly a draft even in a bulk run');

        $addresses = $controller->addressSheet(
            Request::create('/x', 'GET', ['ids' => [$a->id, $b->id]])
        )->render();
        $this->assertStringContainsString('ADDRESS SHEET', $addresses);
        $this->assertStringContainsString('Shadman Hall', $addresses);
        $this->assertStringNotContainsString('400.00', $addresses,
            'the drivers list carries no prices');

        $after = [
            $db->table('journal_lines')->count(),
            $db->table('stock_ledgers')->count(),
            $db->table('catering_estimates')->where('status', 'draft')->count(),
        ];
        $this->assertSame($before, $after, 'bulk printing posts nothing, moves nothing, finalizes nothing');
    }

    public function test_bulk_kitchen_sheets_refuse_when_nothing_is_released(): void
    {
        $a = $this->booking(now()->addDays(2)->toDateString());

        $response = app(CateringBulkDocumentController::class)->kitchenSheets(
            Request::create('/x', 'GET', ['ids' => [$a->id]])
        );

        // Still a refusal, and still 422 — a booking without a release has no
        // kitchen sheet to print. KASHIF-KITCHEN-MATERIALS-1 only changed what
        // the operator SEES: these pages open in a new tab, where an exception
        // renders a framework error for a situation where nothing is wrong.
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringNotContainsString('KITCHEN / SERVICE SHEET', $response->getContent(),
            'no sheet was invented from an unreleased booking');
        $this->assertStringContainsString($a->event_no, $response->getContent(),
            'and the booking that has none is named');
    }
}
