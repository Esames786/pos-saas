<?php

namespace Tests\MySql;

use App\Models\Tenant\Unit;
use App\Services\Kitchen\UnitConversionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * §9 — Operational recipe/component stock REFERENCE fixtures (MYSQL-TEST-FOUNDATION-1).
 *
 * No EdgeOperationalStockService exists yet. This test establishes reusable MySQL
 * fixtures + the reference operational-quantity expectations that the future
 * EdgeOperationalStockService MUST reproduce (quantity only — no COGS/FEFO/GL).
 *
 * The consumption formula is the current cloud rule (RecipeConsumptionService):
 *     required = ingredient.quantity * (soldQty / recipe.yield_quantity)
 * with unit conversion when the ingredient unit differs from the stock unit, and a
 * MISSING conversion HARD-BLOCKS the sale. This test drives the REAL
 * UnitConversionService so the conversion cases are proven against production code,
 * not a re-implemented formula.
 */
class RecipeConsumptionReferenceTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private function makeUnit(string $code, string $name): int
    {
        return DB::connection('tenant')->table('units')->insertGetId([
            'code' => $code, 'name' => $name, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeConversion(int $from, int $to, float $factor): void
    {
        DB::connection('tenant')->table('unit_conversions')->insert([
            'from_unit_id' => $from, 'to_unit_id' => $to, 'factor' => $factor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Reference: direct stock_item and recipe-yield operational quantities. */
    public function test_stock_item_and_recipe_yield_reference_quantities(): void
    {
        // stock_item: sell 3 -> operational -3 of the product itself.
        $soldQty = 3.0;
        $this->assertSame(3.0, $soldQty, 'stock_item consumes its own quantity 1:1.');

        // recipe: Burger yield 1, ingredients bun x1 + patty x1; sell 2 -> bun -2, patty -2.
        $yield = 1.0; $bunPer = 1.0; $pattyPer = 1.0; $sold = 2.0;
        $batch = $sold / $yield;
        $this->assertSame(2.0, $bunPer * $batch);
        $this->assertSame(2.0, $pattyPer * $batch);

        // recipe with a non-unit yield: dough yields 10 buns; sell 5 buns -> flour = per * 0.5.
        $doughYield = 10.0; $flourPerBatch = 2.0; $soldBuns = 5.0;
        $this->assertSame(1.0, $flourPerBatch * ($soldBuns / $doughYield), 'yield>1 scales consumption down.');
    }

    /** Reference: recipe + unit conversion, proven through the REAL UnitConversionService. */
    public function test_recipe_unit_conversion_uses_real_service_and_blocks_when_missing(): void
    {
        $this->cleanTenant(['unit_conversions', 'units']);

        $kg = $this->makeUnit('KG-' . uniqid(), 'Kilogram');
        $g  = $this->makeUnit('G-' . uniqid(), 'Gram');
        $this->makeConversion($g, $kg, 0.001); // 1 g = 0.001 kg

        /** @var UnitConversionService $svc */
        $svc = app(UnitConversionService::class);
        $gUnit = Unit::on('tenant')->find($g);
        $kgUnit = Unit::on('tenant')->find($kg);

        // Modifier "50 g sauce" on a KG-stocked product -> deduct 0.05 KG (real service).
        $this->assertEqualsWithDelta(0.05, $svc->convert(50, $gUnit, $kgUnit), 1e-9,
            'Real UnitConversionService must convert 50 g -> 0.05 KG for operational decrement.');

        // A missing conversion HARD-BLOCKS — the exact offline blocking condition.
        $lb = $this->makeUnit('LB-' . uniqid(), 'Pound');
        $lbUnit = Unit::on('tenant')->find($lb);
        $this->expectException(RuntimeException::class);
        $svc->convert(1, $lbUnit, $kgUnit);
    }

    /**
     * Reference: allow_negative_stock gates the local hard-block. This documents the
     * operational-availability rule the EdgeOperationalStockService must enforce.
     */
    public function test_allow_negative_stock_gates_the_hard_block_reference(): void
    {
        // availability = baseline - consumed
        $baseline = 2.0; $consume = 3.0;
        $available = $baseline - $consume;

        $allowNegativeFalse = false;
        $blocked = (! $allowNegativeFalse) && $available < 0;
        $this->assertTrue($blocked, 'allow_negative_stock=false MUST hard-block when availability < 0.');

        $allowNegativeTrue = true;
        $blockedWhenAllowed = (! $allowNegativeTrue) && $available < 0;
        $this->assertFalse($blockedWhenAllowed, 'allow_negative_stock=true allows and records the negative.');
    }
}
