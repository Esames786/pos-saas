<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Printing\PrintJobService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * A walkthrough, not a guard: one real dine-in check handled by FOUR different counters, printed at
 * every step on the current code, so the paper can be read and checked against what the floor gets.
 *
 *   1. Floor T4   punches Singaporean Rice Khaas (combo) + Chicken Malai Boti + a drink
 *   2. DTQ 3      voids one item
 *   3. DTQ 2      punches a new item onto the same check
 *   4. Floor T4   takes a Bill / Preview
 *   5. DTQ 3      reviews and pays
 *
 * Kashif Food's shape: a counter printer per till, one BBQ station, Beverages pinned per terminal.
 */
class LifecyclePrintWalkthroughMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private array $term = [];       // label => terminal id
    private array $printer = [];    // label => printer id
    private int $userId;
    private int $bbqCat;
    private int $bevCat;
    private int $riceCat;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_line_cancellations',
            'void_reasons', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts',
            'category_printer_mappings', 'terminal_printer_settings', 'printers',
            'combo_components', 'combos', 'terminal_user', 'terminals', 'products', 'categories',
            'payment_methods', 'receipt_layout_settings', 'branches', 'users',
        ]);

        $this->userId = $this->makeUser(['name' => 'Floor T4 Counter', 'employee_code' => 'WK' . Str::random(4)]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');

        $this->branchId = $this->makeBranch(['name' => 'Kashif Food']);

        foreach ([['T4', 'DTQ Floor T4'], ['T3', 'DTQ 3'], ['T2', 'DTQ 2']] as [$code, $name]) {
            $this->term[$code] = $this->makeTerminal($this->branchId, ['code' => $code, 'name' => $name]);
            $this->printer[$code] = $this->makePrinter([
                'code' => 'P' . $code, 'name' => $code . ' Counter Printer',
                'print_role' => 'both', 'branch_id' => $this->branchId, 'supports_reminder' => 1,
            ]);
            DB::connection('tenant')->table('terminal_printer_settings')->insert([
                'terminal_id' => $this->term[$code], 'receipt_printer_id' => $this->printer[$code],
                'kot_printer_id' => $this->printer[$code], 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(ShiftService::class)->open(
                Branch::on('tenant')->find($this->branchId),
                Terminal::on('tenant')->find($this->term[$code]),
                $this->userId, 0.0
            );
        }
        $this->printer['BBQ'] = $this->makePrinter([
            'code' => 'PBBQ', 'name' => 'BBQ / Grill KOT', 'print_role' => 'kot',
            'branch_id' => $this->branchId, 'supports_reminder' => 0,
        ]);

        // Bar-B-Que -> one station for every terminal. Beverages + Rice -> each terminal's own counter.
        $this->bbqCat = $this->makeCategory(['name' => 'Bar-B-Que', 'slug' => 'bbq-' . Str::random(4)]);
        $this->bevCat = $this->makeCategory(['name' => 'Beverages', 'slug' => 'bev-' . Str::random(4)]);
        $this->riceCat = $this->makeCategory(['name' => 'Singaporean Rice', 'slug' => 'rice-' . Str::random(4)]);

        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'terminal_id' => null, 'category_id' => $this->bbqCat,
            'printer_id' => $this->printer['BBQ'], 'print_role' => 'kot', 'order_type' => 'all',
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['T4', 'T3', 'T2'] as $code) {
            foreach ([$this->bevCat, $this->riceCat] as $cat) {
                foreach (['kot', 'reminder'] as $role) {
                    DB::connection('tenant')->table('category_printer_mappings')->insert([
                        'branch_id' => $this->branchId, 'terminal_id' => $this->term[$code], 'category_id' => $cat,
                        'printer_id' => $this->printer[$code], 'print_role' => $role, 'order_type' => 'all',
                        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function paper(?string $raw): string
    {
        return preg_replace('/\x1B\x45.|\x1D\x21.|\x1B.|\x1D\x56\x42./s', '', (string) $raw);
    }

    private function show(string $step, array $jobs): void
    {
        $names = DB::connection('tenant')->table('printers')->pluck('name', 'id');
        fwrite(STDERR, "\n\n" . str_repeat('#', 62) . "\n# " . $step . "\n" . str_repeat('#', 62) . "\n");
        if (! $jobs) { fwrite(STDERR, "\n(koi parchi nahi nikli)\n"); return; }
        foreach ($jobs as $job) {
            $job = PrintJob::on('tenant')->find($job->id);
            fwrite(STDERR, "\n>>>>> " . strtoupper($job->document_type) . "  PRINTER: "
                . ($names[$job->printer_id] ?? 'BROWSER') . "\n");
            fwrite(STDERR, rtrim($this->paper($job->raw_payload)) . "\n");
        }
    }

    public function test_walkthrough(): void
    {
        $service = app(PrintJobService::class);

        /* ── catalogue ─────────────────────────────────────────────────────────────────────── */
        $riceOfKhaas = $this->makeProduct($this->bbqCat, ['name' => 'Rice of Khaas', 'default_selling_price' => 2900]);
        $baluchi = $this->makeProduct($this->bbqCat, ['name' => 'Chicken Baluchi Boti', 'default_selling_price' => 1250]);
        $shahi = $this->makeProduct($this->bbqCat, ['name' => 'Chicken Shahi Chattakh', 'default_selling_price' => 1250]);
        $boneless = $this->makeProduct($this->bbqCat, ['name' => 'Chicken Boti Boneless', 'default_selling_price' => 1150]);
        $malaiBoti = $this->makeProduct($this->bbqCat, ['name' => 'Chicken Malai Boti', 'default_selling_price' => 1150]);
        $drink = $this->makeProduct($this->bevCat, ['name' => 'Soft Drink (345 ml)', 'default_selling_price' => 120]);
        $paratha = $this->makeProduct($this->bevCat, ['name' => 'Parhata Large', 'default_selling_price' => 150]);

        $comboId = DB::connection('tenant')->table('combos')->insertGetId([
            'name' => 'Singaporean Rice Khass (2-3 Persons)', 'code' => 'KF-RKP-SINGKHASS',
            'price' => 2900, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        /* ── 1. FLOOR T4 punches ───────────────────────────────────────────────────────────── */
        $saleId = $this->makeSale($this->branchId, [
            'sale_no' => 'HS-20260831-0007', 'status' => 'held', 'order_type' => 'dine_in',
            'terminal_id' => $this->term['T4'], 'created_by_user_id' => $this->userId,
            'subtotal' => 4170, 'grand_total' => 4170,
        ]);
        $header = $this->makeSaleLine($saleId, $riceOfKhaas, [
            'product_name' => 'Singaporean Rice Khass (2-3 Persons)', 'line_kind' => 'combo_header',
            'combo_id' => $comboId, 'quantity' => 1, 'unit_price' => 2900, 'line_total' => 2900, 'kot_sent_quantity' => 0,
        ]);
        foreach ([[$riceOfKhaas, 'Rice of Khaas'], [$baluchi, 'Chicken Baluchi Boti'],
                  [$shahi, 'Chicken Shahi Chattakh'], [$boneless, 'Chicken Boti Boneless']] as [$pid, $nm]) {
            $this->makeSaleLine($saleId, $pid, [
                'product_name' => $nm, 'line_kind' => 'component', 'parent_sales_order_line_id' => $header,
                'combo_id' => $comboId, 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0, 'kot_sent_quantity' => 0,
            ]);
        }
        $malaiLine = $this->makeSaleLine($saleId, $malaiBoti, ['product_name' => 'Chicken Malai Boti', 'quantity' => 1, 'unit_price' => 1150, 'line_total' => 1150, 'kot_sent_quantity' => 0]);
        $this->makeSaleLine($saleId, $drink, ['product_name' => 'Soft Drink (345 ml)', 'quantity' => 1, 'unit_price' => 120, 'line_total' => 120, 'kot_sent_quantity' => 0]);

        $sale = SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);
        $kot1 = $service->queueKot(sale: $sale, terminalId: (string) $this->term['T4']);
        $this->show('QADAM 1 — Floor T4 ne order punch kiya  |  KOT', $kot1);
        $rem1 = $service->planRemindersForKotJobs($sale->refresh()->load('lines'), $kot1);
        $this->show('QADAM 1 — REMINDER', $rem1['auto_jobs']);

        /* ── 2. DTQ 3 voids one item ───────────────────────────────────────────────────────── */
        $reason = DB::connection('tenant')->table('void_reasons')->insertGetId([
            'name' => 'Customer changed mind', 'reason_type' => 'void', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sale = SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);
        $voidQty = [(string) $malaiLine => 1.0];

        $cancel1 = $service->queueCancellationKot($sale, $voidQty, (string) $this->term['T3']);
        DB::connection('tenant')->table('sales_order_line_cancellations')->insert([
            'event_uuid' => (string) Str::uuid(), 'sales_order_id' => $saleId, 'sales_order_line_id' => $malaiLine,
            'void_reason_id' => $reason, 'requested_by_user_id' => $this->userId, 'approval_mode' => 'auto',
            'product_name' => 'Chicken Malai Boti', 'quantity' => 1, 'kot_batch_id' => $cancel1['batch']?->id,
            'cancelled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('sales_order_lines')->where('id', $malaiLine)->update(['quantity' => 0, 'line_total' => 0]);
        $this->show('QADAM 2 — DTQ 3 ne "Chicken Malai Boti" cancel kiya  |  CANCEL KOT', $cancel1['jobs']);
        $cancelRem1 = $service->queueCancellationReminders(
            SalesOrder::on('tenant')->with('lines')->findOrFail($saleId), $cancel1['batch'], false, (string) $this->term['T3']
        );
        $this->show('QADAM 2 — REMINDER (cancelled / updated order)', $cancelRem1);

        /* ── 3. DTQ 2 punches a new item ───────────────────────────────────────────────────── */
        $this->makeSaleLine($saleId, $paratha, ['product_name' => 'Parhata Large', 'quantity' => 2, 'unit_price' => 150, 'line_total' => 300, 'kot_sent_quantity' => 0]);
        $sale = SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);
        $kot3 = $service->queueKot(sale: $sale, terminalId: (string) $this->term['T2']);
        $this->show('QADAM 3 — DTQ 2 ne naya item punch kiya  |  ADDITION KOT', $kot3);
        $rem3 = $service->planRemindersForKotJobs($sale->refresh()->load('lines'), $kot3);
        $this->show('QADAM 3 — REMINDER', $rem3['auto_jobs']);

        /* ── 4. Floor T4 takes a Bill / Preview ────────────────────────────────────────────── */
        DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->update(['subtotal' => 3320, 'grand_total' => 3320]);
        $preview = $service->queueReceipt(
            SalesOrder::on('tenant')->with('lines')->findOrFail($saleId),
            terminalId: (string) $this->term['T4'], ensureOnce: true
        );
        $this->show('QADAM 4 — Floor T4 ne Bill / Preview liya  |  PROFORMA', [$preview]);

        /* ── 5. DTQ 3 reviews and pays ─────────────────────────────────────────────────────── */
        $cash = $this->makePaymentMethod(['name' => 'Cash', 'method_type' => 'cash']);
        $t3Shift = DB::connection('tenant')->table('shifts')->where('terminal_id', $this->term['T3'])->value('id');
        // What SalesOrderController does on payment: stamp the PAYING terminal and its shift,
        // keep the order's own business date.
        DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->update([
            'status' => 'paid', 'completed_at' => now(),
            'terminal_id' => $this->term['T3'], 'shift_id' => $t3Shift,
            'paid_amount' => 3320, 'change_amount' => 0, 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('sale_payments')->insert([
            'sales_order_id' => $saleId, 'payment_method_id' => $cash, 'amount' => 3320,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $final = $service->queueReceipt(
            SalesOrder::on('tenant')->with('lines')->findOrFail($saleId),
            terminalId: (string) $this->term['T3'], ensureOnce: true
        );
        $this->show('QADAM 5 — DTQ 3 ne Review & Pay kiya  |  FINAL RECEIPT', [$final]);

        /* ── the ledger ────────────────────────────────────────────────────────────────────── */
        $names = DB::connection('tenant')->table('printers')->pluck('name', 'id');
        $terms = DB::connection('tenant')->table('terminals')->pluck('name', 'id');
        fwrite(STDERR, "\n\n" . str_repeat('#', 62) . "\n# KHULASA — har parchi kahan nikli\n" . str_repeat('#', 62) . "\n\n");
        foreach (PrintJob::on('tenant')->orderBy('id')->get() as $j) {
            fwrite(STDERR, sprintf("  %-9s -> %-22s  (job terminal: %s)\n",
                $j->document_type, $names[$j->printer_id] ?? 'BROWSER', $terms[$j->terminal_id] ?? '-'));
        }
        $s = SalesOrder::on('tenant')->findOrFail($saleId);
        fwrite(STDERR, sprintf("\n  sale ka terminal: %s   shift: %s   status: %s\n",
            $terms[$s->terminal_id] ?? '-', $s->shift_id, $s->status));

        $this->assertTrue(true);
    }
}
