<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringCustomerBalanceController;
use App\Http\Controllers\Tenant\Catering\CateringEventController;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\Customer;
use App\Services\Catering\CateringEstimateService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-CONFIRMED-IS-A-GROUP-1 — 11 October.
 *
 * Malik ne status ki fehrist par nishan laga kar likha: "confirm se le kar
 * closed tak saare wo status hain jo actually confirm bookings hoti hain. Jab
 * main Confirmed par click karoon to ye saare status ke order aane chahiyen —
 * in ko alag se show na karo. Confirm ka matlab ye saare confirm hi hain."
 *
 * ── YE EK KAAROBARI TAREEF HAI, EK SCREEN KA FILTER NAHI ───────────────────
 *
 * Graahak ne haan kar di — us ke baad `production_ready`, `released`,
 * `completed`, `closed` sirf ye batate hain ke kaam kitna aage barha, na ke ye
 * ke booking pakki hai ya nahi.
 *
 * Isi liye neeche ka sab se ahem test wo hai jo DONO screenon ko ek saath
 * poochta hai. Filter do jagah hai (bookings ki fehrist aur Customer Catering
 * Balances), aur agar tareef ek jagah badli jaye to ek hi lafz do screenon par
 * do alag jawab dega — aur farq paison wali screen par khulega.
 */
class CateringConfirmedIsAGroupMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private int $branchId;

    private int $productId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();
        View::share('errors', new \Illuminate\Support\ViewErrorBag);
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true);

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_final_invoices', 'catering_advances', 'catering_refunds',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'journal_lines', 'journal_entries', 'accounts', 'payment_methods',
            'customers', 'product_translations', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->branchId = $this->makeBranch();
        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'RICE']), [
            'name' => 'Biryani', 'sku' => 'CG1', 'unit_id' => $this->unitId,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400, 'unit_id' => $this->unitId,
            'effective_from' => now()->subYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Ek booking, seedhi di hui haalat me. */
    private function booking(string $status, string $phone): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. GROUP',
            'customer_phone' => $phone,
            'booking_date' => now()->subDays(3)->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 30,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Biryani',
            'quantity' => 2, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 1000,
        ]]);
        $this->estimates->markSent($event->currentEstimate->refresh());

        // Haalat seedhi likhi ja rahi hai: is test ka sawal FILTER hai, poora
        // lifecycle nahi — aur har status tak asli raaste se pahunchna ise us
        // lifecycle ka test bana deta.
        $event->forceFill(['status' => $status])->save();

        return $event->refresh();
    }

    /** Har us haalat ki ek booking jo "pakki" hai, aur ek jo nahi. */
    private function oneOfEach(): void
    {
        $n = 0;
        foreach (CateringEvent::CONFIRMED_STATUSES as $status) {
            $this->booking($status, '0300-11100'.(++$n));
        }
        $this->booking(CateringEvent::STATUS_QUOTED, '0300-222001');
        $this->booking(CateringEvent::STATUS_CANCELLED, '0300-222002');
    }

    /** Bookings ki fehrist se kaun se event number aate hain. */
    private function eventsListFor(?string $status): array
    {
        $req = Request::create('/catering/events', 'GET', array_filter(['status' => $status]));
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);

        $events = app(CateringEventController::class)->index($req)->getData()['events'];

        // `->items()` — `collect($paginator)` us ki ARRAY SHAKL deta hai
        // (`current_page`, `data`, `first_page_url`…), items nahi. Us ki 13
        // keys hoti hain, is liye pehli koshish me har filter par 13 hi
        // nikalta raha — chahe DB me 7 bookings thin. Ye test ki ghalti thi,
        // code ki nahi.
        return collect($events->items())->pluck('event_no')->sort()->values()->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Asal maqsad.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_confirmed_brings_every_booking_that_is_already_on(): void
    {
        $this->oneOfEach();

        $mile = $this->eventsListFor(CateringEvent::STATUS_CONFIRMED);

        $this->assertCount(count(CateringEvent::CONFIRMED_STATUSES), $mile,
            'Confirmed par confirmed se closed tak ki saari bookings aani chahiyen');

        // Aur jo pakki NAHI hain wo na aayen — warna filter kuch chhanta hi nahi.
        $quoted = CateringEvent::where('status', CateringEvent::STATUS_QUOTED)->value('event_no');
        $cancelled = CateringEvent::where('status', CateringEvent::STATUS_CANCELLED)->value('event_no');
        $this->assertNotContains($quoted, $mile, 'quoted abhi pakki nahi hui');
        $this->assertNotContains($cancelled, $mile, 'aur cancelled pakki thi phir toot gayi');
    }

    /** Baqi filter jyun ke tyun — sirf Confirmed group bana hai. */
    public function test_the_other_filters_still_pick_exactly_one_status(): void
    {
        $this->oneOfEach();

        foreach ([CateringEvent::STATUS_QUOTED, CateringEvent::STATUS_CANCELLED] as $status) {
            $this->assertCount(1, $this->eventsListFor($status),
                "[{$status}] par sirf wohi ek booking aani chahiye");
        }

        $this->assertCount(7, $this->eventsListFor(null), 'bina filter ke saari');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Aur dono screenein ek jaisa jawab dein — yehi asal khatra hai.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Filter DO jagah hai. Tareef ek jagah badalne par ek hi lafz do screenon
     * par do alag jawab deta — aur farq paison wali screen par khulta, jahan se
     * log paisa lete aur refund karte hain.
     */
    public function test_both_screens_mean_the_same_thing_by_confirmed(): void
    {
        $this->oneOfEach();

        $fehrist = $this->eventsListFor(CateringEvent::STATUS_CONFIRMED);

        // Customer Balances — asal controller se, query dobara likh kar nahi.
        $req = Request::create('/catering/customer-balances', 'GET', ['status' => 'confirmed']);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);
        $rows = app(CateringCustomerBalanceController::class)->index($req)->getData()['rows'];

        $balances = collect($rows)->sum('events');

        $this->assertSame(count($fehrist), $balances,
            'dono screenein Confirmed se ek hi cheez samjhein — warna ek hi lafz do jawab deta hai');
        $this->assertGreaterThan(1, $balances,
            'aur ginti ek se zyada ho, warna ye milan bemani hai');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tareef khud.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `cancelled` is group me NAHI — wo booking pakki thi aur phir toot gayi.
     * Aur `CONFIRMED_STATUSES` ko `OPEN_STATUSES` se mat milao: wo alag sawal ka
     * jawab hai ("is par kaam BAQI hai?"), isi liye us me `confirmed` hai magar
     * `released` nahi.
     */
    public function test_the_group_is_spelled_out_once_and_excludes_cancelled(): void
    {
        $this->assertNotContains(CateringEvent::STATUS_CANCELLED, CateringEvent::CONFIRMED_STATUSES);
        $this->assertContains(CateringEvent::STATUS_CLOSED, CateringEvent::CONFIRMED_STATUSES);
        $this->assertNotSame(CateringEvent::OPEN_STATUSES, CateringEvent::CONFIRMED_STATUSES,
            'ye do alag sawal hain — ek ko doosre ki jagah mat samjho');

        // Har wo status jo "pakki" nahi, group se bahar rahe.
        foreach ([CateringEvent::STATUS_INQUIRY, CateringEvent::STATUS_DRAFT, CateringEvent::STATUS_QUOTED] as $s) {
            $this->assertNotContains($s, CateringEvent::CONFIRMED_STATUSES);
        }
    }

    /** Dropdown me group ke andar wale alag se na dikhein. */
    public function test_the_dropdowns_do_not_list_the_group_members_separately(): void
    {
        foreach ([
            'views/tenant/catering/events/index.blade.php',
            'views/tenant/catering/customer-balances/index.blade.php',
        ] as $blade) {
            $html = file_get_contents(resource_path($blade));

            $this->assertStringContainsString('CateringEvent::FILTER_STATUSES', $html,
                "[{$blade}] filter ki apni fehrist se bane");
            $this->assertStringNotContainsString('CateringEvent::STATUSES as $s', $html,
                "[{$blade}] par poori fehrist nahi — warna group ke andar wale alag se nazar aate hain");
        }

        // Aur satar par badge ab bhi ASAL status dikhaye: ye khabar chhupani
        // nahi thi, sirf chunna aasan karna tha.
        $this->assertSame('Released',
            CateringEvent::statusLabel(CateringEvent::STATUS_RELEASED),
            'asal status ka naam barqarar rahe');
    }
}
