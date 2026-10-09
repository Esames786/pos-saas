<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringProductionRelease;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringProductionReleaseService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-SHEET-ALWAYS-CURRENT-1 — 9 October.
 *
 * Malik ne ek kitchen sheet bheji jis par ek dish thi, jab ke booking me teen
 * thin. Phir, saaf lafzon me: "kitchen sheet mai hamesha updated data aana
 * chahiye... mujhe itna status update na karna pare, auto sab ho."
 *
 * ── YAHAN KYA TAY HUA ──────────────────────────────────────────────────────
 *
 * Parcha ab us release se NAHI banta jo kitchen ko bheji gayi thi, balki
 * maujooda quotation se. Release ka record qayam hai aur apna kaam karta hai
 * (maal nikalne ka snapshot, aur "kab bheja tha" ka number) — magar wo ab ye
 * tay nahi karta ke kaghaz par kya chhapega.
 *
 * Pehla ilaj ek button tha. Malik ne radd kiya, aur theek kiya: poora masla hi
 * ye tha ke ek qadam bhula diya gaya, aur ilaj me ek aur qadam jorna usi
 * ghalti ko dawat dena hai. Is liye neeche har jaanch ek hi sawal poochhti
 * hai — BINA kisi button, status ya accept ke, kya parche par aaj ka sach hai?
 */
class CateringSheetAlwaysCurrentMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringProductionReleaseService $releases;

    private int $branchId;

    private int $productId;

    private int $unitId;

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
            'journal_lines', 'journal_entries', 'accounts',
            'stock_ledgers', 'stock_balances',
            'customers', 'product_translations', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->releases = app(CateringProductionReleaseService::class);
        $this->branchId = $this->makeBranch();

        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'RICE']), [
            'name' => 'Biryani Masala Chicken Aaloo', 'sku' => 'AC1', 'unit_id' => $this->unitId,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400, 'unit_id' => $this->unitId,
            'effective_from' => now()->subYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Ek booking, ek dish, kitchen ko bheji hui — bilkul 14:39 wali halat. */
    private function releasedBooking(): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MRS. AIMAN',
            'customer_phone' => '0332-3658660',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDay()->toDateString(),
            'pax' => 30,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Biryani Masala Chicken Aaloo',
            'quantity' => 3, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 2300,
        ]]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->markAccepted($event->currentEstimate->refresh());
        $this->releases->release($event->refresh());

        return $event->refresh();
    }

    /**
     * Do dish aur jor di jati hain — aur yahin wo baat hai jo malik ne maangi:
     * quotation SIRF REVISE ki ja rahi hai. Na accept, na confirm, na koi
     * button. Revision DRAFT hi rehta hai.
     */
    private function addTwoMoreDishesWithoutAnyStatusStep(CateringEvent $event): void
    {
        $revision = $this->estimates->revise($event->refresh()->currentEstimate);
        $this->estimates->saveDraftLines($revision, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani Masala Chicken Aaloo',
                'quantity' => 3, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 2300],
            ['product_id' => $this->productId, 'item_name' => 'Rabri Kheer',
                'quantity' => 5, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 1000],
            ['product_id' => $this->productId, 'item_name' => 'Raita',
                'quantity' => 1, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 1000],
        ]);

        // Jaan-boojh kar yahan RUK rahe hain.
        $this->assertSame('draft', $revision->refresh()->status,
            'revision draft hi rehna chahiye — poora sawal yehi hai ke bina kisi qadam ke parcha taza ho');
    }

    /** Asal raasta: wohi bulk print jo malik ke browser me khulta hai. */
    private function printedSheet(CateringEvent $event): string
    {
        $response = app(CateringBulkDocumentController::class)
            ->kitchenSheets(Request::create('/x', 'GET', ['ids' => [$event->id]]));

        return is_object($response) && method_exists($response, 'getContent')
            ? $response->getContent()
            : (string) $response->render();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Asal maqsad.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_printed_sheet_carries_todays_dishes_without_any_status_step(): void
    {
        $event = $this->releasedBooking();
        $this->assertStringNotContainsString('Rabri Kheer', $this->printedSheet($event),
            'pehle parche par wo dish thi hi nahi — warna neeche ki jaanch bemani hai');

        $this->addTwoMoreDishesWithoutAnyStatusStep($event);

        $html = $this->printedSheet($event->refresh());

        foreach (['Biryani Masala Chicken Aaloo', 'Rabri Kheer', 'Raita'] as $dish) {
            $this->assertStringContainsString($dish, $html,
                "[{$dish}] parche par aani chahiye — bina button, bina accept, bina status badle");
        }
    }

    /** Aur purani tanbeeh bhi na aaye — kyunke parcha purana hai hi nahi. */
    public function test_the_sheet_no_longer_says_the_quotation_changed(): void
    {
        $event = $this->releasedBooking();
        $this->addTwoMoreDishesWithoutAnyStatusStep($event);

        $this->assertStringNotContainsString('QUOTATION CHANGED AFTER THIS SHEET',
            $this->printedSheet($event->refresh()),
            'ye band ab jhoot hoga — lines to aaj ki hi hain');
    }

    /** Aur wo PREVIEW bhi na kahe: booking waqai kitchen ko bheji ja chuki hai. */
    public function test_a_released_booking_still_prints_its_real_release_number(): void
    {
        $event = $this->releasedBooking();
        $realNo = $event->productionReleases()->latest('id')->first()->release_no;

        $this->addTwoMoreDishesWithoutAnyStatusStep($event);
        $html = $this->printedSheet($event->refresh());

        $this->assertStringContainsString($realNo, $html,
            'kaghaz par wohi number rahe jis se kitchen use pehchanta hai');
        $this->assertStringNotContainsString('PRODUCTION NOT RELEASED YET', $html,
            'ye booking release ho chuki hai — use preview nahi kehna chahiye');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Aur jo NAHI badla.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Jis booking ki kabhi release hui hi nahi, wo ab bhi khud kehti hai ke
     * wo abhi kitchen ko bheji nahi gayi. Ye band hatana sab se khatarnak
     * surat paida karta: deewar par do kaghaz, aur purana wala nazar na aaye.
     */
    public function test_a_booking_that_was_never_released_still_says_preview(): void
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. NEVER',
            'customer_phone' => '0300-4445556',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 20,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Biryani Masala Chicken Aaloo',
            'quantity' => 2, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 2300,
        ]]);

        // `sheetFor()` aisi booking par `null` deta hai — aur bulk ka raasta
        // phir khud preview banata hai. Jaanch us NULL par nahi, us KAGHAZ par
        // hai jo operator ke saamne aata hai.
        $this->assertNull($this->releases->sheetFor($event->refresh()),
            'kabhi release nahi hui — yani is raaste se koi asli parcha nahi');

        $html = $this->printedSheet($event->refresh());

        $this->assertStringContainsString('Biryani Masala Chicken Aaloo', $html,
            'parcha phir bhi banna chahiye — release se pehle bhi chhapna mumkin hai');
        $this->assertStringContainsString('PRODUCTION NOT RELEASED YET', $html,
            'magar wo khud kahe ke abhi kitchen ko bheja nahi gaya');
    }

    /**
     * 🚨 DATABASE ME PADI RELEASE CHHERI NA JAYE.
     *
     * `sheetFor()` release ke object par memory me lines badal deta hai. Wo
     * object save ho gaya to database me padi gawahi badal jayegi — "us waqt
     * kitchen ko kya bheja tha" ka jawab hamesha ke liye kho jayega, aur maal
     * nikalne ka snapshot bhi usi row par hai.
     *
     * Is liye yahan DB se dobara parh kar dekha ja raha hai, us object se nahi
     * jo abhi haath me hai.
     */
    public function test_building_the_sheet_never_touches_the_stored_release(): void
    {
        $event = $this->releasedBooking();
        $releaseId = $event->productionReleases()->latest('id')->first()->id;

        $this->addTwoMoreDishesWithoutAnyStatusStep($event);
        $this->printedSheet($event->refresh());
        $this->releases->sheetFor($event->refresh());

        $stored = CateringProductionRelease::findOrFail($releaseId);

        $this->assertSame(1, $stored->lines()->count(),
            'database me padi release par ab bhi wohi EK dish honi chahiye jo us waqt bheji gayi thi');
        $this->assertSame(1, (int) DB::connection('tenant')->table('catering_production_release_lines')
            ->where('catering_production_release_id', $releaseId)->count(),
            'aur ye seedha table se bhi — model ki relation cache par bharosa nahi');
    }

    /**
     * Probe zinda hai: purane tareeqe par pehli jaanch GIRTI.
     *
     * Agar parcha dobara mehfooz shuda release se banne lage to "Rabri Kheer"
     * us par hoti hi nahi. Ye jaanch isi farq par kaat-ti hai, aur isi liye
     * woh seedhi "release me kitni lines hain" nahi poochhti.
     */
    public function test_the_stored_release_and_the_printed_sheet_now_differ_on_purpose(): void
    {
        $event = $this->releasedBooking();
        $this->addTwoMoreDishesWithoutAnyStatusStep($event);

        $stored = $event->refresh()->productionReleases()->latest('id')->first();
        $this->assertSame(1, $stored->lines()->count(), 'mehfooz release: ek dish');

        $sheet = $this->releases->sheetFor($event->refresh());
        $this->assertCount(3, $sheet->lines, 'chhapne wala parcha: teen dish');
    }
}
