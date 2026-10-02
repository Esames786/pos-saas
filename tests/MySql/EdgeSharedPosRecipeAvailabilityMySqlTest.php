<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W-G1 — the recipe "makeable" preview on the Branch Server's shared cashier view (`is_recipe` / `makeable_by_branch` /
 * `limiting_ingredient_by_branch`), computed from the LOCAL database through the ONE shared class Online uses
 * (App\Support\Pos\RecipeAvailability), over REAL HTTP on a branch_server-booted app.
 *
 * The recipe config (recipes / recipe_ingredients / unit_conversions, bootstrap Section K) is in the local DB; the
 * stock is the ACCEPTED operational baseline — the very balances EdgeOperationalStockService::consumeRecipe decrements
 * and refuses on. The numbers below are Online's arithmetic (POSController's former recipeAvailability):
 *   makeable = floor(min over tracked ingredients of (on-hand / required-per-batch in the ingredient's base unit) × yield).
 */
class EdgeSharedPosRecipeAvailabilityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $karahi;      // plain stock item — is_recipe stays false
    private int $naan;        // recipe product (yield 10) — flour (kg) + ghee (g → kg conversion)
    private int $flour;       // raw material, kg, stock-tracked
    private int $ghee;        // raw material, kg, stock-tracked (ingredient quantity given in g)
    private int $salt;        // raw material, NOT stock-tracked — never constrains
    private int $soup;        // recipe product whose only ingredients are untracked → blank (plain service)

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'recipe_consumptions', 'recipe_ingredients', 'recipes', 'unit_conversions',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'printers', 'terminal_printer_settings',
            'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'combo_components', 'combos', 'products', 'categories', 'units', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['name' => 'Recipe Branch', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RCP' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter 1']);
        $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);

        $u = DB::connection('tenant')->table('units');
        $pcs = (int) $u->insertGetId(['code' => 'pcs', 'name' => 'Piece', 'unit_type' => 'quantity', 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $kg = (int) $u->insertGetId(['code' => 'kg', 'name' => 'Kilogram', 'unit_type' => 'weight', 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $g = (int) $u->insertGetId(['code' => 'g', 'name' => 'Gram', 'unit_type' => 'weight', 'is_base' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        // The tenant-global conversion the bootstrap ships (Section K): 1 g = 0.001 kg.
        DB::connection('tenant')->table('unit_conversions')->insert(['from_unit_id' => $g, 'to_unit_id' => $kg, 'factor' => 0.001, 'created_at' => now(), 'updated_at' => now()]);

        $menu = $this->makeCategory(['name' => 'Menu']);
        $raw = $this->makeCategory(['name' => 'Raw Materials']);
        $this->karahi = $this->makeProduct($menu, ['name' => 'Chicken Karahi', 'unit_id' => $pcs, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->naan = $this->makeProduct($menu, ['name' => 'Roghni Naan', 'unit_id' => $pcs, 'item_kind' => 'finished_good', 'inventory_consumption_method' => 'recipe', 'is_stock_tracked' => 0, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->soup = $this->makeProduct($menu, ['name' => 'Clear Soup', 'unit_id' => $pcs, 'inventory_consumption_method' => 'recipe', 'is_stock_tracked' => 0, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 80]);
        // Raw materials: shipped as bare config rows (not sellable, not on the grid) — exactly as the bootstrap ships them.
        $this->flour = $this->makeProduct($raw, ['name' => 'Flour', 'unit_id' => $kg, 'item_kind' => 'ingredient', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 0, 'is_pos_visible' => 0, 'status' => 'active']);
        $this->ghee = $this->makeProduct($raw, ['name' => 'Ghee', 'unit_id' => $kg, 'item_kind' => 'ingredient', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 0, 'is_pos_visible' => 0, 'status' => 'active']);
        $this->salt = $this->makeProduct($raw, ['name' => 'Salt', 'unit_id' => $kg, 'item_kind' => 'ingredient', 'inventory_consumption_method' => 'none', 'is_stock_tracked' => 0, 'is_sellable' => 0, 'is_pos_visible' => 0, 'status' => 'active']);

        // Naan recipe: one batch = 10 naan from 1 kg flour + 250 g ghee (+ salt, untracked). 0.25 / 0.5 kg are exact binary fractions, so floor() never trips over a float artefact (Online arithmetic).
        $naanRecipe = (int) DB::connection('tenant')->table('recipes')->insertGetId(['product_id' => $this->naan, 'name' => 'Naan dough', 'yield_quantity' => 10, 'yield_unit_id' => $pcs, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('recipe_ingredients')->insert([
            ['recipe_id' => $naanRecipe, 'product_id' => $this->flour, 'quantity' => 1, 'unit_id' => $kg, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['recipe_id' => $naanRecipe, 'product_id' => $this->ghee, 'quantity' => 250, 'unit_id' => $g, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['recipe_id' => $naanRecipe, 'product_id' => $this->salt, 'quantity' => 0.02, 'unit_id' => $kg, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Soup recipe: only an untracked ingredient → Online treats it as a plain service (blank).
        $soupRecipe = (int) DB::connection('tenant')->table('recipes')->insertGetId(['product_id' => $this->soup, 'name' => 'Soup', 'yield_quantity' => 4, 'yield_unit_id' => $pcs, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('recipe_ingredients')->insert([
            ['recipe_id' => $soupRecipe, 'product_id' => $this->salt, 'quantity' => 0.01, 'unit_id' => $kg, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    /** productsPayload of the shared page, keyed by product id. */
    private function payload(): array
    {
        $response = $this->get('/edge/local/pos/shared')->assertOk();
        $products = null;
        $response->assertViewHas('productsPayload', function ($p) use (&$products) {
            $products = collect($p)->keyBy('id')->all();

            return true;
        });

        return $products;
    }

    public function test_the_makeable_preview_is_computed_from_the_accepted_baseline_with_online_arithmetic(): void
    {
        // Baseline: 2.5 kg flour, 1.5 kg ghee (the raw materials ride the baseline like any stock item).
        $this->acceptTestBaseline([
            ['product_id' => $this->karahi, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->flour, 'product_variant_id' => null, 'quantity' => 2.5],
            ['product_id' => $this->ghee, 'product_variant_id' => null, 'quantity' => 1.5],
        ]);
        $p = $this->payload();

        // Naan: flour → 2.5 / 1 × 10 = 25; ghee → 1.5 / (250 g = 0.25 kg) × 10 = 60; salt untracked → ignored. Limiting = Flour.
        $naan = $p[$this->naan];
        $this->assertTrue($naan['is_recipe']);
        $this->assertSame([$this->branchId => 25], $naan['makeable_by_branch']);
        $this->assertSame([$this->branchId => 'Flour'], $naan['limiting_ingredient_by_branch']);
        $this->assertFalse($naan['is_stock_tracked'], 'a recipe product is not an on-hand count (the page reads !is_stock_tracked && is_recipe)');
        $this->assertSame([], $naan['stock_by_branch']);

        // A plain stock item is NOT a recipe (Online: inventory_consumption_method !== recipe → blank) — unchanged Edge truth.
        $karahi = $p[$this->karahi];
        $this->assertFalse($karahi['is_recipe']);
        $this->assertSame([], $karahi['makeable_by_branch']);
        $this->assertSame([], $karahi['limiting_ingredient_by_branch']);
        $this->assertSame([$this->branchId => 50.0], $karahi['stock_by_branch']);

        // A recipe with no stock-tracked ingredient is a plain service (Online: blank, never "0 makeable").
        $soup = $p[$this->soup];
        $this->assertFalse($soup['is_recipe']);
        $this->assertSame([], $soup['makeable_by_branch']);

        // Raw materials never become tiles.
        $this->assertArrayNotHasKey($this->flour, $p);
        $this->assertArrayNotHasKey($this->ghee, $p);
    }

    public function test_the_preview_follows_the_operational_balance_the_sale_consumes_and_is_zero_without_a_baseline(): void
    {
        // No accepted baseline yet: honest 0 (the sale refuses without a baseline too), still flagged as a recipe.
        $before = $this->payload()[$this->naan];
        $this->assertTrue($before['is_recipe']);
        $this->assertSame([$this->branchId => 0], $before['makeable_by_branch']);

        // Ghee is the scarce one now: 10 kg flour (→ 100) vs 0.5 kg ghee (→ 0.5 / 0.25 × 10 = 20) → 20, limiting Ghee.
        $this->acceptTestBaseline([
            ['product_id' => $this->karahi, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->flour, 'product_variant_id' => null, 'quantity' => 10],
            ['product_id' => $this->ghee, 'product_variant_id' => null, 'quantity' => 0.5],
        ]);
        $this->assertSame([$this->branchId => 20], $this->payload()[$this->naan]['makeable_by_branch']);
        $this->assertSame([$this->branchId => 'Ghee'], $this->payload()[$this->naan]['limiting_ingredient_by_branch']);

        // Sell 10 naan (one batch): the operational baseline loses 1 kg flour + 0.25 kg ghee → ghee 0.25 → 0.25 / 0.25 × 10 = 10.
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        $cash = (int) DB::connection('tenant')->table('payment_methods')->value('id');
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->naan, 'quantity' => 10]],
            'payments' => [['payment_method_id' => $cash, 'amount' => 500, 'tendered_amount' => 500]],
        ])->assertStatus(201);
        $baselineId = (int) DB::connection('tenant')->table('edge_operational_stock_baselines')->orderByDesc('id')->value('id');
        $this->assertEquals(0.25, $this->edgeOnHand($baselineId, $this->ghee), 'the sale consumed the ingredient on the baseline');
        $after = $this->payload()[$this->naan];
        $this->assertSame([$this->branchId => 10], $after['makeable_by_branch'], 'the preview reads the same balance the sale consumed');
        $this->assertSame([$this->branchId => 'Ghee'], $after['limiting_ingredient_by_branch']);
    }
}
