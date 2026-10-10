<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringCalendarService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Catering\CateringFinancialPositionService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-LIVE-BALANCE-1 — jo cheez "abhi kitna baqi hai" dikhaye, wo hisaab
 * lagaye.
 *
 * ASAL WAQEA (prod, 25 Sep): invoice CI-20260925-0002 gyara baj kar 39 minute
 * 54 second par jaari hui — 38,000 baqi. Graahak ne 11:41:04 par poore 38,000
 * de diye, yani SATTAR SECOND baad. Ledger durust hua, event ki screen ne 0
 * dikhaya — aur chhapa hua invoice mahinon "38,000 baqi" kehta raha. Malik ne
 * wohi kaghaz bheja aur poochha "ye due kyun dikha raha hai".
 *
 * WAJAH: wo kaghaz `catering_final_invoices.balance_due` parhta tha. Wo khaana
 * invoice jaari hote waqt likha jata hai aur phir kabhi nahi badalta.
 *
 * AUR WO BADAL BHI NAHI SAKTA — ye baat is test ki jaan hai. Model par pehra
 * hai: "a catering final invoice is immutable once issued". Pehli tajweez yehi
 * thi ke us khaane ko taza rakha jaye; wo tajweez is pehre se takraati hai aur
 * GHALAT thi. Ek jaari shuda invoice ek jama hua document hai. Is liye ilaj
 * ulta hai: khaana waisa hi jama rahe, aur AAJ ka jawab dene wali har jagah
 * hisaab lagaye.
 *
 * Neeche PAANCH jagah hain. Kharabi ek thi, magar us ke paanch munh the — aur
 * un me se ek ne kaam rok diya tha: ek poori ada-shuda booking UI se band hi
 * nahi ho sakti thi.
 */
class CateringLiveBalanceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringAdvanceService $advances;

    private int $branchId;

    private int $productId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_refunds', 'catering_final_invoices', 'catering_advances',
            'catering_material_rates',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'payment_methods', 'accounts', 'customers', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder())->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->advances = app(CateringAdvanceService::class);

        $this->branchId = $this->makeBranch();
        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'PKG', 'name' => 'Package', 'unit_type' => 'quantity',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'PACKAGES']), [
            'name' => 'Catering Package', 'sku' => 'LB1', 'unit_id' => $unitId,
            'default_purchase_price' => 400,
        ]);
        // Rate book ke baghair markSent() quotation rok deta hai — aur wo
        // rok durust hai: bina lagat ke graahak ko kaghaz nahi jata.
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // payment_methods.cash_bank_account_id `cash_bank_accounts` par jata
        // hai, seedha `accounts` par NAHI — pehli koshish me wahi ghalti hui
        // aur har test FK par gira.
        $cashBankId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => \App\Models\Tenant\Account::on('tenant')->where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $cashBankId]);
    }

    /**
     * Prod wali tarteeb, bilkul wohi: pehle invoice, PHIR paisa.
     *
     * Ye tarteeb ittefaq nahi — yehi aam soorat hai. Prod ki teen invoices me
     * se do aisi hi hain, aur teesri sirf is liye saaf thi ke us par paisa
     * invoice se PEHLE aa gaya tha.
     */
    private function bookingInvoicedThenPaid(float $total): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR,SHEHZAD',
            'customer_phone' => '03363503101',
            'booking_date' => now()->toDateString(),
            // CATERING-NOTHING-FREEZES-BEFORE-EVENT-1 (10 Oct) — ye fixture
            // pehle AANE WALI tareekh ka event banata tha aur phir us ka bill
            // bana deta tha. Wo surat asal duniya me mumkin hi nahi: bill event
            // guzarne ke baad banta hai. Fixture ab sach bol raha hai.
            'event_date' => now()->subDays(4)->toDateString(),
            'pax' => 100,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Catering Package', 'quantity' => 1, 'rate' => $total],
        ]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        app(CateringFinalInvoiceService::class)->issue($event->refresh());

        // ...aur ab paisa, invoice ke BAAD.
        $this->advances->record($event->refresh(), [
            'amount' => $total,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'reference' => 'SETTLE',
        ]);

        return $event->refresh();
    }

    /**
     * Pehle ye sabit karo ke haalat waqai wohi hai jis ki shikayat thi —
     * warna neeche ke saare test kisi aur cheez ka pehra hain.
     */
    public function test_the_frozen_column_really_does_go_stale(): void
    {
        $event = $this->bookingInvoicedThenPaid(38000);
        $invoice = $event->finalInvoice;

        $this->assertSame('38000.00', (string) $invoice->balance_due,
            'jama hua khaana purana hi rehna chahiye — ye kharabi nahi, DESIGN hai');

        $this->assertSame(0.0, app(CateringFinancialPositionService::class)->position($event)['balance_due'],
            'aur asal haalat ye hai ke kuch baqi nahi');
    }

    /** Aur wo khaana badla bhi nahi ja sakta — is liye hal use taza karna NAHI hai. */
    public function test_an_issued_invoice_refuses_to_be_rewritten(): void
    {
        $event = $this->bookingInvoicedThenPaid(38000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $event->finalInvoice->forceFill(['balance_due' => 0])->save();
    }

    /** 1. Chhapa hua invoice — wohi kaghaz jo malik ne bheja tha. */
    public function test_the_printed_invoice_shows_what_is_owed_today(): void
    {
        $event = $this->bookingInvoicedThenPaid(38000);

        $html = app(\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class)
            ->finalInvoice(Request::create('/x', 'GET', ['lang' => 'en']), $event->finalInvoice)
            ->render();

        // Probe zinda hai? Kaghaz waqai bana hai aur usi invoice ka hai.
        $this->assertStringContainsString($event->finalInvoice->invoice_no, $html,
            'kaghaz banna chahiye — warna neeche wali jaanchein bemani hain');

        $this->assertStringContainsString('FULLY PAID', $html,
            'poora paisa aa chuka hai, mohar lagni chahiye');

        // Baqi ki satar par SIFAR ho. Poore kaghaz me "38,000.00" dhoondna
        // ghalat jaanch hai — Net Total bhi wohi adad hai aur wo durust hai.
        // Is liye theek USI satar ko dekha ja raha hai.
        $this->assertMatchesRegularExpression(
            '/Balance Due<\/td><td class="num">0\.00<\/td>/', $html,
            'baqi ki satar par sifar hona chahiye, jama hua 38,000 nahi');
    }

    /** 2. Calendar — ada-shuda booking "Balance Due" par pari rehti thi. */
    public function test_the_calendar_calls_a_paid_booking_complete(): void
    {
        $event = $this->bookingInvoicedThenPaid(38000);
        $event->forceFill(['status' => CateringEvent::STATUS_COMPLETED])->save();

        // Event ko WAISE hi load karo jaise calendar ki apni query karti hai —
        // `nextAction()` usi ke sums par hisaab lagata hai. Sums ke baghair
        // load karne par test hara ho jata aur asal raaste ka kuch sabit na
        // hota.
        $loaded = CateringEvent::query()
            ->with('finalInvoice:id,catering_event_id,grand_total,balance_due,status')
            ->withSum('advances', 'amount')
            ->withSum('refunds', 'amount')
            ->withCount('productionReleases')
            ->findOrFail($event->id);

        $this->assertSame('Complete', app(CateringCalendarService::class)->nextAction($loaded),
            'paisa poora aa chuka hai — calendar par "Balance Due" nahi hona chahiye');
    }

    /**
     * 3. SAB SE AHEM — "Close booking" ka darwaza.
     *
     * Ye sirf ghalat adad nahi tha: prod par EV-20260925-0066 mukammal hai,
     * paisa poora aa chuka hai, aur us ka Close ka button CHHUPA HUA hai.
     * Booking phansi hui hai.
     */
    public function test_a_fully_paid_booking_can_be_closed(): void
    {
        $event = $this->bookingInvoicedThenPaid(38000);
        $event->forceFill(['status' => CateringEvent::STATUS_COMPLETED])->save();

        $billed = (float) $event->finalInvoice->grand_total;
        $received = (float) $event->advances()->sum('amount') - (float) $event->refunds()->sum('amount');

        $this->assertSame(0.0, CateringFinancialPositionService::outstanding($billed, $received),
            'darwaza isi hisaab par khulta hai');

        // Aur service khud bhi maan jaye — screen ka gate aur service ka faisla
        // ek doosre se ikhtilaf na karein.
        // CATERING-CLOSE-AFTER-EVENT-1 (8 Oct) — close ab event ke din se
        // pehle nahi hota. Ye booking mustaqbil ki tareekh par banti hai, is
        // liye band karne se pehle us ka din guzaar diya jata hai. Fixture ki
        // tareekh global badalna theek nahi hota: isi file ke doosre test
        // "aane wali booking" par khare hain.
        $event->forceFill(['event_date' => now()->subDay()->toDateString()])->save();
        app(CateringFinalInvoiceService::class)->close($event->refresh());

        $this->assertSame(CateringEvent::STATUS_CLOSED, $event->refresh()->status,
            'poori tarah ada-shuda booking band honi chahiye');
    }

    /**
     * 4. Qaida EK jagah rahe.
     *
     * Ye test hisaab nahi jaanchta — wo upar ho chuka. Ye ye jaanchta hai ke
     * hisaab DOBARA KAHIN AUR na likh diya jaye. Isi kharabi ki jarh yehi thi:
     * ek hi sawal ke chaar jawab chaar jagah likhe hue the, aur un me se teen
     * purana khaana parhte the.
     */
    public function test_nothing_reads_the_frozen_balance_as_if_it_were_current(): void
    {
        $readers = [
            'resources/views/tenant/catering/documents/final-invoice.blade.php',
            'app/Services/Catering/CateringDocumentPrintService.php',
            'app/Services/Catering/CateringCalendarService.php',
            'resources/views/tenant/catering/events/index.blade.php',
        ];

        foreach ($readers as $path) {
            $src = file_get_contents(base_path($path));

            // Probe zinda hai? File mojood aur khali nahi.
            $this->assertNotSame('', trim($src), "{$path} parhi jani chahiye");

            // COMMENTS HATA KAR dekho. Pehli koshish me ye jaanch apne hi
            // liye likhi gayi tashreeh par gir gayi — un comments par jo ye
            // BATATE hain ke purana khaana kyun nahi parha jata. Ek pehra jo
            // apni hi wazahat par kaat-ta ho, logon ko wazahat mitane par
            // majboor karta hai.
            $code = preg_replace(['/\{\{--.*?--\}\}/s', '/\/\*.*?\*\//s', '/\/\/[^
]*/'], '', $src);

            $this->assertDoesNotMatchRegularExpression(
                '/(invoice|finalInvoice)(\?)?->balance_due/i', $code,
                "{$path} ab bhi jama hua balance_due parh raha hai — wo adad invoice "
                .'jaari hote waqt ka hai, aaj ka nahi');
        }
    }
}
