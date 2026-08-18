<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Models\Tenant\User;
use App\Services\Catering\CateringCostBlockService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-COST-BLOCKS-1 — the screen where a dish is built from parts.
 *
 * The service tests prove the arithmetic. This proves the editing behaviour,
 * which is where the quieter mistakes live:
 *
 *   - a removed part is deactivated, not deleted, because from Phase B a booking
 *     line records which blocks it was priced from
 *   - a charge block never keeps a material or a consumption ratio, however the
 *     form was filled in before its type was changed
 *   - a material block with no material is refused rather than saved and
 *     reported later
 *   - none of it posts anything or moves any stock
 */
class CateringCostBlockHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const PERM = 'tenant.catering.cost-blocks.update';

    private string $host;

    private string $uri;

    private int $ownerId;

    private int $cashierId;

    private int $karahiId;

    private int $chickenId;

    private int $kgUnitId;

    private CateringProductProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'cateringblocks.'.config('tenancy.tenant_base_domain');

        $this->seedMaster();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();

            $planId = $m->table('plans')->where('code', 'blocks-catering')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('subscriptions')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }

            $m->table('tenants')->where('tenant_code', 'cateringblocks')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function materialBlock(array $overrides = []): array
    {
        return array_merge([
            'label' => 'chicken',
            'block_type' => 'material',
            'charge_basis' => 'per_unit',
            'rate' => 200,
            'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 0.5,
            'unit_id' => $this->kgUnitId,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function chargeBlock(array $overrides = []): array
    {
        return array_merge([
            'label' => 'making',
            'block_type' => 'charge',
            'charge_basis' => 'per_unit',
            'rate' => 500,
        ], $overrides);
    }

    private function save(array $blocks, ?int $asUser = null)
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($asUser ?? $this->ownerId), 'tenant')
            ->put($this->uri, ['blocks' => $blocks]);
    }

    private function activeBlocks()
    {
        DB::setDefaultConnection('tenant');

        return CateringProductCostBlock::where('product_id', $this->karahiId)
            ->where('is_active', true)->orderBy('sort_order')->get();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reachability and gating.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_cost_block_routes_exist_and_are_gated(): void
    {
        foreach (['tenant.catering.cost-blocks.edit', self::PERM] as $name) {
            $this->assertTrue(Route::has($name), "route [{$name}] must be registered");
            $this->assertContains('route.permission', Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    public function test_a_user_without_the_permission_cannot_change_what_a_dish_is_made_of(): void
    {
        $res = $this->save([$this->materialBlock()], $this->cashierId);

        $res->assertStatus(403);
        $this->assertCount(0, $this->activeBlocks());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Saving.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Entered through the screen, which authors a material rate PER KILO OF
     * MATERIAL: chicken at 200 a kilo, half a kilo per kilo of dish, so it adds
     * 100 — plus 500 making, and the dish sells at 600.
     */
    public function test_saving_chicken_and_making_prices_the_dish_from_its_blocks(): void
    {
        $res = $this->save([$this->materialBlock(), $this->chargeBlock()]);

        $this->assertNotContains($res->getStatusCode(), [403, 404, 500]);
        $this->assertCount(2, $this->activeBlocks());

        $this->assertSame('per_material_unit', $this->activeBlocks()->firstWhere('label', 'chicken')->rateBasis(),
            'the editor authors new material blocks the way a caterer thinks');
        $this->assertSame(600.0, app(CateringCostBlockService::class)->rateFor($this->karahiId),
            '0.5 KG x 200 + 500 making');
    }

    /**
     * A charge block is money with nothing behind it. If the operator changed a
     * material block into a charge, the material and the ratio must not survive
     * as invisible passengers that a later kitchen sheet would act on.
     */
    public function test_a_charge_block_never_keeps_a_material_or_a_ratio(): void
    {
        $this->save([
            $this->chargeBlock([
                'material_product_id' => $this->chickenId,
                'quantity_per_unit' => 0.5,
                'unit_id' => $this->kgUnitId,
            ]),
        ]);

        $block = $this->activeBlocks()->firstWhere('label', 'making');

        $this->assertNotNull($block);
        $this->assertNull($block->material_product_id, 'a charge draws nothing from the store');
        $this->assertNull($block->quantity_per_unit);
        $this->assertNull($block->unit_id);
        $this->assertSame(0.0, $block->materialRequiredFor(100), 'so it can never ask the store for anything');
    }

    /** A material block with nothing selected is refused, not stored and reported. */
    public function test_a_material_block_without_a_material_is_refused(): void
    {
        $res = $this->save([$this->materialBlock(['material_product_id' => null])]);

        $res->assertSessionHasErrors('blocks');
        $this->assertCount(0, $this->activeBlocks(), 'and nothing is left behind by the refusal');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Editing an existing set.
    // ─────────────────────────────────────────────────────────────────────────

    /** Editing keeps the same rows rather than replacing them wholesale. */
    public function test_editing_a_block_keeps_its_identity(): void
    {
        $this->save([$this->materialBlock(), $this->chargeBlock()]);
        $originalIds = $this->activeBlocks()->pluck('id')->all();

        $this->save([
            $this->materialBlock(['id' => $originalIds[0], 'rate' => 250]),
            $this->chargeBlock(['id' => $originalIds[1]]),
        ]);

        $this->assertSame($originalIds, $this->activeBlocks()->pluck('id')->all(),
            'the same parts were edited, not torn down and rebuilt');
        $this->assertSame(625.0, app(CateringCostBlockService::class)->rateFor($this->karahiId),
            'chicken now 250 a kilo of material — 0.5 x 250 = 125, plus 500 making');
    }

    /**
     * Removal deactivates. From Phase B a booking line records which blocks it
     * was priced from, and a deleted row would leave those lines pointing at
     * nothing — a quotation nobody could explain afterwards.
     */
    public function test_removing_a_part_keeps_it_on_record_as_inactive(): void
    {
        $this->save([$this->materialBlock(), $this->chargeBlock()]);
        $chickenId = $this->activeBlocks()->firstWhere('label', 'chicken')->id;

        $this->save([$this->chargeBlock(['id' => $this->activeBlocks()->firstWhere('label', 'making')->id])]);

        $this->assertCount(1, $this->activeBlocks(), 'chicken no longer prices the dish');

        $removed = CateringProductCostBlock::find($chickenId);
        $this->assertNotNull($removed, 'but the row survives, so old quotations still make sense');
        $this->assertFalse((bool) $removed->is_active);

        $this->assertSame(500.0, app(CateringCostBlockService::class)->rateFor($this->karahiId),
            'and an inactive part adds nothing to the price');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Configuration is not a business event.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_configuring_a_dish_posts_nothing_and_moves_no_stock(): void
    {
        $before = $this->ledgerCounts();

        $this->save([$this->materialBlock(), $this->chargeBlock()]);
        $this->save([$this->materialBlock(['rate' => 900])]);

        $this->assertSame($before, $this->ledgerCounts(),
            'deciding what a dish costs is not the same as anything having happened');
    }

    /** The dish and its material keep their platform identity throughout. */
    public function test_the_shared_product_taxonomy_is_untouched(): void
    {
        $this->save([$this->materialBlock(), $this->chargeBlock()]);

        DB::setDefaultConnection('tenant');
        $this->assertSame('sale_item', Product::find($this->karahiId)->product_kind);
        $this->assertSame('raw_material', Product::find($this->chickenId)->product_kind);
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
        $master->table('tenants')->where('tenant_code', 'cateringblocks')->delete();

        $tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'cateringblocks', 'business_name' => 'Catering Blocks HTTP',
            'owner_name' => 'Owner', 'owner_email' => 'owner@cateringblocks.test',
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

        $plan = Plan::updateOrCreate(['code' => 'blocks-catering'], [
            'name' => 'Blocks Catering', 'price' => 0, 'is_active' => true,
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
            'the test plan must actually enable catering, or the authorization proof means nothing');
    }

    private function seedTenant(): void
    {
        // permissions/roles are NOT truncated: those rows come from a tenant
        // MIGRATION, so wiping them removes data no later test can restore.
        $this->cleanTenant([
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'recipe_ingredients', 'recipes',
            'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'model_has_roles', 'units', 'products', 'categories', 'users', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $role = function (string $name) use ($c) {
            $id = $c->table('roles')->where('name', $name)->where('guard_name', 'tenant')->value('id');

            return $id ?: $c->table('roles')->insertGetId([
                'name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $ownerRole = $role('Owner');
        $cashierRole = $role('Cashier');

        // The cashier must hold NEITHER route, or this proves nothing.
        foreach (['tenant.catering.cost-blocks.edit', self::PERM] as $perm) {
            $permId = $c->table('permissions')->where('name', $perm)->where('guard_name', 'tenant')->value('id');
            if (! $permId) {
                $permId = $c->table('permissions')->insertGetId([
                    'name' => $perm, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
            $c->table('role_has_permissions')
                ->where('permission_id', $permId)->where('role_id', $cashierRole)->delete();
        }

        $this->ownerId = $this->userWithRole($c, 'CBOWN', $ownerRole);
        $this->cashierId = $this->userWithRole($c, 'CBCASH', $cashierRole);

        $categoryId = $this->makeCategory();
        $this->kgUnitId = $c->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->karahiId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Karahi', 'sku' => 'CAT-KAR', 'unit_id' => $this->kgUnitId,
        ]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Raw Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        $this->profile = CateringProductProfile::updateOrCreate(
            ['product_id' => $this->karahiId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );

        $this->uri = 'http://'.$this->host.'/catering/profiles/'.$this->profile->id.'/blocks';

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWithRole($c, string $code, int $roleId): int
    {
        $uid = $c->table('users')->insertGetId([
            'name' => $code, 'email' => strtolower($code).'@cateringblocks.test',
            'password' => bcrypt('x'), 'employee_code' => $code, 'status' => 'active',
            'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $uid]);

        return $uid;
    }
}
