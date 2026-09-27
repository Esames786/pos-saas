<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringEventController;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-LIST-DATE-RANGE-1 + CATERING-LIST-RESPONSIVE-1 — 27 September.
 *
 * Malik: "is screen ko bhi responsive karo, datatable chhoti screen par bhi
 * responsive ho, aur date range filter bhi de do is screen pe."
 *
 * DO ALAG SAWAL, EK HI SAFHA:
 *
 * 1. TAREEKH KI HADD. Bucket cards sirf aaj / kal / agle saat din ka jawab dete
 *    thay. "Is mahine kya kya tha" ka koi raasta nahi tha — aur wohi sawal
 *    mahine ke aakhir me sab se zyada poocha jata hai.
 *
 * 2. GYARAH COLUMNS. Laptop par aa jate hain, chhoti screen par nahi. Venue aur
 *    PAX 992px se neeche qataar se nikal kar customer ke naam ke neeche aa jate
 *    hain — maloomat kahin nahi jaati, sirf jagah badalti hai.
 *
 * Yahan controller ko ASAL raaste se bulaya jata hai. Test ka apna query likhna
 * sab se meetha jhoot hai: wo hamesha green rehta hai kyunke wo us code ko
 * chhoota hi nahi jo safha chalata hai.
 */
class CateringListFilterMySqlTest extends MySqlTenantTestCase
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
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true);

        $this->cleanTenant([
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'unit_id' => $this->unitId]);
    }

    // ── 1. Tareekh ki hadd ──────────────────────────────────────────────────

    /** Hadd ke andar wali booking aaye, bahar wali na aaye. */
    public function test_a_date_range_narrows_the_list_to_what_falls_inside_it(): void
    {
        $early = $this->booking(now()->addDays(5), 'Early Customer');
        $inside = $this->booking(now()->addDays(20), 'Inside Customer');
        $late = $this->booking(now()->addDays(60), 'Late Customer');

        // PEHLE PROBE KO ZINDA SABIT KARO: hadd ke baghair teeno aate hain.
        // Warna "sirf ek aaya" ka koi matlab nahi — ho sakta hai kuch aata hi
        // na ho aur test khamoshi se green rehta.
        $this->assertSame(
            [$late->event_no, $inside->event_no, $early->event_no],
            $this->listed([]),
            'bagair filter ke teeno bookings aani chahiyen (nayi pehle)'
        );

        $this->assertSame([$inside->event_no], $this->listed([
            'from' => now()->addDays(10)->toDateString(),
            'to' => now()->addDays(30)->toDateString(),
        ]));

        // Sirf "from" — us din se aage sab kuch.
        $this->assertSame([$late->event_no, $inside->event_no], $this->listed([
            'from' => now()->addDays(10)->toDateString(),
        ]));

        // Sirf "to" — us din tak sab kuch.
        $this->assertSame([$inside->event_no, $early->event_no], $this->listed([
            'to' => now()->addDays(30)->toDateString(),
        ]));
    }

    /**
     * Hadd ke dono sire KHUD bhi andar hain — warna operator ek din kho deta hai.
     *
     * Sire ke BAHAR bhi ek ek booking rakhi gayi hai, aur ye sirf ihtiyat nahi:
     * un ke baghair ye test "hadd shamil hai" aur "hadd lagi hi nahi" me farq
     * hi nahi kar sakta tha — dono soorton me wohi do bookings aatin. Aisa test
     * hamesha green rehta hai aur kabhi kuch nahi batata.
     */
    public function test_both_ends_of_the_range_are_inside_it(): void
    {
        $this->booking(now()->addDays(9), 'Ek Din Pehle');
        $first = $this->booking(now()->addDays(10), 'First Day');
        $last = $this->booking(now()->addDays(30), 'Last Day');
        $this->booking(now()->addDays(31), 'Ek Din Baad');

        $this->assertSame([$last->event_no, $first->event_no], $this->listed([
            'from' => now()->addDays(10)->toDateString(),
            'to' => now()->addDays(30)->toDateString(),
        ]), 'jo din likha gaya wo hadd ke ANDAR hai, kinare par nahi — aur us se bahar wale bahar hi rahein');
    }

    /**
     * Sarih hadd bucket card par ghalib aaye.
     *
     * Dono event_date ko baandhte hain. Agar dono chal jayen to fehrist un ka
     * QATAA dikhati hai — aksar khali — aur operator ko do filter nazar aate
     * hain jin me se har ek theek lagta hai. Ye wo kism ki khamoshi hai jis ka
     * jawab support call ke ilawa kuch nahi.
     */
    public function test_an_explicit_range_overrides_a_bucket_card(): void
    {
        $todayBooking = $this->booking(now(), 'Aaj Wali');
        $future = $this->booking(now()->addDays(20), 'Aage Wali');

        // Probe zinda: akela bucket waqai aaj wali laata hai.
        $this->assertSame([$todayBooking->event_no], $this->listed(['filter' => 'today']));

        $view = $this->indexView([
            'filter' => 'today',
            'from' => now()->addDays(10)->toDateString(),
            'to' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertSame(
            [$future->event_no],
            collect($view->getData()['events']->items())->pluck('event_no')->all(),
            'likhi hui hadd jeetti hai'
        );
        $this->assertNull($view->getData()['filter'],
            'bucket gir jana chahiye, warna card chuna hua dikhta rahega jab ke wo laagu hi nahi');
    }

    /**
     * Aadhi likhi ya na-mumkin tareekh fehrist khali na kare.
     *
     * URL haath se bhi likhi jati hai, aur Carbon '2026-13-45' ko chupke se
     * agle saal me badal deta hai. Us soorat me safha ek aisi tareekh par
     * filter karta jo kisi ne maangi hi nahi thi.
     */
    public function test_a_half_typed_or_impossible_date_is_ignored_rather_than_obeyed(): void
    {
        $a = $this->booking(now()->addDays(5), 'Aik');
        $b = $this->booking(now()->addDays(20), 'Do');

        foreach (['2026-13-45', '20', '', 'kal', '2026/09/27'] as $junk) {
            $this->assertSame(
                [$b->event_no, $a->event_no],
                $this->listed(['from' => $junk]),
                "'{$junk}' ko nazar-andaaz hona chahiye, maana nahi"
            );
        }
    }

    /** Ulti hadd par safha khud batata hai ke wo khali kyun hai. */
    public function test_a_backwards_range_says_why_the_list_is_empty(): void
    {
        $this->booking(now()->addDays(20), 'Koi Bhi');

        $range = [
            'from' => now()->addDays(30)->toDateString(),
            'to' => now()->addDays(10)->toDateString(),
        ];

        $this->assertSame([], $this->listed($range));
        $this->assertStringContainsString('"From" ki tareekh "To" se baad ki hai', $this->html($range),
            'khali fehrist khud nahi batati ke wo kyun khali hai');
    }

    /** Aur hadd safhe par wapas nazar aaye, warna operator ko pata hi nahi chalega. */
    public function test_the_range_the_operator_typed_comes_back_on_the_page(): void
    {
        $from = now()->addDays(10)->toDateString();
        $to = now()->addDays(30)->toDateString();

        $html = $this->html(['from' => $from, 'to' => $to]);

        $this->assertStringContainsString('name="from" value="'.$from.'"', $html);
        $this->assertStringContainsString('name="to" value="'.$to.'"', $html);
    }

    // ── 2. Chhoti screen ────────────────────────────────────────────────────

    /**
     * Har data column par FARSH ho, tajweez nahi.
     *
     * HTML me `width` browser ke liye tajweez hai: jagah kam parey to wo us se
     * neeche chala jata hai aur khana kuchal deta hai. Yehi keeda punch grid par
     * Qty ko ghayab kar chuka hai.
     */
    public function test_every_data_column_has_a_floor_not_a_suggestion(): void
    {
        $this->booking(now()->addDays(3), 'Floor Customer');
        $html = $this->html([]);

        foreach (['Event #', 'Customer', 'Event Date', 'Venue', 'PAX', 'Quotation', 'Position', 'Status', 'Next Action'] as $col) {
            $this->assertDoesNotMatchRegularExpression(
                '/<th[^>]*style="width:\d+px;?"[^>]*>'.preg_quote($col, '/').'</',
                $html,
                "'{$col}' par `width` hai — wo tajweez hai, farsh nahi"
            );
            $this->assertMatchesRegularExpression(
                '/<th[^>]*style="min-width:\d+px;"[^>]*>'.preg_quote($col, '/').'</',
                $html,
                "'{$col}' ka farsh mojood hona chahiye"
            );
        }

        // Probe zinda: tick ka khana JAAN-BOOJH KAR `width` rakhta hai, kyunke
        // wahi ek soorat hai jahan sikuṛna theek hai. Agar ye bhi na milta to
        // upar ke saare "width nahi hai" wale assert bemani hotay.
        $this->assertMatchesRegularExpression('/<th[^>]*style="width:30px"/', $html,
            'tick wala khana ab bhi `width` par hai — yani jaanch andhi nahi');
    }

    /** Venue aur PAX qataar se nikal jayen, magar safhe se nahi. */
    public function test_venue_and_pax_leave_the_row_on_a_small_screen_without_leaving_the_page(): void
    {
        $this->booking(now()->addDays(3), 'Chhoti Screen', 300);
        $html = $this->html([]);

        foreach (['Venue', 'PAX'] as $col) {
            $this->assertMatchesRegularExpression(
                '/<th[^>]*d-none d-lg-table-cell[^>]*>'.preg_quote($col, '/').'</',
                $html,
                "'{$col}' ka column 992px se neeche chhup jana chahiye"
            );
        }

        // Magar wohi maloomat customer ke khane me mojood ho — do jagah, kyunke
        // ek bari screen ke liye hai aur ek chhoti ke liye. Ginti hi is ka
        // sabooot hai: agar tabadla hua hi na hota to naam sirf ek baar aata.
        $this->assertSame(2, substr_count($html, 'Shadman Hall'),
            'venue apne column me AUR customer ke neeche — dono jagah');
        $this->assertStringContainsString('d-lg-none', $html,
            'chhoti screen wali satar mojood ho');
        $this->assertStringContainsString('300 PAX', $html,
            'aur PAX bhi us satar me likha ho');
    }

    /** PAX sifar ho to likha na jaye — wo khabar nahi, shor hai. */
    public function test_a_booking_without_a_guest_count_does_not_say_zero_pax(): void
    {
        $event = $this->booking(now()->addDays(3), 'Bina PAX', 0);
        $html = $this->html([]);

        // Pehle ye sabit karo ke qatar bani hi hai. Ek manfi assert us safhe par
        // bhi pass ho jata hai jo khali ho — aur phir wo kuch nahi keh raha
        // hota, sirf khamosh hota hai.
        $this->assertStringContainsString($event->event_no, $html, 'booking fehrist me aani chahiye');
        $this->assertStringContainsString('Bina PAX', $html);

        $this->assertStringNotContainsString('0 PAX', $html,
            'PAX ab ikhtiyari hai — jo maloom nahi wo sifar ki shakl me nahi likha jata');
    }

    /**
     * Har naye selector ka nishana markup me mojood ho.
     *
     * Ye test us ghalti ke liye hai jo main pehle kar chuka hoon: CSS likh di
     * aur us ka nishana markup me tha hi nahi. Aisi CSS kuch torti nahi, bas
     * kuch karti bhi nahi — safha theek dikhta hai, kaam adhoora reh jata hai,
     * aur koi test us par nahi bolta.
     */
    public function test_no_stylesheet_rule_points_at_markup_that_does_not_exist(): void
    {
        $html = $this->html([]);

        foreach (['events-table', 'events-table-wrap'] as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'),
                "CSS '#{$id}' ko nishana banati hai — wo markup me theek ek baar hona chahiye");
        }

        $this->assertStringContainsString('#events-table-wrap {', $html, 'wrapper ka kinara');
        $this->assertStringContainsString('@media (max-width: 991.98px)', $html, 'chhoti screen ka apna hissa');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function booking(\Carbon\CarbonInterface $date, string $customer, int $pax = 30): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => $customer,
            'booking_date' => now()->toDateString(),
            'event_date' => $date->toDateString(),
            'pax' => $pax,
            'venue' => 'Shadman Hall',
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 10, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 400,
        ]]);

        return $event->refresh();
    }

    /** Asal controller, asal request. */
    private function indexView(array $query): \Illuminate\Contracts\View\View
    {
        return app(CateringEventController::class)
            ->index(Request::create('/catering/events', 'GET', $query));
    }

    /** @return string[] event numbers, jis tarteeb me safha dikhata hai */
    private function listed(array $query): array
    {
        return collect($this->indexView($query)->getData()['events']->items())
            ->pluck('event_no')->all();
    }

    private function html(array $query): string
    {
        $user = \App\Models\Tenant\User::on('tenant')->find(
            $this->makeUser(['employee_code' => 'LF'.Str::random(4)])
        );
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        return $this->indexView($query)->render();
    }
}
