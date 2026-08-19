<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CateringAdvance;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use App\Models\Tenant\CateringMaterialIssue;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\CateringRefund;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringCostBlockService;
use App\Support\TenantClock;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PDO;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-UAT-SEED-1 — the guarded UAT dataset builder.
 *
 * Two things are under test, and they matter for different reasons.
 *
 * The GUARDS matter because this writes a large amount of data into a named
 * tenant. Everything it refuses — a missing confirmation, an unlisted tenant,
 * the live trading tenant, a Branch Server, a tenant that already holds bookings
 * — is a way it could have been pointed at the wrong database.
 *
 * The DATASET matters because it is what an owner will learn the Cost Block
 * model from, and a dataset that looks complete while teaching the wrong thing
 * is worse than none. So the SEEDED ESTIMATE is asserted here, not only the
 * pricing service behind it: those are two different claims, and the estimate is
 * the one a person actually opens.
 */
class CateringSeedUatMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    /** The real production target, so the allowlist is proved on the tenant it exists for. */
    private const CODE = 'kashifkitchen';

    /** A second REAL tenant schema, to prove seeding one never touches another. */
    private const OTHER_DB = 'pos_test_tenant_cat_other';

    private static bool $otherSchemaReady = false;

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
            config(['app.role' => null]);

            $m = DB::connection('master');
            $m->table('tenant_databases')->whereIn('db_database', [$this->tenantDb, self::OTHER_DB])->delete();

            $planId = $m->table('plans')->where('code', 'uatseed-catering')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('subscriptions')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }

            $m->table('tenants')->whereIn('tenant_code', [self::CODE, 'khatribiryani', 'cateringuatother'])->delete();
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

    private function blocks(): CateringCostBlockService
    {
        return app(CateringCostBlockService::class);
    }

    private function businessDate(): string
    {
        DB::setDefaultConnection('tenant');

        return app(TenantClock::class)->currentBusinessDate(
            Branch::where('status', 'active')->orderBy('id')->first()
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guards — each one is a way this could have hit the wrong database.
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

    /**
     * Fail closed. "Anything except Khatri" would have let this loose on any
     * catering tenant ever added — including a client's — on a mistyped argument.
     * The tenant refused here is entitled, migrated and otherwise perfectly
     * valid; the only thing wrong with it is that nobody listed it.
     */
    public function test_it_refuses_a_tenant_that_is_not_on_the_allowlist(): void
    {
        $exit = Artisan::call('catering:seed-uat', [
            'tenant_code' => 'cateringuatother',
            '--yes' => true,
            '--confirm' => 'cateringuatother',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not a listed UAT tenant', Artisan::output());
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
     * A Branch Server holds one branch's data and syncs upward. Seeding fixtures
     * into one would push invented bookings at the Cloud as though they were real.
     */
    public function test_it_refuses_to_run_on_a_branch_server(): void
    {
        config(['app.role' => 'branch_server']);

        $exit = $this->runSeeder();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Branch Server', Artisan::output());
        $this->assertSame(0, $this->tenantTable('catering_events')->count(),
            'the refusal must come before any data is written');
    }

    /**
     * Running it twice must not double the bookings — nobody could say afterwards
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
    // Tenant boundary.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Seeding one tenant must be invisible to every other. Database-per-tenant
     * makes that likely; it does not make it certain, and a leak here would put
     * invented bookings into somebody's real business.
     */
    public function test_seeding_one_tenant_does_not_touch_another(): void
    {
        $this->ensureOtherSchema();

        $before = $this->onOtherTenant(fn () => $this->fingerprintTenant());

        $this->assertSame(0, $this->runSeeder());

        $after = $this->onOtherTenant(fn () => $this->fingerprintTenant());

        $this->assertSame($before, $after, 'the unrelated tenant must be identical afterwards');
        $this->assertSame(0, $after['catering_events'], 'and hold no seeded bookings at all');
        $this->assertSame(0, $after['products']);
        $this->assertSame(0, $after['stock_ledgers']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The dataset — asserted on what was actually written.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_it_builds_five_differently_shaped_cost_block_dishes(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $dishes = Product::where('sku', 'like', 'UAT-DISH-%')->get();
        $this->assertCount(5, $dishes, 'five scenarios, not one repeated five times');

        // A — the simplest: one material plus making.
        $karahi = $dishes->firstWhere('sku', 'UAT-DISH-KARAHI');
        $this->assertSame(300.0, $this->blocks()->rateFor($karahi->id), 'chicken 100/KG x 0.50 = 50, plus 250 making');

        // B — several materials in one dish, and the business's own worked
        //     example: chicken 100 x 0.50 = 50, rice 80 x 0.40 = 32, making 300.
        $biryani = $dishes->firstWhere('sku', 'UAT-DISH-BIRYANI');
        $this->assertSame(2, CateringProductCostBlock::where('product_id', $biryani->id)
            ->where('block_type', 'material')->count(), 'a dish may consume more than one material');
        $this->assertSame(382.0, $this->blocks()->rateFor($biryani->id),
            'the figure the business checks this dataset against');
        $this->assertSame(1910.0, $this->blocks()->priceLine($biryani->id, 5)['total'],
            '5 KG: 250 chicken + 160 rice + 1,500 making');

        // C — a lump sum that does not scale with the order.
        $counter = $dishes->firstWhere('sku', 'UAT-DISH-COUNTER');
        $this->assertSame(254.0, $this->blocks()->rateFor($counter->id),
            'the setup fee never enters the per-unit rate');

        // E — mostly service, proving a charge moves no stock at all.
        $platter = $dishes->firstWhere('sku', 'UAT-DISH-PLATTER');
        $line = $this->blocks()->priceLine($platter->id, 10);
        $this->assertGreaterThan(
            collect($line['blocks'])->where('type', 'material')->sum('amount') * 4,
            collect($line['blocks'])->where('type', 'charge')->sum('amount'),
            'most of this price is work, not goods'
        );
    }

    /**
     * THE ASSERTION THE SERVICE TEST CANNOT MAKE.
     *
     * priceLine() proving a lump sum is charged once says nothing about what
     * reached the estimate. A line is quantity x rate, and a lump sum cannot live
     * in a rate — multiplied by the order size it stops being a lump sum. Dropped
     * instead, the seeded quotation would have taught an owner that a 3,000
     * counter setup is free.
     */
    public function test_the_seeded_estimate_charges_the_lump_sum_once(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $counter = Product::where('sku', 'UAT-DISH-COUNTER')->firstOrFail();

        $line = CateringEstimateLine::where('product_id', $counter->id)->firstOrFail();
        $estimate = CateringEstimate::findOrFail($line->catering_estimate_id);

        // The RATE carries only the per-unit blocks — a flat fee in a per-kilo
        // rate would be wrong at every order size except one.
        $this->assertSame(254.0, round((float) $line->rate, 2));
        $this->assertSame(254.0, round((float) $line->calculated_rate, 2));

        // The 3,000 belongs to THIS LINE, once, and reaches the line's amount.
        $this->assertSame(3000.0, round((float) $line->lump_sum_amount, 2),
            'the setup fee is charged once for the booking, not per kilo');
        $this->assertSame(
            round((float) $line->quantity * 254 + 3000, 2),
            round((float) $line->amount, 2),
            'quantity x rate, plus the one-off charge'
        );

        // And the document's own other-charge field stays a separate, manual
        // concept — a line lump sum is never smuggled into it.
        $this->assertSame(0.0, round((float) $estimate->other_charge_amount, 2));
    }

    /** A booking with no lump-sum dish must not acquire a phantom charge. */
    public function test_an_estimate_without_a_lump_sum_dish_has_no_other_charge(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $counterId = Product::where('sku', 'UAT-DISH-COUNTER')->value('id');
        $withCounter = CateringEstimateLine::where('product_id', $counterId)->pluck('catering_estimate_id');

        $clean = CateringEstimate::whereNotIn('id', $withCounter)->first();

        $this->assertNotNull($clean, 'not every booking carries the lump-sum dish');
        $this->assertSame(0.0, round((float) $clean->other_charge_amount, 2));
    }

    /**
     * The teaching fixture. Charged 250, draws 0.40 KG, and 0.40 KG at 600 costs
     * 240 — three numbers, none equal to another.
     */
    public function test_the_teaching_dish_makes_all_three_numbers_different(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $handi = Product::where('sku', 'UAT-DISH-HANDI')->firstOrFail();

        $line = $this->blocks()->priceLine($handi->id, 10);
        $chicken = collect($line['blocks'])->firstWhere('label', 'Chicken');

        $this->assertSame(560.0, $chicken['amount'], 'charged for the 4 KG it needs, at 140 a kilo');
        $this->assertEqualsWithDelta(4.0, $chicken['required_qty'], 0.001, 'draws 4 KG');
        $this->assertEqualsWithDelta(320.0, $this->blocks()->expectedMaterialCost($handi->id, 10), 0.01,
            '4 KG at the rate book 80 costs 320 — not the 560 it was charged');
    }

    /** Every active UAT dish is costed from blocks. No recipe line anywhere. */
    public function test_every_seeded_dish_uses_cost_blocks_and_no_booking_line_uses_a_recipe(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        foreach (Product::where('sku', 'like', 'UAT-DISH-%')->pluck('id') as $id) {
            $profile = CateringProductProfile::where('product_id', $id)->first();
            $this->assertNotNull($profile);
            $this->assertSame('blocks', $profile->costingMode());
        }

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

    // ─────────────────────────────────────────────────────────────────────────
    // Dates — the tenant's, not the server's.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The twelve bookings exist to appear under the Store Issue modal's DEFAULT
     * date. A box running UTC rolls over hours before a kitchen in Karachi does,
     * so seeding against the server clock can quietly land them on yesterday and
     * leave the owner looking at an empty modal.
     */
    public function test_the_busy_day_lands_on_the_tenant_business_date(): void
    {
        $this->assertSame(0, $this->runSeeder());

        $businessDate = $this->businessDate();

        $onBusinessDate = CateringEvent::whereDate('event_date', $businessDate)->count();
        $this->assertGreaterThanOrEqual(12, $onBusinessDate,
            "the busy day must be the tenant's today ({$businessDate}), not the server's");

        $eligible = CateringEvent::whereDate('event_date', $businessDate)
            ->whereIn('status', CateringEvent::OPEN_STATUSES)->count();
        $this->assertSame($onBusinessDate, $eligible, 'and every one of them attachable to a store issue');
    }

    public function test_it_seeds_neighbouring_dates_so_the_filter_visibly_does_something(): void
    {
        $this->assertSame(0, $this->runSeeder());

        $this->assertGreaterThanOrEqual(3,
            CateringEvent::whereDate('event_date', '!=', $this->businessDate())->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The owner demo estimate — what a person will actually open.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_owner_demo_estimate_is_a_draft_priced_from_its_blocks(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $demo = CateringEvent::where('customer_name', 'like', 'UAT Owner Demo%')->firstOrFail();
        $estimate = $demo->currentEstimate;

        $this->assertNotNull($estimate);
        $this->assertTrue($estimate->isDraft(), 'a training fixture must stay editable');
        $this->assertCount(4, $estimate->lines, 'four different scenarios side by side');

        // Every line is block-costed, and quoted at what its blocks add up to.
        foreach ($estimate->lines as $line) {
            $profile = CateringProductProfile::where('product_id', $line->product_id)->firstOrFail();
            $this->assertSame('blocks', $profile->costingMode());
            $this->assertSame(
                $this->blocks()->rateFor($line->product_id),
                round((float) $line->rate, 2),
                "{$line->item_name} must be quoted at the rate its blocks add up to"
            );
        }

        $byName = $estimate->lines->keyBy('item_name');
        $this->assertSame(300.0, round((float) $byName['Chicken Karahi (UAT)']->rate, 2));

        // The demo carries the lump-sum dish, so the owner can see a one-off
        // charge sitting beside a per-kilo rate without scaling with it.
        $counterLine = $byName['Live Counter BBQ (UAT)'];
        $this->assertSame(254.0, round((float) $counterLine->rate, 2));
        $this->assertSame(3000.0, round((float) $counterLine->lump_sum_amount, 2),
            'charged once on its own line, whatever the order size');

        $this->assertSame(0, CateringAdvance::where('catering_event_id', $demo->id)->count());
        $this->assertSame(0, CateringFinalInvoice::where('catering_event_id', $demo->id)->count());
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

    public function test_opening_stock_exists_through_the_inventory_authority(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $chicken = Product::where('sku', 'UAT-RM-CHICKEN')->firstOrFail();

        $onHand = (float) DB::connection('tenant')->table('stock_balances')
            ->where('product_id', $chicken->id)->sum('quantity_on_hand');
        $this->assertSame(400.0, round($onHand, 3));

        $ledger = DB::connection('tenant')->table('stock_ledgers')
            ->where('product_id', $chicken->id)->where('direction', 'in')->first();
        $this->assertNotNull($ledger, 'stock must arrive through a posted movement, never a direct balance write');
        $this->assertSame('opening_stock', $ledger->movement_type);
    }

    public function test_materials_are_findable_by_name_and_by_code(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $this->assertNotNull(Product::where('name', 'like', '%Chicken%')
            ->where('product_kind', 'raw_material')->first());
        $this->assertNotNull(Product::where('sku', 'like', '%RM-RICE%')->first());

        $this->assertGreaterThanOrEqual(12, DB::connection('tenant')
            ->table('catering_material_rates')->count(),
            'every UAT material needs a rate or costing cannot resolve');
    }

    public function test_seeding_leaves_the_books_balanced(): void
    {
        $this->assertSame(0, $this->runSeeder());
        DB::setDefaultConnection('tenant');

        $row = DB::connection('tenant')->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
        $this->assertSame(round((float) $row->d, 2), round((float) $row->c, 2),
            'trial balance difference must be zero');

        $orphans = DB::connection('tenant')->table('journal_lines')
            ->leftJoin('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereNull('journal_entries.id')->count();
        $this->assertSame(0, $orphans);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures.
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private function fingerprintTenant(): array
    {
        $c = DB::connection('tenant');

        return [
            'products' => (int) $c->table('products')->count(),
            'categories' => (int) $c->table('categories')->count(),
            'catering_events' => (int) $c->table('catering_events')->count(),
            'catering_estimates' => (int) $c->table('catering_estimates')->count(),
            'catering_material_rates' => (int) $c->table('catering_material_rates')->count(),
            'catering_product_cost_blocks' => (int) $c->table('catering_product_cost_blocks')->count(),
            'stock_ledgers' => (int) $c->table('stock_ledgers')->count(),
            'stock_balances' => (int) $c->table('stock_balances')->count(),
            'journal_entries' => (int) $c->table('journal_entries')->count(),
        ];
    }

    /** Create + migrate the second REAL tenant schema once per process. */
    private function ensureOtherSchema(): void
    {
        if (self::$otherSchemaReady) {
            return;
        }
        if (stripos(self::OTHER_DB, 'test') === false) {
            throw new \RuntimeException('the second tenant database name must contain "test"');
        }

        $config = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.self::OTHER_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $mainDb = $config['database'];
        try {
            config(['database.connections.tenant.database' => self::OTHER_DB]);
            DB::purge('tenant');
            $code = Artisan::call('migrate:fresh', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);
            if ($code !== 0) {
                throw new \RuntimeException('second tenant migrations failed: '.Artisan::output());
            }
        } finally {
            config(['database.connections.tenant.database' => $mainDb]);
            DB::purge('tenant');
        }

        self::$otherSchemaReady = true;
    }

    private function onOtherTenant(callable $callback): mixed
    {
        $mainDb = config('database.connections.tenant.database');
        try {
            config(['database.connections.tenant.database' => self::OTHER_DB]);
            DB::purge('tenant');

            return $callback();
        } finally {
            config(['database.connections.tenant.database' => $mainDb]);
            DB::purge('tenant');
        }
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_databases')->whereIn('db_database', [$this->tenantDb, self::OTHER_DB])->delete();
        $master->table('tenants')->whereIn('tenant_code', [self::CODE, 'khatribiryani', 'cateringuatother'])->delete();

        $tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => self::CODE, 'business_name' => 'Catering UAT Seed Target',
            'owner_name' => 'Owner', 'owner_email' => 'owner@'.self::CODE.'.test',
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

        $plan = Plan::updateOrCreate(['code' => 'uatseed-catering'], [
            'name' => 'UAT Seed Catering', 'price' => 0, 'is_active' => true,
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

        // A stand-in for the live trading tenant, so the by-name refusal is proved
        // against a tenant that exists rather than a missing one.
        $master->table('tenants')->insert([
            'tenant_code' => 'khatribiryani', 'business_name' => 'Khatri Biryani (guard fixture)',
            'owner_name' => 'Owner', 'owner_email' => 'guard@khatri.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // An unrelated catering tenant with its own database — entitled, migrated
        // and valid in every way except that nobody listed it.
        $otherId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'cateringuatother', 'business_name' => 'Unrelated Catering Tenant',
            'owner_name' => 'Owner', 'owner_email' => 'other@cateringuatother.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $master->table('tenant_databases')->insert([
            'tenant_id' => $otherId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => self::OTHER_DB,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Subscription::updateOrCreate(['tenant_id' => $otherId], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
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
