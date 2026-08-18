<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KOT-ROUTING-TERMINAL-1 (Phase 3) — KOT routing keyed on the physical TERMINAL, with precedence:
 * a rule pinned to the sale's terminal WINS over an "any terminal" (NULL) rule, and the two never
 * mix (no double-print). A tenant with no terminal on any rule routes exactly as before.
 */
class PrintRoutingTerminalMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $categoryId;

    private int $productId;

    private int $terminalA;

    private int $terminalB;

    private int $printerA;

    private int $printerB;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders',
            'category_printer_mappings', 'terminal_printer_settings', 'printers', 'products',
            'categories', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->categoryId = $this->makeCategory(['name' => 'Biryani', 'slug' => 'biryani']);
        $this->productId = $this->makeProduct($this->categoryId, ['name' => 'Beef Khatri Biryani']);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Takeaway']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Quick Sale']);
        $this->printerA = $this->makePrinter(['code' => 'PA', 'name' => 'Takeaway Printer', 'print_role' => 'both', 'branch_id' => $this->branchId, 'is_default' => 1]);
        $this->printerB = $this->makePrinter(['code' => 'PB', 'name' => 'QuickSale Printer', 'print_role' => 'both', 'branch_id' => $this->branchId]);
    }

    private function map(?int $terminalId, int $printerId, string $orderType = 'all'): void
    {
        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'terminal_id' => $terminalId, 'category_id' => $this->categoryId,
            'printer_id' => $printerId, 'print_role' => 'kot', 'order_type' => $orderType, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function kotPrinterIds(int $terminalId, string $orderType): array
    {
        $saleId = $this->makeSale($this->branchId, ['terminal_id' => $terminalId, 'order_type' => $orderType, 'status' => 'held']);
        $this->makeSaleLine($saleId, $this->productId, ['product_name' => 'Beef Khatri Biryani', 'quantity' => 1]);
        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($saleId));

        return collect($jobs)->map(fn (PrintJob $j) => (int) $j->printer_id)->sort()->values()->all();
    }

    public function test_a_terminal_rule_wins_and_a_wildcard_falls_back_without_double_print(): void
    {
        // "Any terminal" wildcard → printer A; Quick-Sale terminal pinned → printer B.
        $this->map(null, $this->printerA);
        $this->map($this->terminalB, $this->printerB);

        // Takeaway terminal has no pinned rule → falls back to the wildcard → printer A only.
        $this->assertSame([$this->printerA], $this->kotPrinterIds($this->terminalA, 'quick_sale'),
            'a terminal without its own rule uses the "any terminal" wildcard');

        // Quick-Sale terminal has a pinned rule → printer B ONLY (wildcard is ignored, no double-print).
        $this->assertSame([$this->printerB], $this->kotPrinterIds($this->terminalB, 'quick_sale'),
            'a terminal-pinned rule wins over the wildcard and does not also print to it');
    }

    public function test_the_same_terminal_routes_every_order_type_to_its_counter(): void
    {
        // The whole point: one terminal, two order types, one destination — Takeaway terminal → A.
        $this->map($this->terminalA, $this->printerA);   // order_type = all

        $this->assertSame([$this->printerA], $this->kotPrinterIds($this->terminalA, 'takeaway'));
        $this->assertSame([$this->printerA], $this->kotPrinterIds($this->terminalA, 'quick_sale'),
            'a quick-sale order on the takeaway terminal still prints to the takeaway counter');
    }

    public function test_no_terminal_on_any_rule_behaves_exactly_as_before(): void
    {
        // Legacy shape: order-type + category, no terminal. Must resolve unchanged.
        $this->map(null, $this->printerA, 'dine_in');

        $this->assertSame([$this->printerA], $this->kotPrinterIds($this->terminalA, 'dine_in'),
            'a legacy order-type rule still routes when the order type matches');
        // A quick_sale order finds no order-type match → falls to the branch default printer (A, is_default).
        $this->assertSame([$this->printerA], $this->kotPrinterIds($this->terminalA, 'quick_sale'),
            'no matching rule falls back to the branch default KOT printer, as before');
    }
}
