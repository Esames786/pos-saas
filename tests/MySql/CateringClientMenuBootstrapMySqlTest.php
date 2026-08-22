<?php

namespace Tests\MySql;

use App\Models\Tenant\Category;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringCostBlockService;
use Database\Seeders\Tenant\CateringInstructionVocabularySeeder;
use Database\Seeders\Tenant\KashifClientMenuSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CLIENT-MENU-1 — the real menu arrives; nothing dangerous arrives with it.
 *
 * 888 unique legacy items exactly once, reruns changing nothing; a reused
 * legacy code never overwriting a different item; unknown commercial data
 * never becoming quotable; the representative Cost-Block products priced at
 * the client's own 8701 evidence with every assumed split labelled; and the
 * legacy reference drafts reconciling to the legacy paper to the rupee —
 * 253,515 + 5,500 fare + 2,500 service = 261,515. No GL, no stock, ever.
 */
class CateringClientMenuBootstrapMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private KashifClientMenuSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_commercial_rate_applications',
            'catering_estimate_line_instruction', 'catering_instructions',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->makeBranch();
        (new CateringInstructionVocabularySeeder)->run();
        $this->seeder = new KashifClientMenuSeeder;
    }

    private function ledgers(): array
    {
        $db = DB::connection('tenant');

        return [$db->table('journal_lines')->count(), $db->table('stock_ledgers')->count()];
    }

    public function test_all_888_unique_items_arrive_exactly_once_and_reruns_add_nothing(): void
    {
        $before = $this->ledgers();

        $this->seeder->run();
        $this->assertSame(888, Product::where('sku', 'like', 'KM-%')->count(),
            'every reconciled unique legacy item, exactly once');

        $this->seeder->run();
        $this->assertSame(888, Product::where('sku', 'like', 'KM-%')->count(), 'rerun duplicates nothing');

        $this->assertSame($before, $this->ledgers(), 'a name list moves no money and no stock');

        // Every import is visible but honest about what it does not know.
        $shell = Product::where('sku', 'like', 'KM-%')->first();
        $this->assertNull($shell->unit_id, 'no honest unit exists, so none is faked');
        $this->assertFalse((bool) $shell->is_stock_tracked);
        $this->assertStringContainsString('NEEDS SETUP', $shell->description);
        $this->assertStringContainsString('Legacy Kashif catalogue', $shell->description);
    }

    public function test_a_reused_source_code_never_overwrites_a_different_item(): void
    {
        $this->seeder->run();

        // Find any legacy code the source reuses for two different names.
        $byCode = [];
        foreach (KashifClientMenuSeeder::catalogueRows() as $item) {
            if ($item['code'] !== '') {
                $byCode[$item['code']][$item['name']] = true;
            }
        }
        $reused = collect($byCode)->first(fn ($names) => count($names) > 1);
        $this->assertNotNull($reused, 'the source is known to reuse codes; the fixture should find one');

        foreach (array_keys($reused) as $name) {
            $this->assertTrue(
                Product::where('sku', 'like', 'KM-%')->where('name', $name)->exists(),
                "both items sharing a legacy code must survive: {$name}"
            );
        }

        // And the one source-level collision (two TRUNCATED-code rows, same
        // name) still lands as two rows rather than one silently swallowed.
        $this->assertSame(2, Product::where('sku', 'like', 'KM-%')->where('name', 'Malai Kofta Chicken')->count());
    }

    public function test_dirty_rows_park_in_needs_review_instead_of_confident_misfiling(): void
    {
        $this->seeder->run();

        $review = Category::where('name', KashifClientMenuSeeder::NEEDS_REVIEW)->firstOrFail();
        $this->assertGreaterThan(0, Product::where('category_id', $review->id)->count(),
            'ambiguous/misfiled source rows go to Needs Review, not into a guessed band');
    }

    public function test_unknown_commercial_data_is_never_quote_enabled(): void
    {
        $this->seeder->run();

        $this->assertSame(0, CateringProductProfile::where('catering_enabled', true)->count(),
            'the plain catalogue import enables NOTHING for quotation');

        $this->seeder->runRepresentatives();

        $enabled = CateringProductProfile::where('catering_enabled', true)->count();
        $this->assertSame(count(KashifClientMenuSeeder::REPRESENTATIVES), $enabled,
            'only the explicitly prepared representative set becomes quotable');
        $this->assertLessThanOrEqual(20, $enabled);

        // No quotation-ready zero-shell: every enabled dish either calculates a
        // real rate from its blocks or is a pure charge item with real charges.
        $blocks = app(CateringCostBlockService::class);
        foreach (CateringProductProfile::where('catering_enabled', true)->get() as $profile) {
            $hasLump = CateringProductCostBlock::where('product_id', $profile->product_id)
                ->where('charge_basis', CateringProductCostBlock::BASIS_LUMP_SUM)->exists();
            $this->assertTrue($blocks->rateFor($profile->product_id) > 0 || $hasLump,
                "quotation-ready product {$profile->product_id} must price itself");
        }
    }

    public function test_representatives_carry_the_evidenced_totals_with_labelled_assumptions(): void
    {
        $this->seeder->run();
        $this->seeder->runRepresentatives();

        $blocks = app(CateringCostBlockService::class);
        $rate = fn (string $name) => $blocks->rateFor(Product::where('name', $name)->orderBy('id')->value('id'));

        // The 8701 evidence, reproduced exactly by material charges + Making balance.
        $this->assertSame(3375.0, $rate('Biryani Masala Beef'));
        $this->assertSame(1405.0, $rate('Karahi Chicken'));
        $this->assertSame(300.0, $rate('Naan Milky'));
        $this->assertSame(510.0, $rate('Taftan'));
        $this->assertSame(20.0, $rate('Salad Green'));
        $this->assertSame(550.0, $rate('Raita'));
        $this->assertSame(1100.0, $rate('Lab-e-Shireen'));
        $this->assertSame(855.0, $rate('Bihari Chicken Tikka'));

        // Every assumed block says so, and Making is role-classified, not label-guessed.
        $biryaniId = Product::where('name', 'Biryani Masala Beef')->value('id');
        $making = CateringProductCostBlock::where('product_id', $biryaniId)
            ->where('charge_role', CateringProductCostBlock::ROLE_MAKING)->firstOrFail();
        $this->assertStringContainsString('owner to confirm', $making->label);
        $this->assertStringContainsString('legacy order 8701', $making->label);

        // Raw material sold directly: 1:1 wrapper, no Making at all.
        $muttonId = Product::where('name', 'Mutton')->where('product_kind', 'sale_item')->value('id');
        $muttonBlocks = CateringProductCostBlock::where('product_id', $muttonId)->get();
        $this->assertCount(1, $muttonBlocks);
        $this->assertSame(1.0, (float) $muttonBlocks->first()->quantity_per_unit);

        // Decoration is a lump sum — one price whatever the pax.
        $decorId = Product::where('name', 'Decoration and Arrangement')->value('id');
        $this->assertTrue(CateringProductCostBlock::where('product_id', $decorId)
            ->where('charge_basis', CateringProductCostBlock::BASIS_LUMP_SUM)->exists());
    }

    public function test_legacy_order_8701_reconciles_to_the_rupee(): void
    {
        $this->seeder->run();
        $this->seeder->runRepresentatives();
        $this->seeder->runLegacyOrders();

        $event = CateringEvent::where('notes', 'like', '%'.KashifClientMenuSeeder::LEGACY_8701_MARK.'%')->firstOrFail();
        $estimate = $event->currentEstimate;

        $this->assertSame(253515.0, (float) $estimate->subtotal, 'items exactly as the legacy paper');
        $this->assertSame(2500.0, (float) $estimate->service_charge_amount);
        $this->assertSame('Fare', $estimate->other_charge_label);
        $this->assertSame(5500.0, (float) $estimate->other_charge_amount);
        $this->assertSame(261515.0, (float) $estimate->grand_total, '253,515 + 5,500 + 2,500');
        $this->assertSame('draft', $estimate->status);

        $names = $estimate->lines->pluck('item_name')->all();
        foreach (['Biryani Masala Beef', 'Karahi Chicken', 'Naan Milky', 'Taftan', 'Salad Green', 'Raita', 'Lab-e-Shireen', 'Bihari Chicken Tikka'] as $n) {
            $this->assertContains($n, $names, "8701 must show the client's own item: {$n}");
        }

        // The familiar instructions ride along from the 55-label vocabulary.
        $withInstructions = $estimate->lines->filter(fn ($l) => $l->managedInstructions->isNotEmpty());
        $this->assertGreaterThanOrEqual(3, $withInstructions->count());

        // Rerun: still exactly one 8701 reference.
        $this->seeder->runLegacyOrders();
        $this->assertSame(1, CateringEvent::where('notes', 'like', '%'.KashifClientMenuSeeder::LEGACY_8701_MARK.'%')->count());
    }

    public function test_legacy_order_8704_maps_only_unambiguous_codes(): void
    {
        $this->seeder->run();
        $this->seeder->runLegacyOrders();

        $event = CateringEvent::where('notes', 'like', '%'.KashifClientMenuSeeder::LEGACY_8704_MARK.'%')->firstOrFail();
        $lines = $event->currentEstimate->lines;

        // All eleven screenshot codes resolve uniquely in the reconciled data.
        $this->assertCount(11, $lines);
        $this->assertContains('[66] Kunna Mutton', $lines->pluck('item_name')->all());
        $this->assertContains('[316] Agra Shahi', $lines->pluck('item_name')->all());
        $this->assertStringContainsString('LEFT AT ZERO deliberately', $event->notes,
            'unknown quantities/rates are named as unknown, never guessed');
        $this->assertSame(0.0, (float) $event->currentEstimate->grand_total);
        $this->assertSame('draft', $event->currentEstimate->status);
    }

    public function test_generic_uat_profiles_retire_from_new_quotes_but_history_survives(): void
    {
        $categoryId = $this->makeCategory();
        $old = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi (UAT)']);
        CateringProductProfile::create([
            'product_id' => $old, 'catering_enabled' => true,
            'pricing_mode' => 'fixed', 'costing_mode' => 'blocks',
        ]);

        $this->seeder->retireGenericUatProfiles();

        $this->assertFalse((bool) CateringProductProfile::where('product_id', $old)->value('catering_enabled'),
            'generic (UAT) dishes stop appearing for NEW quotations');
        $this->assertTrue(Product::whereKey($old)->exists(), 'nothing is deleted or renamed');
    }

    public function test_the_command_is_allowlisted_to_kashif_and_khatri_refuses(): void
    {
        $src = file_get_contents(app_path('Console/Commands/CateringBootstrapClientMenuCommand.php'));

        $this->assertStringContainsString("private const ALLOWED_TENANTS = ['kashifkitchen'];", $src);
        $this->assertStringContainsString('in_array($code, self::ALLOWED_TENANTS, true)', $src);
        $this->assertStringNotContainsString('khatribiryani', $src, 'Khatri appears nowhere near this command');
        $this->assertStringContainsString('SAFETY VIOLATION', $src, 'the command self-checks GL/stock after running');
    }

    public function test_market_estimates_price_the_food_bands_and_only_them(): void
    {
        $this->seeder->run();
        $this->seeder->runRepresentatives();
        $stats = $this->seeder->runMarketEstimates();

        $this->assertGreaterThan(300, $stats['priced'], 'the chicken/beef/mutton and staple bands all price');

        $blocks = app(CateringCostBlockService::class);
        $rate = fn (string $name) => $blocks->rateFor(Product::where('name', $name)->orderBy('id')->value('id'));

        // Pakistan-market band figures, exact via the Making balance.
        $this->assertSame(1400.0, $rate('Handi Chicken'), 'main-dish chicken band');
        $this->assertSame(750.0, $rate('Biryani Masala Beef Boneless'), 'biryani beef band');
        $this->assertSame(1700.0, $rate('Kunna Beef'), 'main-dish beef band');
        $this->assertSame(500.0, $rate('Burani Raita Tadka'), 'raita band');

        // Representatives keep their 8701 evidence — the market pass never
        // touches an already-quotable dish.
        $this->assertSame(1405.0, $rate('Karahi Chicken'));
        $this->assertSame(3375.0, $rate('Biryani Masala Beef'));

        // Every generated block confesses what it is, and Making is role-classified.
        $handiId = Product::where('name', 'Handi Chicken')->value('id');
        foreach (CateringProductCostBlock::where('product_id', $handiId)->get() as $b) {
            $this->assertStringContainsString('market estimate — owner to confirm', $b->label);
        }
        $this->assertTrue(CateringProductCostBlock::where('product_id', $handiId)
            ->where('charge_role', CateringProductCostBlock::ROLE_MAKING)->exists());

        // What has no honest template stays needs-setup: fish (no material),
        // platters (unknown composition), non-food service variants.
        foreach (['Biryani Masala Fish', 'Vip Waiters'] as $name) {
            $id = Product::where('name', $name)->value('id');
            if ($id) {
                $this->assertFalse((bool) \App\Models\Tenant\CateringProductProfile::where('product_id', $id)->value('catering_enabled'),
                    $name.' must stay needs-setup');
            }
        }

        // Rerun: same counts, nothing duplicated, evidence intact.
        $this->seeder->runMarketEstimates();
        $this->assertSame(1400.0, $rate('Handi Chicken'));
        $this->assertSame(1405.0, $rate('Karahi Chicken'));
    }

    public function test_the_whole_bootstrap_touches_neither_stock_nor_ledger(): void
    {
        $before = $this->ledgers();

        $this->seeder->run();
        $this->seeder->runRepresentatives();
        $this->seeder->runMarketEstimates();
        $this->seeder->retireGenericUatProfiles();
        $this->seeder->runLegacyOrders();

        $this->assertSame($before, $this->ledgers());
    }
}
