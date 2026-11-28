<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\PrintDocumentController;
use App\Http\Controllers\Tenant\ReceiptLayoutController;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\MySql\Support\TenantFixtures;

/**
 * KOT-CANCEL-CROSS-1 — Khatri's owner, 28 Nov 2026, marking up a real cancel ticket:
 *   - a cancel KOT gets big crosses beside its heading ("XX CANCEL KOT #2 XX", at least double size): today it differs
 *     from the order's own ticket only by the word CANCEL, at the size of every other big line, with identical item rows;
 *   - the order type line (DINE IN / TAKEAWAY …) a bit bigger;
 *   - the TABLE line a bit bigger.
 * Three KOT layout settings, all OFF / blank by default, so every other business keeps today's ticket byte for byte.
 */
class KotCancelCrossMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $kitchen;
    private int $lineId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders',
            'category_printer_mappings', 'terminal_printer_settings', 'receipt_layout_settings', 'printers',
            'restaurant_tables', 'restaurant_floors', 'restaurant_waiters', 'products', 'categories',
            'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $category = $this->makeCategory(['name' => 'Biryani', 'slug' => 'biryani']);
        $this->kitchen = $this->makePrinter(['code' => 'DINE', 'name' => 'Dine In KOT', 'print_role' => 'both', 'branch_id' => $this->branchId, 'is_default' => 1]);
        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'category_id' => $category, 'printer_id' => $this->kitchen,
            'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $saleId = $this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in', 'grand_total' => 1100,
            'restaurant_table_id' => $this->makeTable($this->branchId, ['table_no' => '2']),
            'restaurant_waiter_id' => $this->makeWaiter($this->branchId, ['name' => 'Ilyas']),
        ]);
        $product = $this->makeProduct($category, ['name' => 'Beef Khatri Biryani (1/2 kg)']);
        $this->lineId = $this->makeSaleLine($saleId, $product, ['product_name' => 'Beef Khatri Biryani (1/2 kg)', 'unit_price' => 1100, 'line_total' => 1100]);
        $this->saleId = $saleId;

        // Khatri's KOT layout as it is today: KOT 17 (tall), items 17, time 14, no stars, no category line.
        $this->setLayout(['kot_font_size' => 17, 'item_font_size' => 17, 'time_font_size' => 14, 'show_heading_stars' => 0,
            'show_category_header' => 0, 'header_text' => 'KITCHEN ORDER TICKET']);
    }

    private int $saleId;

    // ── the paper ───────────────────────────────────────────────────────────

    public function test_nothing_set_prints_todays_ticket(): void
    {
        [$normal, $cancel] = $this->tickets();

        $this->assertRow($normal, 'KOT #1', 1, 2, 'heading as today: tall, normal width');
        $this->assertRow($normal, 'DINE IN', 1, 2, 'order type as today');
        $this->assertRow($normal, 'TABLE: 2', 1, 2, 'table as today');
        $this->assertRow($normal, 'WAITER: ILYAS', 1, 2);
        $this->assertMatchesRegularExpression('/^ *CANCEL KOT #\d+$/m', $this->text($cancel), 'cancel heading as today');
        $this->assertSame(1, $this->rowOf($cancel, '/CANCEL KOT/')[1], 'cancel heading at the KOT width');
        $this->assertStringNotContainsString('XX', $this->text($cancel));
    }

    public function test_the_two_sizes_make_the_order_type_and_table_lines_bigger(): void
    {
        $this->setLayout(['order_type_font_size' => 19, 'table_font_size' => 19]);
        [$normal] = $this->tickets();

        $type = $this->assertRow($normal, 'DINE IN', 2, 2, 'order type: tall AND wide');
        $this->assertSame(str_repeat(' ', 7) . 'DINE IN', $type[0], 'centred in the 21 columns a double-size line holds');
        $this->assertRow($normal, 'TABLE: 2', 2, 2, 'table: tall AND wide');
        $this->assertRow($normal, 'WAITER: ILYAS', 1, 2, 'the waiter line was not asked for — unchanged');
        $this->assertRow($normal, 'KOT #1', 1, 2, 'the heading of a normal ticket is unchanged');
        $this->assertRow($normal, '1 BEEF KHATRI BIRYANI (1/2 KG)', 1, 2, 'the item row is unchanged');
    }

    public function test_the_cross_marks_only_the_cancel_ticket(): void
    {
        $this->setLayout(['show_cancel_cross' => 1]);
        [$normal, $cancel] = $this->tickets();

        $heading = $this->rowOf($cancel, '/CANCEL KOT/');
        $this->assertMatchesRegularExpression('/^ XX CANCEL KOT #\d XX$/', $heading[0], 'crossed on both sides, centred in 21 columns');
        $this->assertSame([2, 2, true], [$heading[1], $heading[2], $heading[3]], 'double size, bold');
        $this->assertRow($normal, 'KOT #1', 1, 2, 'the order\'s own ticket is untouched');
        $this->assertStringNotContainsString('XX', $this->text($normal));

        // With the stars switched on the crosses replace them on the cancel ticket only.
        $this->setLayout(['show_heading_stars' => 1]);
        [$normal, $cancel] = $this->tickets();
        $this->assertMatchesRegularExpression('/^ XX CANCEL KOT #\d+ XX$/m', $this->text($cancel));
        $this->assertStringNotContainsString('*** CANCEL', $this->text($cancel));
        $this->assertMatchesRegularExpression('/\*\*\* KOT #1 \*\*\*/', $this->text($normal));
    }

    public function test_a_bigger_kot_font_keeps_the_bigger_size_for_the_crossed_heading(): void
    {
        $this->setLayout(['kot_font_size' => 22, 'show_cancel_cross' => 1]);
        [, $cancel] = $this->tickets();

        $rows = array_values(array_filter($this->rows($cancel), fn ($r) => str_contains($r[0], 'XX') || str_contains($r[0], 'CANCEL')));
        $this->assertSame(3, $rows[0][1], '"at least double": a triple-size KOT font stays triple');
        $this->assertMatchesRegularExpression('/^XX CANCEL KOT #\d+ XX$/', trim(implode(' ', array_map(fn ($r) => trim($r[0]), $rows))), 'wrapped to 14 columns, every word kept');
    }

    // ── the browser ticket, the live preview, the layout screen ────────────────

    public function test_the_browser_kot_and_the_live_preview_follow_the_same_settings(): void
    {
        [$normalJob, $cancelJob] = $this->tickets(jobs: true);
        $plain = $this->html($cancelJob);
        $this->assertStringNotContainsString('obig', $plain, 'nothing set: today\'s HTML');
        $this->assertStringNotContainsString('xbig', $plain);

        $this->setLayout(['show_cancel_cross' => 1, 'order_type_font_size' => 19, 'table_font_size' => 19]);
        $cancelHtml = $this->html($cancelJob);
        $this->assertMatchesRegularExpression('/class="center xbig">XX CANCEL KOT #\d+ XX</', $cancelHtml, 'browser cancel KOT crossed');
        $this->assertStringContainsString('class="center obig">DINE IN<', $cancelHtml);
        $this->assertStringContainsString('class="tbig">TABLE: 2<', $cancelHtml);
        $this->assertStringNotContainsString('XX CANCEL', $this->html($normalJob), 'browser normal KOT not crossed');

        $row = ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'kot')->sole();
        $on = $this->preview($row, ['show_cancel_cross' => '1', 'order_type_font_size' => '19', 'table_font_size' => '']);
        $this->assertStringContainsString('XX CANCEL KOT #2 XX', $on, 'Edit Layout preview: the switch shows a cancel ticket');
        $this->assertStringContainsString('class="center obig"', $on);
        $this->assertStringNotContainsString('class="tbig"', $on, 'a cleared box previews today\'s table line');
        $off = $this->preview($row, ['show_cancel_cross' => '0', 'order_type_font_size' => '', 'table_font_size' => '']);
        $this->assertStringNotContainsString('XX CANCEL', $off);
        $this->assertStringContainsString('KOT #1', $off);
        $this->assertStringNotContainsString('obig', $off);
    }

    public function test_the_layout_screen_offers_and_saves_them_on_the_kot_only(): void
    {
        $form = view('tenant.printing.layouts._form', ['layout' => ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'kot')->sole()])->render();
        $this->assertMatchesRegularExpression('/data-layout-doc="kot">\s*<label class="form-label" for="order_type_font_size">Order Type Font \(px\)<\/label>/', $form);
        $this->assertMatchesRegularExpression('/data-layout-doc="kot">\s*<label class="form-label" for="table_font_size">Table Font \(px\)<\/label>/', $form);
        $this->assertStringContainsString('name="show_cancel_cross"', $form);
        $index = file_get_contents(resource_path('views/tenant/printing/layouts/index.blade.php'));
        $this->assertSame(1, substr_count($index, "'show_cancel_cross'"), 'the switch is offered on the KOT only');
        $this->assertStringContainsString("params.set('order_type_font_size'", $index, 'the live preview sends the box, blank included');
        $this->assertStringContainsString("params.set('table_font_size'", $index);

        $this->save('kot', ['show_cancel_cross' => '1', 'order_type_font_size' => '19', 'table_font_size' => '19']);
        $this->assertSame([1, 19, 19], $this->saved('kot'));
        $this->save('kot', ['order_type_font_size' => '', 'table_font_size' => '']);
        $this->assertSame([0, null, null], $this->saved('kot'), 'unticked = off, cleared box = as today');
        $this->assertSame('0', (string) DB::connection('tenant')->selectOne(
            "SELECT COLUMN_DEFAULT AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_layout_settings' AND COLUMN_NAME = 'show_cancel_cross'"
        )->d, 'a new layout row starts OFF');
    }

    /**
     * Deploy runs git pull → migrate. In that second the new code meets the old table: tickets must still print as today
     * and a layout save must still work, so a blank box or an unticked switch never names a new column.
     */
    public function test_before_the_columns_exist_tickets_print_and_a_save_still_works(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', fn (Blueprint $t) => $t->dropColumn(['show_cancel_cross', 'order_type_font_size', 'table_font_size']));

        try {
            [$normal, $cancel] = $this->tickets();
            $this->assertRow($normal, 'DINE IN', 1, 2);
            $this->assertRow($normal, 'TABLE: 2', 1, 2);
            $this->assertStringNotContainsString('XX', $this->text($cancel));
            [, $cancelJob] = $this->tickets(jobs: true);
            $this->assertStringContainsString('CANCEL KOT', $this->html($cancelJob));

            $this->save('kot', ['header_text' => 'Saved in the deploy second', 'order_type_font_size' => '', 'table_font_size' => '']);
            $this->assertSame('Saved in the deploy second', ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'kot')->value('header_text'));
            $this->save('receipt', ['order_type_font_size' => '', 'table_font_size' => '']);
            $this->assertTrue(ReceiptLayoutSetting::where('branch_id', $this->branchId)->where('document_type', 'receipt')->exists());
        } finally {
            Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $t) {
                $t->boolean('show_cancel_cross')->default(false)->after('show_deal_as_heading');
                $t->unsignedTinyInteger('order_type_font_size')->nullable()->after('customer_font_size');
                $t->unsignedTinyInteger('table_font_size')->nullable()->after('order_type_font_size');
            });
        }
    }

    /** An installed Branch Server (0.9.0) has no such columns and updates a layout row with every key it is sent. */
    public function test_the_branch_server_is_not_sent_the_new_columns_yet(): void
    {
        $source = file_get_contents(app_path('Services/Edge/EdgeBootstrapService.php'));
        foreach (['show_cancel_cross', 'order_type_font_size', 'table_font_size'] as $column) {
            $this->assertStringNotContainsString("'{$column}'", $source, "{$column} must not be replicated before every appliance carries it");
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** The order's KOT, then a cancel KOT for its one line. Raw ESC/POS bytes, or the jobs. */
    private function tickets(bool $jobs = false): array
    {
        DB::connection('tenant')->table('print_jobs')->delete();
        DB::connection('tenant')->table('kot_batch_lines')->delete();
        DB::connection('tenant')->table('kot_batches')->delete();
        DB::connection('tenant')->table('sales_order_lines')->where('id', $this->lineId)->update(['kot_sent_quantity' => 0]);

        $sale = SalesOrder::with('lines')->findOrFail($this->saleId);
        $normal = collect(app(PrintJobService::class)->queueKot(sale: $sale))->firstWhere('printer_id', $this->kitchen);
        $this->assertNotNull($normal, 'the order\'s KOT');
        $result = app(PrintJobService::class)->queueCancellationKot(SalesOrder::with('lines')->findOrFail($this->saleId), [$this->lineId => 1]);
        $cancel = collect($result['jobs'])->firstWhere('printer_id', $this->kitchen);
        $this->assertNotNull($cancel, 'the cancel KOT');

        return $jobs ? [$normal, $cancel] : [$normal->raw_payload, $cancel->raw_payload];
    }

    /** ESC/POS → [text, width, height, bold] per printed line (size GS ! n, bold ESC E n, underline / align skipped, cut skipped). */
    private function rows(string $raw): array
    {
        $rows = [];
        $line = '';
        $w = $h = 1;
        $bold = false;
        $lw = $lh = 1;
        $lb = false;
        for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
            $c = $raw[$i];
            $next = $raw[$i + 1] ?? '';
            if ($c === "\x1D" && $next === '!') { $v = ord($raw[$i + 2]); $w = ($v >> 4) + 1; $h = ($v & 0x0F) + 1; $i += 2; continue; }
            if ($c === "\x1D" && $next === 'V') { $i += 3; continue; }
            if ($c === "\x1B" && in_array($next, ['E', '-', 'a'], true)) { if ($next === 'E') { $bold = (bool) (ord($raw[$i + 2]) & 1); } $i += 2; continue; }
            if ($c === "\n") { $rows[] = [$line, $lw, $lh, $lb]; $line = ''; continue; }
            if ($line === '') { [$lw, $lh, $lb] = [$w, $h, $bold]; }
            $line .= $c;
        }

        return $rows;
    }

    private function text(string $raw): string
    {
        return implode("\n", array_column($this->rows($raw), 0));
    }

    private function rowOf(string $raw, string $regex): array
    {
        foreach ($this->rows($raw) as $row) {
            if (preg_match($regex, $row[0])) {
                return $row;
            }
        }
        $this->fail("no printed line matches {$regex}:\n" . $this->text($raw));
    }

    private function assertRow(string $raw, string $text, int $w, int $h, string $message = ''): array
    {
        $row = $this->rowOf($raw, '/^ *' . preg_quote($text, '/') . '$/');
        $this->assertSame([$w, $h], [$row[1], $row[2]], trim("{$text} prints {$w}x{$h}. {$message}"));

        return $row;
    }

    private function html(PrintJob $job): string
    {
        return app()->call([app(PrintDocumentController::class), 'preview'], ['printJob' => PrintJob::findOrFail($job->id)])->render();
    }

    private function preview(ReceiptLayoutSetting $row, array $query): string
    {
        $request = Request::create('/printing/layouts/' . $row->id . '/preview', 'GET', $query);

        return app()->call([app(ReceiptLayoutController::class), 'preview'], ['request' => $request, 'receiptLayoutSetting' => $row])->render();
    }

    private function setLayout(array $attrs): void
    {
        DB::connection('tenant')->table('receipt_layout_settings')->updateOrInsert(
            ['branch_id' => $this->branchId, 'document_type' => 'kot'],
            array_merge(['paper_size' => '80mm', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()], $attrs),
        );
    }

    private function save(string $type, array $extra): void
    {
        app()->call([app(ReceiptLayoutController::class), 'store'], ['request' => Request::create('/printing/layouts', 'POST', array_merge([
            'branch_id' => $this->branchId, 'document_type' => $type, 'paper_size' => '80mm', 'font_size' => 12, 'kot_font_size' => 17,
        ], $extra))]);
    }

    private function saved(string $type): array
    {
        $row = DB::connection('tenant')->table('receipt_layout_settings')->where('branch_id', $this->branchId)->where('document_type', $type)->first();

        return [(int) $row->show_cancel_cross, $row->order_type_font_size === null ? null : (int) $row->order_type_font_size, $row->table_font_size === null ? null : (int) $row->table_font_size];
    }
}
