<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\User;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLineCostBlockService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-COMMERCIAL-RATE-1 — the operator's half of the house rate book.
 *
 * Without this screen the feature is present and unusable. The migration makes
 * every existing block manual, which is the only safe default — but it also
 * means that on the day this deploys, NOTHING is eligible for a house rate
 * change. Somebody has to be able to say "this dish follows the house rate", and
 * that saying has to be deliberate, checked, and reversible.
 *
 * What is proved here:
 *
 *   - an existing block stays manual until an operator says otherwise
 *   - linking adopts the house rate as the block's applied rate
 *   - a quotation already priced does not move when a dish is linked
 *   - the next quotation does
 *   - unlinking is equally explicit
 *   - and four combinations are refused SERVER-SIDE, not merely hidden: a legacy
 *     per-dish rate, a charge block, a material with no house rate, and a unit
 *     the book does not speak
 *
 * Plus the defect this screen was carrying before the source control existed:
 * the form never submitted rate_basis at all, so the controller's "default to
 * per_material_unit when absent" silently reinterpreted every legacy per-dish
 * block the moment anyone re-saved the dish for any reason.
 */
class CateringCostBlockSourceHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;

    private string $uri;

    private int $ownerId;

    private int $branchId;

    private int $karahiId;

    private int $chickenId;

    private int $riceId;

    private int $kgUnitId;

    private int $gmUnitId;

    private CateringProductProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        Mail::fake();

        $this->host = 'cateringsource.'.config('tenancy.tenant_base_domain');

        $this->seedMaster();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();

            $planId = $m->table('plans')->where('code', 'source-catering')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('subscriptions')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }

            $m->table('tenants')->where('tenant_code', 'cateringsource')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Form helpers.
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function materialBlock(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Chicken',
            'block_type' => 'material',
            'charge_basis' => 'per_unit',
            'rate_basis' => 'per_material_unit',
            'commercial_rate_source' => 'manual',
            'rate' => 100,
            'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 0.5,
            'unit_id' => $this->kgUnitId,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function chargeBlock(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Making',
            'block_type' => 'charge',
            'charge_basis' => 'per_unit',
            'rate' => 300,
        ], $overrides);
    }

    private function save(array $blocks)
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->put($this->uri, ['blocks' => $blocks]);
    }

    private function blocks()
    {
        DB::setDefaultConnection('tenant');

        return CateringProductCostBlock::where('product_id', $this->karahiId)
            ->where('is_active', true)->orderBy('sort_order')->get();
    }

    private function chicken(): ?CateringProductCostBlock
    {
        return $this->blocks()->firstWhere('label', 'Chicken');
    }

    private function houseRate(float $rate, ?int $unitId = null, ?string $date = null): CateringMaterialCommercialRate
    {
        DB::setDefaultConnection('tenant');

        return CateringMaterialCommercialRate::create([
            'product_id' => $this->chickenId,
            'rate' => $rate,
            'unit_id' => $unitId ?? $this->kgUnitId,
            'effective_from' => $date ?? now()->subDay()->toDateString(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The safe default, and the defect underneath it.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nothing becomes eligible for a global rate change by the migration running.
     * That property is what makes deploying this to a live tenant safe.
     */
    public function test_a_block_authored_before_the_book_existed_is_manual(): void
    {
        $legacy = CateringProductCostBlock::create([
            'product_id' => $this->karahiId, 'label' => 'Chicken',
            'block_type' => 'material', 'charge_basis' => 'per_unit',
            'rate_basis' => 'per_material_unit', 'rate' => 100,
            'material_product_id' => $this->chickenId, 'quantity_per_unit' => 0.5,
            'unit_id' => $this->kgUnitId, 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->assertSame(CateringProductCostBlock::SOURCE_MANUAL, $legacy->rateSource());
        $this->assertFalse($legacy->followsCommercialBook(),
            'a house rate change must not be offered to it until somebody links it');
    }

    /**
     * THE SILENT REPRICE. The form carried no rate_basis field at all, so the
     * controller's "default to per_material_unit when absent" applied to every
     * save — including a save that only renamed a block. A legacy dish priced at
     * 200 per KG OF DISH would come back priced at 200 per KG OF CHICKEN, the
     * same stored number meaning a different price, with nothing on screen to
     * say it had happened.
     */
    public function test_saving_a_legacy_dish_does_not_reinterpret_what_its_rate_means(): void
    {
        $legacy = CateringProductCostBlock::create([
            'product_id' => $this->karahiId, 'label' => 'Chicken',
            'block_type' => 'material', 'charge_basis' => 'per_unit',
            'rate_basis' => CateringProductCostBlock::RATE_PER_DISH_UNIT, 'rate' => 200,
            'material_product_id' => $this->chickenId, 'quantity_per_unit' => 0.5,
            'unit_id' => $this->kgUnitId, 'sort_order' => 1, 'is_active' => true,
        ]);

        // A save that says nothing about the basis — a rename, exactly as an old
        // form would have posted it.
        $this->save([[
            'id' => $legacy->id,
            'label' => 'Chicken (renamed)',
            'block_type' => 'material',
            'charge_basis' => 'per_unit',
            'rate' => 200,
            'material_product_id' => $this->chickenId,
            'quantity_per_unit' => 0.5,
            'unit_id' => $this->kgUnitId,
        ]]);

        $this->assertSame(CateringProductCostBlock::RATE_PER_DISH_UNIT,
            $this->blocks()->firstWhere('label', 'Chicken (renamed)')->rateBasis(),
            'a legacy rate keeps the meaning it was authored with unless the operator changes it deliberately');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Linking, and what it does and does not move.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_operator_can_point_a_block_at_the_house_rate_book(): void
    {
        $this->houseRate(120);
        $this->save([
            $this->materialBlock(['commercial_rate_source' => 'commercial_book']),
            $this->chargeBlock(),
        ]);

        $chicken = $this->chicken();
        $this->assertSame(CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK, $chicken->rateSource(),
            'this is the switch that makes the whole rate book operational');
        $this->assertTrue($chicken->followsCommercialBook());
    }

    /** Linking ADOPTS the current house rate, rather than merely remembering to. */
    public function test_linking_takes_the_current_house_rate_as_the_applied_rate(): void
    {
        $this->houseRate(120);

        // Submitted at 100 — the rate the block had. Linking overrides it.
        $this->save([$this->materialBlock(['rate' => 100, 'commercial_rate_source' => 'commercial_book'])]);

        $this->assertEqualsWithDelta(120.0, (float) $this->chicken()->rate, 0.01,
            'a block that follows the house rate starts at the house rate');
    }

    /** And it is recorded, with who did it. */
    public function test_linking_is_recorded_against_the_operator_who_did_it(): void
    {
        $this->houseRate(120);
        $this->save([$this->materialBlock(['commercial_rate_source' => 'commercial_book'])]);

        DB::setDefaultConnection('tenant');
        $entry = CateringCommercialRateApplication::where('action', CateringCommercialRateApplication::ACTION_BLOCK_LINKED)
            ->latest('id')->first();

        $this->assertNotNull($entry, 'pointing a dish at the house rate is a commercial decision and is recorded');
        $this->assertSame($this->ownerId, (int) $entry->performed_by_user_id);
        $this->assertSame($this->chickenId, (int) $entry->material_product_id);
        $this->assertEqualsWithDelta(120.0, (float) $entry->new_commercial_rate, 0.01);
    }

    /**
     * A quotation already priced does NOT move because a dish was linked. The
     * snapshot is the authority for what a customer was told.
     */
    public function test_linking_a_dish_leaves_quotations_already_priced_alone(): void
    {
        $this->save([$this->materialBlock(['rate' => 100]), $this->chargeBlock()]);
        $estimate = $this->quote(10);

        $before = app(CateringLineCostBlockService::class)
            ->snapshotsFor($estimate->refresh()->lines->first())->firstWhere('label', 'Chicken');
        $this->assertEqualsWithDelta(100.0, (float) $before->rate, 0.01);

        $this->houseRate(160);
        $this->save([
            $this->materialBlock(['id' => $this->chicken()->id, 'commercial_rate_source' => 'commercial_book']),
            $this->chargeBlock(['id' => $this->blocks()->firstWhere('label', 'Making')->id]),
        ]);

        $after = app(CateringLineCostBlockService::class)
            ->snapshotsFor($estimate->refresh()->lines->first())->firstWhere('label', 'Chicken');

        $this->assertEqualsWithDelta(100.0, (float) $after->rate, 0.01,
            'the quotation keeps the rate it was quoted at');
        $this->assertEqualsWithDelta(350.0, (float) $estimate->refresh()->lines->first()->calculated_rate, 0.01,
            '0.5 x 100 + 300 making, exactly as it was');
    }

    /** The NEXT quotation is the one that takes the new rate. */
    public function test_the_next_quotation_is_priced_at_the_newly_linked_rate(): void
    {
        $this->houseRate(160);
        $this->save([
            $this->materialBlock(['commercial_rate_source' => 'commercial_book']),
            $this->chargeBlock(),
        ]);

        $estimate = $this->quote(10);
        $line = $estimate->refresh()->lines->first();

        $this->assertEqualsWithDelta(380.0, (float) $line->calculated_rate, 0.01,
            '0.5 KG of chicken at the house 160 = 80, plus 300 making');
        $this->assertSame(CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK,
            app(CateringLineCostBlockService::class)->snapshotsFor($line)->firstWhere('label', 'Chicken')->rateSource(),
            'and the quotation remembers that this price followed the house rate');
    }

    public function test_an_operator_can_take_a_block_back_off_the_house_rate(): void
    {
        $this->houseRate(120);
        $this->save([$this->materialBlock(['commercial_rate_source' => 'commercial_book'])]);

        $this->save([$this->materialBlock([
            'id' => $this->chicken()->id,
            'commercial_rate_source' => 'manual',
            'rate' => 145,
        ])]);

        $chicken = $this->chicken();
        $this->assertSame(CateringProductCostBlock::SOURCE_MANUAL, $chicken->rateSource());
        $this->assertEqualsWithDelta(145.0, (float) $chicken->rate, 0.01,
            'going back to a hand-set rate keeps the rate the operator typed');
        $this->assertFalse($chicken->followsCommercialBook());

        DB::setDefaultConnection('tenant');
        $this->assertTrue(
            CateringCommercialRateApplication::where('action', CateringCommercialRateApplication::ACTION_BLOCK_UNLINKED)->exists(),
            'coming off the house rate is as much a decision as going on it'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // What the server refuses. Hiding a control is not a guard.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_legacy_per_dish_rate_cannot_follow_the_house_book(): void
    {
        $this->houseRate(120);

        $res = $this->save([$this->materialBlock([
            'rate_basis' => 'per_dish_unit',
            'commercial_rate_source' => 'commercial_book',
        ])]);

        $res->assertSessionHasErrors('blocks');
        $this->assertCount(0, $this->blocks(),
            'rupees per kilo of DISH and rupees per kilo of CHICKEN are not the same measurement');
    }

    public function test_a_charge_block_cannot_follow_the_house_book(): void
    {
        $this->houseRate(120);

        $res = $this->save([$this->chargeBlock(['commercial_rate_source' => 'commercial_book'])]);

        $res->assertSessionHasErrors('blocks');
        $this->assertCount(0, $this->blocks(), 'there is no house rate for making');
    }

    public function test_a_material_with_no_house_rate_cannot_be_linked_to_one(): void
    {
        $res = $this->save([$this->materialBlock(['commercial_rate_source' => 'commercial_book'])]);

        $res->assertSessionHasErrors('blocks');
        $this->assertCount(0, $this->blocks(),
            'following a rate that does not exist would leave the block following nothing');
    }

    /**
     * THE DIMENSIONAL GUARD. 120 per KG offered to a block measured in GM would
     * arithmetically produce 500 x 120 for half a kilo of chicken — a number that
     * reads as plausible and is wrong by a factor of a thousand.
     */
    public function test_a_block_measured_in_another_unit_cannot_follow_the_house_rate(): void
    {
        $this->houseRate(120, $this->kgUnitId);

        $res = $this->save([$this->materialBlock([
            'unit_id' => $this->gmUnitId,
            'quantity_per_unit' => 500,
            'commercial_rate_source' => 'commercial_book',
        ])]);

        $res->assertSessionHasErrors('blocks');
        $this->assertStringContainsString('Unit mismatch', session('errors')->first('blocks'));
        $this->assertCount(0, $this->blocks());
    }

    /** A block with no unit at all is the same refusal: "unknown" is not "compatible". */
    public function test_a_block_with_no_unit_cannot_follow_the_house_rate(): void
    {
        $this->houseRate(120);

        $res = $this->save([$this->materialBlock([
            'unit_id' => null,
            'commercial_rate_source' => 'commercial_book',
        ])]);

        $res->assertSessionHasErrors('blocks');
        $this->assertCount(0, $this->blocks());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures.
    // ─────────────────────────────────────────────────────────────────────────

    private function quote(float $qty): CateringEstimate
    {
        DB::setDefaultConnection('tenant');
        $estimates = app(CateringEstimateService::class);

        $event = $estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Source Test',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(7)->toDateString(),
            'pax' => 100,
        ]);

        return $estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->karahiId, 'item_name' => 'Chicken Karahi',
            'quantity' => $qty, 'unit_id' => $this->kgUnitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenants')->where('tenant_code', 'cateringsource')->delete();

        $tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'cateringsource', 'business_name' => 'Catering Source HTTP',
            'owner_name' => 'Owner', 'owner_email' => 'owner@cateringsource.test',
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

        $plan = Plan::updateOrCreate(['code' => 'source-catering'], [
            'name' => 'Source Catering', 'price' => 0, 'is_active' => true,
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
    }

    private function seedTenant(): void
    {
        $this->cleanTenant([
            'catering_commercial_rate_applications',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'recipe_ingredients', 'recipes',
            'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'model_has_roles', 'units', 'products', 'categories', 'users', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        foreach (['tenant.catering.cost-blocks.edit', 'tenant.catering.cost-blocks.update'] as $perm) {
            $permId = $c->table('permissions')->where('name', $perm)->where('guard_name', 'tenant')->value('id');
            if (! $permId) {
                $permId = $c->table('permissions')->insertGetId([
                    'name' => $perm, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'CSOWN', 'email' => 'csown@cateringsource.test',
            'password' => bcrypt('x'), 'employee_code' => 'CSOWN', 'status' => 'active',
            'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();

        $this->kgUnitId = $c->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->gmUnitId = $c->table('units')->insertGetId([
            'code' => 'GM', 'name' => 'Gram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->karahiId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Karahi', 'sku' => 'CAT-KAR', 'unit_id' => $this->kgUnitId,
        ]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Raw Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->kgUnitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        $this->riceId = $this->makeProduct($categoryId, [
            'name' => 'Rice', 'sku' => 'RM-RICE', 'unit_id' => $this->kgUnitId,
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
}
