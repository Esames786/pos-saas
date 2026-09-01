<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * COMBO-KOT-DEAL-NAME-1 — a KOT names the deal each of its components belongs to.
 *
 * A KOT is split by category and the combo HEADER line is deliberately skipped, so the grill
 * station used to see three loose kebabs with no way to know they were one platter — and plated
 * them separately. Each component line now carries its deal name as a sub-row, in the same place
 * variants and modifiers already sit, so the QTY | ITEM column layout is untouched.
 *
 * A standalone item must gain NOTHING: only lines with a combo_id are named.
 */
class ComboKotDealNameMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $grill;
    private int $counter;
    private int $bbqCat;
    private int $riceCat;
    private int $comboId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'combo_components', 'combos',
            'sales_order_lines', 'sales_orders', 'category_printer_mappings', 'printers',
            'products', 'categories', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->bbqCat = $this->makeCategory(['name' => 'Bar-B-Que', 'slug' => 'bbq']);
        $this->riceCat = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice']);
        $this->grill = $this->makePrinter(['code' => 'GRILL', 'print_role' => 'kot', 'branch_id' => $this->branchId, 'is_default' => 1]);
        $this->counter = $this->makePrinter(['code' => 'CNT', 'print_role' => 'both', 'branch_id' => $this->branchId, 'is_default' => 0]);

        foreach ([[$this->bbqCat, $this->grill], [$this->riceCat, $this->counter]] as [$cat, $printer]) {
            DB::connection('tenant')->table('category_printer_mappings')->insert([
                'branch_id' => $this->branchId, 'category_id' => $cat, 'printer_id' => $printer,
                'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->comboId = DB::connection('tenant')->table('combos')->insertGetId([
            'name' => 'Classic Platter 1 (6 Persons)', 'code' => 'PLAT-1', 'price' => 5500,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Build a sale: one platter (2 components across 2 stations) + one standalone item. */
    private function makePlatterSale(): SalesOrder
    {
        $comboProduct = $this->makeProduct($this->bbqCat, ['name' => 'Classic Platter 1']);
        $kebab = $this->makeProduct($this->bbqCat, ['name' => 'Beef Seekh Kebab']);
        $rice = $this->makeProduct($this->riceCat, ['name' => 'Arabic Rice']);
        $loose = $this->makeProduct($this->bbqCat, ['name' => 'Chicken Tikka']);

        $saleId = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'dine_in']);
        $headerId = $this->makeSaleLine($saleId, $comboProduct, [
            'product_name' => 'Classic Platter 1 (6 Persons)', 'line_kind' => 'combo_header',
            'combo_id' => $this->comboId, 'quantity' => 1,
        ]);
        $this->makeSaleLine($saleId, $kebab, [
            'product_name' => 'Beef Seekh Kebab', 'line_kind' => 'component',
            'parent_sales_order_line_id' => $headerId, 'combo_id' => $this->comboId, 'quantity' => 2,
        ]);
        $this->makeSaleLine($saleId, $rice, [
            'product_name' => 'Arabic Rice', 'line_kind' => 'component',
            'parent_sales_order_line_id' => $headerId, 'combo_id' => $this->comboId, 'quantity' => 1,
        ]);
        // Standalone — no combo_id. Must print exactly as it always has.
        $this->makeSaleLine($saleId, $loose, ['product_name' => 'Chicken Tikka', 'quantity' => 1]);

        return SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);
    }

    /** Every station's ticket names the platter under its own component. */
    public function test_each_station_ticket_names_the_deal(): void
    {
        $sale = $this->makePlatterSale();
        $jobs = app(PrintJobService::class)->queueKot(sale: $sale);

        $this->assertCount(2, $jobs, 'the platter still splits per component category.');

        foreach ($jobs as $job) {
            $this->assertStringContainsString(
                '(Classic Platter 1 (6 Persons))', $job->raw_payload,
                'a station must be told which deal its component belongs to.'
            );
        }
    }

    /** The deal name rides UNDER its component, not as a ticket or column of its own. */
    public function test_the_deal_name_sits_under_the_item_without_touching_the_columns(): void
    {
        $sale = $this->makePlatterSale();
        $jobs = app(PrintJobService::class)->queueKot(sale: $sale);

        $grillJob = collect($jobs)->firstWhere('printer_id', $this->grill);
        // Bold is a THREE-byte sequence (1B 45 n) and size is 1D 21 n — strip both whole, or the
        // trailing n survives and sits between the item and its deal line.
        $plain = preg_replace('/\x1B\x45.|\x1D\x21.|\x1B./s', '', $grillJob->raw_payload);

        $this->assertStringContainsString('QTY', $plain, 'the QTY | ITEM header is untouched.');
        $this->assertMatchesRegularExpression(
            '/BEEF SEEKH KEBAB\s*\n\s*\(Classic Platter 1 \(6 Persons\)\)/',
            $plain,
            'the deal name must print on the line directly BELOW its item.'
        );
    }

    /** A standalone item gains nothing — only combo lines are named. */
    public function test_a_standalone_item_is_not_labelled(): void
    {
        $sale = $this->makePlatterSale();
        $jobs = app(PrintJobService::class)->queueKot(sale: $sale);

        $grillJob = collect($jobs)->firstWhere('printer_id', $this->grill);
        // Bold is a THREE-byte sequence (1B 45 n) and size is 1D 21 n — strip both whole, or the
        // trailing n survives and sits between the item and its deal line.
        $plain = preg_replace('/\x1B\x45.|\x1D\x21.|\x1B./s', '', $grillJob->raw_payload);

        $this->assertStringContainsString('CHICKEN TIKKA', $plain);
        $this->assertDoesNotMatchRegularExpression(
            '/CHICKEN TIKKA\s*\n\s*\(Classic Platter/',
            $plain,
            'an item ordered on its own must never be labelled with a deal.'
        );
    }

    /** A sale with no combo at all is byte-identical to before — zero regression. */
    public function test_a_sale_without_combos_is_unchanged(): void
    {
        $loose = $this->makeProduct($this->bbqCat, ['name' => 'Chicken Tikka']);
        $saleId = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'dine_in']);
        $this->makeSaleLine($saleId, $loose, ['product_name' => 'Chicken Tikka', 'quantity' => 1]);
        $sale = SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);

        $jobs = app(PrintJobService::class)->queueKot(sale: $sale);
        $this->assertCount(1, $jobs);
        $this->assertStringNotContainsString('(Classic Platter', $jobs[0]->raw_payload);
        $this->assertStringContainsString('CHICKEN TIKKA', $jobs[0]->raw_payload);
    }
}
