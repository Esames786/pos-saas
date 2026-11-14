<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\ReceiptLayoutController;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\MySql\Support\TenantFixtures;

/**
 * RECEIPT-CUSTOMER-SIZE-1 — the bill's Customer / Phone / Deliver to lines get their own size box
 * on Edit Layout (Receipt). The client asked for the phone and address to be bigger on the delivery
 * bill; those lines were fixed at normal size while only the items and totals followed the layout.
 *
 * Blank box = the exact bill of today (paper bytes and preview markup). 15–17 = tall, 18–20 = tall
 * and wide (wrapped to 21), the same bands as every other size box.
 */
class ReceiptCustomerSizeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const BOLD_ON = "\x1B\x45\x01";

    private const BOLD_OFF = "\x1B\x45\x00";

    private const SIZE_NORMAL = "\x1D\x21\x00";

    private const SIZE_TALL = "\x1D\x21\x01";    // w1 h2

    private const SIZE_DOUBLE = "\x1D\x21\x11";  // w2 h2

    private const ADDRESS = 'House 12, Street 4, Block 13-B, Gulistan-e-Johar, near Masjid';

    private int $branchId;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders', 'delivery_riders',
            'category_printer_mappings', 'terminal_printer_settings', 'receipt_layout_settings',
            'printers', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch();
        $this->categoryId = $this->makeCategory(['name' => 'Biryani', 'slug' => 'biryani']);
        $this->makePrinter(['code' => 'P1', 'name' => 'Delivery', 'print_role' => 'both', 'branch_id' => $this->branchId, 'is_default' => 1]);
    }

    // ── Paper ────────────────────────────────────────────────────────────────

    public function test_a_blank_box_prints_todays_bill_byte_for_byte(): void
    {
        // The shop's real receipt shape: totals at 15 (tall), item rows at 14, size box never set.
        $this->setLayout(['font_size' => 15, 'item_font_size' => 14]);
        $paper = $this->receiptPayload($this->deliverySale());

        // Exactly the composition the bill had before this change: bold, no size bytes around it.
        $this->assertStringContainsString(self::BOLD_ON . "Customer: Sample Customer" . self::BOLD_OFF . "\n", $paper);
        $this->assertStringContainsString(self::BOLD_ON . "Phone: 0300-1234567" . self::BOLD_OFF . "\n", $paper);
        $this->assertStringContainsString(self::BOLD_ON . 'Deliver to: ' . self::ADDRESS . self::BOLD_OFF . "\n", $paper);
        $this->assertStringNotContainsString(self::SIZE_TALL . self::BOLD_ON . 'Phone', $paper);
        $this->assertStringNotContainsString(self::SIZE_DOUBLE . self::BOLD_ON . 'Phone', $paper);
    }

    public function test_17_prints_customer_phone_and_address_tall_and_leaves_the_rest_alone(): void
    {
        $this->setLayout(['font_size' => 15, 'item_font_size' => 14, 'customer_font_size' => 17]);
        $paper = $this->receiptPayload($this->deliverySale());

        $this->assertStringContainsString(self::SIZE_TALL . self::BOLD_ON . "Customer: Sample Customer\n" . self::BOLD_OFF . self::SIZE_NORMAL, $paper);
        $this->assertStringContainsString(self::SIZE_TALL . self::BOLD_ON . "Phone: 0300-1234567\n" . self::BOLD_OFF . self::SIZE_NORMAL, $paper);
        // Tall keeps the full 42 columns: the 73-character address wraps at a word, into two lines.
        $this->assertStringContainsString(self::SIZE_TALL . self::BOLD_ON . "Deliver to: House 12, Street 4, Block\n13-B, Gulistan-e-Johar, near Masjid\n" . self::BOLD_OFF . self::SIZE_NORMAL, $paper);
        // The rider's line and the cashier's line keep their old look.
        $this->assertStringContainsString(self::BOLD_ON . 'Rider: Rider One' . self::BOLD_OFF . "\n", $paper);
        $this->assertStringContainsString("Cashier: ", $paper);
        $this->assertStringNotContainsString(self::SIZE_TALL . 'Cashier', $paper);
    }

    public function test_19_prints_them_tall_and_wide_wrapped_to_21_characters(): void
    {
        $this->setLayout(['font_size' => 15, 'item_font_size' => 14, 'customer_font_size' => 19]);
        $paper = $this->receiptPayload($this->deliverySale());

        $this->assertStringContainsString(self::SIZE_DOUBLE . self::BOLD_ON . "Phone: 0300-1234567\n" . self::BOLD_OFF . self::SIZE_NORMAL, $paper);

        $start = strpos($paper, self::SIZE_DOUBLE . self::BOLD_ON . 'Deliver to:');
        $this->assertNotFalse($start, 'the address prints double');
        $block = substr($paper, $start + strlen(self::SIZE_DOUBLE . self::BOLD_ON));
        $block = substr($block, 0, strpos($block, self::BOLD_OFF));
        $lines = array_values(array_filter(explode("\n", $block), 'strlen'));
        $this->assertGreaterThan(2, count($lines), 'a double-wide address takes more lines');
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(21, strlen($line), "\"{$line}\" fits the 21 columns of double width");
        }
        $this->assertSame('Deliver to: ' . self::ADDRESS, implode(' ', $lines), 'nothing of the address is lost in the wrap');
    }

    // ── Screen ───────────────────────────────────────────────────────────────

    public function test_the_live_preview_follows_the_box_and_clearing_it_shows_todays_bill(): void
    {
        $this->deliverySale();
        $row = $this->setLayout(['font_size' => 15, 'customer_font_size' => 17]);

        $saved = $this->preview($row, []);
        $this->assertStringContainsString('<div class="bold" style="font-size: 1.45em">Phone: 0300-1234567</div>', $saved, 'no box sent = the saved size');
        $this->assertStringContainsString('<div class="bold" style="font-size: 1.45em">Deliver to: ' . self::ADDRESS . '</div>', $saved);
        $this->assertStringContainsString('<div class="bold" style="font-size: 1.45em">Customer: Sample Customer</div>', $saved);

        $cleared = $this->preview($row, ['customer_font_size' => '']);
        $this->assertStringContainsString('<div class="bold">Phone: 0300-1234567</div>', $cleared, 'a cleared box previews today\'s bill');
        $this->assertStringNotContainsString('style="font-size: 1.45em">Phone', $cleared);

        $big = $this->preview($row, ['customer_font_size' => '22']);
        $this->assertStringContainsString('<div class="bold" style="font-size: 1.9em">Phone: 0300-1234567</div>', $big);
    }

    public function test_the_bill_preview_with_no_size_has_no_style_attribute(): void
    {
        $sale = $this->deliverySale();
        $this->setLayout(['font_size' => 15]);
        $html = view('tenant.printing.documents.receipt', [
            'job' => null,
            'salesOrder' => SalesOrder::with(['lines', 'branch', 'payments.method', 'createdBy'])->findOrFail($sale),
            'layout' => ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'receipt')->first(),
        ])->render();

        $this->assertStringContainsString('<div class="bold">Customer: Sample Customer</div>', $html);
        $this->assertStringContainsString('<div class="bold">Phone: 0300-1234567</div>', $html);
        $this->assertStringContainsString('<div class="bold">Deliver to: ' . self::ADDRESS . '</div>', $html);
    }

    // ── The layout screen ────────────────────────────────────────────────────

    public function test_the_layout_screen_offers_saves_and_clears_the_box_on_the_bill_only(): void
    {
        $form = view('tenant.printing.layouts._form', ['layout' => $this->setLayout(['customer_font_size' => 17])])->render();
        $this->assertMatchesRegularExpression('/data-layout-doc="receipt">\s*<label class="form-label" for="customer_font_size">Customer &amp; Delivery Font \(px\)<\/label>/', $form);
        $this->assertStringContainsString('name="customer_font_size" value="17"', $form);

        $index = file_get_contents(resource_path('views/tenant/printing/layouts/index.blade.php'));
        $this->assertStringContainsString("'customer_font_size' => \$l->customer_font_size", $index, 'the edit modal loads the saved size');
        $this->assertStringContainsString("setFormValue(form, 'customer_font_size'", $index);
        $this->assertStringContainsString("params.set('customer_font_size'", $index, 'the live preview is sent the box');
        $this->assertStringContainsString("querySelectorAll('[data-layout-doc]')", $index, 'the box is hidden on KOT / Reminder');

        $this->save('receipt', ['customer_font_size' => '19']);
        $this->assertSame(19, $this->savedSize('receipt'));
        $this->save('receipt', ['customer_font_size' => '']);
        $this->assertNull($this->savedSize('receipt'), 'clearing the box saves blank = today\'s bill');
        $this->save('kot', ['customer_font_size' => '']);
        $this->assertNull($this->savedSize('kot'));
    }

    /**
     * Deploy runs git pull → migrate. In that second the new code meets the old table: the bill must
     * still print and a layout save must still work, so a blank box never names the new column.
     */
    public function test_before_the_column_exists_the_bill_prints_and_a_save_still_works(): void
    {
        $sale = $this->deliverySale();
        $this->setLayout(['font_size' => 15]);
        Schema::connection('tenant')->table('receipt_layout_settings', fn (Blueprint $t) => $t->dropColumn('customer_font_size'));

        try {
            $paper = $this->receiptPayload($sale);
            $this->assertStringContainsString(self::BOLD_ON . "Phone: 0300-1234567" . self::BOLD_OFF . "\n", $paper);

            $this->save('receipt', ['customer_font_size' => '', 'header_text' => 'Saved in the deploy second']);
            $this->assertSame('Saved in the deploy second', ReceiptLayoutSetting::where('branch_id', $this->branchId)
                ->where('document_type', 'receipt')->value('header_text'));
            $this->save('kot', ['customer_font_size' => '']);
            $this->assertTrue(ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'kot')->exists());
        } finally {
            Schema::connection('tenant')->table('receipt_layout_settings', fn (Blueprint $t) => $t->unsignedTinyInteger('customer_font_size')->nullable()->after('time_font_size'));
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function deliverySale(): int
    {
        $riderId = DB::connection('tenant')->table('delivery_riders')->insertGetId([
            'branch_id' => $this->branchId, 'name' => 'Rider One', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = $this->makeSale($this->branchId, [
            'order_type' => 'delivery', 'status' => 'paid',
            'customer_name' => 'Sample Customer', 'customer_phone' => '0300-1234567',
            'delivery_address' => self::ADDRESS, 'delivery_rider_id' => $riderId,
        ]);
        $p = $this->makeProduct($this->categoryId, ['name' => 'Beef Biryani']);
        $this->makeSaleLine($id, $p, ['product_name' => 'Beef Biryani', 'quantity' => 1, 'unit_price' => 1450, 'line_total' => 1450]);

        return $id;
    }

    private function setLayout(array $attrs): ReceiptLayoutSetting
    {
        DB::connection('tenant')->table('receipt_layout_settings')->updateOrInsert(
            ['branch_id' => $this->branchId, 'document_type' => 'receipt'],
            array_merge([
                'paper_size' => '80mm', 'is_active' => 1, 'show_customer_name' => 1, 'show_delivery_details' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], $attrs),
        );

        return ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'receipt')->firstOrFail();
    }

    private function receiptPayload(int $saleId): string
    {
        return (string) app(PrintJobService::class)->queueReceipt(SalesOrder::findOrFail($saleId))->raw_payload;
    }

    private function preview(ReceiptLayoutSetting $row, array $query): string
    {
        $request = Request::create('/printing/layouts/' . $row->id . '/preview', 'GET', $query);

        return app()->call([app(ReceiptLayoutController::class), 'preview'], ['request' => $request, 'receiptLayoutSetting' => $row])->render();
    }

    private function save(string $type, array $extra): void
    {
        app()->call([app(ReceiptLayoutController::class), 'store'], ['request' => Request::create('/printing/layouts', 'POST', array_merge([
            'branch_id' => $this->branchId, 'document_type' => $type, 'paper_size' => '80mm', 'font_size' => 15, 'kot_font_size' => 17,
        ], $extra))]);
    }

    private function savedSize(string $type): ?int
    {
        $v = ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', $type)->value('customer_font_size');

        return $v === null ? null : (int) $v;
    }
}
