<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * LINKAGE GUARD for KOT-PER-CATEGORY: splitting tickets by category must not disturb the older
 * POS features that share the KOT path.
 *
 *  - a COMBO must not emit a ticket of its own: the header is a display row that both renderers
 *    skip, so a per-category route for it would print a BLANK slip. Its components are the
 *    kitchen items and route on their own categories.
 *  - MODIFIERS ride on their line's ticket and must still reach the station that cooks it.
 *  - a SPLIT-BILL child is its own sale and prints its own tickets.
 */
class ComboModifierKotIntegrityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $kitchen;
    private int $bar;
    private int $foodCat;
    private int $drinkCat;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders', 'category_printer_mappings', 'printers', 'products', 'categories', 'terminals', 'branches', 'users']);

        $this->branchId = $this->makeBranch();
        $this->foodCat = $this->makeCategory(['name' => 'Food', 'slug' => 'food']);
        $this->drinkCat = $this->makeCategory(['name' => 'Drinks', 'slug' => 'drinks']);
        $this->kitchen = $this->makePrinter(['code' => 'KIT', 'print_role' => 'both', 'branch_id' => $this->branchId, 'is_default' => 1]);
        $this->bar = $this->makePrinter(['code' => 'BAR', 'print_role' => 'kot', 'branch_id' => $this->branchId, 'is_default' => 0]);

        foreach ([[$this->foodCat, $this->kitchen], [$this->drinkCat, $this->bar]] as [$cat, $printer]) {
            DB::connection('tenant')->table('category_printer_mappings')->insert([
                'branch_id' => $this->branchId, 'category_id' => $cat, 'printer_id' => $printer,
                'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_a_combo_prints_its_components_and_never_a_blank_header_ticket(): void
    {
        $comboProduct = $this->makeProduct($this->foodCat, ['name' => 'Family Deal']);
        $burger = $this->makeProduct($this->foodCat, ['name' => 'Burger']);
        $cola = $this->makeProduct($this->drinkCat, ['name' => 'Cola']);

        $saleId = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'dine_in']);
        $headerId = $this->makeSaleLine($saleId, $comboProduct, ['product_name' => 'Family Deal', 'line_kind' => 'combo_header', 'quantity' => 1]);
        $this->makeSaleLine($saleId, $burger, ['product_name' => 'Burger', 'line_kind' => 'component', 'parent_sales_order_line_id' => $headerId, 'quantity' => 2]);
        $this->makeSaleLine($saleId, $cola, ['product_name' => 'Cola', 'line_kind' => 'component', 'parent_sales_order_line_id' => $headerId, 'quantity' => 2]);

        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($saleId));

        // One ticket for the food station, one for the bar, and none for the header.
        $this->assertCount(2, $jobs, 'a combo prints per component category, never a header ticket');
        foreach ($jobs as $job) {
            $this->assertNotEmpty(trim($job->raw_payload), 'no blank ticket');
            $this->assertNotContains($headerId, $job->payload['line_ids'], 'the combo header is never routed');
        }
        $byPrinter = collect($jobs)->keyBy('printer_id');
        $this->assertStringContainsString('BURGER', $byPrinter[$this->kitchen]->raw_payload);
        $this->assertStringContainsString('COLA', $byPrinter[$this->bar]->raw_payload);
    }

    public function test_modifiers_travel_with_their_line_to_the_right_station(): void
    {
        $burger = $this->makeProduct($this->foodCat, ['name' => 'Burger']);
        $saleId = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'takeaway']);
        $this->makeSaleLine($saleId, $burger, [
            'product_name' => 'Burger', 'quantity' => 1,
            'modifiers' => json_encode([['name' => 'Extra Cheese', 'price_delta' => 50]]),
            'kitchen_note' => 'No onions',
        ]);

        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($saleId));

        $this->assertCount(1, $jobs);
        $this->assertSame($this->kitchen, $jobs[0]->printer_id);
        $this->assertStringContainsString('Extra Cheese', $jobs[0]->raw_payload, 'modifier printed with its item');
        $this->assertStringContainsString('No onions', $jobs[0]->raw_payload, 'kitchen note printed');
    }

    public function test_a_split_bill_child_prints_its_own_tickets(): void
    {
        $burger = $this->makeProduct($this->foodCat, ['name' => 'Burger']);
        $cola = $this->makeProduct($this->drinkCat, ['name' => 'Cola']);

        // parent already sent its KOT
        $parentId = $this->makeSale($this->branchId, ['status' => 'held', 'sale_no' => 'HS-PARENT', 'order_type' => 'dine_in']);
        $this->makeSaleLine($parentId, $burger, ['product_name' => 'Burger', 'quantity' => 1, 'kot_sent' => 1, 'kot_sent_quantity' => 1]);
        $this->assertCount(0, app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($parentId)), 'nothing unsent left on the parent');

        // the split child is a separate sale with its own unsent lines
        $childId = $this->makeSale($this->branchId, ['status' => 'held', 'sale_no' => 'HS-SPLIT', 'order_type' => 'dine_in']);
        $this->makeSaleLine($childId, $cola, ['product_name' => 'Cola', 'quantity' => 1]);

        $childJobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($childId));
        $this->assertCount(1, $childJobs);
        $this->assertSame($this->bar, $childJobs[0]->printer_id, 'the split child routes on its own categories');
        $this->assertSame((int) $childId, (int) $childJobs[0]->reference_id);
        $this->assertNotSame(
            PrintJob::where('reference_id', $parentId)->value('logical_key'),
            $childJobs[0]->logical_key,
            'parent and split child never share a print identity'
        );
    }
}
