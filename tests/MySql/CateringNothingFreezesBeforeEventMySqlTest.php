<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Support\TenantClock;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-NOTHING-FREEZES-BEFORE-EVENT-1 — 10 October.
 *
 * Malik: "jab tak event ka din na guzar jaye tab tak koi invoice freeze na ho,
 * na hi final ho. Order edit ho sake."
 *
 * ── PEHRA BANA THA, MAGAR GALAT DARWAZE PAR ────────────────────────────────
 *
 * 8 Oct ko `CATERING-CLOSE-AFTER-EVENT-1` bana: booking apne din se pehle CLOSE
 * nahi ho sakti. Magar asal tala close par nahi, INVOICE par lagta hai —
 * `issue()` khud event ko `completed` kar deta hai, aur `completed` par
 * `isCommerciallyOpen()` jhoot ho kar "Create Revision" chhupa deta hai.
 *
 * Yani darwaza B par taala laga tha aur log darwaze A se andar aate rahe.
 * Prod par 33 me se 3 invoices apne din se pehle bane; EV-20261009-0219
 * (event 10 Oct, bill 9 Oct) wo case hai jis par malik ne ungli rakhi.
 *
 * Neeche ki har jaanch EK sawal poochhti hai: din guzre baghair kya kuch jam
 * to nahi raha, aur kya order abhi bhi khula hai?
 */
class CateringNothingFreezesBeforeEventMySqlTest extends MySqlTenantTestCase
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

        $this->cleanTenant([
            'catering_material_issue_lines', 'catering_material_issues',
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
            'name' => 'Biryani Masala Chicken Aaloo', 'sku' => 'NF1', 'unit_id' => $this->unitId,
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

    /** Ek booking jo bill ke liye poori tarah tayyar hai — sirf din ka faasla hai. */
    private function readyBooking(string $eventDate): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR.M JAVED',
            'customer_phone' => '0332-3593572',
            'booking_date' => now()->subDays(3)->toDateString(),
            'event_date' => $eventDate,
            'pax' => 30,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Biryani Masala Chicken Aaloo',
            'quantity' => 6, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 1865,
        ]]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->markAccepted($event->currentEstimate->refresh());

        return $event->refresh();
    }

    private function today(): string
    {
        return app(TenantClock::class)->now()->toDateString();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Din guzre baghair kuch jamta nahi.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_invoice_cannot_be_issued_before_the_event_day(): void
    {
        $event = $this->readyBooking(app(TenantClock::class)->now()->addDay()->toDateString());

        try {
            $this->invoices->issue($event);
            $this->fail('kal ki booking par aaj bill ban gaya — yehi EV-20261009-0219 me hua tha');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('guzra nahi', $e->getMessage());
        }

        $this->assertNull($event->refresh()->finalInvoice()->first(), 'koi invoice bana hi na ho');
    }

    /**
     * EVENT KE DIN BHI NAHI — aur ye jaan boojh kar hai.
     *
     * Malik: "aaj 10 hai, aaj complete nahi hone dena tha." Us din khana abhi
     * ja raha hota hai aur rakam badal sakti hai. Purana close-pehra `> today`
     * tha, yani event ke din band hone deta tha; ab dono darwaze `< today`
     * lagate hain.
     */
    public function test_not_even_on_the_event_day_itself(): void
    {
        $event = $this->readyBooking($this->today());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('guzra nahi');

        $this->invoices->issue($event);
    }

    /** Aur din guzarte hi bill ban jata hai — warna ye pehra ek deewar hota. */
    public function test_once_the_day_has_passed_the_invoice_issues(): void
    {
        $event = $this->readyBooking(app(TenantClock::class)->now()->subDay()->toDateString());

        $invoice = $this->invoices->issue($event);

        $this->assertNotNull($invoice);
        $this->assertEqualsWithDelta(11190.0, (float) $invoice->grand_total, 0.01);
        $this->assertSame(CateringEvent::STATUS_COMPLETED, $event->refresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Aur us doran order KHULA rehta hai — yehi malik ka asal matlab tha.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_order_stays_editable_right_up_to_the_event_day(): void
    {
        $event = $this->readyBooking($this->today());

        $this->assertTrue($event->isCommerciallyOpen(),
            'booking khuli honi chahiye — isi par "Create Revision" ka button tika hai');

        // Aur revision waqai ban jata hai: ye dawa screen par nahi, asal
        // service par jaancha ja raha hai.
        $revision = $this->estimates->revise($event->currentEstimate);
        $this->estimates->saveDraftLines($revision, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani Masala Chicken Aaloo',
                'quantity' => 6, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 1865],
            ['product_id' => $this->productId, 'item_name' => 'Rabri Kheer',
                'quantity' => 5, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 1000],
        ]);

        $this->assertCount(2, $event->refresh()->currentEstimate->lines,
            'order me nayi dish jur jani chahiye');
    }

    /** Paisa pehle bhi liya ja sakta hai — wo advance hai, aamdani nahi. */
    public function test_money_can_still_be_taken_before_the_event(): void
    {
        $event = $this->readyBooking(app(TenantClock::class)->now()->addDays(2)->toDateString());

        $advance = app(\App\Services\Catering\CateringAdvanceService::class)->record($event, [
            'amount' => 2000,
            'payment_method_id' => $this->paymentMethodId,
            'received_date' => $this->today(),
        ]);

        $this->assertSame(\App\Models\Tenant\CateringAdvance::POSTING_ADVANCE, $advance->posting_type,
            'invoice se pehle aaya paisa ADVANCE hai — aamdani nahi, kyunke khana abhi gaya hi nahi');
        $this->assertTrue($event->refresh()->isCommerciallyOpen(),
            'paisa lene se booking band nahi honi chahiye');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dono darwaze ek hi qaida lagayen.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Close aur Invoice ka qaida barabar ho.
     *
     * Ye jaanch aise hi nahi likhi: poora masla hi ye tha ke ek darwaze par
     * pehra laga aur doosra khula reh gaya. Agar kal koi ek hadd badle aur
     * doosri na badle, ye test wohi farq pakrega.
     */
    public function test_both_doors_use_the_same_rule(): void
    {
        $service = file_get_contents(app_path('Services/Catering/CateringFinalInvoiceService.php'));

        $this->assertSame(2, substr_count($service, 'refuseBeforeTheEventDayHasPassed($event'),
            'dono darwaze (bill banana aur booking band karna) ek hi qaide ko bulayen');
        $this->assertStringContainsString('toDateString() < $today', $service,
            'hadd "din guzar jaye" ho — "din aa jaye" nahi');

        // Lamhon ka muqabla WAPAS na aaye: `event_date` UTC ki aadhi raat hai
        // aur TenantClock ki aadhi raat Karachi ki. Ye ghalti yahan ek baar ho
        // chuki hai.
        //
        // COMMENT nikal kar dekha ja raha hai: us purani ghalti ki tafseel isi
        // file ke comment me likhi hui hai, aur pehli koshish me ye jaanch usi
        // tafseel par kaat gayi thi — code par nahi.
        $code = preg_replace(['~/\*.*?\*/~s', '~//[^
]*~'], '', $service);
        $this->assertStringNotContainsString('startOfDay()', $code,
            'tareekh ki string par muqabla — lamhon par nahi');
        $this->assertStringContainsString('startOfDay()', $service,
            'aur wo tanbeeh comment me mojood rahe — warna agla aadmi wohi ghalti dobara karega');
    }

    /** Aur screen chup chaap button na chhupaye — wajah likhe. */
    public function test_the_screen_says_why_the_invoice_button_is_not_there(): void
    {
        $blade = file_get_contents(resource_path('views/tenant/catering/events/show.blade.php'));

        $this->assertStringContainsString('Final invoice event ke baad', $blade,
            'operator ko wajah dikhni chahiye — gayab button "kuch toota hai" jaisa lagta hai');
        $this->assertStringContainsString('$eventDayPassed', $blade);

        // 🚨 Inline `@php(` is file me 500 de chuka hai: Blade us se aage pehle
        // `@endphp` tak sab nigal jata hai, aur agla block sainkron satar neeche
        // hai. Block form hi chalegi.
        $this->assertStringNotContainsString('@php($eventDayPassed', $blade,
            'inline @php( nahi — block form, warna neeche ka poora hissa nigal jata hai');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // "Aisa na ho ke main order update kar doon aur baqi jagahon par purana
    // data aa raha ho" — malik, 10 Oct.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * EK pehra, TEENON kaghazon par — aur ye jaan boojh kar ek hi test hai.
     *
     * Teen alag test likhne par ek din koi ek kaghaz chup-chaap peeche reh
     * jayega aur baqi do hare rahenge. Bilkul yehi hua tha: kitchen sheet ek
     * jami hui nakal par chal raha tha, quotation aage nikal gayi thi, aur
     * kisi test ne nahi poochha ke dono ek baat kehte hain ya nahi.
     *
     * Is liye sawal ek hai: order me nayi dish dalo, phir HAR kaghaz uthao aur
     * dekho ke wo dish us par hai.
     */
    public function test_one_order_change_shows_up_on_every_paper(): void
    {
        $event = $this->readyBooking(app(TenantClock::class)->now()->addDay()->toDateString());

        // Kitchen ko bhej do — taake parcha ek jami hui release se bane, yani
        // wohi surat jahan se poora masla shuru hua tha.
        app(\App\Services\Catering\CateringProductionReleaseService::class)->release($event->refresh());

        // Ab order badlo — aur KOI status qadam nahi. Revision draft hi rehta
        // hai: malik ne yehi maanga tha ("customer accept wagera kuch na karna
        // pare").
        $revision = $this->estimates->revise($event->refresh()->currentEstimate);
        $this->estimates->saveDraftLines($revision, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani Masala Chicken Aaloo',
                'quantity' => 6, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 1865],
            ['product_id' => $this->productId, 'item_name' => 'Rabri Kheer',
                'quantity' => 5, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 1000],
        ]);
        $this->assertSame('draft', $revision->refresh()->status,
            'revision draft hi rahe — warna ye test us qadam ko chhupa raha hai jo malik nahi karna chahte');

        $event->refresh();
        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);

        $papers = [];

        // 1) Kitchen sheet — asal bulk raasta, wohi jo browser me khulta hai.
        $res = app(\App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController::class)
            ->kitchenSheets(\Illuminate\Http\Request::create('/x', 'GET', ['ids' => [$event->id]]));
        $papers['kitchen sheet'] = is_object($res) && method_exists($res, 'getContent')
            ? $res->getContent() : (string) $res->render();

        // 2) Proforma — graahak ko dene wala bill.
        $docs = app(\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class);
        $papers['proforma'] = (string) $docs
            ->proformaInvoice(\Illuminate\Http\Request::create('/x', 'GET'), $event)
            ->render();

        // 3) Quotation.
        $papers['quotation'] = (string) $docs
            ->estimate(\Illuminate\Http\Request::create('/x', 'GET'), $event->currentEstimate)
            ->render();

        foreach ($papers as $naam => $html) {
            $this->assertStringContainsString('Rabri Kheer', $html,
                "[{$naam}] par nayi dish aani chahiye — warna operator order badal kar bhi purana kaghaz de dega");
        }
    }

    /** Aur proforma kuch MEHFOOZ na kare — na number, na status, na GL. */
    public function test_a_proforma_writes_nothing_at_all(): void
    {
        $event = $this->readyBooking(app(TenantClock::class)->now()->addDays(2)->toDateString());

        $before = [
            'invoices' => DB::connection('tenant')->table('catering_final_invoices')->count(),
            'entries' => DB::connection('tenant')->table('journal_entries')->count(),
            'status' => $event->status,
        ];

        $proforma = $this->invoices->proforma($event);

        $this->assertFalse($proforma->exists, 'proforma database me hai hi nahi');
        $this->assertSame('PROFORMA', $proforma->invoice_no,
            'qatar ka number kharch na ho — ek proforma par number lagana us qatar me hamesha ka sooraakh chhod deta hai');
        $this->assertEqualsWithDelta(11190.0, (float) $proforma->grand_total, 0.01,
            'adad maujooda order ke hon');

        $this->assertSame($before['invoices'], DB::connection('tenant')->table('catering_final_invoices')->count(),
            'koi invoice row na bane');
        $this->assertSame($before['entries'], DB::connection('tenant')->table('journal_entries')->count(),
            'koi journal entry na bane — proforma khaton me kuch nahi dalta');
        $this->assertSame($before['status'], $event->refresh()->status,
            'booking ka status na hile');
    }

    /**
     * Proforma aur asli bill ke adad EK jagah se aayen.
     *
     * Do nakal rakhne ka anjaam maloom hai: ek din graahak ka proforma aur us
     * ka bill alag adad kehne lagte, aur farq graahak ke saamne khulta.
     */
    public function test_the_proforma_and_the_real_invoice_are_built_from_the_same_place(): void
    {
        $event = $this->readyBooking(app(TenantClock::class)->now()->subDay()->toDateString());

        $proforma = $this->invoices->proforma($event);
        $real = $this->invoices->issue($event->refresh());

        foreach (['subtotal', 'service_charge_amount', 'discount_amount', 'grand_total', 'balance_due'] as $khaana) {
            $this->assertEqualsWithDelta((float) $proforma->$khaana, (float) $real->$khaana, 0.01,
                "[{$khaana}] dono par ek jaisa hona chahiye");
        }

        $service = file_get_contents(app_path('Services/Catering/CateringFinalInvoiceService.php'));
        $this->assertSame(2, substr_count($service, 'documentAttributesFor($event, $estimate'),
            'dono ek hi builder se banein — do nakal rakhne par wo ek din alag ho jayengi');
    }
}
