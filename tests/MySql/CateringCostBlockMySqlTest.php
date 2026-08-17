<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringCostBlockService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-COST-BLOCKS-1 — a dish priced from its parts.
 *
 * The examples below are Kashif's own, used verbatim as the specification:
 *
 *   Chicken Karahi   chicken 200 + making 500 = 700/KG
 *   Biryani 10 KG    chicken 5 KG and rice 5 KG   (ratio 0.5, not 1:1)
 *   Biryani 6 KG     customer brings the chicken — rice and making still charged
 *
 * The property worth protecting is that a material block's two numbers stay
 * independent: what the customer is charged, and what the store hands over.
 * Collapsing them would make either the bill or the kitchen sheet wrong, and
 * nothing else in the system would notice.
 */
class CateringCostBlockMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private Product $biryani;

    private Product $chicken;

    private Product $rice;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates',
            'units', 'products', 'categories', 'branches',
        ]);

        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryani = Product::findOrFail($this->makeProduct($categoryId, [
            'name' => 'Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->unitId,
        ]));
        $this->chicken = Product::findOrFail($this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]));
        $this->rice = Product::findOrFail($this->makeProduct($categoryId, [
            'name' => 'Rice', 'sku' => 'RM-RICE', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]));

        foreach ([[$this->chicken, 200], [$this->rice, 120]] as [$material, $rate]) {
            CateringMaterialRate::create([
                'product_id' => $material->id, 'rate' => $rate, 'unit_id' => $this->unitId,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
        }
    }

    private function service(): CateringCostBlockService
    {
        return app(CateringCostBlockService::class);
    }

    /** @return array<string, CateringProductCostBlock> */
    private function buildBiryani(): array
    {
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryani->id],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

        return [
            'chicken' => CateringProductCostBlock::create([
                'product_id' => $this->biryani->id, 'label' => 'chicken',
                'block_type' => 'material', 'material_product_id' => $this->chicken->id,
                'quantity_per_unit' => 0.5, 'unit_id' => $this->unitId,
                'rate' => 200, 'charge_basis' => 'per_unit', 'sort_order' => 1,
            ]),
            'rice' => CateringProductCostBlock::create([
                'product_id' => $this->biryani->id, 'label' => 'rice',
                'block_type' => 'material', 'material_product_id' => $this->rice->id,
                'quantity_per_unit' => 0.5, 'unit_id' => $this->unitId,
                'rate' => 120, 'charge_basis' => 'per_unit', 'sort_order' => 2,
            ]),
            'making' => CateringProductCostBlock::create([
                'product_id' => $this->biryani->id, 'label' => 'making',
                'block_type' => 'charge', 'rate' => 400,
                'charge_basis' => 'per_unit', 'sort_order' => 3,
            ]),
        ];
    }

    /** The dish rate is the sum of its per-unit blocks. */
    public function test_the_dish_rate_is_the_sum_of_its_blocks(): void
    {
        $this->buildBiryani();

        // 200 chicken + 120 rice + 400 making
        $this->assertSame(720.0, $this->service()->rateFor($this->biryani->id));
    }

    /** Change a block, and the dish price follows on its own. */
    public function test_raising_a_block_raises_the_dish(): void
    {
        $blocks = $this->buildBiryani();

        $blocks['chicken']->update(['rate' => 250]);

        $this->assertSame(770.0, $this->service()->rateFor($this->biryani->id),
            'the price is the sum of the parts, so a part rising raises the whole');
    }

    /**
     * Kashif's example: 10 KG of biryani needs 5 KG chicken and 5 KG rice — the
     * ratio, not one-for-one.
     */
    public function test_ten_kilos_of_biryani_needs_five_of_chicken_and_five_of_rice(): void
    {
        $this->buildBiryani();

        $line = $this->service()->priceLine($this->biryani->id, 10);

        $byName = collect($line['materials'])->keyBy('name');
        $this->assertEqualsWithDelta(5.0, $byName['Chicken']['required_qty'], 0.001);
        $this->assertEqualsWithDelta(5.0, $byName['Rice']['required_qty'], 0.001);

        // Charged 10 × 720, while only 5 KG of each material leaves the store.
        $this->assertSame(7200.0, $line['total']);
    }

    /** The charge and the requirement are separate numbers, and must stay so. */
    public function test_the_charge_and_the_material_drawn_are_independent(): void
    {
        $this->buildBiryani();

        $line = $this->service()->priceLine($this->biryani->id, 20);

        $chicken = collect($line['blocks'])->firstWhere('label', 'chicken');

        $this->assertSame(4000.0, $chicken['amount'], '20 × 200 charged for chicken');
        $this->assertEqualsWithDelta(10.0, $chicken['required_qty'], 0.001, 'but only 10 KG drawn');
    }

    /**
     * Customer brings their own chicken. It must drop out of BOTH the bill and
     * the store request — either alone is a different kind of wrong.
     */
    public function test_a_customer_supplied_block_is_neither_charged_nor_drawn(): void
    {
        $blocks = $this->buildBiryani();

        $line = $this->service()->priceLine($this->biryani->id, 6, [$blocks['chicken']->id]);

        $chicken = collect($line['blocks'])->firstWhere('label', 'chicken');
        $this->assertTrue($chicken['customer_supplied']);
        $this->assertSame(0.0, $chicken['amount'], 'not charged for chicken the customer brought');
        $this->assertSame(0.0, $chicken['required_qty'], 'and the store is not asked for it either');

        $this->assertSame([], collect($line['materials'])->where('name', 'Chicken')->values()->all(),
            'it must not appear on the kitchen sheet at all');

        // 6 × (120 rice + 400 making) = 3,120
        $this->assertSame(3120.0, $line['total']);
        $this->assertEqualsWithDelta(3.0, collect($line['materials'])->firstWhere('name', 'Rice')['required_qty'], 0.001);
    }

    /** A lump sum ignores quantity — that is the whole point of it. */
    public function test_a_lump_sum_block_is_charged_once_however_big_the_order(): void
    {
        $this->buildBiryani();

        CateringProductCostBlock::create([
            'product_id' => $this->biryani->id, 'label' => 'live counter setup',
            'block_type' => 'charge', 'rate' => 3000,
            'charge_basis' => 'lump_sum', 'sort_order' => 4,
        ]);

        $ten = $this->service()->priceLine($this->biryani->id, 10);
        $hundred = $this->service()->priceLine($this->biryani->id, 100);

        $this->assertSame(3000.0, collect($ten['blocks'])->firstWhere('label', 'live counter setup')['amount']);
        $this->assertSame(3000.0, collect($hundred['blocks'])->firstWhere('label', 'live counter setup')['amount'],
            'a lump sum does not scale — 100 KG pays the same setup as 10 KG');

        $this->assertSame(10200.0, $ten['total']);   // 7,200 + 3,000
        $this->assertSame(75000.0, $hundred['total']); // 72,000 + 3,000
    }

    /**
     * A lump sum must never leak into the per-unit rate: it would be wrong at
     * every quantity except the one it was divided by.
     */
    public function test_a_lump_sum_never_appears_in_the_per_unit_rate(): void
    {
        $this->buildBiryani();

        CateringProductCostBlock::create([
            'product_id' => $this->biryani->id, 'label' => 'setup',
            'block_type' => 'charge', 'rate' => 5000,
            'charge_basis' => 'lump_sum', 'sort_order' => 9,
        ]);

        $this->assertSame(720.0, $this->service()->rateFor($this->biryani->id),
            'the per-unit rate stays 720 — the setup fee belongs on the line, not in the rate');
    }

    /** Expected material cost comes from the Rate Book, and is not the charge. */
    public function test_expected_cost_is_read_from_the_rate_book_not_the_charge(): void
    {
        $this->buildBiryani();

        // 5 KG chicken × 200 + 5 KG rice × 120 = 1,600
        $this->assertEqualsWithDelta(1600.0, $this->service()->expectedMaterialCost($this->biryani->id, 10), 0.01);

        // Charged 7,200 for the same line — the gap is the real margin.
        $this->assertSame(7200.0, $this->service()->priceLine($this->biryani->id, 10)['total']);
    }

    /**
     * The number that matters most, stated on its own: what the customer is
     * charged for a material and what that material costs are unrelated.
     *
     * A 10 KG biryani charges 200/KG of dish for chicken — 2,000 — while drawing
     * 5 KG at the rate book's 320, which is 1,600. Snapshotting the charge as
     * cost would report a margin that never existed.
     */
    public function test_the_charge_for_a_material_is_not_what_that_material_costs(): void
    {
        $blocks = $this->buildBiryani();
        CateringMaterialRate::where('product_id', $this->chicken->id)->delete();
        CateringMaterialRate::create([
            'product_id' => $this->chicken->id, 'rate' => 320, 'unit_id' => $this->unitId,
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        $line = $this->service()->priceLine($this->biryani->id, 10);
        $chicken = collect($line['blocks'])->firstWhere('label', 'chicken');

        $this->assertSame(2000.0, $chicken['amount'], 'charged 10 x 200 for chicken');
        $this->assertEqualsWithDelta(5.0, $chicken['required_qty'], 0.001, 'while drawing 5 KG');

        // 5 KG chicken x 320 + 5 KG rice x 120 = 1,600 + 600
        $this->assertEqualsWithDelta(2200.0, $this->service()->expectedMaterialCost($this->biryani->id, 10), 0.01);
        $this->assertEqualsWithDelta(
            1600.0,
            $this->service()->expectedMaterialCost($this->biryani->id, 10, [$blocks['rice']->id]),
            0.01,
            'the chicken alone costs 1,600 — not the 2,000 it was charged at'
        );
    }

    /** Customer-supplied material costs nothing, because none of it is used. */
    public function test_customer_supplied_material_costs_nothing(): void
    {
        $blocks = $this->buildBiryani();

        // rice only: 5 × 120 = 600
        $this->assertEqualsWithDelta(
            600.0,
            $this->service()->expectedMaterialCost($this->biryani->id, 10, [$blocks['chicken']->id]),
            0.01
        );
    }

    /** A material with no rate-book entry blocks quoting rather than guessing. */
    public function test_a_material_with_no_rate_blocks_the_quotation(): void
    {
        $this->buildBiryani();
        CateringMaterialRate::where('product_id', $this->chicken->id)->delete();

        $readiness = $this->service()->readiness($this->biryani->id);

        $this->assertFalse($readiness['ready']);
        $this->assertNotEmpty($readiness['blockers']);
        $this->assertStringContainsString('Material Rate Book', implode(' ', $readiness['blockers']));
    }

    /** A dish switched to blocks with none defined cannot be quoted. */
    public function test_a_dish_in_block_mode_with_no_blocks_is_not_ready(): void
    {
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryani->id],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

        $readiness = $this->service()->readiness($this->biryani->id);

        $this->assertFalse($readiness['ready']);
        $this->assertStringContainsString('has none defined', implode(' ', $readiness['blockers']));
    }

    /** Existing dishes are untouched: recipe stays the default. */
    public function test_a_dish_defaults_to_recipe_mode(): void
    {
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryani->id],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed']
        );

        $this->assertFalse($this->service()->usesBlocks($this->biryani->fresh()),
            'nothing changes for an existing dish until it is deliberately switched');
    }
}
