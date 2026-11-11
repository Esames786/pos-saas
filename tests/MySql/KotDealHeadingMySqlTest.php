<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\PrintDocumentController;
use App\Http\Controllers\Tenant\ReceiptLayoutController;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KOT-DEAL-HEADING-1 — the KOT layout switch "Deal name on top, its items under it".
 *
 * Owner, 11 Nov: on the KOT a deal should print its name ONCE — only a little bigger than an item —
 * with its items under it, smaller, and no rule between them; a deal item in another category still
 * goes to that category's own slip, under the same deal name. Category routing does not change.
 * OFF by default: every ticket stays what it was until a branch turns it on in Edit Layout.
 *
 * DEAL-BILL-NO-VARIANT-1 rides along: the POS gives a deal's line its first item's product, and the
 * server stores that product's default variant on it, so the bill printed "(<rice>)" under a deal as if
 * it were the only thing inside. A bill never prints a variant under a deal line.
 */
class KotDealHeadingMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const DEAL = 'Grill Platter 2';

    private int $branchId;
    private int $grill;
    private int $bread;
    private int $counter;
    private int $grillCat;
    private int $breadCat;
    private int $riceCat;
    private int $comboId;
    private array $lineIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'combo_components', 'combos',
            'sales_order_lines', 'sales_orders', 'category_printer_mappings', 'terminal_printer_settings',
            'receipt_layout_settings', 'printers', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->grillCat = $this->makeCategory(['name' => 'Grill', 'slug' => 'grill']);
        $this->breadCat = $this->makeCategory(['name' => 'Breads', 'slug' => 'breads']);
        $this->riceCat = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice']);
        $this->grill = $this->makePrinter(['code' => 'GRILL', 'name' => 'Grill KOT', 'print_role' => 'kot', 'branch_id' => $this->branchId, 'is_default' => 0]);
        $this->bread = $this->makePrinter(['code' => 'BREAD', 'name' => 'Bread KOT', 'print_role' => 'kot', 'branch_id' => $this->branchId, 'is_default' => 0]);
        $this->counter = $this->makePrinter(['code' => 'CNT', 'name' => 'Counter', 'print_role' => 'both', 'branch_id' => $this->branchId, 'is_default' => 1]);
        // Breads go to the SAME physical printer category as the grill would in a real shop, but a
        // printer of its own here makes "its own slip" easy to see.
        foreach ([[$this->grillCat, $this->grill], [$this->breadCat, $this->bread], [$this->riceCat, $this->counter]] as [$cat, $printer]) {
            DB::connection('tenant')->table('category_printer_mappings')->insert([
                'branch_id' => $this->branchId, 'category_id' => $cat, 'printer_id' => $printer,
                'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->comboId = DB::connection('tenant')->table('combos')->insertGetId([
            'name' => self::DEAL, 'code' => 'GP-2', 'price' => 1800, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── KOT ──────────────────────────────────────────────────────────────────

    public function test_switch_off_by_default_keeps_todays_ticket(): void
    {
        $sale = $this->platterSale();
        $this->setLayout(['item_font_size' => 17]);   // a saved row that never touched the switch

        $grill = $this->plain($this->jobFor($this->queue($sale), $this->grill)->raw_payload);
        $this->assertSame(3, substr_count($grill, '(' . self::DEAL . ')'), 'OFF: the deal name under each of the 3 grill items, as before');
        $this->assertStringNotContainsString("\n" . strtoupper(self::DEAL), $grill, 'OFF: no deal heading');
        $this->assertMatchesRegularExpression('/CHICKEN BOTI\s*\n\s*\(' . preg_quote(self::DEAL) . '\)\s*\n-{42}/', $grill);
    }

    public function test_switch_on_prints_the_deal_once_with_its_items_under_it(): void
    {
        $sale = $this->platterSale();
        $this->setLayout(['item_font_size' => 17, 'show_deal_as_heading' => 1]);
        $jobs = $this->queue($sale);
        $this->assertCount(3, $jobs, 'routing unchanged: grill, bread and rice each get their own slip');

        $raw = $this->jobFor($jobs, $this->grill)->raw_payload;
        $grill = $this->plain($raw);
        $this->assertSame(1, substr_count($grill, strtoupper(self::DEAL)), 'the deal name prints ONCE');
        $this->assertStringNotContainsString('(' . self::DEAL . ')', $grill, 'no repeated "(deal)" sub-line');
        $this->assertMatchesRegularExpression(
            '/  1 GRILL PLATTER 2\n {4}  1 CHICKEN BOTI\n {4}  1 SEEKH KEBAB\n {4}  2 CHUTNEY\n-{42}\n/',
            $grill,
            'deal heading, its items indented under it, no rule between them, ONE rule after the deal'
        );
        $this->assertMatchesRegularExpression('/  1 CHICKEN TIKKA\n-{42}\n/', $grill, 'a plain item prints exactly as before');

        // Sizes: item rows 1x2 (17px) → the deal one step TALLER (1x3, same width), its items one step
        // smaller (1x1). GS ! n — high nibble = width-1, low nibble = height-1.
        $this->assertStringContainsString("\x1D\x21\x02\x1B\x45\x01  1 GRILL PLATTER 2", $raw, 'deal heading: 1x3, bold');
        $this->assertStringContainsString("\x1D\x21\x00\x1B\x45\x01      1 CHICKEN BOTI", $raw, 'deal items: 1x1, bold, indented');
        $this->assertStringContainsString("\x1D\x21\x01\x1B\x45\x01  1 CHICKEN TIKKA", $raw, 'plain item: unchanged 1x2');

        // The Paratha is in another category: its own slip, under the same deal name.
        $bread = $this->plain($this->jobFor($jobs, $this->bread)->raw_payload);
        $this->assertMatchesRegularExpression('/  1 GRILL PLATTER 2\n {4}  1 PARATHA\n-{42}\n/', $bread);
        $rice = $this->plain($this->jobFor($jobs, $this->counter)->raw_payload);
        $this->assertMatchesRegularExpression('/  1 GRILL PLATTER 2\n {4}  1 PLAIN RICE\n-{42}\n/', $rice);
        $this->assertStringNotContainsString('PARATHA', $grill, 'nothing moved between slips');
    }

    public function test_two_platters_then_one_more_reads_as_deals_not_pieces(): void
    {
        $sale = $this->platterSale(deals: 2);
        $this->setLayout(['item_font_size' => 17, 'show_deal_as_heading' => 1]);

        $grill = $this->plain($this->jobFor($this->queue($sale), $this->grill)->raw_payload);
        $this->assertMatchesRegularExpression('/  2 GRILL PLATTER 2\n {4}  2 CHICKEN BOTI\n {4}  2 SEEKH KEBAB\n {4}  4 CHUTNEY\n/', $grill,
            'two platters: "2" on the deal, the kitchen\'s totals on the items');

        // One more platter added to the running order → an ADDITION KOT for ONE platter.
        foreach (['header' => 3, 'boti' => 3, 'kebab' => 3, 'chutney' => 6, 'paratha' => 3, 'rice' => 3] as $key => $qty) {
            DB::connection('tenant')->table('sales_order_lines')->where('id', $this->lineIds[$key])->update(['quantity' => $qty]);
        }
        $more = $this->plain($this->jobFor($this->queue(SalesOrder::with('lines')->findOrFail($sale->id)), $this->grill)->raw_payload);
        $this->assertStringContainsString('ADDITION KOT', $more);
        $this->assertMatchesRegularExpression('/  1 \(R\) GRILL PLATTER 2\n {4}  1 CHICKEN BOTI\n {4}  1 SEEKH KEBAB\n {4}  2 CHUTNEY\n/', $more,
            'the addition names the deal once, marked (R), with only what was added');
    }

    public function test_a_cancel_ticket_groups_its_snapshot_under_the_deal(): void
    {
        $sale = $this->platterSale();
        $this->setLayout(['item_font_size' => 17, 'show_deal_as_heading' => 1]);
        $this->queue($sale);

        $result = app(PrintJobService::class)->queueCancellationKot(
            SalesOrder::with('lines')->findOrFail($sale->id),
            [$this->lineIds['boti'] => 1, $this->lineIds['kebab'] => 1, $this->lineIds['chutney'] => 2],
        );
        $cancel = $this->plain($this->jobFor($result['jobs'], $this->grill)->raw_payload);
        $this->assertStringContainsString('CANCEL KOT', $cancel);
        $this->assertMatchesRegularExpression('/GRILL PLATTER 2\n {4}  1 CHICKEN BOTI\n {4}  1 SEEKH KEBAB\n {4}  2 CHUTNEY\n-{42}\n/', $cancel,
            'a cancel snapshot carries no parent id — it still finds its deal through the live line');
    }

    public function test_a_reprint_after_the_bill_was_saved_again_still_counts_the_deal(): void
    {
        $sale = $this->platterSale();
        $this->setLayout(['item_font_size' => 17, 'show_deal_as_heading' => 1]);
        $job = $this->jobFor($this->queue($sale), $this->grill);

        // Saving a held bill deletes and recreates every line with a new id (KOT-REPRINT-BLANK-1), so
        // the ticket falls back to its snapshot, whose line ids no longer exist in the sale.
        $t = DB::connection('tenant');
        $rows = $t->table('sales_order_lines')->where('sales_order_id', $sale->id)->orderBy('id')->get();
        $t->table('sales_order_lines')->where('sales_order_id', $sale->id)->whereNotNull('parent_sales_order_line_id')->delete();
        $t->table('sales_order_lines')->where('sales_order_id', $sale->id)->delete();
        $newId = [];
        foreach ($rows as $row) {
            $copy = (array) $row;
            $old = $copy['id'];
            unset($copy['id']);
            if ($copy['parent_sales_order_line_id']) {
                $copy['parent_sales_order_line_id'] = $newId[$copy['parent_sales_order_line_id']];
            }
            $newId[$old] = $t->table('sales_order_lines')->insertGetId($copy);
        }

        $again = $this->plain(app(\App\Services\Printing\EscPosPayloadService::class)->build(PrintJob::findOrFail($job->id)));
        $this->assertMatchesRegularExpression('/  1 GRILL PLATTER 2\n {4}  1 CHICKEN BOTI\n {4}  1 SEEKH KEBAB\n {4}  2 CHUTNEY\n-{42}\n/', $again,
            'the deal is still counted from the same item under the same deal');
    }

    public function test_a_reprint_after_the_deal_left_the_order_counts_from_the_recipe(): void
    {
        $sale = $this->platterSale(deals: 2);
        $this->setLayout(['item_font_size' => 17, 'show_deal_as_heading' => 1]);
        $job = $this->jobFor($this->queue($sale), $this->grill);

        // The platter is later taken off the order and the bill saved again (every line recreated with a
        // new id): none of the ticket's line ids resolve, so it prints from its snapshot (KOT-REPRINT-BLANK-1).
        $t = DB::connection('tenant');
        $tikka = (array) $t->table('sales_order_lines')->where('id', $this->lineIds['tikka'])->first();
        $t->table('sales_order_lines')->where('sales_order_id', $sale->id)->whereNotNull('parent_sales_order_line_id')->delete();
        $t->table('sales_order_lines')->where('sales_order_id', $sale->id)->delete();
        unset($tikka['id']);
        $t->table('sales_order_lines')->insert($tikka);

        $again = $this->plain(app(\App\Services\Printing\EscPosPayloadService::class)->build(PrintJob::findOrFail($job->id)));
        $this->assertMatchesRegularExpression('/  2 GRILL PLATTER 2\n {4}  2 CHICKEN BOTI\n {4}  2 SEEKH KEBAB\n {4}  4 CHUTNEY\n-{42}\n/', $again,
            'no deal line left in the sale: the name comes from the deal, the count from its recipe (4 chutney ÷ 2 per platter)');
    }

    public function test_the_browser_kot_and_the_live_preview_follow_the_same_switch(): void
    {
        $sale = $this->platterSale(status: 'paid');
        $this->setLayout(['item_font_size' => 17, 'show_deal_as_heading' => 1]);
        $job = $this->jobFor($this->queue($sale), $this->grill);

        $html = app()->call([app(PrintDocumentController::class), 'preview'], ['printJob' => PrintJob::findOrFail($job->id)])->render();
        $this->assertSame(1, substr_count($html, 'GRILL PLATTER 2'), 'browser KOT: the deal once');
        $this->assertStringContainsString('class="deal-head"', $html);
        $this->assertSame(3, substr_count($html, 'class="deal-item'), 'its three grill items under it');
        $this->assertStringNotContainsString('(' . self::DEAL . ')', $html);

        $row = ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'kot')->sole();
        $on = $this->preview($row, ['show_deal_as_heading' => '1']);
        $this->assertStringContainsString('class="deal-head"', $on, 'Edit Layout preview: switch on');
        $off = $this->preview($row, ['show_deal_as_heading' => '0']);
        $this->assertStringNotContainsString('class="deal-head"', $off, 'Edit Layout preview: switch off');
        $this->assertStringContainsString('(' . self::DEAL . ')', $off, 'switch off: the preview now names the deal like the paper does');
    }

    public function test_the_layout_screen_offers_and_saves_the_switch_on_the_kot_only(): void
    {
        $this->assertContains('show_deal_as_heading', ReceiptLayoutSetting::TOGGLE_FIELDS);
        $this->assertStringContainsString("'show_deal_as_heading'", file_get_contents(resource_path('views/tenant/printing/layouts/_form.blade.php')));
        $this->assertMatchesRegularExpression("/'show_deal_as_heading'\\s*=>\\s*\\['nullable', 'boolean'\\]/",
            file_get_contents(app_path('Http/Controllers/Tenant/ReceiptLayoutController.php')),
            'without its own rule the save handler would turn it OFF on every save');
        $this->assertSame(1, substr_count(file_get_contents(resource_path('views/tenant/printing/layouts/index.blade.php')), "'show_deal_as_heading'"),
            'offered on the KOT only');

        $save = fn (array $extra) => app()->call([app(ReceiptLayoutController::class), 'store'], ['request' => Request::create('/printing/layouts', 'POST', array_merge([
            'branch_id' => $this->branchId, 'document_type' => 'kot', 'paper_size' => '80mm', 'font_size' => 12, 'kot_font_size' => 17,
        ], $extra))]);
        $save(['show_deal_as_heading' => '1']);
        $this->assertTrue((bool) ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'kot')->value('show_deal_as_heading'));
        $save([]);
        $this->assertFalse((bool) ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'kot')->value('show_deal_as_heading'),
            'unticked = off, like every other switch');
        $this->assertFalse((bool) DB::connection('tenant')->selectOne(
            "SELECT COLUMN_DEFAULT AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_layout_settings' AND COLUMN_NAME = 'show_deal_as_heading'"
        )->d, 'a new layout row starts OFF');
    }

    // ── Bill ─────────────────────────────────────────────────────────────────

    public function test_a_bill_never_prints_a_variant_under_a_deal(): void
    {
        $sale = $this->platterSale(status: 'paid');
        // What the server stores today: the deal line carries its first item's DEFAULT variant.
        DB::connection('tenant')->table('sales_order_lines')->where('id', $this->lineIds['header'])->update(['variant_name' => 'House Rice']);
        // A plain item's real variant must still print.
        DB::connection('tenant')->table('sales_order_lines')->where('id', $this->lineIds['tikka'])->update(['variant_name' => 'Half']);
        $sale = SalesOrder::with('lines')->findOrFail($sale->id);

        $job = app(PrintJobService::class)->queueReceipt($sale, \App\Models\Tenant\Printer::findOrFail($this->counter));
        $paper = $this->plain($job->raw_payload);
        $this->assertStringContainsString('Grill Platter 2', $paper);
        $this->assertStringNotContainsString('House Rice', $paper, 'thermal bill: no variant under the deal');
        $this->assertStringContainsString('(Half)', $paper, 'thermal bill: a plain item keeps its variant');

        $html = app()->call([app(PrintDocumentController::class), 'preview'], ['printJob' => PrintJob::findOrFail($job->id)])->render();
        $this->assertStringContainsString('Grill Platter 2', $html);
        $this->assertStringNotContainsString('House Rice', $html, 'browser bill / preview: no variant under the deal');
        $this->assertStringContainsString('(Half)', $html);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** One platter (3 grill items, a Paratha in Breads, rice in Rice) + a plain grill item. */
    private function platterSale(int $deals = 1, string $status = 'held'): SalesOrder
    {
        $rice = $this->makeProduct($this->riceCat, ['name' => 'Plain Rice']);
        $boti = $this->makeProduct($this->grillCat, ['name' => 'Chicken Boti']);
        $kebab = $this->makeProduct($this->grillCat, ['name' => 'Seekh Kebab']);
        $chutney = $this->makeProduct($this->grillCat, ['name' => 'Chutney']);
        $paratha = $this->makeProduct($this->breadCat, ['name' => 'Paratha']);
        $tikka = $this->makeProduct($this->grillCat, ['name' => 'Chicken Tikka']);

        // The deal's recipe — one platter's worth of each item.
        foreach ([[$rice, 1], [$boti, 1], [$kebab, 1], [$paratha, 1], [$chutney, 2]] as $i => [$product, $per]) {
            DB::connection('tenant')->table('combo_components')->insert([
                'combo_id' => $this->comboId, 'product_id' => $product, 'quantity' => $per, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $saleId = $this->makeSale($this->branchId, ['status' => $status, 'order_type' => 'dine_in', 'grand_total' => 1800 * $deals + 700]);
        // The POS gives the deal line its FIRST item's product (the rice) — exactly as live.
        $header = $this->makeSaleLine($saleId, $rice, [
            'product_name' => self::DEAL, 'line_kind' => 'combo_header', 'combo_id' => $this->comboId,
            'quantity' => $deals, 'unit_price' => 1800, 'line_total' => 1800 * $deals,
        ]);
        $part = fn (int $product, string $name, float $per) => $this->makeSaleLine($saleId, $product, [
            'product_name' => $name, 'line_kind' => 'component', 'combo_id' => $this->comboId,
            'parent_sales_order_line_id' => $header, 'quantity' => $per * $deals, 'unit_price' => 0, 'line_total' => 0,
        ]);
        $this->lineIds = [
            'header' => $header,
            'rice' => $part($rice, 'Plain Rice', 1),
            'boti' => $part($boti, 'Chicken Boti', 1),
            'kebab' => $part($kebab, 'Seekh Kebab', 1),
            'paratha' => $part($paratha, 'Paratha', 1),
            'chutney' => $part($chutney, 'Chutney', 2),
            'tikka' => $this->makeSaleLine($saleId, $tikka, ['product_name' => 'Chicken Tikka', 'quantity' => 1, 'unit_price' => 700, 'line_total' => 700]),
        ];

        return SalesOrder::with('lines')->findOrFail($saleId);
    }

    private function setLayout(array $attrs): void
    {
        DB::connection('tenant')->table('receipt_layout_settings')->updateOrInsert(
            ['branch_id' => $this->branchId, 'document_type' => 'kot'],
            array_merge(['paper_size' => '80mm', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()], $attrs),
        );
    }

    private function queue(SalesOrder $sale): array
    {
        return app(PrintJobService::class)->queueKot(sale: $sale);
    }

    private function jobFor(array $jobs, int $printerId): PrintJob
    {
        $job = collect($jobs)->firstWhere('printer_id', $printerId);
        $this->assertNotNull($job, "a slip for printer #{$printerId}");

        return $job;
    }

    /** Strip ESC/POS control sequences (size 1D 21 n, bold 1B 45 n, align 1B 61 n, cut 1D 56 …). */
    private function plain(string $raw): string
    {
        return preg_replace('/\x1D\x56..|\x1B[\x45\x61\x2D].|\x1D\x21./s', '', $raw);
    }

    private function preview(ReceiptLayoutSetting $row, array $query): string
    {
        $request = Request::create('/printing/layouts/' . $row->id . '/preview', 'GET', $query);

        return app()->call([app(ReceiptLayoutController::class), 'preview'], ['request' => $request, 'receiptLayoutSetting' => $row])->render();
    }
}
