<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringFinalInvoiceController;
use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Support\TenantClock;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-VOID-BUTTON-1 — 13 October.
 *
 * Malik ne 11 Oct ko kaha tha "ye order cancel kardo, test entry thi" aur
 * maine wo server se haath se chalaya tha. Phir unhone poochha: "ye system
 * mein kaise karsakte hain?" — jawab tha: nahi kar sakte. Ab kar sakte hain.
 *
 * ── IS SCREEN KA ASAL KHATRA ───────────────────────────────────────────────
 *
 * Ye is module ka sab se bara hathyar hai. Ek ghalat click ek ASLI graahak ka
 * asli bill aur us ki asli adaygiyan khaton se nikal deta hai, aur wo wapas
 * nahi hota. Is liye neeche ki aadhi jaanchein us par hain jo NAHI hona
 * chahiye.
 */
class CateringVoidButtonMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringFinalInvoiceService $invoices;

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
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true);

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_final_invoices', 'catering_advances', 'catering_refunds',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'journal_lines', 'journal_entries',
            'cash_bank_account_transactions', 'cash_bank_accounts', 'accounts', 'payment_methods',
            'customers', 'product_translations', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->invoices = app(CateringFinalInvoiceService::class);
        $this->branchId = $this->makeBranch();

        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'RICE']), [
            'name' => 'Biryani', 'sku' => 'VB1', 'unit_id' => $this->unitId,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400, 'unit_id' => $this->unitId,
            'effective_from' => now()->subYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cashAccountId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 900000, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $cashAccountId]);
    }

    /** Ek bhari hui, bill shuda booking. */
    private function invoicedBooking(): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. VOID',
            'customer_phone' => '0300-7778889',
            'booking_date' => now()->subDays(5)->toDateString(),
            'event_date' => app(TenantClock::class)->now()->subDay()->toDateString(),
            'pax' => 30,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Biryani',
            'quantity' => 6, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 2000,
        ]]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->markAccepted($event->currentEstimate->refresh());

        app(\App\Services\Catering\CateringAdvanceService::class)->record($event->refresh(), [
            'amount' => 2000,
            'payment_method_id' => $this->paymentMethodId,
            'received_date' => now()->subDays(2)->toDateString(),
        ]);

        $this->invoices->issue($event->refresh());

        return $event->refresh();
    }

    private function accountMovement(string $code): float
    {
        return (float) DB::connection('tenant')->table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)
            ->selectRaw('ROUND(COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0), 2) as d')
            ->value('d');
    }

    /** `post()` naam Laravel ke apne TestCase::post() se takrata hai. */
    private function sendVoid(CateringEvent $event, array $data)
    {
        $req = Request::create('/x', 'POST', $data);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);

        return app(CateringFinalInvoiceController::class)->voidEverything($req, $event);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Jo hona chahiye.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_button_takes_the_booking_out_of_the_books(): void
    {
        $event = $this->invoicedBooking();
        $this->assertNotEqualsWithDelta(0.0, $this->accountMovement('4160'), 0.01);

        $this->sendVoid($event, ['reason' => 'test entry thi', 'confirm_event_no' => $event->event_no]);

        foreach (['4160', '1300', '1110'] as $code) {
            $this->assertEqualsWithDelta(0.0, $this->accountMovement($code), 0.01,
                "[{$code}] par is booking ka koi asar baqi na rahe");
        }
        $this->assertTrue($event->fresh()->isCancelled());
    }

    /**
     * Dabba jo adad dikhata hai, wo WAHI hon jo asal me hote hain.
     *
     * Preview ke liye alag hisaab likhna sab se khatarnak shakl hai: screen
     * kuch dikhati aur button kuch aur karta, aur farq tabhi khulta jab paisa
     * hil chuka hota. Is liye yahan preview ko asal natije se milaya ja raha
     * hai — alfaz se nahi, adad se.
     */
    public function test_the_preview_matches_what_actually_happens(): void
    {
        $event = $this->invoicedBooking();

        $preview = $this->invoices->voidPreview($event);
        $this->assertNotEmpty($preview['accounts'], 'preview khali nahi hona chahiye');

        $pehle = [];
        foreach (array_keys($preview['accounts']) as $code) { $pehle[$code] = $this->accountMovement($code); }

        $this->sendVoid($event, ['reason' => 'test entry thi', 'confirm_event_no' => $event->event_no]);

        foreach ($preview['accounts'] as $code => $khaata) {
            $this->assertEqualsWithDelta(
                $pehle[$code] + $khaata['delta'],
                $this->accountMovement($code),
                0.01,
                "[{$code}] par preview ne {$khaata['delta']} kaha tha — asal natija wohi hona chahiye"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Aur jo NAHI hona chahiye — yahi is screen ka asal kaam hai.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GHALAT NUMBER PAR KUCH BHI NA HO.
     *
     * Ye sirf ek paighaam ki jaanch nahi: neeche khaate bhi naape ja rahe hain.
     * Ek aisi soorat jahan number mel na khaye magar kaam phir bhi ho jaye,
     * poori tasdeeq ko bemani bana deti hai.
     */
    public function test_a_wrong_booking_number_changes_nothing(): void
    {
        $event = $this->invoicedBooking();
        $pehle = $this->accountMovement('4160');

        $this->sendVoid($event, ['reason' => 'test', 'confirm_event_no' => 'EV-00000000-9999']);

        $this->assertEqualsWithDelta($pehle, $this->accountMovement('4160'), 0.01,
            'khaton me kuch hila tak na ho');
        $this->assertFalse($event->fresh()->isCancelled(), 'booking waisi hi rahe');
        $this->assertNotNull($event->fresh()->finalInvoice()->first(), 'bill bhi waisa hi rahe');
    }

    /** Aur khali number bhi na chale — "OK" jaisa koi shortcut nahi. */
    public function test_an_empty_confirmation_is_refused(): void
    {
        $event = $this->invoicedBooking();

        try {
            $this->sendVoid($event, ['reason' => 'test', 'confirm_event_no' => '']);
            $this->fail('khali tasdeeq par ruk jana chahiye tha');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('confirm_event_no', $e->errors());
        }

        $this->assertFalse($event->fresh()->isCancelled());
    }

    /** Bina wajah ke bhi nahi. */
    public function test_a_missing_reason_is_refused(): void
    {
        $event = $this->invoicedBooking();

        try {
            $this->sendVoid($event, ['reason' => '', 'confirm_event_no' => $event->event_no]);
            $this->fail('bina wajah ke ruk jana chahiye tha');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $this->assertFalse($event->fresh()->isCancelled());
    }

    /**
     * Screen par wo teenon rukavatein mojood hon.
     *
     * Service par pehra hona kaafi nahi: agar screen number maange hi na, to
     * operator ke liye ye ek aam button ban jata hai aur rukavat ka poora
     * maqsad khatam ho jata hai.
     */
    public function test_the_screen_asks_for_the_booking_number_and_a_reason(): void
    {
        $blade = file_get_contents(resource_path('views/tenant/catering/events/show.blade.php'));

        $this->assertStringContainsString('name="confirm_event_no"', $blade,
            'tasdeeq ke liye booking ka number maanga jaye — sirf OK kaafi nahi');
        $this->assertStringContainsString('void-everything-modal', $blade, 'dabba mojood ho');
        $this->assertMatchesRegularExpression('/name="reason"[^>]*required/', $blade,
            'wajah lazmi ho');

        // Aur button sirf Owner ko — ye permission is route ki apni hai.
        $this->assertStringContainsString("@can('tenant.catering.final-invoices.void-everything')", $blade,
            'button permission ke peechhe ho');
    }

    /** Jo booking khaton me kuch rakhti hi nahi, us par khatarnak button na dikhe. */
    public function test_a_booking_with_nothing_posted_shows_no_button(): void
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. EMPTY',
            'customer_phone' => '0300-1112221',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(4)->toDateString(),
            'pax' => 10,
        ]);

        $preview = $this->invoices->voidPreview($event->refresh());

        $this->assertSame(0, $preview['entries'],
            'khaton me kuch hai hi nahi — yani button bhi nahi aana chahiye');
        $this->assertSame([], $preview['accounts']);
    }
}
