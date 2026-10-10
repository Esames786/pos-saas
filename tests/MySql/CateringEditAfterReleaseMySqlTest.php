<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Catering\CateringProductionReleaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-EDIT-AFTER-RELEASE-1 — release ke baad bhi sauda badla ja sake,
 * magar chup chaap nahi.
 *
 * ── ASAL WAQEA, PROD, 1 OCTOBER ────────────────────────────────────────────
 *
 * EV-20261001-0120:
 *     11:57:33  Q1 bheji gayi
 *     12:14:48  production release  → event = released
 *     12:16:55  kisi ne Revise daba diya → Q1 superseded, Q2 draft
 *
 * Us ke baad booking KAHIN nahi ja sakti thi:
 *     Edit    → mana (booking khuli nahi)
 *     Revise  → mana (estimate draft hai, "edit it directly instead")
 *     Invoice → mana (draft bill nahi hota)
 *
 * Har pehra apni jagah durust tha. Mil kar unhon ne ek aisa kamra bana diya
 * jis ka darwaza nahi tha — aur banane wala `revise()` tha, jo sirf ESTIMATE
 * ka status dekhta tha aur BOOKING ka kabhi nahi.
 *
 * Malik ne is par policy badli: release ke baad editing ki ijazat ho, kyunke
 * graahak usi din item barha deta hai. Us ijazat ki EK QEEMAT hai — nikla hua
 * parcha purani quotation ka hai — aur wo qeemat chhupayi nahi ja sakti.
 *
 * Is file ke teen hisse wohi teen cheezein hain: darwaza khula, ijazat lazmi,
 * aur farq nazar aane wala.
 */
class CateringEditAfterReleaseMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

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
            'journal_lines', 'journal_entries', 'accounts',
            'customers', 'units', 'products', 'categories', 'branches',
        ]);

        // Invoice GL par post karti hai, is liye khaata-bahi lazmi hai.
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->branchId = $this->makeBranch();

        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'RICE']), [
            'name' => 'Biryani', 'sku' => 'EAR1', 'unit_id' => $unitId, 'default_purchase_price' => 400,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Prod wali booking, qadam ba qadam: bhej do, confirm karo, release karo. */
    private function releasedBooking(float $total = 22500): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. ADNAN',
            'customer_phone' => '03008274731',
            'booking_date' => now()->toDateString(),
            // CATERING-NOTHING-FREEZES-BEFORE-EVENT-1 (10 Oct) — ye fixture
            // pehle AANE WALI tareekh ka event banata tha aur phir us ka bill
            // bana deta tha. Wo surat asal duniya me mumkin hi nahi: bill event
            // guzarne ke baad banta hai. Fixture ab sach bol raha hai.
            'event_date' => now()->subDays(2)->toDateString(),
            'pax' => 100,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani', 'quantity' => 10, 'rate' => $total / 10],
        ]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());
        app(CateringProductionReleaseService::class)->release($event->refresh());

        return $event->refresh();
    }

    /** Pehle ye sabit karo ke fixture waqai us haalat me hai. */
    public function test_the_booking_really_is_released(): void
    {
        $event = $this->releasedBooking();

        $this->assertSame(CateringEvent::STATUS_RELEASED, $event->status);
        $this->assertFalse($event->isOpen(),
            'released booking "open" nahi hai — calendar ka matlab wohi rehna chahiye');
        $this->assertTrue($event->isCommerciallyOpen(),
            'magar us ka sauda ab badla ja sakta hai');
    }

    /**
     * SAB SE AHEM. Wo band gali dobara na bane.
     *
     * Release ke baad Revise chale, aur us ke baad booking AAGE JA SAKE —
     * yani jo Q2 bani wo finalise aur invoice ho sake. Pehle yahan teen
     * darwaze the aur teenon band.
     */
    public function test_a_revision_after_release_does_not_trap_the_booking(): void
    {
        $event = $this->releasedBooking();

        $revision = $this->estimates->revise($event->currentEstimate()->first());
        $this->assertTrue($revision->isDraft(), 'revision ek naya draft hona chahiye');
        $this->assertSame(CateringEvent::STATUS_RELEASED, $event->refresh()->status,
            'release hui hai, aur us ko jhutlaya nahi ja sakta — booking released hi rahe');

        // DARWAZA EK: draft ab badla ja sakta hai.
        $this->estimates->saveDraftLines($revision->refresh(), [
            ['product_id' => $this->productId, 'item_name' => 'Biryani', 'quantity' => 12, 'rate' => 2250],
        ]);
        $this->assertSame(27000.0, round((float) $revision->refresh()->grand_total, 2),
            'nayi miqdaar quotation par aani chahiye');

        // DARWAZA DO: finalise ho sake.
        $this->estimates->markSent($revision->refresh());
        $this->assertSame(CateringEstimate::STATUS_SENT, $revision->refresh()->status);

        // DARWAZA TEEN: aur bill ban sake.
        $invoice = app(CateringFinalInvoiceService::class)->issue($event->refresh());
        $this->assertSame(27000.0, round((float) $invoice->grand_total, 2),
            'bill NAYE saude par banna chahiye, purane par nahi');
    }

    /**
     * Farq NAZAR aana chahiye — yehi wo cheez hai jo is poori ijazat ko
     * qabil-e-bardasht banati hai.
     *
     * Bawarchi screen nahi dekhta, deewar par laga kaghaz dekhta hai. Agar
     * tabdeeli khamosh ho to wo 10 KG pakayega aur bill 12 KG ka banega.
     */
    public function test_a_release_made_before_the_change_is_marked_stale(): void
    {
        $event = $this->releasedBooking();

        $this->assertFalse($event->hasStaleRelease(),
            'abhi tak parcha aur quotation ek hi hain — koi tanbeeh nahi honi chahiye');

        $this->estimates->revise($event->currentEstimate()->first());

        $this->assertTrue($event->refresh()->hasStaleRelease(),
            'ab nikla hua parcha purani quotation ka hai — ye nazar aana chahiye');
    }

    /**
     * Invoice ke BAAD phir bhi kuch nahi hilta. Ye hadd is tabdeeli se nahi
     * hilni chahiye thi, aur ye test usi ka pehra hai.
     */
    public function test_the_invoice_is_still_the_hard_boundary(): void
    {
        $event = $this->releasedBooking();
        app(CateringFinalInvoiceService::class)->issue($event->refresh());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closed to commercial change');

        $this->estimates->revise($event->refresh()->currentEstimate()->first());
    }

    /** Mukammal/band/mansookh booking par ab bhi kuch nahi badalta. */
    public function test_a_completed_booking_is_still_closed(): void
    {
        $event = $this->releasedBooking();
        $event->forceFill(['status' => CateringEvent::STATUS_COMPLETED])->save();

        $this->assertFalse($event->isCommerciallyOpen(),
            'sirf `released` khola gaya tha — completed nahi');

        $this->expectException(\RuntimeException::class);
        $this->estimates->revise($event->refresh()->currentEstimate()->first());
    }
}
