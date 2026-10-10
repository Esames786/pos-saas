<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Support\TenantClock;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-INVOICE-VOID-1 — 10 October.
 *
 * Malik: "jo order ab complete ho gaye hain before date, un ki invoices ko
 * proforma mein change karna hoga taake wo order edit ho sake."
 *
 * Ab tak is ka koi raasta tha hi nahi — model ke apne comment me likha tha ke
 * "void/reversal policy is a future finance design", yani jaan boojh kar baad
 * ke liye chhora gaya aur banaya kabhi nahi gaya. Prod par 33 invoices me se
 * 3 apne din se pehle ban kar booking band kar baithi hain.
 *
 * ── IS KAAM KA ASAL KHATRA ─────────────────────────────────────────────────
 *
 * Paisa khaton me ja chuka hai. Agar bill par nishan lag jaye aur us ki journal
 * entries khari rahen, to kitaabein KHAMOSHI SE jhoot bolne lagti hain: aamdani
 * un khaton me padi rahegi jo wapas li ja chuki, aur `tb_diff` ko is ki khabar
 * tak nahi hogi kyunke dono taraf barabar hai.
 *
 * Is liye neeche har jaanch khaton se poochhti hai, bill ke row se nahi.
 */
class CateringInvoiceVoidMySqlTest extends MySqlTenantTestCase
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
            'name' => 'Biryani', 'sku' => 'VD1', 'unit_id' => $this->unitId,
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

    /** Ek bhari hui booking jis ka bill ban chuka hai — bilkul 0219 jaisi. */
    private function invoicedBooking(float $advance = 2000): array
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR.M JAVED',
            'customer_phone' => '0332-3593572',
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

        if ($advance > 0) {
            app(\App\Services\Catering\CateringAdvanceService::class)->record($event->refresh(), [
                'amount' => $advance,
                'payment_method_id' => $this->paymentMethodId,
                'received_date' => now()->subDays(2)->toDateString(),
            ]);
        }

        $invoice = $this->invoices->issue($event->refresh());

        return [$event->refresh(), $invoice];
    }

    /** Khaton ka mizan — debit aur credit ka farq. Sifar rehna chahiye, hamesha. */
    private function trialBalance(): float
    {
        return (float) DB::connection('tenant')->table('journal_lines')
            ->selectRaw('ROUND(COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0), 2) as d')->value('d');
    }

    /** Ek khaate ka asar — code se, id se nahi. */
    private function accountMovement(string $code): float
    {
        return (float) DB::connection('tenant')->table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)
            ->selectRaw('ROUND(COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0), 2) as d')
            ->value('d');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Khate — sab se ahem hissa.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Void ke baad khaton par bill ka koi nishan baqi na rahe.
     *
     * Ye `tb_diff` se NAHI poocha ja raha. Agar reversal post hi na ho to
     * `tb_diff` phir bhi sifar rahega — dono taraf barabar hai. Is liye HAR
     * mutaalliq khaate ka asar alag alag naapa ja raha hai.
     */
    public function test_voiding_puts_the_books_back_where_they_were(): void
    {
        // Pehle sirf booking aur advance — bill abhi nahi.
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR.M JAVED',
            'customer_phone' => '0332-3593572',
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

        // BILL SE PEHLE ki halat — yehi wo jagah hai jahan void ko wapas
        // le jana chahiye. "Sab khaane sifar" maangna GHALAT hota: graahak
        // ka 2,000 advance 2300 par liability ke taur par para hai aur usay
        // wahin rehna chahiye.
        $pehle = [];
        foreach (['4160', '1300', '2300', '1110'] as $code) { $pehle[$code] = $this->accountMovement($code); }
        $this->assertEqualsWithDelta(-2000.0, $pehle['2300'], 0.01,
            'bill se pehle advance 2300 par liability hai — warna neeche ka milan bemani hai');

        $invoice = $this->invoices->issue($event->refresh());

        $this->assertNotEqualsWithDelta($pehle['4160'], $this->accountMovement('4160'), 0.01,
            'bill ke baad aamdani darj honi chahiye — warna neeche ki jaanch bemani hai');

        $this->invoices->void($invoice, 'test');

        foreach ($pehle as $code => $tha) {
            $this->assertEqualsWithDelta($tha, $this->accountMovement($code), 0.01,
                "[{$code}] void ke baad bilkul wahin hona chahiye jahan bill se PEHLE tha");
        }

        $this->assertEqualsWithDelta(0.0, $this->trialBalance(), 0.01, 'khate barabar rahein');
    }

    /** Purani entries mitti nahi — reversal ALAG entry banti hai. */
    public function test_the_original_entries_are_reversed_not_deleted(): void
    {
        [, $invoice] = $this->invoicedBooking();

        $before = DB::connection('tenant')->table('journal_entries')
            ->whereIn('source_type', ['catering_final_invoice', 'catering_advance_application'])
            ->where('source_id', $invoice->id)->count();
        $this->assertSame(2, $before, 'bill ki do entries honi chahiyen');

        $this->invoices->void($invoice, 'test');

        $this->assertSame($before, DB::connection('tenant')->table('journal_entries')
            ->whereIn('source_type', ['catering_final_invoice', 'catering_advance_application'])
            ->where('source_id', $invoice->id)->count(),
            'purani entries jahan thin wahin rahein — gawahi mitti nahi');

        $this->assertSame(2, DB::connection('tenant')->table('journal_entries')
            ->where('is_reversal', 1)->where('source_id', $invoice->id)->count(),
            'aur do reversal entries bani hon');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Booking wapas khul jaye — yehi malik ka asal maqsad tha.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_order_becomes_editable_again(): void
    {
        [$event, $invoice] = $this->invoicedBooking();

        $this->assertFalse($event->isCommerciallyOpen(), 'bill ke baad band thi');

        $this->invoices->void($invoice, 'test');

        $this->assertTrue($event->fresh()->isCommerciallyOpen(),
            'void ke baad order edit hona chahiye — yehi poora maqsad tha');

        // Aur revision waqai ban jaye: ye dawa status par nahi, asal service par.
        $revision = $this->estimates->revise($event->fresh()->currentEstimate);
        $this->assertSame('draft', $revision->status);
    }

    /**
     * 🚨 VOID HUA BILL HAR SAWAL SE BAHAR.
     *
     * `finalInvoice` 14 files me 34 jagah parha jata hai. Agar void sirf ek
     * column hota aur har jagah shart alag likhni parti, to ek jagah bhoolte hi
     * booking par ek aisa bill "mojood" rehta jo void ho chuka — aur graahak ka
     * baqi ghalat ho jata.
     */
    public function test_a_voided_invoice_disappears_from_every_question(): void
    {
        [$event, $invoice] = $this->invoicedBooking();

        $this->invoices->void($invoice, 'test');

        $this->assertNull($event->fresh()->finalInvoice()->first(),
            'booking par koi bill na mile');
        $this->assertSame(0, CateringFinalInvoice::count(),
            'aur seedhi ginti me bhi na aaye');

        // Magar gawahi mojood rahe — wahan jahan maqsad hi wo ho.
        $this->assertSame(1, CateringFinalInvoice::withVoided()->count(),
            'withVoided() se nazar aana chahiye — row mitaya nahi gaya');

        $stored = CateringFinalInvoice::withVoided()->first();
        $this->assertNotNull($stored->voided_at);
        $this->assertSame('test', $stored->void_reason);
        $this->assertEqualsWithDelta(12000.0, (float) $stored->grand_total, 0.01,
            'bill ke apne adad waise ke waise rahein — void unhen badalta nahi');
    }

    /** Aur graahak ka paisa gum na ho — wo advance ke taur par wapas us ke naam par aa jaye. */
    public function test_the_customers_money_stays_his(): void
    {
        [$event, $invoice] = $this->invoicedBooking(2000);

        $this->invoices->void($invoice, 'test');

        $this->assertEqualsWithDelta(2000.0, (float) $event->fresh()->advances()->sum('amount'), 0.01,
            'advance ka apna row chhua tak na jaye');

        $position = app(\App\Services\Catering\CateringFinancialPositionService::class)->position($event->fresh());
        $this->assertEqualsWithDelta(2000.0, (float) $position['net_received'], 0.01,
            'aur hisaab me wo paisa ab bhi aaya hua gina jaye');
        $this->assertFalse($position['has_invoice'],
            'aur hisaab ab quotation se chale, us bill se nahi jo void ho chuka');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Aur jo NAHI hona chahiye.
    // ─────────────────────────────────────────────────────────────────────────

    /** Bina wajah ke void nahi — chhe mahine baad kisi ko yaad nahi rehta. */
    public function test_a_void_needs_a_reason(): void
    {
        [, $invoice] = $this->invoicedBooking();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->invoices->void($invoice, '   ');
    }

    /** Dobara chalane par kuch na ho — na doosri reversal, na doosra nishan. */
    public function test_voiding_twice_changes_nothing_more(): void
    {
        [, $invoice] = $this->invoicedBooking();

        $this->invoices->void($invoice, 'pehli baar');
        $reversals = DB::connection('tenant')->table('journal_entries')->where('is_reversal', 1)->count();
        $voidedAt = CateringFinalInvoice::withVoided()->first()->voided_at;

        $this->invoices->void(CateringFinalInvoice::withVoided()->first(), 'doosri baar');

        $this->assertSame($reversals, DB::connection('tenant')->table('journal_entries')->where('is_reversal', 1)->count(),
            'doosri reversal na bane');
        $this->assertEquals($voidedAt, CateringFinalInvoice::withVoided()->first()->voided_at,
            'aur pehla nishan hi rahe');
        $this->assertEqualsWithDelta(0.0, $this->trialBalance(), 0.01);
    }

    /**
     * Bill ke apne adad ab bhi jame hue hain.
     *
     * Void ka matlab "ye bill ab nahi chalta" hai, "ye bill badla ja sakta hai"
     * nahi. Wo pehra qayam rehna chahiye.
     */
    public function test_the_commercial_fields_are_still_frozen(): void
    {
        [, $invoice] = $this->invoicedBooking();
        $this->invoices->void($invoice, 'test');

        $stored = CateringFinalInvoice::withVoided()->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable once issued');

        $stored->forceFill(['grand_total' => 1])->save();
    }
}
