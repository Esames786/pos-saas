<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-COSTING-SOURCE-1 — a dish cannot be moved somewhere useless.
 *
 * Configuring blocks and switching to them are two separate acts, deliberately:
 * an operator prepares the blocks while the recipe is still deciding the cost,
 * and only then moves the authority across.
 *
 * What this file protects is the moment of the move. Saving "Cost Blocks" on a
 * dish with no blocks breaks nothing immediately — it simply makes the dish
 * unquotable, and the operator finds out at send time, by which point they have
 * forgotten what they changed. The refusal belongs at the decision, not three
 * screens later.
 *
 * The guard fires only when the source actually changes. Kashif has dishes whose
 * recipes were never complete; editing a station or a label on one of those must
 * not suddenly start failing because of history nobody is touching.
 */
class CateringCostingSwitchHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const PERM = 'tenant.catering.profiles.update';

    private string $host;

    private int $ownerId;

    private int $karahiId;

    private int $chickenId;

    private int $kgUnitId;

    private CateringProductProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'cateringswitch.'.config('tenancy.tenant_base_domain');

        $this->seedMaster();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();

            $planId = $m->table('plans')->where('code', 'switch-catering')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('subscriptions')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }

            $m->table('tenants')->where('tenant_code', 'cateringswitch')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /** Submit the profile form exactly as the screen does. */
    private function saveProfile(array $overrides = [])
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->put('http://'.$this->host.'/catering/profiles/'.$this->profile->id, array_merge([
                'catering_enabled' => 1,
                'pricing_mode' => 'fixed',
                'costing_mode' => 'recipe',
                'production_station' => 'Curry',
            ], $overrides));
    }

    private function storedMode(): string
    {
        DB::setDefaultConnection('tenant');

        return $this->profile->fresh()->costingMode();
    }

    /** A complete block set: chicken 200 (0.5 KG drawn) + making 500. */
    private function addCompleteBlocks(): void
    {
        CateringProductCostBlock::create([
            'product_id' => $this->karahiId, 'label' => 'chicken', 'block_type' => 'material',
            'material_product_id' => $this->chickenId, 'quantity_per_unit' => 0.5,
            'unit_id' => $this->kgUnitId, 'rate' => 200, 'charge_basis' => 'per_unit', 'sort_order' => 1,
        ]);
        CateringProductCostBlock::create([
            'product_id' => $this->karahiId, 'label' => 'making', 'block_type' => 'charge',
            'rate' => 500, 'charge_basis' => 'per_unit', 'sort_order' => 2,
        ]);
    }

    private function addCompleteRecipe(): void
    {
        $recipeId = DB::connection('tenant')->table('recipes')->insertGetId([
            'product_id' => $this->karahiId, 'name' => 'Karahi Deg', 'yield_quantity' => 10,
            'yield_unit_id' => $this->kgUnitId, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('recipe_ingredients')->insert([
            'recipe_id' => $recipeId, 'product_id' => $this->chickenId, 'quantity' => 5,
            'unit_id' => $this->kgUnitId, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rateChicken(): void
    {
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 320, 'unit_id' => $this->kgUnitId,
            'effective_from' => now()->subDay()->toDateString(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A / B. Recipe -> Blocks is refused until the blocks can cost the dish.
    // ─────────────────────────────────────────────────────────────────────────

    /** Nothing configured: the dish would become unquotable the moment it moved. */
    public function test_a_dish_cannot_move_to_cost_blocks_with_no_blocks(): void
    {
        $this->addCompleteRecipe();
        $this->rateChicken();

        $res = $this->saveProfile(['costing_mode' => 'blocks']);

        $res->assertSessionHasErrors('costing_mode');
        $this->assertSame('recipe', $this->storedMode(), 'the dish stays where it was working');
    }

    /** Blocks exist, but the material behind one has no rate to cost it at. */
    public function test_a_dish_cannot_move_to_cost_blocks_while_a_material_has_no_rate(): void
    {
        $this->addCompleteRecipe();
        $this->rateChicken();
        $this->addCompleteBlocks();
        CateringMaterialRate::where('product_id', $this->chickenId)->delete();

        $res = $this->saveProfile(['costing_mode' => 'blocks']);

        $res->assertSessionHasErrors('costing_mode');
        $this->assertStringContainsString('Material Rate Book',
            (string) session('errors')->first('costing_mode'),
            'and it says exactly what is missing rather than just refusing');
        $this->assertSame('recipe', $this->storedMode());
        $this->assertSame(2, CateringProductCostBlock::where('product_id', $this->karahiId)->count(),
            'the half-finished blocks are still there when the operator comes back');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C. And it succeeds once they can.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_dish_moves_to_cost_blocks_once_they_are_complete(): void
    {
        $this->addCompleteRecipe();
        $this->rateChicken();
        $this->addCompleteBlocks();

        $res = $this->saveProfile(['costing_mode' => 'blocks']);

        $res->assertSessionHasNoErrors();
        $this->assertSame('blocks', $this->storedMode());
        $this->assertSame(1, (int) DB::connection('tenant')->table('recipes')
            ->where('product_id', $this->karahiId)->count(),
            'and the recipe it came from is still stored, so the move is reversible');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D / E. The same rule in the other direction.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_dish_cannot_move_back_to_a_recipe_that_cannot_cost_it(): void
    {
        $this->rateChicken();
        $this->addCompleteBlocks();
        $this->profile->update(['costing_mode' => 'blocks']);

        // No recipe, and the dish itself has no rate to be costed directly from.
        $res = $this->saveProfile(['costing_mode' => 'recipe']);

        $res->assertSessionHasErrors('costing_mode');
        $this->assertSame('blocks', $this->storedMode());
        $this->assertSame(2, CateringProductCostBlock::where('product_id', $this->karahiId)->count(),
            'a refused switch destroys nothing');
    }

    public function test_a_dish_moves_back_to_recipe_once_the_recipe_can_cost_it(): void
    {
        $this->rateChicken();
        $this->addCompleteBlocks();
        $this->profile->update(['costing_mode' => 'blocks']);
        $this->addCompleteRecipe();

        $res = $this->saveProfile(['costing_mode' => 'recipe']);

        $res->assertSessionHasNoErrors();
        $this->assertSame('recipe', $this->storedMode());
        $this->assertSame(2, CateringProductCostBlock::where('product_id', $this->karahiId)->count(),
            'and the blocks are kept for whenever they are wanted again');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F. The guard is on the switch, not on the history.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Kashif has dishes whose recipes were never complete. Editing a station on
     * one of those must not start failing because of costing history nobody is
     * touching — that would make ordinary admin impossible.
     */
    public function test_editing_other_fields_still_works_on_a_dish_that_could_not_be_costed(): void
    {
        // No recipe, no rate, no blocks — unready by every measure.
        $res = $this->saveProfile(['costing_mode' => 'recipe', 'production_station' => 'BBQ']);

        $res->assertSessionHasNoErrors();
        DB::setDefaultConnection('tenant');
        $this->assertSame('BBQ', $this->profile->fresh()->production_station,
            'the guard exists to stop a move, not to freeze a dish nobody is moving');
    }

    /** Same when the active source is blocks and stays blocks. */
    public function test_editing_other_fields_still_works_while_blocks_stay_active(): void
    {
        $this->rateChicken();
        $this->addCompleteBlocks();
        $this->profile->update(['costing_mode' => 'blocks']);
        CateringProductCostBlock::where('product_id', $this->karahiId)->update(['is_active' => false]);

        $res = $this->saveProfile(['costing_mode' => 'blocks', 'production_station' => 'Tandoor']);

        $res->assertSessionHasNoErrors();
        DB::setDefaultConnection('tenant');
        $this->assertSame('Tandoor', $this->profile->fresh()->production_station);
        $this->assertSame('blocks', $this->storedMode());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // G / H. Nothing else moves.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_refused_switch_leaves_the_pricing_method_alone(): void
    {
        $this->profile->update(['pricing_mode' => 'per_pax']);

        $this->saveProfile(['costing_mode' => 'blocks', 'pricing_mode' => 'fixed']);

        DB::setDefaultConnection('tenant');
        $this->assertSame('per_pax', $this->profile->fresh()->pricing_mode,
            'a refusal refuses the whole save — it does not half-apply it');
    }

    public function test_a_successful_switch_leaves_the_pricing_method_alone(): void
    {
        $this->profile->update(['pricing_mode' => 'per_pax']);
        $this->rateChicken();
        $this->addCompleteBlocks();

        $this->saveProfile(['costing_mode' => 'blocks', 'pricing_mode' => 'per_pax']);

        DB::setDefaultConnection('tenant');
        $this->assertSame('per_pax', $this->profile->fresh()->pricing_mode);
        $this->assertSame('blocks', $this->storedMode());
    }

    public function test_no_switch_attempt_posts_anything_or_moves_stock(): void
    {
        $before = $this->ledgerCounts();

        $this->saveProfile(['costing_mode' => 'blocks']);   // refused
        $this->rateChicken();
        $this->addCompleteBlocks();
        $this->saveProfile(['costing_mode' => 'blocks']);   // accepted

        $this->assertSame($before, $this->ledgerCounts());
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        DB::setDefaultConnection('tenant');
        $c = DB::connection('tenant');

        return [
            'journal_entries' => (int) $c->table('journal_entries')->count(),
            'journal_lines' => (int) $c->table('journal_lines')->count(),
            'stock_ledgers' => (int) $c->table('stock_ledgers')->count(),
        ];
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenants')->where('tenant_code', 'cateringswitch')->delete();

        $tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'cateringswitch', 'business_name' => 'Catering Switch HTTP',
            'owner_name' => 'Owner', 'owner_email' => 'owner@cateringswitch.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_databases')->insert([
            'tenant_id' => $tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_domains')->insert([
            'tenant_id' => $tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = Plan::updateOrCreate(['code' => 'switch-catering'], [
            'name' => 'Switch Catering', 'price' => 0, 'is_active' => true,
        ]);
        PlanModule::where('plan_id', $plan->id)->delete();

        $module = Module::updateOrCreate(['key' => 'catering'], [
            'name' => 'Catering', 'category' => 'Operations',
            'route_module_keys' => ['tenant.catering'], 'is_core' => false, 'is_active' => true,
        ]);
        PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);

        Subscription::updateOrCreate(['tenant_id' => $tenantId], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);

        $this->assertContains('catering', $plan->fresh()->loadMissing('enabledModules')->enabledModules->pluck('key')->all(),
            'the test plan must actually enable catering, or these assertions mean nothing');
    }

    private function seedTenant(): void
    {
        // permissions/roles are NOT truncated: those rows come from a tenant
        // MIGRATION, so wiping them removes data no later test can restore.
        $this->cleanTenant([
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'recipe_ingredients', 'recipes',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'model_has_roles', 'units', 'products', 'categories', 'users', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        $permId = $c->table('permissions')->where('name', self::PERM)->where('guard_name', 'tenant')->value('id');
        if (! $permId) {
            $permId = $c->table('permissions')->insertGetId([
                'name' => self::PERM, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'SWOWN', 'email' => 'swown@cateringswitch.test',
            'password' => bcrypt('x'), 'employee_code' => 'SWOWN', 'status' => 'active',
            'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $categoryId = $this->makeCategory();
        $this->kgUnitId = $c->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->karahiId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Karahi', 'sku' => 'CAT-KAR', 'unit_id' => $this->kgUnitId,
            'default_purchase_price' => 0,
        ]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Raw Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'default_purchase_price' => 0,
        ]);

        $this->profile = CateringProductProfile::updateOrCreate(
            ['product_id' => $this->karahiId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'recipe']
        );

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
