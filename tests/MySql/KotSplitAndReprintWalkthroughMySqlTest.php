<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * Documentation by execution — rebuilt from a REAL order, HS-20260831104329-417 (Kashif Food,
 * 31 Aug 10:43, table 12, DTQ 2 Counter):
 *
 *     Deal 1 (Serve 1)  =  Chicken Biryani (Small) + Chicken Chatni Roll + Regular Drink
 *     plus  Chicken Tikka (Chest), Mayo Garlic Fries (Regular)
 *
 * Its three deal parts are cooked in three different places, so the deal is TORN APART across
 * stations while the reminder keeps it whole. This prints both, so the layout can be read rather
 * than imagined.
 *
 * Then it does the thing that cannot be checked on production without risking a live ticket: the
 * bill is edited (the POS deletes and recreates its lines on every save, with new ids), and the
 * ORIGINAL kot is asked to print again. On 31 Aug that came out blank on all three stations.
 */
class KotSplitAndReprintWalkthroughMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private array $printers = [];
    private array $products = [];
    private int $comboId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors',
            'combo_components', 'combos', 'category_printer_mappings', 'printers',
            'products', 'categories', 'terminals', 'branches', 'users',
        ]);

        $this->userId = $this->makeUser(['employee_code' => 'KS' . Str::random(4), 'name' => 'DTQ 2 Counter']);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');

        $this->branchId  = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'DTQ 2']);

        // the three stations of the real kitchen
        foreach (['KF-P-T2' => 'both', 'KF-P-BBQ' => 'kot', 'KF-P-FF' => 'kot'] as $code => $role) {
            $this->printers[$code] = $this->makePrinter([
                'code' => $code, 'print_role' => $role, 'branch_id' => $this->branchId,
                'supports_reminder' => $code === 'KF-P-T2' ? 1 : 0,
            ]);
        }

        // category -> station, exactly as Kashif Food routes them
        $routes = [
            'Chicken Biryani' => 'KF-P-T2',
            'Beverages'       => 'KF-P-T2',
            'Chicken Roll'    => 'KF-P-BBQ',
            'Bar-B-Que'       => 'KF-P-BBQ',
            'Fries'           => 'KF-P-FF',
        ];
        $items = [
            'Chicken Biryani (Small)'     => 'Chicken Biryani',
            'Regular Drink'               => 'Beverages',
            'Chicken Chatni Roll'         => 'Chicken Roll',
            'Chicken Tikka (Chest)'       => 'Bar-B-Que',
            'Mayo Garlic Fries (Regular)' => 'Fries',
        ];

        $categoryIds = [];
        foreach ($routes as $catName => $printerCode) {
            $categoryIds[$catName] = $this->makeCategory([
                'name' => $catName, 'slug' => Str::slug($catName) . '-' . Str::random(4),
            ]);
            foreach (['kot', 'reminder'] as $printRole) {
                // reminders always go to the counter; KOTs to the station that cooks it
                DB::connection('tenant')->table('category_printer_mappings')->insert([
                    'branch_id' => $this->branchId,
                    'category_id' => $categoryIds[$catName],
                    'printer_id' => $printRole === 'reminder' ? $this->printers['KF-P-T2'] : $this->printers[$printerCode],
                    'print_role' => $printRole, 'order_type' => 'all', 'is_active' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        foreach ($items as $name => $catName) {
            $this->products[$name] = $this->makeProduct($categoryIds[$catName], ['name' => $name]);
        }

        $this->comboId = DB::connection('tenant')->table('combos')->insertGetId([
            'name' => 'Deal 1 (Serve 1)', 'code' => 'DEAL1-' . Str::random(4), 'price' => 875,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** strip the ESC/POS control bytes so the slip can be read */
    private function readable(string $raw): string
    {
        $t = preg_replace('/\x1b@|\x1d\x56[\x00-\xff]{1,2}|\x1b[!aEMdG][\x00-\xff]?|\x1d[!Bh][\x00-\xff]?|\x1b[23][\x00-\xff]?/', '', $raw);

        return trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $t));
    }

    /**
     * Decode the ESC/POS byte stream into the slip as the printer lays it out, keeping the SIZE of
     * every line — because on an 80mm roll the size is what decides how much fits: 42 characters at
     * normal, 21 at double width. Reading the text alone hides that a heading eats two lines.
     */
    private function slipWithSizes(string $raw): string
    {
        $w = $h = 1; $bold = false; $align = 0;
        $out = [];
        $line = '';
        $lineW = 1; $lineH = 1; $lineBold = false; $lineAlign = 0;
        $started = false;

        for ($i = 0; $i < strlen($raw); $i++) {
            $c = $raw[$i];

            if ($c === "\x1d" && ($raw[$i + 1] ?? '') === '!') {          // GS ! n — size
                $n = ord($raw[$i + 2] ?? "\x00");
                $w = ($n >> 4) + 1; $h = ($n & 0x0F) + 1;
                $i += 2;
                if (! $started) { $lineW = $w; $lineH = $h; }
                continue;
            }
            if ($c === "\x1b" && ($raw[$i + 1] ?? '') === 'E') {          // ESC E n — bold
                $bold = ord($raw[$i + 2] ?? "\x00") > 0; $i += 2;
                if (! $started) { $lineBold = $bold; }
                continue;
            }
            if ($c === "\x1b" && ($raw[$i + 1] ?? '') === 'a') {          // ESC a n — align
                $align = ord($raw[$i + 2] ?? "\x00"); $i += 2;
                if (! $started) { $lineAlign = $align; }
                continue;
            }
            if ($c === "\x1b" && ($raw[$i + 1] ?? '') === '@') { $i += 1; continue; }   // reset
            if ($c === "\x1d" && ($raw[$i + 1] ?? '') === 'V') { $i += 3; continue; }   // cut

            if ($c === "\n") {
                $out[] = [$line, $lineW, $lineH, $lineBold, $lineAlign];
                $line = ''; $started = false;
                $lineW = $w; $lineH = $h; $lineBold = $bold; $lineAlign = $align;
                continue;
            }
            if (ord($c) < 32) { continue; }
            if (! $started) { $lineW = $w; $lineH = $h; $lineBold = $bold; $lineAlign = $align; $started = true; }
            $line .= $c;
        }
        if (trim($line) !== '') { $out[] = [$line, $lineW, $lineH, $lineBold, $lineAlign]; }

        $render = "        +--------------------------------------------+  size\n";
        foreach ($out as [$text, $lw, $lh, $lb, $la]) {
            $cols = intdiv(42, max(1, $lw));
            $t = rtrim($text);
            if ($la === 1) { $t = str_pad(ltrim($t), (int) floor(($cols + strlen(ltrim($t))) / 2), ' ', STR_PAD_LEFT); }
            $tag = $lw . 'x' . $lh . ($lb ? ' B' : '  ');
            $note = match (true) {
                $lw >= 3 => 'HUGE  (14 cols)',
                $lw === 2 => 'BIG   (21 cols)',
                $lh >= 2 => 'tall  (42 cols)',
                default => 'normal(42 cols)',
            };
            $render .= sprintf("        | %-42s |  %-5s %s\n", substr($t, 0, 42), $tag, $note);
            if ($lh >= 2) {
                $render .= sprintf("        | %-42s |        (takes 2 lines of paper)\n", '');
            }
        }

        return $render . "        +--------------------------------------------+\n";
    }

    /** Kashif Food's real saved layout, so this preview matches the shop's printers exactly. */
    private function applyKashifLayout(): void
    {
        $rows = [
            ['kot', '*** KITCHEN ORDER TICKET ***', 14, 18, 17, 14],
            ['reminder', '*** ORDER REMINDER ***', 18, 14, null, null],
        ];
        foreach ($rows as [$doc, $header, $font, $kotFont, $itemFont, $timeFont]) {
            DB::connection('tenant')->table('receipt_layout_settings')->updateOrInsert(
                ['document_type' => $doc, 'branch_id' => $this->branchId],
                [
                    'header_text' => $header, 'font_size' => $font, 'kot_font_size' => $kotFont,
                    'item_font_size' => $itemFont, 'time_font_size' => $timeFont,
                    'created_at' => now(), 'updated_at' => now(),
                ]
            );
        }
    }

    private function printerCode(?int $id): string
    {
        return (string) DB::connection('tenant')->table('printers')->where('id', $id)->value('code');
    }

    private function station(string $code): string
    {
        return match ($code) {
            'KF-P-BBQ' => 'BBQ / GRILL STATION',
            'KF-P-FF'  => 'FAST FOOD STATION',
            default    => 'COUNTER (DTQ 2)',
        };
    }

    /** Build the bill exactly as the POS wrote it: a deal header, its parts, then loose items. */
    private function buildTheOrder(int $tableId): SalesOrder
    {
        $saleId = $this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in',
            'terminal_id' => $this->terminalId, 'restaurant_table_id' => $tableId,
            'grand_total' => 875 + 650 + 450,
        ]);

        // the deal: one header carrying the whole price, three parts carrying nothing
        $this->makeSaleLine($saleId, $this->products['Chicken Biryani (Small)'], [
            'product_name' => 'Deal 1 (Serve 1)', 'quantity' => 1, 'unit_price' => 875,
            'line_total' => 875, 'line_kind' => 'combo_header', 'combo_id' => $this->comboId,
            'kot_sent_quantity' => 0,
        ]);
        foreach (['Chicken Biryani (Small)', 'Chicken Chatni Roll', 'Regular Drink'] as $part) {
            $this->makeSaleLine($saleId, $this->products[$part], [
                'product_name' => $part, 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0,
                'line_kind' => 'component', 'combo_id' => $this->comboId, 'kot_sent_quantity' => 0,
            ]);
        }
        // and the ordinary items on the same bill
        foreach ([['Chicken Tikka (Chest)', 650], ['Mayo Garlic Fries (Regular)', 450]] as [$name, $price]) {
            $this->makeSaleLine($saleId, $this->products[$name], [
                'product_name' => $name, 'quantity' => 1, 'unit_price' => $price,
                'line_total' => $price, 'line_kind' => 'standard', 'kot_sent_quantity' => 0,
            ]);
        }

        return SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);
    }

    /**
     * PART 1 — what the kitchen and the counter actually receive.
     * PART 2 — the same tickets asked to print again after the bill was edited.
     */
    public function test_a_deal_splits_across_stations_and_the_reminder_keeps_it_whole(): void
    {
        $this->applyKashifLayout();
        $tableId = $this->makeTable($this->branchId, ['table_no' => '12']);
        $sale = $this->buildTheOrder($tableId);

        $service = app(PrintJobService::class);
        $esc = app(EscPosPayloadService::class);

        $kotJobs = $service->queueKot(sale: $sale, terminalId: (string) $this->terminalId);
        $plan = $service->planRemindersForKotJobs($sale, $kotJobs);
        $reminderJobs = $plan['auto_jobs'] ?? [];

        echo "\n\n" . str_repeat('#', 60) . "\n";
        echo "#  ONE BILL, ONE DEAL, THREE STATIONS\n";
        echo "#  Deal 1 (Serve 1) = Biryani + Chatni Roll + Drink\n";
        echo "#  plus Chicken Tikka (Chest) and Mayo Garlic Fries\n";
        echo str_repeat('#', 60) . "\n";

        $this->assertNotEmpty($kotJobs, 'the order must produce kitchen tickets');

        echo "\n===== WHERE EACH TICKET WENT =====\n";
        foreach ($kotJobs as $job) {
            $code = $this->printerCode($job->printer_id);
            $items = collect(data_get($job->payload, 'line_snapshots', []))
                ->map(fn ($s) => rtrim(rtrim(number_format((float) ($s['quantity'] ?? 1), 2), '0'), '.')
                    . ' x ' . ($s['product_name'] ?? '?')
                    . (! empty($s['combo_id']) ? '  (Deal 1 (Serve 1))' : ''))
                ->implode(' | ');
            echo sprintf("  %-10s %-22s %s\n", $code, $this->station($code), $items);
        }

        echo "\n===== EACH TICKET, ON THE PAPER, AT ITS REAL SIZE =====\n";
        echo "  (Kashif Food's saved layout: KOT heading 18px -> 2x2, items 17px -> double tall,\n";
        echo "   time 14px -> normal. 80mm roll = 42 characters at normal, 21 at double width.)\n";
        $n = 0;
        foreach ($kotJobs as $job) {
            $n++;
            $code = $this->printerCode($job->printer_id);
            echo "\n\n  ***** TICKET {$n} of " . count($kotJobs) . "  ->  {$code}   " . $this->station($code) . " *****\n";
            echo $this->slipWithSizes($esc->build($job->refresh()));
        }

        foreach ($reminderJobs as $job) {
            $code = $this->printerCode($job->printer_id);
            echo "\n\n  ***** REMINDER  ->  {$code}   " . $this->station($code) . " *****\n";
            echo $this->slipWithSizes($esc->buildReminder($job->refresh()));
        }

        // The deal is genuinely torn apart: its three parts do not all land on one printer.
        $dealPrinters = collect($kotJobs)->filter(
            fn ($j) => collect(data_get($j->payload, 'line_snapshots', []))->contains(fn ($s) => ! empty($s['combo_id']))
        )->pluck('printer_id')->unique();
        $this->assertGreaterThan(1, $dealPrinters->count(),
            'the deal is cooked in more than one place, so its parts must reach more than one station');

        // Every KOT prints its items today.
        foreach ($kotJobs as $job) {
            $slip = $this->readable($esc->build($job->refresh()));
            $snap = collect(data_get($job->payload, 'line_snapshots', []))->first();
            $this->assertStringContainsString(
                strtoupper(substr((string) ($snap['product_name'] ?? ''), 0, 12)),
                strtoupper($slip),
                'a freshly printed ticket must carry its items'
            );
        }

        // ── PART 2: the bill is edited, then those tickets are asked to print again ──────────
        echo "\n\n" . str_repeat('#', 60) . "\n";
        echo "#  NOW THE WAITER ADDS SOMETHING AND SAVES\n";
        echo "#  (the POS deletes and recreates the lines, with new ids)\n";
        echo str_repeat('#', 60) . "\n";

        $oldIds = $sale->lines->pluck('id')->all();
        $rows = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale->id)->get();
        DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale->id)->delete();
        foreach ($rows as $row) {
            $data = (array) $row;
            unset($data['id']);
            DB::connection('tenant')->table('sales_order_lines')->insert($data);
        }
        $newIds = DB::connection('tenant')->table('sales_order_lines')
            ->where('sales_order_id', $sale->id)->pluck('id')->all();

        echo "\n  line ids before the save : " . implode(', ', $oldIds) . "\n";
        echo "  line ids after the save  : " . implode(', ', $newIds) . "\n";
        $this->assertEmpty(array_intersect($oldIds, $newIds), 'a save gives every line a new id');

        echo "\n===== THE SAME TICKETS, REPRINTED =====\n";
        $blank = 0;
        foreach ($kotJobs as $job) {
            $code = $this->printerCode($job->printer_id);
            $slip = $this->readable($esc->build(PrintJob::on('tenant')->find($job->id)));
            // everything between the QTY ITEM heading and the closing rule is the item list
            preg_match('/QTY ITEM\s*-+\s*(.*?)\s*-+\s*$/s', $slip, $m);
            $body = trim($m[1] ?? '');
            $isBlank = $body === '';
            $blank += $isBlank ? 1 : 0;
            echo sprintf("  %-10s %-22s items on the reprint: %s\n", $code, $this->station($code),
                $isBlank ? '*** NOTHING ***' : $body);
        }

        echo "\n  reminders reprint fine, because they render from their own frozen copy:\n";
        foreach ($reminderJobs as $job) {
            $slip = $this->readable(app(PrintJobService::class)
                ->queueReminderReprint(PrintJob::on('tenant')->find($job->id))->payload
                ? $esc->buildReminder(app(PrintJobService::class)
                    ->queueReminderReprint(PrintJob::on('tenant')->find($job->id))) : '');
            echo '    ' . (str_contains(strtoupper($slip), 'DEAL 1') ? 'reminder reprint STILL carries the deal' : 'reminder reprint is empty too') . "\n";
        }

        echo "\n  => " . $blank . ' of ' . count($kotJobs) . " kitchen tickets would print BLANK.\n\n";

        $this->assertSame(count($kotJobs), $blank,
            'this is the defect: after a save, every earlier KOT reprints with no items at all');
    }
}
