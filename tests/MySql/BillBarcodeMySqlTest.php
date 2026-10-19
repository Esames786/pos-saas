<?php

namespace Tests\MySql;

use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use App\Support\Code128;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * BILL-BARCODE-1 — the bill number as a big barcode on the bill and the KOT, so the billing counter scans a
 * bill held at another terminal and it comes up.
 *
 * The promise that matters most: NO tenant's paper changes on deploy. The switch is OFF by default, and the
 * bill template's own $show() treats a missing switch as ON — so "no layout row" and "a row saved before
 * this column existed" are both proven to print no barcode. Only a switch SAVED on prints one, on the
 * thermal paper (the printer's own Code 128) and in the browser copy (an SVG) alike.
 */
class BillBarcodeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const GS_K_CODE128 = "\x1D\x6B\x49";

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
        $this->categoryId = $this->makeCategory(['name' => 'Medicines', 'slug' => 'medicines']);
        $printer = $this->makePrinter(['code' => 'P1', 'name' => 'Counter', 'print_role' => 'both', 'branch_id' => $this->branchId, 'is_default' => 1]);
        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'category_id' => $this->categoryId, 'printer_id' => $printer,
            'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_switch_is_off_everywhere_so_no_paper_changes(): void
    {
        $this->assertContains('show_bill_barcode', ReceiptLayoutSetting::TOGGLE_FIELDS);

        // 1. No layout row at all (most tenants): no barcode — the $show() default of "on" must not apply.
        $paid = $this->sale('paid');
        $this->assertStringNotContainsString(self::GS_K_CODE128, $this->receiptPayload($paid));
        $this->assertStringNotContainsString('aria-label="Barcode', $this->receiptHtml($paid));
        $this->assertStringNotContainsString(self::GS_K_CODE128, $this->kotPayload($this->sale('held')));

        // 2. A layout row saved before this column existed: the column's default (OFF) holds.
        $this->setLayout('receipt', ['font_size' => 12]);
        $this->setLayout('kot', ['kot_font_size' => 14]);
        $this->assertFalse((bool) ReceiptLayoutSetting::where('document_type', 'receipt')->value('show_bill_barcode'));
        $this->assertStringNotContainsString(self::GS_K_CODE128, $this->receiptPayload($paid));
        $this->assertStringNotContainsString('aria-label="Barcode', $this->receiptHtml($paid));
        $this->assertStringNotContainsString(self::GS_K_CODE128, $this->kotPayload($this->sale('held')));
    }

    public function test_switched_on_the_bill_and_the_kot_carry_the_bill_number_as_a_barcode(): void
    {
        $this->setLayout('receipt', ['show_bill_barcode' => 1]);
        $this->setLayout('kot', ['show_bill_barcode' => 1]);

        $paid = $this->sale('paid');
        $code = Code128::billCode($paid);
        $payload = $this->receiptPayload($paid);
        $this->assertStringContainsString(self::GS_K_CODE128 . chr(strlen('{B' . $code)) . '{B' . $code, $payload, 'the printer prints its own Code 128');
        $html = $this->receiptHtml($paid);
        $this->assertStringContainsString('aria-label="Barcode ' . $code . '"', $html, 'the browser copy has the same barcode');
        $this->assertStringContainsString('>' . $code . '</div>', $html, 'and the number under it');

        $held = $this->sale('held');
        $this->assertStringContainsString('{B' . Code128::billCode($held), $this->kotPayload($held), 'the KOT too — the order held on the floor');

        // 58 mm paper gets narrower bars (2 dots), 80 mm wider (3) — both fit.
        $this->setLayout('receipt', ['show_bill_barcode' => 1, 'paper_size' => '58mm']);
        $this->assertStringContainsString("\x1D\x77" . chr(2), $this->receiptPayload($paid));
    }

    public function test_the_layout_screen_saves_the_switch_both_ways(): void
    {
        $this->assertStringContainsString('show_bill_barcode', file_get_contents(resource_path('views/tenant/printing/layouts/_form.blade.php')));
        $src = file_get_contents(app_path('Http/Controllers/Tenant/ReceiptLayoutController.php'));
        $this->assertMatchesRegularExpression("/'show_bill_barcode'\\s*=>\\s*\\['nullable', 'boolean'\\]/", $src,
            'without its own rule the save handler would turn it OFF on every save');
        $index = file_get_contents(resource_path('views/tenant/printing/layouts/index.blade.php'));
        $this->assertSame(2, substr_count($index, "'show_bill_barcode'"), 'offered on the bill and on the KOT');
    }

    public function test_code128_symbol_is_well_formed(): void
    {
        $w = Code128::widths('BL20339');
        $this->assertSame((7 + 3) * 11 + 2, array_sum($w), 'start + 7 chars + check + stop(13 modules)');
        $this->assertSame([2, 1, 1, 2, 1, 4], array_slice($w, 0, 6), 'start B');
        $this->assertSame([2, 3, 3, 1, 1, 1, 2], array_slice($w, -7), 'stop');
        $svg = Code128::svg('BL20339');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertSame(intdiv(count($w) + 1, 2), substr_count($svg, '<rect x='), 'one rect per bar');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function sale(string $status): int
    {
        $id = $this->makeSale($this->branchId, ['order_type' => 'takeaway', 'status' => $status]);
        $p = $this->makeProduct($this->categoryId, ['name' => 'Panadol 500mg Tablet']);
        $this->makeSaleLine($id, $p, ['product_name' => 'Panadol 500mg Tablet', 'quantity' => 2]);

        return $id;
    }

    private function setLayout(string $type, array $attrs): void
    {
        DB::connection('tenant')->table('receipt_layout_settings')->updateOrInsert(
            ['branch_id' => $this->branchId, 'document_type' => $type],
            array_merge(['paper_size' => '80mm', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()], $attrs),
        );
    }

    private function receiptPayload(int $saleId): string
    {
        return (string) app(PrintJobService::class)->queueReceipt(SalesOrder::findOrFail($saleId))->raw_payload;
    }

    private function kotPayload(int $saleId): string
    {
        return collect(app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($saleId)))->pluck('raw_payload')->implode("\n");
    }

    private function receiptHtml(int $saleId): string
    {
        $sale = SalesOrder::with(['lines', 'branch', 'payments.method', 'createdBy'])->findOrFail($saleId);

        return view('tenant.printing.documents.receipt', [
            'job'        => null,
            'salesOrder' => $sale,
            'layout'     => ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'receipt')->first(),
        ])->render();
    }
}
