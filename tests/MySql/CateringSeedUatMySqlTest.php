<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Tenant\CateringAdvance;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use App\Models\Tenant\CateringMaterialIssue;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\CateringRefund;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringCostBlockService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-UAT-SEED-1 — the guarded UAT dataset builder.
 *
 * Two things are under test and they matter for different reasons.
 *
 * The GUARDS matter because this command writes a large amount of data into a
 * named tenant. Everything it refuses — a missing confirmation, the live trading
 * tenant, a tenant that already holds bookings — is a way it could have been
 * pointed at the wrong database or run twice.
 *
 * The DATASET matters because it is what an owner will learn the Cost Block
 * model from. If every dish were the same shape, or a line resolved through a
 * recipe, or the demo estimate arrived already frozen, the dataset would teach
 * the wrong thing while looking complete.
 */
class CateringSeedUatMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const CODE = 'cateringuatseed';

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->seedMaster();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();

            $planId = $m->table('plans')->where('code', 'uatseed-catering')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('subscriptions')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }

            $m->table('tenants')->where('tenant_code', self::CODE)->delete();
            $m->table('tenants')->where('tenant_code', 'khatribiryani')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    private function runSeeder(array $options = []): int
    {
        return Artisan::call('catering:seed-uat', array_merge([
            'tenant_code' => self::CODE,
            '--yes' => true,
            '--confirm' => self::CODE,
        ], $options));
    }

    private function tenantTable(string $table)
    {
        DB::setDefaultConnection('tenant');

        return DB::connection('tenant')->table($table);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guards — every one of these is a way it could hit the wrong database.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_it_refuses_without_the_yes_flag(): void
    {
        $exit = Artisan::call('catering:seed-uat', [
            'tenant_code' => self::CODE, '--confirm' => self::CODE,
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, $this->tenantTable('catering_events')->count());
    }

    public function test_it_refuses_when_the_confirmation_does_not_match(): void
    {
        $exit = $this->runSeeder(['--confirm' => 'something-else']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('typed confirmation', Artisan::output());
        $this->assertSame(0, $this->tenantTable('catering_events')->count());
    }

    /** The one tenant that trades for real is refused by name, before anything else. */
    public function test_it_refuses_the_live_trading_tenant_by_name(): void
    {
        $exit = Artisan::call('catering:seed-uat', [
            'tenant_code' => 'khatribiryani',
            '--yes' => true,
            '--confirm' => 'khatribiryani',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('live trading tenant', Artisan::output());
    }

    public function test_it_refuses_an_unknown_tenant(): void
    {
        $exit = Artisan::call('catering:seed-uat', [
            'tenant_code' => 'no-such-tenant',
            '--yes' => true,
            '--confirm' => 'no-such-tenant',
        ]);

        $this->assertSame(1, $exit);
    }

    /**
     * Running it twice must not double the bookings. Nobody could say afterwards
     * which twelve were the real dataset.
     */
    public function test_a_second_run_refuses_rather_than_duplicating_the_dataset(): void
    {
        $this->assertSame(0, $this->runSeeder());
        $firstCount = $this->tenantTable('catering_events')->count();

        $exit = $this->runSeeder();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('still holds', Artisan::output());
        $this->assertSame($firstCount, $this->tenantTable('catering_events')->count(),
            'the refusal must leave the first dataset exactly as it was');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The dataset.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_it_builds_five_differently_shaped_cost_block_dishes(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $dishes = Product::where('sku', 'like', 'UAT-DISH-%')->get();
        $this->assertCount(5, $dishes, 'five scenarios, not one repeated five times');

        $blocks = $this->costBlockService();

        // A — the simplest: one material plus making.
        $karahi = $dishes->firstWhere('sku', 'UAT-DISH-KARAHI');
        $this->assertSame(700.0, $blocks->rateFor($karahi->id), '200 chicken + 500 making');

        // B — one dish drawing three separate materials.
        $biryani = $dishes->firstWhere('sku', 'UAT-DISH-BIRYANI');
        $materialBlocks = CateringProductCostBlock::where('product_id', $biryani->id)
            ->where('block_type', 'material')->count();
        $this->assertSame(3, $materialBlocks, 'a dish may consume several physical materials');

        // C — a lump sum that does not scale with the order.
        $counter = $dishes->firstWhere('sku', 'UAT-DISH-COUNTER');
        $ten = $blocks->priceLine($counter->id, 10);
        $hundred = $blocks->priceLine($counter->id, 100);
        $setupTen = collect($ten['blocks'])->firstWhere('label', 'Live counter setup')['amount'];
        $setupHundred = collect($hundred['blocks'])->firstWhere('label', 'Live counter setup')['amount'];
        $this->assertSame(3000.0, $setupTen);
        $this->assertSame(3000.0, $setupHundred, 'a lump sum is charged once, whatever the order size');
        $this->assertSame(550.0, $blocks->rateFor($counter->id), 'and never enters the per-unit rate');

        // E — mostly service, proving a charge moves no stock at all.
        $platter = $dishes->firstWhere('sku', 'UAT-DISH-PLATTER');
        $line = $blocks->priceLine($platter->id, 10);
        $chargeTotal = collect($line['blocks'])->where('type', 'charge')->sum('amount');
        $this->assertGreaterThan(
            collect($line['blocks'])->where('type', 'material')->sum('amount') * 4,
            $chargeTotal,
            'most of this price is work, not goods'
        );
    }

    /**
     * The teaching fixture. Charged 250, draws 0.40 KG, and 0.40 KG at 600 costs
     * 240 — three numbers, none equal to another. An operator who understands
     * this one dish understands the whole model.
     */
    public function test_the_teaching_dish_makes_all_three_numbers_different(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $handi = Product::where('sku', 'UAT-DISH-HANDI')->firstOrFail();
        $blocks = $this->costBlockService();

        $line = $blocks->priceLine($handi->id, 10);
        $chicken = collect($line['blocks'])->firstWhere('label', 'Chicken');

        $this->assertSame(2500.0, $chicken['amount'], 'charged 10 x 250');
        $this->assertEqualsWithDelta(4.0, $chicken['required_qty'], 0.001, 'draws 4 KG');
        $this->assertEqualsWithDelta(2400.0, $blocks->expectedMaterialCost($handi->id, 10), 0.01,
            '4 KG at the rate book 600 costs 2,400 — not the 2,500 it was charged');
    }

    /** Every active UAT dish is costed from blocks. No recipe line anywhere. */
    public function test_every_seeded_dish_uses_cost_blocks_and_no_booking_line_uses_a_recipe(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $dishIds = Product::where('sku', 'like', 'UAT-DISH-%')->pluck('id');

        foreach ($dishIds as $id) {
            $profile = CateringProductProfile::where('product_id', $id)->first();
            $this->assertNotNull($profile);
            $this->assertSame('blocks', $profile->costingMode());
        }

        // Every line on every seeded booking resolves to a block-costed product.
        $lineProductIds = CateringEstimateLine::whereNotNull('product_id')->pluck('product_id')->unique();
        $this->assertNotEmpty($lineProductIds);

        $recipeLines = 0;
        foreach ($lineProductIds as $productId) {
            $profile = CateringProductProfile::where('product_id', $productId)->first();
            if (! $profile || ! $profile->usesBlocks()) {
                $recipeLines++;
            }
        }

        $this->assertSame(0, $recipeLines, 'the human UAT dataset is Cost-Block only');
    }

    /** Twelve on one day is the shape the store screen was built for. */
    public function test_it_seeds_a_busy_day_plus_neighbouring_dates(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $today = CateringEvent::whereDate('event_date', now()->toDateString())->count();
        $this->assertGreaterThanOrEqual(12, $today, 'a full night of work to select from');

        $other = CateringEvent::whereDate('event_date', '!=', now()->toDateString())->count();
        $this->assertGreaterThanOrEqual(2, $other, 'and other days, so the date filter visibly does something');

        // Eligible for a store issue, or the modal shows nothing.
        $eligible = CateringEvent::whereDate('event_date', now()->toDateString())
            ->whereIn('status', CateringEvent::OPEN_STATUSES)->count();
        $this->assertSame($today, $eligible, 'every seeded booking must be attachable to a store issue');
    }

    /** The demo booking is left open, so nothing about it is frozen. */
    public function test_the_owner_demo_estimate_is_a_draft_with_four_different_shapes(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $demo = CateringEvent::where('customer_name', 'like', 'UAT Owner Demo%')->firstOrFail();
        $estimate = $demo->currentEstimate;

        $this->assertNotNull($estimate);
        $this->assertTrue($estimate->isDraft(), 'a training fixture must stay editable');
        $this->assertCount(4, $estimate->lines, 'four different scenarios side by side');
        $this->assertGreaterThan(0, (float) $estimate->grand_total);
    }

    /** A clean baseline: no finance noise to wade through. */
    public function test_it_creates_no_advances_refunds_or_invoices(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $this->assertSame(0, CateringAdvance::count());
        $this->assertSame(0, CateringRefund::count());
        $this->assertSame(0, CateringFinalInvoice::count());
        $this->assertSame(0, CateringMaterialIssue::count(),
            'the owner performs the first store issue by hand — that is the point of the dataset');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Stock and integrity.
    // ─────────────────────────────────────────────────────────────────────────

    /** Enough on the shelf to issue several times before hitting an empty one. */
    public function test_opening_stock_exists_through_the_inventory_authority(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $chicken = Product::where('sku', 'UAT-RM-CHICKEN')->firstOrFail();

        $onHand = (float) DB::connection('tenant')->table('stock_balances')
            ->where('product_id', $chicken->id)->sum('quantity_on_hand');
        $this->assertSame(400.0, round($onHand, 3));

        // Through InventoryService, so a real ledger movement exists behind it.
        $ledger = DB::connection('tenant')->table('stock_ledgers')
            ->where('product_id', $chicken->id)->where('direction', 'in')->first();
        $this->assertNotNull($ledger, 'stock must arrive through a posted movement, never a direct balance write');
        $this->assertSame('opening_stock', $ledger->movement_type);
    }

    /** Materials are searchable by the things a storeman would actually type. */
    public function test_materials_are_findable_by_name_and_by_code(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $this->assertNotNull(Product::where('name', 'like', '%Chicken%')
            ->where('product_kind', 'raw_material')->first());
        $this->assertNotNull(Product::where('sku', 'like', '%RM-RICE%')->first());

        $rated = DB::connection('tenant')->table('catering_material_rates')->count();
        $this->assertGreaterThanOrEqual(12, $rated, 'every UAT material needs a rate or costing cannot resolve');
    }

    /** Seeding is not a business event: the books stay where they were. */
    public function test_seeding_leaves_the_books_balanced(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $row = DB::connection('tenant')->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        $this->assertSame(round((float) $row->d, 2), round((float) $row->c, 2), 'trial balance difference must be zero');

        $orphans = DB::connection('tenant')->table('journal_lines')
            ->leftJoin('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereNull('journal_entries.id')->count();
        $this->assertSame(0, $orphans);
    }

    private function costBlockService(): CateringCostBlockService
    {
        return app(CateringCostBlockService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenants')->where('tenant_code', self::CODE)->delete();
        $master->table('tenants')->where('tenant_code', 'khatribiryani')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => self::CODE, 'business_name' => 'Catering UAT Seed',
            'owner_name' => 'Owner', 'owner_email' => 'owner@'.self::CODE.'.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = Plan::updateOrCreate(['code' => 'uatseed-catering'], [
            'name' => 'UAT Seed Catering', 'price' => 0, 'is_active' => true,
        ]);
        PlanModule::where('plan_id', $plan->id)->delete();

        $module = Module::updateOrCreate(['key' => 'catering'], [
            'name' => 'Catering', 'category' => 'Operations',
            'route_module_keys' => ['tenant.catering'], 'is_core' => false, 'is_active' => true,
        ]);
        PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);

        Subscription::updateOrCreate(['tenant_id' => $this->tenantId], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);

        // A stand-in for the live trading tenant, so the by-name refusal is proved
        // against a tenant that actually exists rather than a missing one.
        $master->table('tenants')->insert([
            'tenant_code' => 'khatribiryani', 'business_name' => 'Khatri Biryani (guard fixture)',
            'owner_name' => 'Owner', 'owner_email' => 'guard@khatri.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenant(): void
    {
        $this->cleanTenant([
            'catering_material_issue_events', 'catering_material_issue_lines', 'catering_material_issues',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_refunds', 'catering_final_invoices', 'catering_advances',
            'catering_cost_snapshots', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'accounts', 'units', 'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        (new DefaultChartOfAccountsSeeder)->run();
        $this->makeBranch();

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
    }
}
