<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringAdvance;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\Customer;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringCustomerBalanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Catering\CateringFinancialPositionService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-ADVANCE-VOID-1 — darj shuda receipt ki ghalti theek karna.
 *
 * Malik (1 Oct): "client keh raha hai us ne amount daalte hue ghalti kar di,
 * ya reference likhte hue — wo kaise edit hoga?"
 *
 * Aaj tak koi raasta tha hi nahi: receipt par sirf `store` tha.
 *
 * AMOUNT KA "EDIT" JAAN-BOOJH KAR NAHI BANAYA GAYA. Receipt darj hote hi
 * paisa hil chuka hota hai — journal entry ban chuki hoti hai aur cash/bank
 * ka balance barh chuka hota hai. Us adad ko chup chaap badal dena kitabon me
 * ek adad aur screen par doosra chhor deta, bina kisi nishani ke. Is liye
 * receipt ULTI hoti hai aur sahi nayi darj hoti hai.
 *
 * Reference alag cheez hai: wo sirf ek label hai aur us ke liye paisa hilana
 * bemani hoga.
 *
 * ── IS FILE KA SAB SE AHEM TEST ────────────────────────────────────────────
 *
 * `test_voided_money_disappears_from_every_total`. Advances ka paisa NAU
 * jagah gina jata hai. Agar ulti hui rakam un me se EK jagah bhi reh gayi, to
 * graahak ke zimme kam paisa dikhega aur kisi ko pata nahi chalega. Isi liye
 * model par global scope hai — aur isi liye us par pehra bhi.
 */
class CateringAdvanceVoidMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringAdvanceService $advances;

    private int $branchId;

    private int $productId;

    private int $paymentMethodId;

    private int $cashBankId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_final_invoices', 'catering_advances', 'catering_refunds',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            // Profiles aur cost blocks bhi — inhein chhor dena akele chalne par
            // nazar nahi aata magar poori suite me screen 500 de deti hai:
            // `products` khaali ho jata hai aur pichhle test ka profile ek aise
            // product ki taraf ishara karta reh jata hai jo ab hai hi nahi.
            // (Asal system me ye soorat ban hi nahi sakti — FK cascadeOnDelete
            // hai aur Product soft-delete nahi karta. Ye sirf test ki safai hai.)
            'catering_product_cost_blocks', 'catering_product_profiles',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'payment_methods', 'customers', 'product_translations',
            'units', 'products', 'categories', 'branches',
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
            'name' => 'Catering Package', 'sku' => 'AV1', 'unit_id' => $unitId, 'default_purchase_price' => 400,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->cashBankId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => Account::on('tenant')->where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $this->cashBankId]);
    }

    private function bookingWithReceipt(float $total, float $received): array
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. VOID',
            'customer_phone' => '03001230000',
            'booking_date' => now()->toDateString(),
            // CATERING-NOTHING-FREEZES-BEFORE-EVENT-1 (10 Oct) — ye fixture
            // pehle AANE WALI tareekh ka event banata tha aur phir us ka bill
            // bana deta tha. Wo surat asal duniya me mumkin hi nahi: bill event
            // guzarne ke baad banta hai. Fixture ab sach bol raha hai.
            'event_date' => now()->subDays(4)->toDateString(),
            'pax' => 50,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Catering Package', 'quantity' => 1, 'rate' => $total],
        ]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        $advance = $this->advances->record($event->refresh(), [
            'amount' => $received,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'reference' => 'SLIP-WRONG',
        ]);

        return [$event->refresh(), $advance];
    }

    private function cashBalance(): float
    {
        return round((float) DB::connection('tenant')->table('cash_bank_accounts')
            ->where('id', $this->cashBankId)->value('current_balance'), 2);
    }

    /** Ulta karna: journal ulti, cash wapas, receipt nishan-zada. */
    public function test_voiding_reverses_the_journal_and_the_cash(): void
    {
        [$event, $advance] = $this->bookingWithReceipt(100000, 40000);

        $this->assertSame(40000.0, $this->cashBalance(), 'receipt par cash barhna chahiye tha');
        $entriesBefore = DB::connection('tenant')->table('journal_entries')->count();

        $this->advances->void($advance, 'amount ghalat daal diya tha', null);

        $voided = CateringAdvance::withoutGlobalScope('notVoided')->findOrFail($advance->id);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('amount ghalat daal diya tha', $voided->void_reason);

        $this->assertSame(0.0, $this->cashBalance(), 'cash wapas honi chahiye');
        $this->assertSame($entriesBefore + 1, DB::connection('tenant')->table('journal_entries')->count(),
            'ek ULTI journal entry banni chahiye — purani mitti nahi');
        $this->assertNotNull($voided->void_journal_entry_id, 'ulti entry receipt se juri ho');
    }

    /**
     * SAB SE AHEM. Ulti hui rakam HAR ginti se nikal jaye.
     *
     * Ye alag alag raaste jaan-boojh kar jaanche ja rahe hain — position(),
     * Customer Balances, aur seedha relation — kyunke paisa nau jagah gina
     * jata hai aur global scope ka poora maqsad yehi hai ke koi jagah chhoot
     * na jaye.
     */
    public function test_voided_money_disappears_from_every_total(): void
    {
        [$event, $advance] = $this->bookingWithReceipt(100000, 40000);
        $position = app(CateringFinancialPositionService::class);
        $balances = app(CateringCustomerBalanceService::class);
        $customer = Customer::on('tenant')->where('phone', '03001230000')->firstOrFail();

        // Pehle probe zinda sabit karo: abhi paisa har jagah dikhta hai.
        $this->assertSame(40000.0, $position->position($event)['net_received']);
        $this->assertSame(40000.0, $balances->rows()->firstWhere('customer_id', $customer->id)['received']);
        $this->assertSame(40000.0, round((float) $event->advances()->sum('amount'), 2));

        $this->advances->void($advance, 'duplicate entry', null);

        $fresh = $event->fresh();
        $this->assertSame(0.0, $position->position($fresh)['net_received'],
            'position() me ulti rakam nahi honi chahiye');
        $this->assertSame(100000.0, $position->position($fresh)['balance_due'],
            'aur baqi poora wapas aana chahiye');
        $this->assertSame(0.0, $balances->rows()->firstWhere('customer_id', $customer->id)['received'],
            'Customer Balances me bhi nahi');
        $this->assertSame(0.0, round((float) $fresh->advances()->sum('amount'), 2),
            'relation se bhi nahi — global scope ka yehi kaam hai');
    }

    /**
     * ...magar LEDGER par wo nazar aaye. Us ka hona khud khabar hai: "paisa
     * aaya tha aur phir ulta kiya gaya" wo baat hai jo record me likhi honi
     * chahiye, warna ek khala reh jata hai aur koi nahi jaanta kyun.
     */
    public function test_the_ledger_still_shows_the_voided_receipt(): void
    {
        [$event, $advance] = $this->bookingWithReceipt(100000, 40000);
        $this->advances->void($advance, 'client ne ghalat slip di thi', null);

        $ledger = app(CateringFinancialPositionService::class)->ledger($event->fresh());
        $row = collect($ledger)->first(fn ($r) => str_contains($r['type'], 'VOIDED'));

        $this->assertNotNull($row, 'ulti hui receipt ledger par honi chahiye');
        $this->assertStringContainsString('client ne ghalat slip di thi', (string) $row['note'],
            'aur wajah us ke saath');
        $this->assertSame(0.0, (float) $row['money_in'],
            'magar hisaab me na jore — wo paisa wapas ja chuka hai');
    }

    /** Do baar dabane se paisa do baar wapas na ho. */
    public function test_voiding_twice_does_not_reverse_the_money_twice(): void
    {
        [$event, $advance] = $this->bookingWithReceipt(100000, 40000);
        $this->advances->void($advance, 'pehli baar', null);

        $cashAfterFirst = $this->cashBalance();
        $txnsAfterFirst = DB::connection('tenant')->table('cash_bank_account_transactions')
            ->where('transaction_type', 'catering_advance_void_reversal')->count();

        try {
            $this->advances->void($advance->fresh() ?? $advance, 'doosri baar', null);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pehle hi ulti', $e->getMessage());
        }

        $this->assertSame($cashAfterFirst, $this->cashBalance(), 'cash dobara nahi hilni chahiye');
        $this->assertSame($txnsAfterFirst, DB::connection('tenant')->table('cash_bank_account_transactions')
            ->where('transaction_type', 'catering_advance_void_reversal')->count(),
            'doosra reversal nahi banna chahiye');
    }

    /**
     * Invoice ke baad void nahi. Jaari shuda invoice apne andar ye rakam jama
     * kar chuki hoti hai aur wo document immutable hai — receipt ulti kar dene
     * se invoice ek aisi rakam ginti rehti jo mojood hi nahi. Us surat me sahi
     * raasta refund hai.
     */
    public function test_a_receipt_cannot_be_voided_once_the_invoice_exists(): void
    {
        [$event, $advance] = $this->bookingWithReceipt(100000, 40000);
        app(CateringFinalInvoiceService::class)->issue($event->refresh());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refund istemaal karein');

        $this->advances->void($advance, 'bahut der kar di', null);
    }

    /** Reference theek karna sirf label badalta hai — paisa nahi hilta. */
    public function test_fixing_the_reference_moves_no_money(): void
    {
        [$event, $advance] = $this->bookingWithReceipt(100000, 40000);
        $cashBefore = $this->cashBalance();
        $entriesBefore = DB::connection('tenant')->table('journal_entries')->count();

        $this->advances->updateReference($advance, 'SLIP-4821', 'bank slip, counter 2');

        $fresh = CateringAdvance::findOrFail($advance->id);
        $this->assertSame('SLIP-4821', $fresh->reference);
        $this->assertSame('bank slip, counter 2', $fresh->notes);
        $this->assertSame(40000.0, round((float) $fresh->amount, 2), 'rakam waisi hi rehni chahiye');

        $this->assertSame($cashBefore, $this->cashBalance(), 'cash nahi hilni chahiye');
        $this->assertSame($entriesBefore, DB::connection('tenant')->table('journal_entries')->count(),
            'koi nayi journal entry nahi banni chahiye');
    }

    /** Wajah ke baghair void nahi — chhe mahine baad jawab sirf wahin milega. */
    public function test_voiding_needs_a_reason(): void
    {
        [$event, $advance] = $this->bookingWithReceipt(100000, 40000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wajah');

        $this->advances->void($advance, '   ', null);
    }

    /** Screen ko us haalat me laana jahan asal controller chal sake. */
    private function actAsOperator(): void
    {
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        \Illuminate\Support\Facades\Gate::before(fn (?\App\Models\Tenant\User $u = null) => true);
        $user = \App\Models\Tenant\User::on('tenant')
            ->find($this->makeUser(['employee_code' => 'AV'.\Illuminate\Support\Str::random(4)]));
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');
    }

    /**
     * DONO screens par — malik ne yehi kaha tha.
     *
     * Pehle booking ki screen par ye kaam maanga, phir Customer Catering
     * Balances ka screenshot bhej kar kaha "is screen pe bhi". Ek jagah ban
     * jana aur doosri reh jana bilkul wohi shakal hai jo is project me pehle
     * bhi masle banati rahi hai, is liye dono EK SAATH jaanche jate hain —
     * aur Blade parh kar nahi, asal controller chala kar.
     */
    public function test_both_screens_offer_the_fix(): void
    {
        [$event, $advance] = $this->bookingWithReceipt(100000, 40000);
        $this->actAsOperator();

        // Nishan jaan-boojh kar `ms-2 adv-void-btn` hai, sirf `adv-void-btn`
        // nahi: wo naam modal partial ke JS me bhi mojood hai (`.adv-void-btn`
        // par click sunna), is liye akela wo har page par mil jata hai chahe
        // button bana ho ya na bana ho. Pehli koshish me yehi jaanch khokhli
        // thi aur aage wale test ne usay pakra.
        $eventHtml = app(\App\Http\Controllers\Tenant\Catering\CateringEventController::class)
            ->show($event->fresh())->render();
        $this->assertStringContainsString('ms-2 adv-void-btn', $eventHtml, 'booking ki screen par Void');
        $this->assertStringContainsString('text-muted adv-ref-btn', $eventHtml, 'booking ki screen par Reference');
        $this->assertStringContainsString('advVoidModal', $eventHtml, 'aur us ka parcha bhi');

        $customer = Customer::on('tenant')->where('phone', '03001230000')->firstOrFail();
        $cbHtml = app(\App\Http\Controllers\Tenant\Catering\CateringCustomerBalanceController::class)
            ->show(\Illuminate\Http\Request::create('/x', 'GET'), $customer)->render();
        $this->assertStringContainsString('ms-2 adv-void-btn', $cbHtml, 'Customer Balances par bhi Void');
        $this->assertStringContainsString('text-muted adv-ref-btn', $cbHtml, 'Customer Balances par bhi Reference');
        $this->assertStringContainsString('advVoidModal', $cbHtml, 'aur wahan bhi parcha');
        $this->assertStringContainsString('name="return_customer"', $cbHtml,
            'kaam ke baad isi screen par wapas aana chahiye');
    }

    /**
     * Invoice ban jane ke baad Void ka button PESH hi na ho.
     *
     * Service us surat me mana karti hai; ye pehra us se alag hai aur isi
     * liye zaroori — ek aisa button jo dabane par hamesha error de, screen
     * ka jhoot hai.
     */
    public function test_the_void_button_is_withdrawn_once_the_invoice_exists(): void
    {
        [$event, $advance] = $this->bookingWithReceipt(100000, 40000);
        $this->actAsOperator();

        // Pehle probe zinda sabit karo: abhi button mojood hai.
        $before = app(\App\Http\Controllers\Tenant\Catering\CateringEventController::class)
            ->show($event->fresh())->render();
        $this->assertStringContainsString('ms-2 adv-void-btn', $before);

        app(CateringFinalInvoiceService::class)->issue($event->refresh());

        $after = app(\App\Http\Controllers\Tenant\Catering\CateringEventController::class)
            ->show($event->fresh())->render();
        $this->assertStringNotContainsString('ms-2 adv-void-btn', $after,
            'invoice ke baad Void pesh nahi hona chahiye — POST bhi usay mana karta hai');
        $this->assertStringContainsString('text-muted adv-ref-btn', $after,
            'magar reference phir bhi theek ho sake — us se paisa nahi hilta');
    }

    /** Ulti hui receipt booking ki screen par NAZAR aaye, chup-chaap ghayab na ho. */
    public function test_the_event_screen_still_shows_the_voided_receipt(): void
    {
        [$event, $advance] = $this->bookingWithReceipt(100000, 40000);
        $this->advances->void($advance, 'duplicate entry thi', null);
        $this->actAsOperator();

        $html = app(\App\Http\Controllers\Tenant\Catering\CateringEventController::class)
            ->show($event->fresh())->render();

        $this->assertStringContainsString('VOIDED', $html,
            'ulti hui receipt screen par nishan ke saath honi chahiye');
        $this->assertStringContainsString('duplicate entry thi', $html, 'wajah ke saath');
    }
}
