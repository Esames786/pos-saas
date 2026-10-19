<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\Customer;
use App\Services\Catering\CateringCustomerBalanceService;
use App\Services\Catering\CateringEstimateService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-BALANCES-STATUS-FILTER-1 — 4 October.
 *
 * Malik: "yahan event status waghera dikha do, us ke filter ke hisaab se
 * customer show hon."
 *
 * ── IS KAAM KA ASAL KHATRA ─────────────────────────────────────────────────
 *
 * Filter EVENTS par lagta hai, graahak par nahi — aur isi se paisa bhi bat
 * jata hai. Agar ek graahak ki do bookings hon, ek confirmed aur ek completed,
 * to "Confirmed" chunte hi us ka Billed/Received/Balance sirf PEHLI booking ka
 * hona chahiye. Agar filter sirf fehrist chhante aur adad poore dikhte rahen,
 * to screen ek rakam ke saamne doosri rakam likh degi — aur yehi wo screen hai
 * jahan se log paisa lete aur refund karte hain.
 *
 * Doosri taraf wohi khatra ulta: adad bat jayen magar screen ye na kahe ke ab
 * ye adhure hain. Is liye neeche dono baatein jaanchi jati hain — hisaab, aur
 * us par likhi hui tanbeeh.
 */
class CateringBalancesStatusFilterMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringCustomerBalanceService $balances;

    private int $branchId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_final_invoices', 'catering_advances', 'catering_refunds',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'journal_lines', 'journal_entries', 'accounts', 'payment_methods',
            'customers', 'product_translations', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder())->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->balances = app(CateringCustomerBalanceService::class);
        $this->branchId = $this->makeBranch();

        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'RICE']), [
            'name' => 'Biryani', 'sku' => 'SF1', 'unit_id' => $unitId, 'default_purchase_price' => 400,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Ek booking, diye hue total par, aur phir sidhi us haalat me rakh di. */
    private function booking(string $phone, float $total, string $status): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. FILTER',
            'customer_phone' => $phone,
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 50,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani', 'quantity' => 1, 'rate' => $total],
        ]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        // Haalat seedhi likhi ja rahi hai: is test ka sawal filter hai, poora
        // lifecycle nahi — aur har status tak asli raaste se pahunchna is test
        // ko us lifecycle ka test bana deta.
        $event->forceFill(['status' => $status])->save();

        return $event->refresh();
    }

    private function rowFor(string $phone, array $statuses = []): ?array
    {
        $customer = Customer::on('tenant')->where('phone', $phone)->first();
        if (! $customer) {
            return null;
        }

        return $this->balances->rows(null, null, $statuses)
            ->firstWhere('customer_id', $customer->id);
    }

    /**
     * SAB SE AHEM. Filter lagte hi PAISA bhi us hisse ka ho jaye.
     *
     * Ek graahak, do bookings: 100,000 confirmed aur 40,000 completed.
     * Bina filter 140,000; "Confirmed" par 100,000; "Completed" par 40,000.
     */
    public function test_the_filter_narrows_the_money_not_just_the_list(): void
    {
        $phone = '03001110001';
        $this->booking($phone, 100000, CateringEvent::STATUS_CONFIRMED);
        $this->booking($phone, 40000, CateringEvent::STATUS_COMPLETED);

        $all = $this->rowFor($phone);
        $this->assertSame(2, $all['events'], 'bina filter dono bookings');
        $this->assertSame(140000.0, $all['billed'], 'aur dono ka paisa');

        $confirmed = $this->rowFor($phone, [CateringEvent::STATUS_CONFIRMED]);
        $this->assertSame(1, $confirmed['events']);
        $this->assertSame(100000.0, $confirmed['billed'],
            'Confirmed chunte hi sirf us booking ka paisa — warna screen ek rakam ke saamne doosri likh degi');

        $completed = $this->rowFor($phone, [CateringEvent::STATUS_COMPLETED]);
        $this->assertSame(1, $completed['events']);
        $this->assertSame(40000.0, $completed['billed']);
    }

    /** Jis graahak ka koi event us haalat me nahi, wo fehrist se nikal jaye. */
    public function test_a_customer_with_nothing_in_that_status_drops_out(): void
    {
        $phone = '03001110002';
        $this->booking($phone, 50000, CateringEvent::STATUS_COMPLETED);

        $this->assertNotNull($this->rowFor($phone), 'bina filter mojood ho');
        $this->assertNull($this->rowFor($phone, [CateringEvent::STATUS_CONFIRMED]),
            'Confirmed par is graahak ka koi event nahi — wo dikhna hi nahi chahiye');
    }

    /** "Still open" apni tareef nahi rakhta — model ki OPEN_STATUSES hai. */
    public function test_still_open_means_exactly_what_the_model_says(): void
    {
        $phone = '03001110003';
        $this->booking($phone, 10000, CateringEvent::STATUS_QUOTED);      // open
        $this->booking($phone, 20000, CateringEvent::STATUS_CONFIRMED);   // open
        $this->booking($phone, 70000, CateringEvent::STATUS_RELEASED);    // open NAHI
        $this->booking($phone, 5000, CateringEvent::STATUS_COMPLETED);    // open NAHI

        $open = $this->rowFor($phone, CateringEvent::OPEN_STATUSES);
        $this->assertSame(2, $open['events']);
        $this->assertSame(30000.0, $open['billed'],
            'sirf quoted + confirmed — released aur completed OPEN_STATUSES me nahi hain');
    }

    /** Har graahak ke saamne us ki bookings ki haalat ki GINTI. */
    public function test_each_customer_carries_a_breakdown_of_its_statuses(): void
    {
        $phone = '03001110004';
        $this->booking($phone, 1000, CateringEvent::STATUS_CONFIRMED);
        $this->booking($phone, 2000, CateringEvent::STATUS_CONFIRMED);
        $this->booking($phone, 3000, CateringEvent::STATUS_COMPLETED);

        $counts = $this->rowFor($phone)['status_counts'];
        $this->assertSame(2, $counts[CateringEvent::STATUS_CONFIRMED]);
        $this->assertSame(1, $counts[CateringEvent::STATUS_COMPLETED]);

        // Tarteeb lifecycle ki ho, huroof-e-tahajji ki nahi: "Confirmed" se
        // pehle "Completed" likhna waqt ko ulta dikhata hai.
        $this->assertSame(
            [CateringEvent::STATUS_CONFIRMED, CateringEvent::STATUS_COMPLETED],
            array_keys($counts)
        );
    }

    /**
     * Screen par CONTROLLER se — aur sab se ahem, filter lagte hi wo tanbeeh
     * mojood ho ke ye adad adhure hain.
     *
     * Ye jaanch sidhi nahi, ULTI soorat par kaat-ti hai: bina filter wo tanbeeh
     * nahi honi chahiye, warna wo har waqt ki safedi ban kar bemani ho jati hai.
     */
    public function test_the_screen_says_when_the_figures_are_only_part_of_the_picture(): void
    {
        $phone = '03001110005';
        $this->booking($phone, 100000, CateringEvent::STATUS_CONFIRMED);
        $this->booking($phone, 40000, CateringEvent::STATUS_COMPLETED);

        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        \Illuminate\Support\Facades\Gate::before(fn (?\App\Models\Tenant\User $u = null) => true);
        $user = \App\Models\Tenant\User::on('tenant')
            ->find($this->makeUser(['employee_code' => 'SF'.\Illuminate\Support\Str::random(4)]));
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        $controller = app(\App\Http\Controllers\Tenant\Catering\CateringCustomerBalanceController::class);

        $plain = $controller->index(\Illuminate\Http\Request::create('/catering/customer-balances', 'GET'))->render();
        $this->assertStringContainsString('140,000.00', $plain, 'bina filter poora paisa');
        $this->assertStringNotContainsString('count these bookings only', $plain,
            'bina filter koi tanbeeh nahi honi chahiye');

        $filtered = $controller->index(\Illuminate\Http\Request::create(
            '/catering/customer-balances?status=confirmed', 'GET'))->render();
        $this->assertStringContainsString('100,000.00', $filtered, 'filter par sirf us booking ka paisa');
        $this->assertStringNotContainsString('140,000.00', $filtered, 'aur poora total nazar na aaye');
        $this->assertStringContainsString('count these bookings only', $filtered,
            'aur screen saaf kahe ke ye adad sirf in bookings ke hain');
    }

    /** URL me kuch bhi likh dene se query par kuch nahi lagta. */
    public function test_a_made_up_status_in_the_url_is_ignored_not_obeyed(): void
    {
        $phone = '03001110006';
        $this->booking($phone, 100000, CateringEvent::STATUS_CONFIRMED);

        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        \Illuminate\Support\Facades\Gate::before(fn (?\App\Models\Tenant\User $u = null) => true);
        $user = \App\Models\Tenant\User::on('tenant')
            ->find($this->makeUser(['employee_code' => 'SG'.\Illuminate\Support\Str::random(4)]));
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        $html = app(\App\Http\Controllers\Tenant\Catering\CateringCustomerBalanceController::class)
            ->index(\Illuminate\Http\Request::create(
                "/catering/customer-balances?status=' OR 1=1 --", 'GET'))->render();

        $this->assertStringContainsString('100,000.00', $html, 'bakwaas status par poori fehrist aaye');
        $this->assertStringNotContainsString('count these bookings only', $html,
            'aur wo filter laga hua na samjha jaye');
    }

    /**
     * Status ka naam aur rang EK jagah se aaye.
     *
     * Ye dono bookings ki fehrist ke Blade me inline likhe hue the. Yahan naql
     * bana lena sab se aasan tha — aur bilkul wohi ghalti jo isi hafte punch
     * grid me pakri gayi thi. Pehra us par.
     */
    public function test_the_status_vocabulary_is_written_in_exactly_one_place(): void
    {
        $this->assertSame('Production Ready', CateringEvent::statusLabel('production_ready'));
        $this->assertSame('success', CateringEvent::statusBadge(CateringEvent::STATUS_RELEASED));
        $this->assertSame('danger', CateringEvent::statusBadge(CateringEvent::STATUS_CANCELLED));
        $this->assertSame('secondary', CateringEvent::statusBadge('kuch_aur'));

        foreach ([
            'resources/views/tenant/catering/events/index.blade.php',
            'resources/views/tenant/catering/customer-balances/index.blade.php',
            'resources/views/tenant/catering/customer-balances/show.blade.php',
        ] as $path) {
            $code = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(base_path($path)));

            $this->assertDoesNotMatchRegularExpression(
                "/'confirmed',\s*'production_ready',\s*'released'\s*=>\s*'success'/", $code,
                "{$path} rang ka apna map rakh raha hai — wo CateringEvent::statusBadge() me hai"
            );
            $this->assertDoesNotMatchRegularExpression(
                "/ucwords\(str_replace\('_', ' ', \\\$event->status\)\)/", $code,
                "{$path} naam khud bana raha hai — wo CateringEvent::statusLabel() me hai"
            );
        }
    }
}
