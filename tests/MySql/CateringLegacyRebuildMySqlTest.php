<?php

namespace Tests\MySql;

use App\Models\Tenant\Category;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Product;
use App\Services\Catering\CateringCostBlockService;
use Database\Seeders\Tenant\KashifLegacyRebuildSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-LEGACY-REBUILD-1 — the client's own book, imported without invention.
 *
 * The properties worth protecting: the legacy id IS the SKU and cannot repeat;
 * a dish prices to the rupee its own book states (OrderRate = MeatRate +
 * ServiceRate); a named meat becomes a real material at the house's purchase
 * rate; an unnamed one is carried as a charge that SAYS it is unnamed rather
 * than inventing a material; the per-item switches come from the book; and a
 * catalogue rebuild never moves money or stock.
 */
class CateringLegacyRebuildMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private KashifLegacyRebuildSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_event_revisions',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_estimate_line_instruction', 'catering_instructions',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances',
            'product_barcodes', 'product_variants', 'products',
            'units', 'categories', 'customers', 'branches',
        ]);

        $this->makeBranch();
        $this->seeder = new KashifLegacyRebuildSeeder;
    }

    private function ledgers(): array
    {
        $db = DB::connection('tenant');

        return [$db->table('journal_lines')->count(), $db->table('stock_ledgers')->count()];
    }

    public function test_the_rebuild_carries_the_clients_own_ids_rates_and_switches(): void
    {
        $before = $this->ledgers();

        $stats = $this->seeder->run();

        // Every legacy item, once, keyed on the id the operator knows.
        $this->assertSame(909, $stats['products']);
        $this->assertSame(909, Product::where('product_kind', 'sale_item')->count());
        $this->assertSame(
            909,
            Product::where('product_kind', 'sale_item')->distinct()->count('sku'),
            'the legacy id IS the SKU — a repeat must be impossible'
        );

        // The client's own eighteen categories, in their own order.
        $this->assertSame(18, $stats['categories']);
        $this->assertSame('RICE', Category::where('sort_order', 2)->value('name'));

        // Item 361 to the rupee: the legacy screen shows Order 2650 =
        // Meat 1450 + Making 1200, and so does the rebuild.
        $blocks = app(CateringCostBlockService::class);
        $beefBiryani = Product::where('sku', '361')->firstOrFail();
        $this->assertSame(2650.0, $blocks->rateFor($beefBiryani->id));
        $this->assertSame('RICE', Category::find($beefBiryani->category_id)->name);

        $material = CateringProductCostBlock::where('product_id', $beefBiryani->id)
            ->where('block_type', CateringProductCostBlock::TYPE_MATERIAL)->firstOrFail();
        $this->assertSame(1450.0, (float) $material->rate);
        $this->assertNotNull($material->material_product_id, 'a named meat becomes a real material');

        $making = CateringProductCostBlock::where('product_id', $beefBiryani->id)
            ->where('charge_role', CateringProductCostBlock::ROLE_MAKING)->firstOrFail();
        $this->assertSame(1200.0, (float) $making->rate);

        // The material carries the HOUSE's own purchase rate, so "Costs us"
        // is a real number rather than a guess.
        $this->assertSame(1450.0, (float) DB::connection('tenant')->table('catering_material_rates')
            ->where('product_id', $material->material_product_id)->value('rate'));

        // Where the book names no meat, the money is still exact and the
        // screen says the material is unknown instead of inventing one.
        $this->assertGreaterThan(0, $stats['charge_only']);
        $unnamed = CateringProductCostBlock::where('label', 'like', 'Material — not named%')->first();
        $this->assertNotNull($unnamed);
        $this->assertNull($unnamed->material_product_id);

        // The per-item switches are the book's, not ours.
        $this->assertSame(445, $stats['party_on']);
        $this->assertSame(15, $stats['complimentary']);
        $this->assertSame(15, CateringProductProfile::where('is_complimentary', true)->count());

        // Items the book prices at nothing stay visibly needs-setup.
        $this->assertSame(105, $stats['needs_setup']);
        $this->assertSame(804, CateringProductProfile::where('catering_enabled', true)->count());

        // Every customer the order book knows.
        $this->assertSame(4848, Customer::count());

        // And a catalogue rebuild is not a financial event.
        $this->assertSame($before, $this->ledgers());
    }

    public function test_a_wipe_then_rebuild_leaves_exactly_one_catalogue(): void
    {
        $this->seeder->run();
        $this->seeder->wipe();
        $this->seeder->run();

        $this->assertSame(909, Product::where('product_kind', 'sale_item')->count(),
            'a rebuild replaces the catalogue — it never layers a second copy on it');
        $this->assertSame(4848, Customer::count());
        $this->assertSame(2650.0, app(CateringCostBlockService::class)
            ->rateFor(Product::where('sku', '361')->value('id')));
    }
}
