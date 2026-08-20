<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLineCostBlockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-CUSTOMER-SUPPLIED-1 — the family brings their own chicken.
 *
 * A common arrangement, and the whole difficulty is that TWO THINGS ARE TRUE AT
 * ONCE and the system has to hold both:
 *
 *   THE KITCHEN STILL NEEDS 5 KG. The dish has not changed. Zeroing the
 *   requirement would be a lie about the food, and the kitchen sheet would stop
 *   asking for something the cooks genuinely need.
 *
 *   OUR STORE ISSUES NONE OF IT, and the customer is charged nothing for it.
 *   Making, packing and labour are charged exactly as before — the caterer still
 *   did the work.
 *
 * So this is a flag, never a quantity edit. Every test here exists to keep those
 * two facts from collapsing into each other.
 *
 * The dish is the business's worked example — chicken 100/KG x 0.50, rice 80/KG
 * x 0.40, making 300 — which prices at 382/KG, and at 332/KG once the customer
 * brings the chicken.
 */
class CateringCustomerSuppliedMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private int $branchId;

    private int $biryaniId;

    private int $chickenId;

    private int $riceId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->unitId]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        $this->riceId = $this->makeProduct($categoryId, [
            'name' => 'Rice', 'sku' => 'RM-RICE', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        foreach ([[$this->chickenId, 80], [$this->riceId, 55]] as [$id, $rate]) {
            CateringMaterialRate::create([
                'product_id' => $id, 'rate' => $rate, 'unit_id' => $this->unitId,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
        }

        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryaniId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

        $this->materialBlock('Chicken', $this->chickenId, 100, 0.50, 1);
        $this->materialBlock('Rice', $this->riceId, 80, 0.40, 2);
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE, 'rate' => 300,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT, 'sort_order' => 3,
        ]);
    }

    private function materialBlock(string $label, int $materialId, float $rate, float $ratio, int $order, ?string $basis = null): void
    {
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => $label,
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $materialId, 'quantity_per_unit' => $ratio,
            'unit_id' => $this->unitId, 'rate' => $rate,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => $basis ?? CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'sort_order' => $order,
        ]);
    }

    private function draft(float $qty = 5, string $customer = 'Supply Customer'): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => $customer,
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(6)->toDateString(),
            'pax' => 100,
        ]);

        return $this->estimates->saveDraftLines($event->currentEstimate, [$this->lineInput($qty)]);
    }

    /** @return array<string, mixed> */
    private function lineInput(float $qty, ?string $uuid = null): array
    {
        return array_filter([
            'line_uuid' => $uuid,
            'product_id' => $this->biryaniId,
            'item_name' => 'Chicken Biryani',
            'quantity' => $qty,
            'unit_id' => $this->unitId,
            'unit_code' => 'KG',
            'rate' => 0,
        ], fn ($v) => $v !== null);
    }

    private function line(CateringEstimate $estimate): CateringEstimateLine
    {
        return $estimate->refresh()->lines->first();
    }

    private function snapshot(CateringEstimateLine $line, string $label): CateringEstimateLineCostBlock
    {
        return $this->lineBlocks->snapshotsFor($line)->firstWhere('label', $label);
    }

    private function supplyChicken(CateringEstimate $estimate, bool $supplied = true): void
    {
        $this->lineBlocks->setCustomerSupplied(
            $this->snapshot($this->line($estimate), 'Chicken'),
            $supplied
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The two facts.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_material_is_supplied_by_the_business_until_someone_says_otherwise(): void
    {
        $chicken = $this->snapshot($this->line($this->draft()), 'Chicken');

        $this->assertFalse($chicken->isCustomerSupplied());
        $this->assertEqualsWithDelta(2.5, $chicken->ourStockRequirement(), 0.001);
        $this->assertSame(250.0, round((float) $chicken->amount, 2));
    }

    /**
     * THE ONE THAT MATTERS. The kitchen still needs its chicken — only the
     * source and the charge change.
     */
    public function test_the_kitchen_still_needs_the_material_the_customer_brings(): void
    {
        $estimate = $this->draft(5);
        $this->supplyChicken($estimate);

        $chicken = $this->snapshot($this->line($estimate), 'Chicken');

        $this->assertEqualsWithDelta(2.5, (float) $chicken->event_material_qty, 0.001,
            'the requirement is untouched — the dish is the same dish');
        $this->assertEqualsWithDelta(2.5, $chicken->physicalRequirement(), 0.001);

        $this->assertSame(0.0, $chicken->ourStockRequirement(), 'but our store hands over none of it');
        $this->assertSame(0.0, round((float) $chicken->amount, 2), 'and the customer is not charged for it');
        $this->assertSame(0.0, round((float) $chicken->material_cost, 2), 'nor did it cost us anything');
    }

    public function test_the_other_material_and_the_making_are_still_charged(): void
    {
        $estimate = $this->draft(5);
        $this->supplyChicken($estimate);

        $line = $this->line($estimate);
        $this->assertSame(160.0, round((float) $this->snapshot($line, 'Rice')->amount, 2), '2 KG x 80');
        $this->assertSame(1500.0, round((float) $this->snapshot($line, 'Making')->amount, 2),
            'the caterer still did the cooking');
    }

    /** The rate book still knows what chicken is worth; we simply did not buy it. */
    public function test_the_material_rate_book_is_not_touched(): void
    {
        $estimate = $this->draft(5);
        $before = CateringMaterialRate::where('product_id', $this->chickenId)->value('rate');

        $this->supplyChicken($estimate);

        $this->assertSame($before, CateringMaterialRate::where('product_id', $this->chickenId)->value('rate'));
        $this->assertEqualsWithDelta(80.0,
            (float) $this->snapshot($this->line($estimate), 'Chicken')->material_rate_at_quote, 0.01,
            'the rate stays on the snapshot as context — it is simply not what we paid');
    }

    /** And neither is the dish, or any other booking of it. */
    public function test_nothing_outside_this_line_changes(): void
    {
        $first = $this->draft(5, 'First Customer');
        $second = $this->draft(5, 'Second Customer');

        $masterBefore = CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->orderBy('id')->get(['id', 'rate', 'quantity_per_unit'])->toArray();

        $this->supplyChicken($first);

        $this->assertEquals($masterBefore, CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->orderBy('id')->get(['id', 'rate', 'quantity_per_unit'])->toArray(),
            'the dish everyone else is quoted from must not move');

        $otherChicken = $this->snapshot($this->line($second), 'Chicken');
        $this->assertFalse($otherChicken->isCustomerSupplied());
        $this->assertSame(1910.0, round((float) $second->refresh()->subtotal, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The worked example.
    // ─────────────────────────────────────────────────────────────────────────

    /** 382/KG normally; 332/KG once the customer brings the chicken. */
    public function test_the_worked_example_drops_from_382_to_332(): void
    {
        $estimate = $this->draft(5);
        $this->assertSame(382.0, round((float) $this->line($estimate)->calculated_rate, 2));
        $this->assertSame(1910.0, round((float) $estimate->refresh()->subtotal, 2));

        $this->supplyChicken($estimate);

        $line = $this->line($estimate);
        $this->assertSame(332.0, round((float) $line->calculated_rate, 2), 'rice 32 + making 300');
        $this->assertSame(1660.0, round((float) $line->amount, 2));
        $this->assertSame(1660.0, round((float) $estimate->refresh()->subtotal, 2),
            'and the quotation follows the line');
        $this->assertSame(1660.0, round((float) $estimate->refresh()->grand_total, 2));
    }

    public function test_taking_it_back_restores_everything(): void
    {
        $estimate = $this->draft(5);
        $this->supplyChicken($estimate);
        $this->supplyChicken($estimate, false);

        $line = $this->line($estimate);
        $chicken = $this->snapshot($line, 'Chicken');

        $this->assertSame(250.0, round((float) $chicken->amount, 2));
        $this->assertEqualsWithDelta(200.0, (float) $chicken->material_cost, 0.01, '2.5 KG x 80 again');
        $this->assertSame(382.0, round((float) $line->calculated_rate, 2));
        $this->assertSame(1910.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Interaction with the event quantity override.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * An operator who said this event needs 3 KG still means 3 KG. Marking it
     * customer-supplied changes who brings the three kilos, not how many.
     */
    public function test_an_event_quantity_override_survives_being_customer_supplied(): void
    {
        $estimate = $this->draft(5);
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($this->line($estimate), 'Chicken'), 3.0);

        $this->supplyChicken($estimate);

        $chicken = $this->snapshot($this->line($estimate), 'Chicken');
        $this->assertEqualsWithDelta(3.0, (float) $chicken->event_material_qty, 0.001);
        $this->assertTrue($chicken->is_overridden);
        $this->assertSame(0.0, $chicken->ourStockRequirement());
        $this->assertSame(0.0, round((float) $chicken->amount, 2));
    }

    /** And taking it back charges the OVERRIDDEN quantity, not the ratio's. */
    public function test_undoing_it_charges_the_overridden_quantity(): void
    {
        $estimate = $this->draft(5);
        $this->lineBlocks->overrideMaterialQuantity($this->snapshot($this->line($estimate), 'Chicken'), 3.0);
        $this->supplyChicken($estimate);

        $this->supplyChicken($estimate, false);

        $chicken = $this->snapshot($this->line($estimate), 'Chicken');
        $this->assertSame(300.0, round((float) $chicken->amount, 2), '3 KG x 100, not the ratio 2.5');
        $this->assertEqualsWithDelta(3.0, (float) $chicken->event_material_qty, 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Interaction with the dish quantity.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_growing_the_order_grows_the_requirement_but_not_the_charge(): void
    {
        $estimate = $this->draft(5);
        $this->supplyChicken($estimate);

        $line = $this->line($estimate);
        $line->forceFill(['quantity' => 10])->save();
        DB::connection('tenant')->transaction(fn () => $this->lineBlocks->recalculateForQuantityLocked($line->fresh()));

        $chicken = $this->snapshot($this->line($estimate), 'Chicken');
        $this->assertEqualsWithDelta(5.0, (float) $chicken->event_material_qty, 0.001,
            'twice the biryani needs twice the chicken, whoever brings it');
        $this->assertSame(0.0, $chicken->ourStockRequirement(), 'our store still issues none');
        $this->assertSame(0.0, round((float) $chicken->amount, 2), 'and it is still not charged');

        // Rice 4 KG x 80 = 320, making 10 x 300 = 3,000
        $this->assertSame(3320.0, round((float) $this->line($estimate)->amount, 2));
        $this->assertSame(332.0, round((float) $this->line($estimate)->calculated_rate, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Quoted rate.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_untouched_quoted_rate_follows_the_new_calculation(): void
    {
        $estimate = $this->draft(5);
        $this->supplyChicken($estimate);

        $this->assertSame(332.0, round((float) $this->line($estimate)->rate, 2));
    }

    /** A price somebody agreed with a customer is not moved by this. */
    public function test_an_agreed_rate_survives_the_customer_supplying_a_material(): void
    {
        $estimate = $this->draft(5);
        $this->lineBlocks->overrideQuotedRate($this->line($estimate), 500, 'Customer agreed rate');

        $this->supplyChicken($estimate);

        $line = $this->line($estimate);
        $this->assertSame(332.0, round((float) $line->calculated_rate, 2), 'the calculation moved');
        $this->assertSame(500.0, round((float) $line->rate, 2), 'the agreed price did not');
        $this->assertSame('Customer agreed rate', $line->rate_override_reason);
        $this->assertSame(2500.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    public function test_use_calculated_rate_still_works_afterwards(): void
    {
        $estimate = $this->draft(5);
        $this->lineBlocks->overrideQuotedRate($this->line($estimate), 500, 'Agreed');
        $this->supplyChicken($estimate);

        $this->lineBlocks->useCalculatedRate($this->line($estimate));

        $this->assertSame(332.0, round((float) $this->line($estimate)->rate, 2));
        $this->assertSame(1660.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ordinary editing.
    // ─────────────────────────────────────────────────────────────────────────

    /** Editing the order the ordinary way must not lose the arrangement. */
    public function test_an_ordinary_save_keeps_the_supply_decision(): void
    {
        $estimate = $this->draft(5);
        $this->supplyChicken($estimate);
        $uuid = $this->line($estimate)->line_uuid;

        $this->estimates->saveDraftLines($estimate->refresh(), [$this->lineInput(10, $uuid)]);

        $chicken = $this->snapshot($this->line($estimate), 'Chicken');
        $this->assertTrue($chicken->isCustomerSupplied(), 'the customer is still bringing the chicken');
        $this->assertEqualsWithDelta(5.0, (float) $chicken->event_material_qty, 0.001, 'for the bigger order');
        $this->assertSame(0.0, $chicken->ourStockRequirement());
        $this->assertSame(3320.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Legacy rows.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A legacy per-dish-unit material is charged 200 a kilo of DISH. Customer
     * supplied still means charged nothing — the binary rule needs no prorating
     * for either basis.
     */
    public function test_a_legacy_per_dish_material_is_also_charged_nothing(): void
    {
        CateringProductCostBlock::where('product_id', $this->biryaniId)
            ->where('label', 'Chicken')
            ->update(['rate_basis' => CateringProductCostBlock::RATE_PER_DISH_UNIT, 'rate' => 200]);

        $estimate = $this->draft(5);
        $this->assertSame(1000.0, round((float) $this->snapshot($this->line($estimate), 'Chicken')->amount, 2),
            '5 KG of dish x 200 — the legacy reading, unchanged');

        $this->supplyChicken($estimate);

        $chicken = $this->snapshot($this->line($estimate), 'Chicken');
        $this->assertSame(0.0, round((float) $chicken->amount, 2));
        $this->assertEqualsWithDelta(2.5, (float) $chicken->event_material_qty, 0.001,
            'and the kitchen requirement is still recorded');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Boundaries.
    // ─────────────────────────────────────────────────────────────────────────

    /** There is nothing for a customer to bring when the line is labour. */
    public function test_a_charge_block_cannot_be_customer_supplied(): void
    {
        $estimate = $this->draft(5);
        $making = $this->snapshot($this->line($estimate), 'Making');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is a charge, not a material/');

        $this->lineBlocks->setCustomerSupplied($making, true);
    }

    public function test_a_sent_quotation_refuses_the_change(): void
    {
        $estimate = $this->draft(5);
        $chicken = $this->snapshot($this->line($estimate), 'Chicken');
        $this->estimates->markSent($estimate->refresh());

        try {
            $this->lineBlocks->setCustomerSupplied($chicken->fresh(), true);
            $this->fail('a sent quotation must refuse a supply change');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('has been sent', $e->getMessage());
        }

        $this->assertSame(1910.0, round((float) $estimate->refresh()->subtotal, 2));
    }

    /** Quoting is planning, not a business event. */
    public function test_marking_a_material_customer_supplied_posts_nothing(): void
    {
        $before = $this->ledgerCounts();

        $estimate = $this->draft(5);
        $this->supplyChicken($estimate);
        $this->supplyChicken($estimate, false);

        $this->assertSame($before, $this->ledgerCounts());
    }

    /** The screen has to show both facts, or the model is undone at the glass. */
    public function test_the_cost_details_screen_shows_both_facts(): void
    {
        $html = file_get_contents(base_path('resources/views/tenant/catering/events/show.blade.php'));

        $this->assertStringContainsString('Customer will provide this', $html);
        $this->assertStringContainsString('Customer provides', $html);
        $this->assertStringContainsString('we issue 0', $html,
            'the screen must say the store hands over nothing while the kitchen still needs it');
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        $c = DB::connection('tenant');

        return [
            'journal_entries' => (int) $c->table('journal_entries')->count(),
            'journal_lines' => (int) $c->table('journal_lines')->count(),
            'stock_ledgers' => (int) $c->table('stock_ledgers')->count(),
        ];
    }
}
