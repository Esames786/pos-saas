<?php

namespace Tests\Feature\Pos;

use App\Models\Tenant\Product;
use App\Models\Tenant\Recipe;
use App\Models\Tenant\RecipeIngredient;
use App\Models\Tenant\Unit;
use App\Services\Kitchen\UnitConversionService;
use App\Support\Pos\RecipeAvailability;
use Tests\TestCase;

/**
 * W-G1 — ONE implementation of the recipe "makeable" preview for both runtimes. The arithmetic is Online's (the former
 * private POSController::recipeAvailability, moved verbatim); both controllers must call the shared class and no copy may
 * remain. Pure in-memory models — no database.
 */
class RecipeAvailabilityTest extends TestCase
{
    private function unit(int $id, string $code): Unit
    {
        $u = new Unit(['code' => $code, 'name' => $code]);
        $u->id = $id;

        return $u;
    }

    private function ingredient(Product $product, float $qty, ?Unit $unit, ?int $variantId = null): RecipeIngredient
    {
        $i = new RecipeIngredient(['quantity' => $qty, 'unit_id' => $unit?->id, 'product_variant_id' => $variantId]);
        $i->setRelation('product', $product);
        $i->setRelation('unit', $unit);

        return $i;
    }

    private function raw(int $id, string $name, Unit $unit, bool $tracked = true): Product
    {
        $p = new Product(['name' => $name, 'unit_id' => $unit->id, 'is_stock_tracked' => $tracked]);
        $p->id = $id;
        $p->setRelation('unit', $unit);

        return $p;
    }

    private function recipeProduct(float $yield, array $ingredients): Product
    {
        $p = new Product(['name' => 'Naan', 'inventory_consumption_method' => 'recipe', 'is_stock_tracked' => false]);
        $p->id = 1;
        $recipe = new Recipe(['yield_quantity' => $yield, 'is_active' => true]);
        $recipe->setRelation('ingredients', collect($ingredients));
        $p->setRelation('activeRecipe', $recipe);

        return $p;
    }

    private function availability(?UnitConversionService $conversion = null): RecipeAvailability
    {
        if (! $conversion) {
            $conversion = \Mockery::mock(UnitConversionService::class);
            $conversion->shouldNotReceive('convert');
        }

        return new RecipeAvailability($conversion);
    }

    public function test_online_arithmetic_min_over_tracked_ingredients_times_yield_floored_with_the_limiting_name(): void
    {
        $kg = $this->unit(2, 'kg');
        $flour = $this->raw(10, 'Flour', $kg);
        $ghee = $this->raw(11, 'Ghee', $kg);
        $salt = $this->raw(12, 'Salt', $kg, tracked: false);
        $naan = $this->recipeProduct(10, [
            $this->ingredient($flour, 1, $kg),
            $this->ingredient($ghee, 0.2, $kg),
            $this->ingredient($salt, 0.02, $kg), // untracked → never constrains
        ]);
        $branches = [(object) ['id' => 7], (object) ['id' => 8]];
        $stock = [7 => [10 => [0 => 2.5], 11 => [0 => 1.5]], 8 => [10 => [0 => 10.0], 11 => [0 => 0.5]]];

        $r = $this->availability()->forProduct($naan, $branches, $stock);

        $this->assertTrue($r['is_recipe']);
        // branch 7: flour 2.5/1×10 = 25 vs ghee 1.5/0.2×10 = 75 → 25, Flour; branch 8: flour 100 vs ghee 25 → 25, Ghee.
        $this->assertSame([7 => 25, 8 => 25], $r['makeable']);
        $this->assertSame([7 => 'Flour', 8 => 'Ghee'], $r['limiting']);

        // A branch with no stock rows at all → 0 makeable, the first ingredient limits (Online: `?? 0.0`).
        $none = $this->availability()->forProduct($naan, [(object) ['id' => 9]], $stock);
        $this->assertSame([9 => 0], $none['makeable']);
        $this->assertSame([9 => 'Flour'], $none['limiting']);
    }

    public function test_unit_conversion_is_applied_from_the_ingredient_unit_to_the_product_base_unit_and_a_missing_path_uses_the_raw_quantity(): void
    {
        $kg = $this->unit(2, 'kg');
        $g = $this->unit(3, 'g');
        $ghee = $this->raw(11, 'Ghee', $kg);
        $conversion = \Mockery::mock(UnitConversionService::class);
        $conversion->shouldReceive('convert')->once()->with(200.0, $g, $kg)->andReturn(0.2);
        $naan = $this->recipeProduct(10, [$this->ingredient($ghee, 200, $g)]);

        $r = $this->availability($conversion)->forProduct($naan, [(object) ['id' => 1]], [1 => [11 => [0 => 1.5]]]);
        $this->assertSame([1 => 75], $r['makeable']); // 1.5 / 0.2 × 10

        // No conversion path → the quantity is used as-is (Online swallows the RuntimeException).
        $broken = \Mockery::mock(UnitConversionService::class);
        $broken->shouldReceive('convert')->once()->andThrow(new \RuntimeException('No unit conversion found'));
        $r2 = $this->availability($broken)->forProduct($naan, [(object) ['id' => 1]], [1 => [11 => [0 => 1.5]]]);
        $this->assertSame([1 => 0], $r2['makeable']); // floor(1.5 / 200 × 10)

        // A variant-specific ingredient reads the variant's balance.
        $variantIng = $this->ingredient($this->raw(20, 'Sauce', $kg), 0.5, $kg, variantId: 44);
        $r3 = $this->availability()->forProduct($this->recipeProduct(1, [$variantIng]), [(object) ['id' => 1]], [1 => [20 => [0 => 100.0, 44 => 2.0]]]);
        $this->assertSame([1 => 4], $r3['makeable']);
    }

    public function test_blank_for_non_recipe_products_recipes_without_an_active_recipe_or_without_tracked_ingredients(): void
    {
        $kg = $this->unit(2, 'kg');
        $blank = ['is_recipe' => false, 'makeable' => [], 'limiting' => []];
        $this->assertSame($blank, RecipeAvailability::blank());

        $stockItem = new Product(['inventory_consumption_method' => 'stock_item']);
        $this->assertSame($blank, $this->availability()->forProduct($stockItem, [(object) ['id' => 1]], []));

        $noRecipe = new Product(['inventory_consumption_method' => 'recipe']);
        $noRecipe->setRelation('activeRecipe', null);
        $this->assertSame($blank, $this->availability()->forProduct($noRecipe, [(object) ['id' => 1]], []));

        $untrackedOnly = $this->recipeProduct(4, [$this->ingredient($this->raw(12, 'Salt', $kg, tracked: false), 0.01, $kg)]);
        $this->assertSame($blank, $this->availability()->forProduct($untrackedOnly, [(object) ['id' => 1]], []));

        // yield 0 / null → treated as 1 (Online `(float) ($recipe->yield_quantity ?: 1) ?: 1`).
        $zeroYield = $this->recipeProduct(0, [$this->ingredient($this->raw(10, 'Flour', $kg), 2, $kg)]);
        $this->assertSame([1 => 3], $this->availability()->forProduct($zeroYield, [(object) ['id' => 1]], [1 => [10 => [0 => 6.0]]])['makeable']);
    }

    public function test_stock_lookup_builder_matches_the_former_inline_loops_of_both_controllers(): void
    {
        $rows = [
            (object) ['branch_id' => 1, 'product_id' => 10, 'product_variant_id' => null, 'qty' => '2.500'],
            (object) ['branch_id' => 1, 'product_id' => 10, 'product_variant_id' => 44, 'qty' => '1'],
            ['branch_id' => '2', 'product_id' => '11', 'product_variant_id' => 0, 'qty' => 7],
        ];
        $this->assertSame([1 => [10 => [0 => 2.5, 44 => 1.0]], 2 => [11 => [0 => 7.0]]], RecipeAvailability::stockLookup($rows, 'qty'));
        $this->assertSame([1 => [10 => [0 => 3.0]]], RecipeAvailability::stockLookup([['branch_id' => 1, 'product_id' => 10, 'product_variant_id' => null, 'quantity_on_hand' => 3]], 'quantity_on_hand'));
    }

    public function test_both_controllers_use_the_shared_class_and_no_private_copy_remains(): void
    {
        $online = (string) file_get_contents(base_path('app/Http/Controllers/Tenant/POSController.php'));
        $edge = (string) file_get_contents(base_path('app/Http/Controllers/Edge/EdgeLocalPosController.php'));

        $this->assertStringContainsString('RecipeAvailability::class', $online);
        $this->assertStringContainsString('->forProduct($product, $branches, $stockLookup)', $online);
        $this->assertStringNotContainsString('private function recipeAvailability(', $online, 'the Online private copy must be gone');
        $this->assertStringNotContainsString('UnitConversionService', $online, 'Online no longer converts units itself');

        $this->assertStringContainsString('RecipeAvailability::class', $edge);
        $this->assertStringContainsString('->forProduct($p, [$branch], $recipeStock)', $edge);
        $this->assertStringContainsString("'activeRecipe.ingredients.product.unit'", $edge, 'Edge eager-loads the same recipe relations Online does');
        $this->assertStringNotContainsString("'is_recipe' => false", $edge, 'the Edge no longer ships a hard-coded is_recipe=false on the shared view');
        $this->assertStringNotContainsString('floor(max(0', $edge, 'no second copy of the arithmetic on the Edge');
    }
}
