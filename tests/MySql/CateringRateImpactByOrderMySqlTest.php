<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Services\Catering\CateringCommercialRateImpactService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLineCostBlockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\MySql\Support\TenantFixtures;

/**
 * RATE-IMPACT-BY-ORDER-1 + RATE-REPRICE-WINDOW-1 — 28 September.
 *
 * Malik: "order aare hon, main order par click karun to collapse khul ke order
 * ki detail aaye, total wagera, aur jis item ka rate update hoga wo highlight
 * ho" — aur "draft, inquiry, release, quote, ya kisi bhi aise status par jo
 * mukammal na ho, us par naya commercial rate laga sakein."
 *
 * PEHLE HAR QATAR EK DISH THI. Ek booking ke teen dish teen qatarein bante, aur
 * koi qatar ye nahi bata sakti thi ke POORE bill par kya asar parega — jo ke
 * asal sawal hai, kyunke graahak bill dekhta hai, dish ki qatar nahi.
 *
 * SAB SE AHEM TEST NEECHE WALA HAI: jo total preview me likha hai, apply ke
 * baad WOHI banna chahiye. Preview ka kaam andaza lagana nahi, batana hai —
 * aur ek preview jo apply se alag ho, us se koi preview na hona behtar hai.
 */
class CateringRateImpactByOrderMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private CateringCommercialRateImpactService $impact;

    private int $branchId;

    private int $unitId;

    private int $chickenId;

    private int $biryaniId;

    private int $karahiId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true);

        $this->cleanTenant([
            'catering_commercial_rate_applications',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_final_invoices', 'catering_refunds', 'catering_advances', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);
        $this->impact = app(CateringCommercialRateImpactService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 80, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        CateringMaterialCommercialRate::create([
            'product_id' => $this->chickenId, 'rate' => 100, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Biryani', 'sku' => 'D-BIR', 'unit_id' => $this->unitId]);
        $this->karahiId = $this->makeProduct($categoryId, ['name' => 'Karahi', 'sku' => 'D-KAR', 'unit_id' => $this->unitId]);

        foreach ([$this->biryaniId, $this->karahiId] as $dish) {
            // Block se qeemat banti hai sirf tab jab profile yahi kehta ho —
            // warna line par koi snapshot bhi nahi banta aur ye poora screen
            // khali rehta hai.
            \App\Models\Tenant\CateringProductProfile::updateOrCreate(
                ['product_id' => $dish],
                ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
            );

            CateringProductCostBlock::create([
                'product_id' => $dish,
                'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
                'material_product_id' => $this->chickenId,
                'label' => 'Chicken',
                'unit_id' => $this->unitId,
                'quantity_per_unit' => 1,
                'rate' => 100,
                'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
                'commercial_rate_source' => CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK,
                'sort_order' => 1,
            ]);
        }
    }

    // ── Ek qatar ek booking ────────────────────────────────────────────────

    public function test_one_booking_is_one_row_however_many_of_its_dishes_move(): void
    {
        $this->bookingWithTwoDishes('Do Dish Wala');
        $this->raiseChickenTo(150);

        $orders = $this->impact->quotationImpactByOrder($this->chickenId);

        $this->assertCount(1, $orders, 'do dish ki ek hi booking — ek hi qatar');
        $this->assertCount(2, $orders[0]['lines'], 'aur us ke andar dono lines');
        $this->assertSame(2, collect($orders[0]['lines'])->where('moves', true)->count(),
            'dono hilti hain, is liye dono highlight hongi');
    }

    /** Order me wo lines bhi aayen jo hil hi nahi rahin — warna context gayab. */
    public function test_the_order_carries_every_line_not_only_the_ones_that_move(): void
    {
        $estimate = $this->bookingWithTwoDishes('Teen Line Wala');

        // Ek aisi line jis me chicken hai hi nahi.
        $this->estimates->saveDraftLines($estimate->refresh(), [
            ['product_id' => $this->biryaniId, 'item_name' => 'Biryani', 'quantity' => 10,
                'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0],
            ['product_id' => $this->karahiId, 'item_name' => 'Karahi', 'quantity' => 5,
                'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0],
            ['product_id' => null, 'item_name' => 'Mineral Water', 'quantity' => 20,
                'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 50],
        ]);
        $this->raiseChickenTo(150);

        $orders = $this->impact->quotationImpactByOrder($this->chickenId);

        $this->assertCount(3, $orders[0]['lines'], 'poora order dikhta hai');
        $water = collect($orders[0]['lines'])->firstWhere('item_name', 'Mineral Water');
        $this->assertFalse($water['affected'], 'pani par chicken ka rate nahi lagta');
        $this->assertSame($water['amount'], $water['new_amount'], 'aur us ka amount hilta bhi nahi');
    }

    // ── SAB SE AHEM: preview apply ka waada hai ────────────────────────────

    /**
     * Jo total preview me likha hai, apply ke baad WOHI banna chahiye.
     *
     * Ye test do alag raaston ko aamne saamne rakhta hai: ek taraf
     * `quotationImpactByOrder` ka hisaab, doosri taraf `applyToDrafts` ka asal
     * likha hua natija. Agar ye kabhi alag ho gaye to preview andaza ban jayega
     * — aur ek ghalat andaza na hone se bura hai, kyunke us par faisla hota
     * hai.
     */
    public function test_the_total_the_preview_promises_is_the_total_the_apply_produces(): void
    {
        $estimate = $this->bookingWithTwoDishes('Waada Wala');
        $this->raiseChickenTo(150);

        $order = $this->impact->quotationImpactByOrder($this->chickenId)[0];
        $promised = $order['new_total'];

        $this->assertNotSame($order['old_total'], $promised, 'kuch to hilna chahiye, warna test bemani hai');

        $this->impact->applyToDrafts($this->chickenId, $order['eligible_snapshot_ids']);

        $actual = round((float) $estimate->refresh()->lines->sum(
            fn ($l) => round((float) $l->quantity * (float) $l->rate, 2)
        ), 2);

        $this->assertSame($promised, $actual, 'preview ne jo total kaha tha, wohi bana');
    }

    // ── Agreed rate: dikhta hai, magar bill nahi hilata ────────────────────

    public function test_an_agreed_rate_line_is_shown_but_does_not_move_the_bill(): void
    {
        $estimate = $this->bookingWithTwoDishes('Agreed Wala');
        $line = $estimate->refresh()->lines->first();
        $line->forceFill(['rate' => 999, 'rate_override_reason' => 'malik ne tay kiya'])->save();
        $this->raiseChickenTo(150);

        $order = $this->impact->quotationImpactByOrder($this->chickenId)[0];
        $agreed = collect($order['lines'])->firstWhere('is_override', true);

        $this->assertNotNull($agreed, 'agreed wali line fehrist me honi chahiye');
        $this->assertTrue($agreed['affected'], 'us par chicken hai, is liye wo mutasir to hai');
        $this->assertFalse($agreed['moves'], 'magar graahak ka adad nahi hilta');
        $this->assertSame($agreed['amount'], $agreed['new_amount'],
            'jis qeemat par baat ho chuki hai wo apni jagah rehti hai');
    }

    // ── Reprice ki khirki ─────────────────────────────────────────────────

    /** Released booking ki qeemat ab bhi durust ho sakti hai. */
    public function test_a_released_booking_can_still_have_its_price_corrected(): void
    {
        $estimate = $this->bookingWithTwoDishes('Released Wala');
        $estimate->event->forceFill(['status' => CateringEvent::STATUS_RELEASED])->save();
        $this->raiseChickenTo(150);

        $order = $this->impact->quotationImpactByOrder($this->chickenId)[0];

        $this->assertNotContains('locked', $order['states'],
            'release ke baad ITEM badalna mana hai, RATE durust karna nahi — '
            .'kitchen sheet par qeemat chhapti hi nahi');
    }

    /** Magar invoice ban gayi to bas. */
    public function test_an_invoiced_booking_is_closed_to_repricing(): void
    {
        $estimate = $this->bookingWithTwoDishes('Invoice Wala');
        DB::connection('tenant')->table('catering_final_invoices')->insert([
            'invoice_uuid' => (string) \Illuminate\Support\Str::ulid(),
            'invoice_no' => 'INV-TEST-1',
            'catering_event_id' => $estimate->catering_event_id,
            'catering_estimate_id' => $estimate->id,
            'snapshot' => '{}',
            'subtotal' => 1000, 'grand_total' => 1000, 'balance_due' => 1000,
            'status' => 'issued', 'issued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->raiseChickenTo(150);

        $order = $this->impact->quotationImpactByOrder($this->chickenId)[0];

        $this->assertSame(['locked'], $order['states'],
            'bill ban chuka — us ke peeche qeemat hilane se graahak ke haath me ek adad hoga aur system me doosra');
        $this->assertSame([], $order['eligible_snapshot_ids'], 'aur kuch chuna bhi nahi ja sakta');
    }

    /** Aur khatam/mansookh booking par durusti ka koi matlab nahi. */
    public function test_a_finished_or_cancelled_booking_is_out(): void
    {
        foreach ([CateringEvent::STATUS_COMPLETED, CateringEvent::STATUS_CANCELLED] as $status) {
            $estimate = $this->bookingWithTwoDishes('Khatam '.$status);
            $estimate->event->forceFill(['status' => $status])->save();
        }
        $this->raiseChickenTo(150);

        foreach ($this->impact->quotationImpactByOrder($this->chickenId) as $order) {
            $this->assertSame(['locked'], $order['states'], "[{$order['event_status']}] band hona chahiye");
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function bookingWithTwoDishes(string $customer): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => $customer,
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(7)->toDateString(),
            'pax' => 100,
        ]);

        return $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->biryaniId, 'item_name' => 'Biryani', 'quantity' => 10,
                'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0],
            ['product_id' => $this->karahiId, 'item_name' => 'Karahi', 'quantity' => 5,
                'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0],
        ]);
    }

    private function raiseChickenTo(float $rate): void
    {
        CateringMaterialCommercialRate::create([
            'product_id' => $this->chickenId, 'rate' => $rate, 'unit_id' => $this->unitId,
            'effective_from' => now()->toDateString(),
        ]);
    }
}
