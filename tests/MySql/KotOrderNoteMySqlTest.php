<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\ReceiptLayoutController;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KOT-ORDER-NOTE-1 — the order note on the KOT.
 *
 * Owner, 2 Nov: a website order's KOT printed the whole website note (payment, location, distance, map link)
 * and Edit Layout had no switch for it. Now the kitchen gets only its part of a website note, and the KOT
 * layout has an "Order Note" switch — ON by default, so no tenant's paper changes on deploy. The paper KOT
 * (network printer) and the browser KOT (agent / Live Preview) print the same note behind the same switch.
 */
class KotOrderNoteMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const WEBSITE_NOTE = 'WEBSITE ORDER | CASH ON DELIVERY | Landmark: Near the mosque | Location: near PECHS Block 6 (GPS ±14 m)'
        .' | Distance: 6.8 km | Alt phone: 0300 1112223 | Map: https://maps.google.com/?q=24.863817,67.07347'
        .' | Zinger Burger (Regular): NoMayo | Note: LessSpicy';

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
        $this->categoryId = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice']);
        $printer = $this->makePrinter(['code' => 'P1', 'name' => 'Kitchen', 'print_role' => 'both', 'branch_id' => $this->branchId, 'is_default' => 1]);
        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'category_id' => $this->categoryId, 'printer_id' => $printer,
            'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_website_kot_prints_only_the_kitchen_part_and_a_counter_note_prints_as_typed(): void
    {
        $web = $this->kotPayload($this->sale(['order_source' => 'website', 'order_type' => 'delivery', 'notes' => self::WEBSITE_NOTE]));
        $this->assertStringContainsString('ORDER NOTE:', $web);
        $this->assertStringContainsString('NoMayo', $web, 'the customer\'s item note reaches the kitchen');
        $this->assertStringContainsString('LessSpicy', $web, 'the customer\'s own note reaches the kitchen');
        foreach (['WEBSITE ORDER', 'CASH ON DELIVERY', 'Landmark', 'PECHS', 'Distance', '0300 1112223', 'maps.google'] as $counterOnly) {
            $this->assertStringNotContainsString($counterOnly, $web, "{$counterOnly} is for the counter and the rider, not the kitchen");
        }

        // Nothing for the kitchen in it: no ORDER NOTE line at all.
        $bare = $this->kotPayload($this->sale(['order_source' => 'website', 'order_type' => 'delivery',
            'notes' => 'WEBSITE ORDER | CASH ON DELIVERY | Location: near PECHS Block 6 | Map: https://maps.google.com/?q=1,2']));
        $this->assertStringNotContainsString('ORDER NOTE', $bare);

        // A counter order's note is the cashier's words: printed exactly as typed.
        $pos = $this->kotPayload($this->sale(['order_type' => 'takeaway', 'notes' => 'ExtraRaita PackSeparately']));
        $this->assertStringContainsString('ExtraRaita PackSeparately', $pos);
    }

    public function test_the_switch_turns_the_order_note_off_and_a_layout_from_before_still_prints_it(): void
    {
        $sale = fn () => $this->sale(['order_type' => 'takeaway', 'notes' => 'Extra raita']);

        $this->assertStringContainsString('ORDER NOTE: Extra raita', $this->kotPayload($sale()), 'no layout row: printed, as today');

        $this->setLayout(['show_column_dividers' => 0]);   // a row saved without touching the new switch = column default
        $this->assertStringContainsString('ORDER NOTE: Extra raita', $this->kotPayload($sale()), 'deploy changes no paper: default ON');

        $this->setLayout(['show_order_note' => 0]);
        $this->assertStringNotContainsString('ORDER NOTE', $this->kotPayload($sale()), 'switched off in Edit Layout: gone from the KOT');
    }

    public function test_the_browser_kot_and_live_preview_show_the_same_note_behind_the_same_switch(): void
    {
        $this->sale(['order_source' => 'website', 'order_type' => 'delivery', 'status' => 'paid', 'notes' => self::WEBSITE_NOTE]);
        $this->setLayout([]);
        $row = ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'kot')->sole();

        $html = $this->preview($row, []);
        $this->assertStringContainsString('ORDER NOTE:', $html);
        $this->assertStringContainsString('LessSpicy', $html);
        $this->assertStringNotContainsString('CASH ON DELIVERY', $html);
        $this->assertStringNotContainsString('maps.google', $html);

        $this->assertStringNotContainsString('ORDER NOTE', $this->preview($row, ['show_order_note' => '0']), 'the live preview follows the switch');
    }

    public function test_the_layout_screen_offers_and_saves_the_switch_on_the_kot_only(): void
    {
        $this->assertStringContainsString("'show_order_note'", file_get_contents(resource_path('views/tenant/printing/layouts/_form.blade.php')));
        $src = file_get_contents(app_path('Http/Controllers/Tenant/ReceiptLayoutController.php'));
        $this->assertMatchesRegularExpression("/'show_order_note'\\s*=>\\s*\\['nullable', 'boolean'\\]/", $src,
            'without its own rule the save handler would turn it OFF on every save');
        $index = file_get_contents(resource_path('views/tenant/printing/layouts/index.blade.php'));
        $this->assertSame(1, substr_count($index, "'show_order_note'"), 'offered on the KOT only');
        $this->assertContains('show_order_note', ReceiptLayoutSetting::TOGGLE_FIELDS);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function sale(array $attrs): int
    {
        $id = $this->makeSale($this->branchId, array_merge(['order_type' => 'takeaway', 'status' => 'held'], $attrs));
        $p = $this->makeProduct($this->categoryId, ['name' => 'Singaporean Rice (Regular)']);
        $this->makeSaleLine($id, $p, ['product_name' => 'Singaporean Rice (Regular)', 'quantity' => 2]);

        return $id;
    }

    private function setLayout(array $attrs): void
    {
        DB::connection('tenant')->table('receipt_layout_settings')->updateOrInsert(
            ['branch_id' => $this->branchId, 'document_type' => 'kot'],
            array_merge(['paper_size' => '80mm', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()], $attrs),
        );
    }

    private function kotPayload(int $saleId): string
    {
        return collect(app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($saleId)))->pluck('raw_payload')->implode("\n");
    }

    private function preview(ReceiptLayoutSetting $row, array $query): string
    {
        $request = Request::create('/printing/layouts/'.$row->id.'/preview', 'GET', $query);

        return app()->call([app(ReceiptLayoutController::class), 'preview'], ['request' => $request, 'receiptLayoutSetting' => $row])->render();
    }
}
