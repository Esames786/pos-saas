<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRINT-LAYOUT-ROWS-1 — the KOT, receipt and reminder honour the new layout settings end-to-end,
 * with a sale that mixes a NORMAL product, a VARIANT product, a COMBO (header + component) and a
 * MODIFIER — proving every line kind still renders once dividers / category / row-size are applied.
 */
class LayoutRowDividerMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders',
            'category_printer_mappings', 'terminal_printer_settings', 'receipt_layout_settings',
            'printers', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->categoryId = $this->makeCategory(['name' => 'Biryani', 'slug' => 'biryani']);
        $printer = $this->makePrinter([
            'code' => 'P1', 'name' => 'Kitchen', 'print_role' => 'both',
            'branch_id' => $this->branchId, 'is_default' => 1,
        ]);
        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'category_id' => $this->categoryId, 'printer_id' => $printer,
            'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A sale carrying every line kind: normal (+ modifier), variant, combo header + component. */
    private function makeMixedSale(string $status): int
    {
        $saleId = $this->makeSale($this->branchId, ['order_type' => 'dine_in', 'status' => $status]);

        $normal = $this->makeProduct($this->categoryId, ['name' => 'Chicken Biryani']);
        $this->makeSaleLine($saleId, $normal, [
            'product_name' => 'Chicken Biryani', 'quantity' => 2,
            'modifiers' => json_encode([['name' => 'Extra Spicy', 'price_delta' => 0]]),
        ]);

        $variant = $this->makeProduct($this->categoryId, ['name' => 'Beef Khatri Biryani']);
        $this->makeSaleLine($saleId, $variant, [
            'product_name' => 'Beef Khatri Biryani', 'variant_name' => '1/2 kg', 'quantity' => 1,
        ]);

        $comboProduct = $this->makeProduct($this->categoryId, ['name' => 'Family Deal']);
        $comboId = $this->makeSaleLine($saleId, $comboProduct, [
            'product_name' => 'Family Deal', 'line_kind' => 'combo_header', 'quantity' => 1,
        ]);
        $componentProduct = $this->makeProduct($this->categoryId, ['name' => 'Raita']);
        $this->makeSaleLine($saleId, $componentProduct, [
            'product_name' => 'Raita', 'line_kind' => 'component',
            'parent_sales_order_line_id' => $comboId, 'quantity' => 1,
        ]);

        return $saleId;
    }

    private function setLayout(string $documentType, array $attrs): void
    {
        DB::connection('tenant')->table('receipt_layout_settings')->updateOrInsert(
            ['branch_id' => $this->branchId, 'document_type' => $documentType],
            array_merge(['paper_size' => '80mm', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()], $attrs),
        );
    }

    public function test_kot_with_dividers_on_and_category_off_still_renders_every_line_kind(): void
    {
        $this->setLayout('kot', [
            'kot_font_size' => 18, 'item_font_size' => 17, 'time_font_size' => 12,
            'show_column_dividers' => 1, 'show_category_header' => 0,
        ]);

        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($this->makeMixedSale('held')));
        $payload = collect($jobs)->pluck('raw_payload')->implode("\n");

        $this->assertStringContainsString(' | ', $payload, 'divider line between Qty and Item');
        $this->assertStringNotContainsString('[ ', $payload, 'category header removed when toggled off');
        // Every line kind survives: normal, variant sub-row, combo component, modifier sub-row.
        $this->assertStringContainsString('CHICKEN BIRYANI', $payload);
        $this->assertStringContainsString('BEEF KHATRI BIRYANI', $payload);
        $this->assertStringContainsString('1/2 kg', $payload, 'variant prints as a sub-row');
        $this->assertStringContainsString('RAITA', $payload, 'combo component prints on the KOT');
        $this->assertStringContainsString('Extra Spicy', $payload, 'modifier prints as a sub-row');
    }

    public function test_kot_defaults_keep_category_header_and_no_dividers(): void
    {
        // No item/time overrides, dividers OFF (default), category ON (default) — today's ticket.
        $this->setLayout('kot', [
            'kot_font_size' => 14, 'show_column_dividers' => 0, 'show_category_header' => 1,
        ]);

        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($this->makeMixedSale('held')));
        $payload = collect($jobs)->pluck('raw_payload')->implode("\n");

        $this->assertStringContainsString('[ ', $payload, 'category header prints when left on');
        $this->assertStringNotContainsString(' | ', $payload, 'no divider when the toggle is off');
    }

    public function test_receipt_with_dividers_renders_combo_modifier_and_variant(): void
    {
        $this->setLayout('receipt', [
            'font_size' => 15, 'item_font_size' => 14, 'show_column_dividers' => 1,
        ]);

        $job = app(PrintJobService::class)->queueReceipt(SalesOrder::findOrFail($this->makeMixedSale('paid')));
        $payload = (string) $job->raw_payload;

        $this->assertStringContainsString(' | ', $payload, 'divider lines between the receipt columns');
        $this->assertStringContainsString('Amount', $payload, 'the Amount column header is present');
        $this->assertStringContainsString('Family Deal', $payload, 'combo header prints on the receipt');
        $this->assertStringContainsString('Raita', $payload, 'combo component prints under its header');
        $this->assertStringContainsString('(1/2 kg)', $payload, 'variant prints in parentheses');
        $this->assertStringContainsString('Extra Spicy', $payload, 'modifier prints under its item');
    }
}
