<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeOperationalStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-POS-1 (H10/K) — the Edge-ONLY operational stock decrement against real MySQL.
 * Quantity rules must reproduce the Cloud domain (RecipeConsumptionReferenceTest contract) while writing
 * ONLY edge_operational_stock_* tables: the official stock_balances/stock_ledgers stay untouched in every
 * test, there is no valuation anywhere, and consumption is atomic per sale (all-or-nothing).
 */
class EdgeOperationalStockMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta', 'stock_ledgers', 'stock_balances', 'recipe_ingredients', 'recipes', 'modifiers', 'modifier_groups', 'product_variants', 'sale_payments', 'sales_order_lines', 'sales_orders', 'products', 'categories', 'unit_conversions', 'units', 'journal_lines', 'journal_entries', 'branches']);
        $this->userId = $this->makeUser();
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime();
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────
    private function makeUnit(string $prefix): int
    {
        return DB::connection('tenant')->table('units')->insertGetId(['code' => $prefix . Str::random(4), 'name' => $prefix, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeSaleWithLine(int $productId, float $qty, ?array $modifiers = null, string $orderType = 'quick_sale', ?int $variantId = null): SalesOrder
    {
        $sale = SalesOrder::create(['sale_no' => 'SO-' . Str::random(8), 'branch_id' => $this->branchId, 'sale_date' => now(), 'order_type' => $orderType, 'created_by_user_id' => $this->userId]);
        $sale->lines()->create(['product_id' => $productId, 'product_variant_id' => $variantId, 'product_name' => 'X', 'quantity' => $qty, 'unit_price' => 10, 'line_total' => 10 * $qty, 'modifiers' => $modifiers]);

        return $sale->fresh();
    }

    private function consume(SalesOrder $sale): void
    {
        DB::connection('tenant')->transaction(fn () => app(EdgeOperationalStockService::class)->consumeForSale($sale, $this->userId));
    }

    private function makeRecipe(int $productId, float $yield, array $ingredients): int
    {
        $recipeId = DB::connection('tenant')->table('recipes')->insertGetId(['product_id' => $productId, 'name' => 'R' . Str::random(4), 'yield_quantity' => $yield, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($ingredients as $ing) {
            DB::connection('tenant')->table('recipe_ingredients')->insert(array_merge([
                'recipe_id' => $recipeId, 'created_at' => now(), 'updated_at' => now(),
            ], $ing));
        }

        return $recipeId;
    }

    private function makeModifier(int $linkedProductId, float $linkedQty, ?int $linkedUnitId = null, bool $consume = true, ?int $forceNullLinked = null): int
    {
        $groupId = DB::connection('tenant')->table('modifier_groups')->insertGetId(['name' => 'G' . Str::random(4), 'created_at' => now(), 'updated_at' => now()]);

        return DB::connection('tenant')->table('modifiers')->insertGetId([
            'modifier_group_id' => $groupId, 'name' => 'M' . Str::random(4), 'price_delta' => 0,
            'linked_product_id' => $forceNullLinked !== null ? null : $linkedProductId,
            'consume_stock' => $consume ? 1 : 0, 'linked_quantity' => $linkedQty, 'linked_unit_id' => $linkedUnitId,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertOfficialUntouched(): void
    {
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->count(), 'official stock_balances must stay untouched');
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count(), 'official stock_ledgers must stay untouched');
    }

    // ── matrix ───────────────────────────────────────────────────────────────
    public function test_stock_item_decrement_and_movement_official_untouched(): void
    {
        $p = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $b = $this->acceptTestBaseline([['product_id' => $p, 'product_variant_id' => null, 'quantity' => 10]]);
        $sale = $this->makeSaleWithLine($p, 3);

        $this->consume($sale);

        $this->assertSame(7.0, $this->edgeOnHand($b->id, $p));
        $m = DB::connection('tenant')->table('edge_operational_stock_movements')->where('sale_uuid', $sale->sale_uuid)->first();
        $this->assertNotNull($m);
        $this->assertSame('out', $m->direction);
        $this->assertSame(7.0, (float) $m->balance_after);
        $this->assertSame($sale->lines()->first()->line_uuid, $m->line_uuid);
        $this->assertOfficialUntouched();
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
    }

    public function test_no_accepted_baseline_refuses_consumption(): void
    {
        $p = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $sale = $this->makeSaleWithLine($p, 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/baseline/i');
        $this->consume($sale);
    }

    public function test_recipe_yield_one_consumes_per_ingredient(): void
    {
        $cat = $this->makeCategory();
        $bun = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $patty = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $burger = $this->makeProduct($cat, ['inventory_consumption_method' => 'recipe', 'is_stock_tracked' => 0]);
        $this->makeRecipe($burger, 1, [
            ['product_id' => $bun, 'quantity' => 1],
            ['product_id' => $patty, 'quantity' => 1],
        ]);
        $b = $this->acceptTestBaseline([
            ['product_id' => $bun, 'product_variant_id' => null, 'quantity' => 10],
            ['product_id' => $patty, 'product_variant_id' => null, 'quantity' => 10],
        ]);

        $this->consume($this->makeSaleWithLine($burger, 2));

        $this->assertSame(8.0, $this->edgeOnHand($b->id, $bun), 'sell 2 burgers → bun -2');
        $this->assertSame(8.0, $this->edgeOnHand($b->id, $patty), 'sell 2 burgers → patty -2');
        $this->assertOfficialUntouched();
    }

    public function test_recipe_yield_not_one_scales_consumption(): void
    {
        $cat = $this->makeCategory();
        $flour = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $bunBatch = $this->makeProduct($cat, ['inventory_consumption_method' => 'recipe', 'is_stock_tracked' => 0]);
        // dough yields 10 buns; flour 2 per batch; sell 5 buns → flour −(2 × 5/10) = −1.
        $this->makeRecipe($bunBatch, 10, [['product_id' => $flour, 'quantity' => 2]]);
        $b = $this->acceptTestBaseline([['product_id' => $flour, 'product_variant_id' => null, 'quantity' => 10]]);

        $this->consume($this->makeSaleWithLine($bunBatch, 5));

        $this->assertSame(9.0, $this->edgeOnHand($b->id, $flour), 'yield>1 scales consumption down (2 × 5/10 = 1)');
    }

    public function test_order_type_specific_ingredient_only_consumes_for_matching_order_type(): void
    {
        $cat = $this->makeCategory();
        $box = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $meal = $this->makeProduct($cat, ['inventory_consumption_method' => 'recipe', 'is_stock_tracked' => 0]);
        $this->makeRecipe($meal, 1, [
            ['product_id' => $box, 'quantity' => 1, 'applicable_order_types' => json_encode(['takeaway'])],
        ]);
        $b = $this->acceptTestBaseline([['product_id' => $box, 'product_variant_id' => null, 'quantity' => 10]]);

        // quick_sale: the takeaway-only packaging is NOT consumed.
        $this->consume($this->makeSaleWithLine($meal, 1, null, 'quick_sale'));
        $this->assertSame(10.0, $this->edgeOnHand($b->id, $box), 'takeaway-only ingredient skipped on quick_sale');

        // takeaway: consumed.
        $this->consume($this->makeSaleWithLine($meal, 1, null, 'takeaway'));
        $this->assertSame(9.0, $this->edgeOnHand($b->id, $box), 'takeaway ingredient consumed on takeaway');
    }

    public function test_recipe_unit_conversion_and_missing_conversion_block(): void
    {
        $cat = $this->makeCategory();
        $kg = $this->makeUnit('KG');
        $g = $this->makeUnit('G');
        DB::connection('tenant')->table('unit_conversions')->insert(['from_unit_id' => $g, 'to_unit_id' => $kg, 'factor' => 0.001, 'created_at' => now(), 'updated_at' => now()]);
        $sauce = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'unit_id' => $kg]);
        $dish = $this->makeProduct($cat, ['inventory_consumption_method' => 'recipe', 'is_stock_tracked' => 0]);
        $this->makeRecipe($dish, 1, [['product_id' => $sauce, 'quantity' => 50, 'unit_id' => $g]]);
        $b = $this->acceptTestBaseline([['product_id' => $sauce, 'product_variant_id' => null, 'quantity' => 1.0]]);

        $this->consume($this->makeSaleWithLine($dish, 1));
        $this->assertEqualsWithDelta(0.95, $this->edgeOnHand($b->id, $sauce), 1e-6, '50 g → 0.05 KG decrement');

        // missing conversion HARD-BLOCKS and rolls back (no partial decrement).
        $lb = $this->makeUnit('LB');
        $exotic = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'unit_id' => $kg]);
        $dish2 = $this->makeProduct($cat, ['inventory_consumption_method' => 'recipe', 'is_stock_tracked' => 0]);
        $this->makeRecipe($dish2, 1, [['product_id' => $exotic, 'quantity' => 1, 'unit_id' => $lb]]);
        DB::connection('tenant')->table('edge_operational_stock_balances')->insert([
            'balance_key' => $b->id . '-' . $exotic . '-0', 'baseline_id' => $b->id, 'branch_id' => $this->branchId,
            'product_id' => $exotic, 'quantity_on_hand' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $this->consume($this->makeSaleWithLine($dish2, 1));
            $this->fail('missing unit conversion must block the sale');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no unit conversion', $e->getMessage());
        }
        $this->assertSame(5.0, $this->edgeOnHand($b->id, $exotic), 'blocked sale leaves quantity unchanged');
    }

    public function test_modifier_consume_stock_with_conversion_and_missing_linked_product_block(): void
    {
        $cat = $this->makeCategory();
        $kg = $this->makeUnit('KG');
        $g = $this->makeUnit('G');
        DB::connection('tenant')->table('unit_conversions')->insert(['from_unit_id' => $g, 'to_unit_id' => $kg, 'factor' => 0.001, 'created_at' => now(), 'updated_at' => now()]);
        $cheese = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'unit_id' => $kg]);
        $main = $this->makeProduct($cat, ['inventory_consumption_method' => 'none', 'is_stock_tracked' => 0]);
        $b = $this->acceptTestBaseline([['product_id' => $cheese, 'product_variant_id' => null, 'quantity' => 1.0]]);

        // 50 g cheese modifier on a KG-stocked product, line qty 2 → −0.1 KG.
        $mod = $this->makeModifier($cheese, 50, $g);
        $this->consume($this->makeSaleWithLine($main, 2, [['modifier_id' => $mod]]));
        $this->assertEqualsWithDelta(0.9, $this->edgeOnHand($b->id, $cheese), 1e-6, 'modifier 50 g × qty2 → −0.1 KG');

        // consume_stock modifier with NO linked product → hard block.
        $bad = $this->makeModifier($cheese, 1, null, true, 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no linked product/');
        $this->consume($this->makeSaleWithLine($main, 1, [['modifier_id' => $bad]]));
    }

    public function test_variant_specific_operational_stock(): void
    {
        $cat = $this->makeCategory();
        $p = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $variantId = DB::connection('tenant')->table('product_variants')->insertGetId(['product_id' => $p, 'sku' => 'V' . Str::random(5), 'name' => 'Large', 'is_default' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $b = $this->acceptTestBaseline([
            ['product_id' => $p, 'product_variant_id' => $variantId, 'quantity' => 5],
        ]);

        $this->consume($this->makeSaleWithLine($p, 2, null, 'quick_sale', $variantId));

        $this->assertSame(3.0, $this->edgeOnHand($b->id, $p, $variantId), 'variant-keyed balance decremented');
        $this->assertOfficialUntouched();
    }

    public function test_negative_stock_policy_on_and_off(): void
    {
        $p = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $b = $this->acceptTestBaseline([['product_id' => $p, 'product_variant_id' => null, 'quantity' => 2]]);

        // OFF: block.
        try {
            $this->consume($this->makeSaleWithLine($p, 3));
            $this->fail('negative stock disallowed must block');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Insufficient stock', $e->getMessage());
        }
        $this->assertSame(2.0, $this->edgeOnHand($b->id, $p));

        // ON: records the negative.
        DB::connection('tenant')->table('branches')->where('id', $this->branchId)->update(['allow_negative_stock' => 1]);
        $this->consume($this->makeSaleWithLine($p, 3));
        $this->assertSame(-1.0, $this->edgeOnHand($b->id, $p), 'allow_negative_stock=true records the negative');
    }

    // ── (#8) movement history is append-only: a baseline delete must NOT erase it ──
    public function test_baseline_delete_is_restricted_while_movement_history_exists(): void
    {
        $p = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $b = $this->acceptTestBaseline([['product_id' => $p, 'product_variant_id' => null, 'quantity' => 10]]);
        $this->consume($this->makeSaleWithLine($p, 3));
        $this->assertSame(1, DB::connection('tenant')->table('edge_operational_stock_movements')->count());

        try {
            DB::connection('tenant')->table('edge_operational_stock_baselines')->where('id', $b->id)->delete();
            $this->fail('deleting a baseline with movement history must be restricted');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('foreign key', $e->getMessage());
        }
        // history + balance intact.
        $this->assertSame(1, DB::connection('tenant')->table('edge_operational_stock_movements')->count(), 'movement history survives');
        $this->assertSame(7.0, $this->edgeOnHand($b->id, $p), 'balance intact');
        $this->assertSame(1, DB::connection('tenant')->table('edge_operational_stock_baselines')->where('id', $b->id)->count(), 'baseline intact');
    }

    public function test_multi_component_failure_rolls_back_everything(): void
    {
        $cat = $this->makeCategory();
        $kg = $this->makeUnit('KG');
        $lb = $this->makeUnit('LB'); // no conversion path
        $ok = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);
        $blocked = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'unit_id' => $kg]);
        $combo = $this->makeProduct($cat, ['inventory_consumption_method' => 'recipe', 'is_stock_tracked' => 0]);
        // ingredient A consumable; ingredient B needs LB→KG conversion that does not exist.
        $this->makeRecipe($combo, 1, [
            ['product_id' => $ok, 'quantity' => 1],
            ['product_id' => $blocked, 'quantity' => 1, 'unit_id' => $lb],
        ]);
        $b = $this->acceptTestBaseline([
            ['product_id' => $ok, 'product_variant_id' => null, 'quantity' => 10],
            ['product_id' => $blocked, 'product_variant_id' => null, 'quantity' => 10],
        ]);

        try {
            $this->consume($this->makeSaleWithLine($combo, 1));
            $this->fail('component B failure must abort the whole consumption');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no unit conversion', $e->getMessage());
        }
        // component A was consumed INSIDE the transaction — the rollback must restore it.
        $this->assertSame(10.0, $this->edgeOnHand($b->id, $ok), 'component A decrement rolled back');
        $this->assertSame(10.0, $this->edgeOnHand($b->id, $blocked));
        $this->assertSame(0, DB::connection('tenant')->table('edge_operational_stock_movements')->count(), 'no movement survives the rollback');
        $this->assertOfficialUntouched();
    }
}
