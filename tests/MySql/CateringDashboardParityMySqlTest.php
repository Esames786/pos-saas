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

        // CATERING-ACCEPT-CONFIRMS-1: acceptance carries the booking with it, so
        // "Booking Confirmation Pending" is no longer a stage anyone waits in.
        // The walk is one step shorter, and that is the point being pinned.
        $this->estimates->markAccepted($event->currentEstimate->refresh());
        $this->assertSame(\App\Models\Tenant\CateringEvent::STATUS_CONFIRMED, $event->fresh()->status,
            'the customer said yes, so the booking is on');
        $this->assertSame('Production Pending', $this->calendar->nextAction($event->fresh()));

        // And pressing Confirm Booking anyway is harmless.
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
        // Tareekh MAHINE KE ANDAR se chuni ja rahi hai, "aaj se do din baad"
        // se nahi. `window()` sirf mojooda mahine ke aakhir tak dekhta hai
        // ($anchor->endOfMonth()), is liye purana `addDays(2)` har mahine ke
        // AAKHRI DO DIN agle mahine me chala jata tha aur ye test bina kisi
        // asal kharabi ke red ho jata. 29 September ko theek yehi hua.
        //
        // Is test ka sawal ye hai ke ek masroof tareekh par EK ginti wala
        // nishan bane — us ka mustaqbil me hona zaroori nahi.
        $date = now()->startOfMonth()->addDays(9)->toDateString();
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

    /**
     * KITCHEN-SHEET-PREVIEW-1 (27 Sep) ne is usool ko ULAT diya.
     *
     * Ye test pehle pehra deta tha ke release ke baghair bulk parcha chhapne se
     * INKAAR kare — "no sheet was invented from an unreleased booking". Malik
     * ne saaf kaha: "kitchen sheet can be print to any status." Ab release se
     * pehle bhi parcha banta hai.
     *
     * Test narm nahi kiya gaya, NAYE usool par laya gaya hai. Purane usool ke
     * peeche jo asal khauf tha — ke aarzi parcha asli jaisa dikhega — us ka
     * pehra ab yehi test deta hai: parcha bane, aur khud kahe ke wo jaari
     * nahi hua.
     */
    public function test_bulk_kitchen_sheets_print_before_release_as_a_marked_preview(): void
    {
        $a = $this->booking(now()->addDays(2)->toDateString());

        $html = app(CateringBulkDocumentController::class)->kitchenSheets(
            Request::create('/x', 'GET', ['ids' => [$a->id]])
        )->render();

        $this->assertStringContainsString('KITCHEN / SERVICE SHEET', $html,
            'release se pehle bhi parcha banna chahiye — yehi maanga gaya tha');
        $this->assertStringContainsString('<div class="preview-band">', $html,
            'aur parcha khud kahe ke production abhi jaari nahi hui');
        $this->assertStringContainsString('PRODUCTION NOT RELEASED YET', $html);
    }

    /**
     * Ab bulk sirf EK soorat me inkaar karta hai: booking par koi quotation hi
     * na ho — yani parche par rakhne ko koi khana hi na ho.
     */
    public function test_bulk_kitchen_sheets_still_refuse_a_booking_with_no_quotation(): void
    {
        $a = $this->booking(now()->addDays(2)->toDateString());

        $db = DB::connection('tenant');
        $estimateIds = $db->table('catering_estimates')->where('catering_event_id', $a->id)->pluck('id');
        $db->table('catering_estimate_lines')->whereIn('catering_estimate_id', $estimateIds)->delete();
        $db->table('catering_estimates')->whereIn('id', $estimateIds)->delete();

        $response = app(CateringBulkDocumentController::class)->kitchenSheets(
            Request::create('/x', 'GET', ['ids' => [$a->id]])
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString($a->event_no, $response->getContent(),
            'aur jo booking chhoot gayi, us ka naam liya jaye');
    }

    /**
     * CAL-BALANCE-FILTER-1 (3 Oct) — calendar par "balance baqi" ka filter.
     *
     * Malik: "ek filter aur daalo, sirf wo jin par balance hai."
     *
     * Ye filter TONES SE ALAG hai, aur yehi is test ki asal baat hai. Tone har
     * booking par EK hi lagta hai (confirmed YA quoted YA draft); "balance hai"
     * ek alag sifat hai jo un me se kisi ke bhi saath aa sakti hai. Agar kabhi
     * ise saatwan tone bana diya gaya to har booking ko do tone chahiye honge
     * aur chips ek doosre ko kaat-ne lagenge.
     *
     * Aur baqi ka hisaab yahan DOBARA nahi likha ja raha: `outstanding()` wohi
     * hai jo booking ki screen aur Customer Balances chalate hain.
     */
    public function test_the_calendar_carries_each_booking_s_outstanding_balance(): void
    {
        $date = now()->startOfMonth()->addDays(9)->toDateString();

        // Dono ek jaisi: 10 KG x 400 = 4,000.
        $owing = $this->booking($date, 400, 'Owing Customer', '0300-7777771');
        $settled = $this->booking($date, 400, 'Settled Customer', '0300-7777772');

        $billed = (float) $owing->fresh()->currentEstimate->grand_total;
        $this->assertGreaterThan(0, $billed, 'quotation bani honi chahiye');

        // AUR AB PAISA AATA HAI. Ye is jaanch ki jaan hai: agar dono par kuch
        // na aaya hota to "billed" aur "baqi" ek hi adad hote, aur ye test us
        // din bhi hara rehta jis din calendar ghataana bhool jaata. (Pehli baar
        // maine yehi ghalti ki thi — probe ne tooti halat par bhi hari jhandi
        // dikhai.)
        $pay = function (CateringEvent $e, float $amount) {
            $est = $e->currentEstimate;
            $this->estimates->markSent($est);
            $this->estimates->markAccepted($est->refresh());
            app(CateringAdvanceService::class)->record($e->refresh(), [
                'amount' => $amount, 'payment_method_id' => $this->paymentMethodId,
                'received_date' => now()->toDateString(),
            ]);
        };
        $pay($owing, 1500);        // adhoora
        $pay($settled, $billed);   // poora

        // Events `months -> weeks -> days` me nested hain. Test ko us shakl par
        // nahi bandha ja raha: wo dhaancha kal badal sakta hai aur tab ye test
        // bina kisi asal kharabi ke girta. Jo bhi array `event_no` rakhta ho,
        // wohi ek booking hai.
        $flat = [];
        $walk = function ($node) use (&$walk, &$flat) {
            if (! is_array($node)) { return; }
            if (isset($node['event_no'])) { $flat[$node['event_no']] = $node; return; }
            foreach ($node as $child) { $walk($child); }
        };
        $walk(app(CateringCalendarService::class)->window());

        $owingRow = $flat[$owing->event_no] ?? null;
        $settledRow = $flat[$settled->event_no] ?? null;

        $this->assertNotNull($owingRow, 'booking calendar par honi chahiye — warna neeche ki jaanch bemani hai');
        $this->assertNotNull($settledRow);
        $this->assertArrayHasKey('balance', $owingRow,
            'har booking apna baqi saath le kar aaye — filter isi par chalta hai');

        // Jo adhoori bhari gayi: baqi 2,500 — yani 4,000 me se 1,500 ghata hua.
        $this->assertSame(
            \App\Services\Catering\CateringFinancialPositionService::outstanding($billed, 1500.0),
            $owingRow['balance'],
            'calendar ka baqi us qaide se alag nikla jo baqi screenein chalati hain'
        );
        $this->assertSame(2500.0, $owingRow['balance'],
            'aur wo 2,500 hai — ghataana hua hi nahi to ye 4,000 hoga');

        // Jo poori bhar di gayi: sifar — aur yehi wajah hai ke filter ise chhupata hai.
        $this->assertSame(0.0, $settledRow['balance'],
            'poori bhari booking par kuch baqi nahi — warna filter use bhi dikhata rahega');
    }

    /** Chip tones se alag ho — warna wo un ko kaat-ne lagega. */
    public function test_the_balance_chip_is_not_a_seventh_tone(): void
    {
        $blade = file_get_contents(resource_path('views/tenant/partials/catering-calendar.blade.php'));

        $this->assertStringContainsString('id="cal-only-balance"', $blade, 'chip mojood ho');
        $this->assertDoesNotMatchRegularExpression('/id="cal-only-balance"[^>]*class="[^"]*cal-tone/', $blade,
            'ye chip tone NAHI hai — tone ban-ne par har booking ko do tone chahiye honge');

        // "All" dono saaf kare, warna wo adhoora saaf karta hai.
        $this->assertMatchesRegularExpression('/cal-clear-tones.*?activeTones = \[\];\s*onlyBalance = false;/s', $blade,
            '"All" par balance ka filter bhi hatna chahiye');
    }
}
