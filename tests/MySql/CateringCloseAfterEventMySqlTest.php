<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-CLOSE-AFTER-EVENT-1 — event ka din guzre baghair booking band nahi.
 *
 * Malik (8 Oct), do qaide ek saath:
 *
 *   "any event should not be mark complete or close event untill the 100%
 *    balance refund or paid"
 *   "it cannot be closed untill the event day is passed"
 *
 * ── PEHLA QAIDA PEHLE SE MOJOOD NIKLA, DOOSRA NAHI ───────────────────────
 *
 * Paisa poora hone ki shart CLOSE par pehle se lagti thi — dono simt me, yani
 * graahak par baqi bhi aur graahak ka credit bhi. Prod par naapa: 21 closed
 * bookings, ek bhi kharab nahi.
 *
 * Us qaide ko INVOICE par bhi lagaya gaya tha (kyunke `completed` wahin banta
 * hai) aur phir WAAPAS liya gaya. Wajah test suite ne batayi: 46 maujooda
 * pehre toot gaye, aur un me se paanch us soorat ke the jo
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 me malik ki APNI booking se banayi gayi
 * thi — EV-20260816-0001: 458,250 ka quote, 492,500 wasool, phir neeche
 * revise. Naya pehra us booking ka bill hi na banne deta. Malik ko ye dikha
 * kar faisla liya gaya: sirf date wala qaida rahega.
 *
 * Sabaq jo likh rakhne layak hai: "complete na ho jab tak paisa poora na ho"
 * sunne me saada lagta hai, magar `completed` invoice se banta hai aur invoice
 * hi wo cheez hai jo receivable kitabon me likhti hai. Us par rok lagana
 * udhaar ko record se hi gayab kar deta.
 *
 * ── JO IS FILE ME BACHA ──────────────────────────────────────────────────
 *
 * Doosra qaida, jo waqai mojood nahi tha — aur prod par DO baar toota hua
 * mila: EV-20260909-0002 (event 15 Oct) aur EV-20261004-0157 (event 9 Oct),
 * dono apne din se pehle band ho chuki thin. Ye wo kharabi hai jo khud ko
 * chhupati hai: booking chalti hui fehrist se nikal jati hai aur kisi ko pata
 * nahi chalta ke us par kaam baqi tha.
 */
class CateringCloseAfterEventMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringFinalInvoiceService $invoices;

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
            'catering_production_release_lines', 'catering_production_releases',
            'catering_final_invoices', 'catering_advances', 'catering_refunds',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'payment_methods', 'customers', 'product_translations',
            'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder())->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->invoices = app(CateringFinalInvoiceService::class);
        $this->advances = app(CateringAdvanceService::class);
        $this->branchId = $this->makeBranch();

        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'RICE']), [
            'name' => 'Biryani', 'sku' => 'SB1', 'unit_id' => $unitId, 'default_purchase_price' => 400,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod();
    }

    /** Ek confirmed booking, diye hue total aur event ke din par. */
    private function booking(float $total, string $eventDate): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. SETTLE',
            'customer_phone' => '03007770001',
            'booking_date' => now()->subDays(10)->toDateString(),
            'event_date' => $eventDate,
            'pax' => 60,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani', 'quantity' => 1, 'rate' => $total],
        ]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        return $event->refresh();
    }

    private function pay(CateringEvent $event, float $amount): void
    {
        $this->advances->record($event->refresh(), [
            'amount' => $amount,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);
    }

    /**
     * Probe zinda hai: poora paisa aate hi invoice ban jati hai aur booking
     * `completed` ho jati hai.
     *
     * Ye test sab se zyada ahem hai, is liye nahi ke wo kuch rokta hai — balke
     * is liye ke wo sabit karta hai ke upar wali rok JHOOTI nahi. Ek pehra jo
     * har soorat me mana kar de, feature ko maar deta hai aur dekhne me theek
     * lagta hai.
     */
    public function test_a_fully_paid_booking_invoices_and_completes(): void
    {
        $event = $this->booking(100000, now()->subDay()->toDateString());
        $this->pay($event, 100000);

        $invoice = $this->invoices->issue($event->refresh());

        $this->assertSame(100000.0, round((float) $invoice->grand_total, 2));
        $this->assertSame(CateringEvent::STATUS_COMPLETED, $event->fresh()->status);
    }

    /** QAIDA 2 — event ka din aaye baghair close nahi. */
    public function test_a_booking_cannot_be_closed_before_its_event_day(): void
    {
        $event = $this->booking(100000, now()->addDays(7)->toDateString());
        $this->pay($event, 100000);
        $this->invoices->issue($event->refresh());

        $this->assertSame(CateringEvent::STATUS_COMPLETED, $event->fresh()->status,
            'bill to ban sakta hai — rok sirf CLOSE par hai');

        try {
            $this->invoices->close($event->refresh());
            $this->fail('event se pehle close nahi hona chahiye tha');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('din', $e->getMessage());
        }

        $this->assertSame(CateringEvent::STATUS_COMPLETED, $event->fresh()->status);
    }

    /** Aur probe zinda hai: din guzar jane par close ho jati hai. */
    public function test_a_booking_closes_once_its_event_day_has_passed(): void
    {
        $event = $this->booking(100000, now()->subDays(2)->toDateString());
        $this->pay($event, 100000);
        $this->invoices->issue($event->refresh());

        $this->invoices->close($event->refresh());

        $this->assertSame(CateringEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    /** Event ke USI din bhi band ho sakti hai — "guzar jaye" ka matlab "aa jaye" hai. */
    public function test_the_event_day_itself_counts_as_arrived(): void
    {
        $event = $this->booking(50000, app(\App\Support\TenantClock::class)->now()->toDateString());
        $this->pay($event, 50000);
        $this->invoices->issue($event->refresh());

        $this->invoices->close($event->refresh());

        $this->assertSame(CateringEvent::STATUS_CLOSED, $event->fresh()->status,
            'jis din event hai usi din khana ja chuka hota hai — us din rokna fazool hai');
    }
}
