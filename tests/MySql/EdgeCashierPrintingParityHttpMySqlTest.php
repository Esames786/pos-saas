<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalPrintDelivery;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPrintDeliveryService;
use App\Services\Edge\EdgeLocalPrintDirectPayService;
use App\Services\Edge\EdgeLocalPrintKotService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W5 (Team 5) — kitchen printing & documents parity on the Branch Server, over REAL HTTP on a branch_server-booted app,
 * on the SHARED PrintJobService / PrintRoutingService / KotCancellationService / EscPos / canonical renderer:
 *
 *  D-01  3-station KOT routing (BBQ / Fastfood station printers + a terminal-pinned counter category) sent from ANOTHER
 *        counter → one ticket per category per printer, counter items at the SENDING counter; the Edge worker delivers
 *        each ticket's EXACT stored bytes to its own (fake) TCP printer.
 *  D-02  Add Round → ADDITION KOT #2 with only the delta.   D-03  reprint → DUPLICATE KOT, copy 1 then 2 per destination.
 *  D-04  Reminder: auto on round 1 (worker delivers it), Ask-on-addition on round 2 → confirm / decline, Reminder
 *        DUPLICATE n reprint, Print Here document.
 *  D-05  whole-order cancel → CANCEL KOT at the cancelling counter's network printer + cancellation Reminder there.
 *  D-06  line void → CANCEL KOT + the correction Reminder at the voiding counter (W5 service; revise path is Team 2/3).
 *  D-08  Direct Pay intents → KOT + receipt via the shared orchestrator, idempotent, retry endpoint.
 *  D-10  bill-preview document (canonical receipt Blade, zero mutation) for a cart and a saved check; Send to network.
 *  D-12  per-sale Last Print list fields; report job has no browser document.
 *  D-13/14  retry of a terminal failure, dismiss (no counters, live-lease guard, printed refused), retry of a dismissed job.
 *  D-23  UserDataScope on every print endpoint.   D-24  print preferences endpoint.   Page: the W5 controls render.
 */
class EdgeCashierPrintingParityHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;
    private int $tableId;
    private int $catBbq;
    private int $catFast;
    private int $catDrinks;
    private int $bbq;
    private int $burger;
    private int $drink;
    private int $cashMethodId;
    private int $voidReasonId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_local_print_deliveries', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations', 'edge_sync_outbox',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'manager_approvals', 'void_reasons',
            'category_printer_mappings', 'terminal_printer_settings', 'printers', 'terminal_user',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'held_kot_cancellation_approval_mode' => 'auto_approve', 'held_kot_line_cancellation_approval_mode' => 'auto_approve']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'W5P' . Str::random(4), 'name' => 'W5 Cashier']);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Counter B']);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T7', 'status' => 'available']);
        $this->catBbq = $this->makeCategory(['name' => 'BBQ', 'slug' => 'bbq-' . Str::random(4)]);
        $this->catFast = $this->makeCategory(['name' => 'Fastfood', 'slug' => 'ff-' . Str::random(4)]);
        $this->catDrinks = $this->makeCategory(['name' => 'Beverages', 'slug' => 'bev-' . Str::random(4)]);
        $mk = fn (int $cat, string $name, int $price) => $this->makeProduct($cat, ['name' => $name, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => $price]);
        $this->bbq = $mk($this->catBbq, 'Chicken Tikka', 300);
        $this->burger = $mk($this->catFast, 'Zinger Burger', 400);
        $this->drink = $mk($this->catDrinks, 'Mint Margarita', 150);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->voidReasonId = (int) DB::connection('tenant')->table('void_reasons')->insertGetId([
            'name' => 'Guest changed mind', 'reason_type' => 'cancel', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->bbq, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->burger, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->drink, 'product_variant_id' => null, 'quantity' => 50],
        ]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->grantEdgePermission($this->userId, 'tenant.pos.void-kot-item');
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->atCounter($this->terminalA);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** Select a counter (and open its shift once) — the operator's CURRENT terminal. */
    private function atCounter(int $terminalId): void
    {
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $terminalId])->assertOk();
        if (! DB::connection('tenant')->table('shifts')->where('terminal_id', $terminalId)->where('status', 'open')->exists()) {
            $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        }
    }

    private function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $this->assertNotSame(9100, $port);

        return $port;
    }

    private function networkPrinter(string $name, string $role, int $port, bool $reminder = false): int
    {
        return $this->makePrinter(['name' => $name, 'code' => 'W5-' . Str::random(6), 'branch_id' => $this->branchId, 'printer_type' => 'network',
            'print_role' => $role, 'ip_address' => '127.0.0.1', 'port' => $port, 'supports_reminder' => $reminder ? 1 : 0]);
    }

    private function map(?int $categoryId, int $printerId, string $role = 'kot', ?int $terminalId = null, bool $askOnAddition = false): void
    {
        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'terminal_id' => $terminalId, 'category_id' => $categoryId, 'printer_id' => $printerId,
            'print_role' => $role, 'order_type' => 'all', 'reminder_confirm_on_addition' => $askOnAddition ? 1 : 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Start the PHP FakePrinter (REAL separate process) on $port; returns [proc, pipes, file]. */
    private function startFakePrinter(int $port, int $maxConnections = 1): array
    {
        $out = sys_get_temp_dir() . '/edge_w5_fake_printer_' . Str::random(8) . '.bin';
        @unlink($out);
        @unlink($out . '.ready');
        $proc = proc_open([PHP_BINARY, base_path('tests/MySql/Support/fake_printer.php'), (string) $port, $out, (string) $maxConnections, '25'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $deadline = microtime(true) + 10;
        while (! is_file($out . '.ready')) {
            if (microtime(true) > $deadline) {
                $this->fail('FakePrinter did not start listening');
            }
            usleep(20000);
        }

        return ['proc' => $proc, 'pipes' => $pipes, 'file' => $out];
    }

    private function fakePrinterBytes(array $fp): string
    {
        stream_get_contents($fp['pipes'][1]);
        fclose($fp['pipes'][1]);
        fclose($fp['pipes'][2]);
        proc_close($fp['proc']);

        return is_file($fp['file']) ? (string) file_get_contents($fp['file']) : '';
    }

    private function holdDineIn(array $lines): array
    {
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId, 'lines' => $lines])->assertStatus(201);

        return [(int) $hold->json('sale_id'), (int) $sessionId, $hold->json('lines')];
    }

    private function jobsOf(int $saleId, string $type): \Illuminate\Support\Collection
    {
        return PrintJob::on('tenant')->where('reference_type', 'sales_order')->where('reference_id', $saleId)->where('document_type', $type)->orderBy('id')->get();
    }

    // ───────────────────────────── D-01 / D-02 / D-03 ─────────────────────────────

    public function test_three_station_kot_routing_from_another_counter_additions_and_duplicates_delivered_by_the_edge_worker(): void
    {
        $pBbq = $this->freePort();
        $pFast = $this->freePort();
        $pCounterB = $this->freePort();
        $bbqPrinter = $this->networkPrinter('BBQ Station', 'kot', $pBbq);
        $fastPrinter = $this->networkPrinter('Fastfood Station', 'kot', $pFast);
        $counterAPrinter = $this->networkPrinter('Counter A KOT', 'kot', $this->freePort());
        $counterBPrinter = $this->networkPrinter('Counter B KOT', 'kot', $pCounterB);
        $this->map($this->catBbq, $bbqPrinter);                       // station rules (all terminals)
        $this->map($this->catFast, $fastPrinter);
        $this->map($this->catDrinks, $counterAPrinter, 'kot', $this->terminalA); // counter category, terminal-pinned (Kashif shape)
        $this->map($this->catDrinks, $counterBPrinter, 'kot', $this->terminalB);

        // The order is punched at Counter A …
        [$saleId, $sessionId, $lines] = $this->holdDineIn([
            ['product_id' => $this->bbq, 'quantity' => 2], ['product_id' => $this->burger, 'quantity' => 1], ['product_id' => $this->drink, 'quantity' => 1],
        ]);
        // … and its KOT is sent from Counter B (recalled there): RECALL-REPRINT-TERMINAL parity.
        $this->atCounter($this->terminalB);
        $kot = $this->postJson("/edge/local/pos/sales/{$saleId}/kot")->assertStatus(201);
        $jobs = collect($kot->json('jobs'));
        $this->assertCount(3, $jobs, 'one ticket per category per printer');
        $byPrinter = $jobs->keyBy('printer_name');
        $this->assertEqualsCanonicalizing(['BBQ Station', 'Fastfood Station', 'Counter B KOT'], $byPrinter->keys()->all(), 'station categories keep their stations; the counter category prints at the SENDING counter (B), never A');
        $jobs->each(fn ($j) => $this->assertSame($this->terminalB, (int) $j['terminal_id']));
        $this->assertSame('normal', $byPrinter['BBQ Station']['event_type']);
        $this->assertSame($this->terminalA, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('terminal_id'), 'the order keeps its own counter');
        $this->assertSame(['BBQ', 'Fastfood', 'Beverages'], $jobs->sortBy('id')->map(fn ($j) => PrintJob::on('tenant')->find($j['id'])->payload['kot_category'])->values()->all());
        // network routes are marked sent at queue time (shared markKotLinesQueued).
        $this->assertSame(0, DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->whereColumn('kot_sent_quantity', '<', 'quantity')->count());
        $this->assertSame([], $kot->json('reminder.auto_jobs'), 'no Reminder printer mapped → no Reminder slip');
        $this->assertSame([], $kot->json('reminder.ask_printers'));

        // The EDGE PRINT WORKER delivers each ticket to its own printer with the EXACT stored bytes.
        $fps = ['BBQ Station' => $this->startFakePrinter($pBbq), 'Fastfood Station' => $this->startFakePrinter($pFast), 'Counter B KOT' => $this->startFakePrinter($pCounterB)];
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        }
        foreach ($fps as $printerName => $fp) {
            $row = PrintJob::on('tenant')->find((int) $byPrinter[$printerName]['id']);
            $this->assertSame((string) $row->raw_payload . "\n\n\n", $this->fakePrinterBytes($fp), "{$printerName} received exactly its own ticket");
            $this->assertSame('printed', $row->fresh()->print_status);
        }
        $this->assertStringContainsString('CHICKEN TIKKA', strtoupper((string) PrintJob::on('tenant')->find((int) $byPrinter['BBQ Station']['id'])->raw_payload));
        $this->assertStringNotContainsString('ZINGER', strtoupper((string) PrintJob::on('tenant')->find((int) $byPrinter['BBQ Station']['id'])->raw_payload), 'a station ticket carries only its own category');

        // D-02 — Add Round at A: only the delta goes, as ADDITION KOT #2, to the BBQ station only.
        $this->atCounter($this->terminalA);
        $line = collect($lines)->firstWhere('product_id', $this->bbq);
        $current = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->get()->keyBy('product_id');
        $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [
                ['sales_order_line_id' => $current[$this->bbq]->id, 'product_id' => $this->bbq, 'quantity' => 3],
                ['sales_order_line_id' => $current[$this->burger]->id, 'product_id' => $this->burger, 'quantity' => 1],
                ['sales_order_line_id' => $current[$this->drink]->id, 'product_id' => $this->drink, 'quantity' => 1],
            ],
        ])->assertOk();
        $round2 = $this->postJson("/edge/local/pos/sales/{$saleId}/kot")->assertStatus(201);
        $this->assertCount(1, $round2->json('jobs'));
        $this->assertSame('BBQ Station', $round2->json('jobs.0.printer_name'));
        $this->assertSame('addition', $round2->json('jobs.0.event_type'));
        $this->assertSame([1.0], array_values(array_map('floatval', $round2->json('jobs.0.line_quantities'))), 'only the added unit');
        $add = PrintJob::on('tenant')->find((int) $round2->json('jobs.0.id'));
        $this->assertSame(2, (int) $add->payload['kot_sequence_no']);
        $this->assertStringContainsString('ADDITION KOT #2', (string) $add->raw_payload);
        $this->postJson("/edge/local/pos/sales/{$saleId}/kot")->assertOk()->assertJsonPath('message', 'No new items to send to kitchen');

        // D-03 — reprint = DUPLICATE KOT of every line, copy 1 then 2 per destination, no bookkeeping change.
        $dup1 = collect($this->postJson("/edge/local/pos/sales/{$saleId}/kot", ['reprint' => true])->assertStatus(201)->json('jobs'));
        $dup2 = collect($this->postJson("/edge/local/pos/sales/{$saleId}/kot", ['reprint' => true])->assertStatus(201)->json('jobs'));
        $this->assertSame(['duplicate'], $dup1->pluck('event_type')->unique()->values()->all());
        $this->assertSame([1], $dup1->pluck('copy_no')->unique()->values()->all());
        $this->assertSame([2], $dup2->pluck('copy_no')->unique()->values()->all());
        $this->assertSame('Counter A KOT', $dup1->firstWhere('printer_name', 'Counter A KOT')['printer_name'] ?? null, 'a duplicate from Counter A prints the counter category at A');
        $dupRow = PrintJob::on('tenant')->find((int) $dup2->firstWhere('printer_name', 'BBQ Station')['id']);
        $this->assertStringContainsString('DUPLICATE KOT #2', (string) $dupRow->raw_payload);
        $this->assertStringContainsString('DUPLICATE 2', (string) $dupRow->raw_payload);
        $this->assertSame(2, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->count(), 'duplicates never create a kitchen round');
    }

    // ───────────────────────────── D-04 Reminder ─────────────────────────────

    public function test_reminder_auto_round_delivered_ask_on_addition_confirm_decline_and_duplicate_reprint(): void
    {
        $rPort = $this->freePort();
        $reminderPrinter = $this->networkPrinter('Punching Counter', 'receipt', $rPort, true); // not a KOT/default-KOT printer
        $this->map(null, $reminderPrinter, 'reminder', null, true); // all categories, Ask on addition
        // No KOT printer → the kitchen ticket is a Print Here (browser) job; only the Reminder is a network job.

        [$saleId, $sessionId] = $this->holdDineIn([['product_id' => $this->bbq, 'quantity' => 1]]);
        $r1 = $this->postJson("/edge/local/pos/sales/{$saleId}/kot")->assertStatus(201);
        $this->assertTrue((bool) $r1->json('jobs.0.fallback'));
        $this->assertSame(1, $r1->json('reminder.revision'));
        $this->assertCount(1, $r1->json('reminder.auto_jobs'), 'round 1 Reminder is automatic');
        $this->assertSame([], $r1->json('reminder.ask_printers'));
        $reminder1 = PrintJob::on('tenant')->find((int) $r1->json('reminder.auto_jobs.0.id'));
        $this->assertSame('reminder', $reminder1->document_type);
        $this->assertSame($reminderPrinter, (int) $reminder1->printer_id);
        $this->assertStringContainsString('REMINDER', (string) $reminder1->raw_payload);

        // The Edge worker delivers the Reminder slip (document_type reminder) with its exact stored bytes.
        $fp = $this->startFakePrinter($rPort);
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $this->assertSame((string) $reminder1->raw_payload . "\n\n\n", $this->fakePrinterBytes($fp));
        $this->assertSame('printed', $reminder1->fresh()->print_status);
        $this->assertSame(EdgeLocalPrintDelivery::STATE_DELIVERED, EdgeLocalPrintDelivery::where('print_job_id', $reminder1->id)->value('delivery_state'));

        // Print Here of the browser KOT → the operator confirms → sent bookkeeping (Online markPrinted).
        $kotJobId = (int) $r1->json('jobs.0.id');
        $this->get("/edge/local/pos/print-jobs/{$kotJobId}/document")->assertOk()->assertSee('CHICKEN TIKKA');
        $this->assertSame(0.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('kot_sent_quantity'), 'a browser ticket is not "sent" before it printed');
        $this->postJson("/edge/local/pos/print-jobs/{$kotJobId}/printed")->assertOk();
        $this->assertSame(1.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('kot_sent_quantity'));

        // Round 2 → the Ask-on-addition printer needs the operator's Yes.
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->first();
        $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['sales_order_line_id' => $line->id, 'product_id' => $this->bbq, 'quantity' => 1], ['product_id' => $this->drink, 'quantity' => 1]]])->assertOk();
        $r2 = $this->postJson("/edge/local/pos/sales/{$saleId}/kot")->assertStatus(201);
        $this->assertSame(2, $r2->json('reminder.revision'));
        $this->assertSame([], $r2->json('reminder.auto_jobs'));
        $this->assertSame([['id' => $reminderPrinter, 'name' => 'Punching Counter']], $r2->json('reminder.ask_printers'));
        $token = (string) $r2->json('reminder.confirmation_token');
        $this->assertNotSame('', $token);
        $this->postJson("/edge/local/pos/sales/{$saleId}/reminders/confirm", ['confirmation_token' => 'forged'])->assertStatus(422);
        $yes = $this->postJson("/edge/local/pos/sales/{$saleId}/reminders/confirm", ['confirmation_token' => $token, 'decision' => 'confirm'])->assertOk();
        $this->assertCount(1, $yes->json('jobs'));
        $this->assertSame(2, $yes->json('jobs.0.revision'));
        $this->assertStringContainsString('UPDATED ORDER', (string) PrintJob::on('tenant')->find((int) $yes->json('jobs.0.id'))->raw_payload);
        $this->assertStringContainsString('REVISION 2', (string) PrintJob::on('tenant')->find((int) $yes->json('jobs.0.id'))->raw_payload);
        // the same token again is idempotent (shared logical key) — never a second slip.
        $this->postJson("/edge/local/pos/sales/{$saleId}/reminders/confirm", ['confirmation_token' => $token])->assertOk();
        $this->assertSame(2, $this->jobsOf($saleId, 'reminder')->count());

        // Round 3 → No.
        $this->postJson("/edge/local/pos/print-jobs/" . (int) $r2->json('jobs.0.id') . "/printed")->assertOk();
        $cur = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->get()->keyBy('product_id');
        $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['sales_order_line_id' => $cur[$this->bbq]->id, 'product_id' => $this->bbq, 'quantity' => 2], ['sales_order_line_id' => $cur[$this->drink]->id, 'product_id' => $this->drink, 'quantity' => 1]]])->assertOk();
        $r3 = $this->postJson("/edge/local/pos/sales/{$saleId}/kot")->assertStatus(201);
        $this->postJson("/edge/local/pos/sales/{$saleId}/reminders/confirm", ['confirmation_token' => $r3->json('reminder.confirmation_token'), 'decision' => 'decline'])
            ->assertOk()->assertJsonPath('declined', true)->assertJsonPath('jobs', []);
        $this->assertSame(2, $this->jobsOf($saleId, 'reminder')->count(), 'declined → no slip');

        // Reminder reprint → DUPLICATE 1, then 2, of the round-1 slip; a browser job cannot be reminder-reprinted.
        $copy = $this->postJson("/edge/local/pos/print-jobs/{$reminder1->id}/reminder-reprint")->assertStatus(201);
        $this->assertSame(1, $copy->json('copy_no'), 'first duplicate of this slip (shared reminder-copy numbering)');
        $this->assertTrue($copy->json('is_reprint'));
        $this->assertStringContainsString('DUPLICATE 1', (string) PrintJob::on('tenant')->find((int) $copy->json('id'))->raw_payload);
        $this->assertSame(2, $this->postJson("/edge/local/pos/print-jobs/{$reminder1->id}/reminder-reprint")->assertStatus(201)->json('copy_no'));
        $this->postJson("/edge/local/pos/print-jobs/{$kotJobId}/reminder-reprint")->assertStatus(422);
        $this->get("/edge/local/pos/print-jobs/{$reminder1->id}/document")->assertOk()->assertSee('REMINDER');

        // D-12 per-sale list: every job of the sale with the Online fields.
        $list = collect($this->getJson("/edge/local/pos/print-jobs?sale_id={$saleId}")->assertOk()->json('jobs'));
        $rem = $list->where('document_type', 'reminder')->sortBy('id')->values();
        $this->assertSame([1, 2, 1, 1], $rem->pluck('revision')->all());
        $this->assertSame([1, 1, 1, 2], $rem->pluck('copy_no')->all());
        $this->assertSame([false, false, true, true], $rem->pluck('is_reprint')->all());
        $this->assertSame(1, $list->firstWhere('id', $kotJobId)['line_count']);
    }

    // ───────────────────────────── D-05 / D-06 cancellation ─────────────────────────────

    public function test_whole_order_cancel_and_line_void_print_cancel_kot_and_correction_reminder_at_the_acting_counter(): void
    {
        $counterA = $this->networkPrinter('Counter A', 'both', $this->freePort(), true);
        $counterB = $this->networkPrinter('Counter B', 'both', $this->freePort(), true);
        foreach ([[$this->terminalA, $counterA], [$this->terminalB, $counterB]] as [$t, $p]) {
            $this->map($this->catDrinks, $p, 'kot', $t);
            $this->map($this->catDrinks, $p, 'reminder', $t);
        }

        // D-06 — a line void (reduce below sent) recorded by the revise; the correction Reminder follows at the voiding counter.
        [$saleId, $sessionId] = $this->holdDineIn([['product_id' => $this->drink, 'quantity' => 2]]);
        $this->postJson("/edge/local/pos/sales/{$saleId}/kot")->assertStatus(201);
        $this->atCounter($this->terminalB);
        $maxBatch = (int) DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->max('id');
        $line = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->first();
        $this->postJson('/edge/local/pos/held-sales', ['held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['sales_order_line_id' => $line->id, 'product_id' => $this->drink, 'quantity' => 1]],
            'void_items' => [['old_line_id' => $line->id, 'quantity' => 1, 'reason_id' => $this->voidReasonId]]])->assertOk();
        $cancelKot = $this->jobsOf($saleId, 'kot')->filter(fn ($j) => ($j->payload['kot_event_type'] ?? null) === 'cancel')->values();
        $this->assertCount(1, $cancelKot, 'the line void queued ONE CANCEL KOT (shared KotCancellationService)');
        $this->assertStringContainsString('CANCEL KOT #2', (string) $cancelKot[0]->raw_payload);

        $sale = SalesOrder::on('tenant')->findOrFail($saleId);
        $reminders = app(EdgeLocalPrintKotService::class)->queueLineVoidCorrectionReminders($sale, $this->terminalB, $maxBatch);
        $this->assertCount(1, $reminders, 'ONE correction slip, at the counter that voided');
        $this->assertSame($counterB, (int) $reminders[0]->printer_id);
        $this->assertSame($this->terminalB, (int) $reminders[0]->terminal_id);
        $this->assertSame('cancelled_updated_order', $reminders[0]->payload['event_type']);
        $this->assertStringContainsString('CANCELLED / UPDATED ORDER', (string) $reminders[0]->raw_payload);
        // idempotent: a repeated call (retry) never prints a second slip.
        app(EdgeLocalPrintKotService::class)->queueLineVoidCorrectionReminders($sale, $this->terminalB, $maxBatch);
        app(EdgeLocalPrintKotService::class)->queueLineVoidCorrectionReminders($sale, $this->terminalB);
        $this->assertSame(1, $this->jobsOf($saleId, 'reminder')->where('payload.event_type', 'cancelled_updated_order')->count());

        // D-05 — whole-order cancel from Counter B: CANCEL KOT on B's network printer + the cancellation Reminder at B.
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/cancel", ['reason_id' => $this->voidReasonId])->assertOk()->assertJsonPath('status', 'cancelled');
        $cancelJobs = $this->jobsOf($saleId, 'kot')->filter(fn ($j) => ($j->payload['kot_event_type'] ?? null) === 'cancel')->values();
        $this->assertCount(2, $cancelJobs);
        $wholeCancel = $cancelJobs->last();
        $this->assertSame($counterB, (int) $wholeCancel->printer_id, 'CANCEL KOT prints on the cancelling counter\'s network printer');
        $this->assertSame($this->terminalB, (int) $wholeCancel->terminal_id);
        $this->assertStringContainsString('CANCEL KOT #3', (string) $wholeCancel->raw_payload);
        $cancelled = $this->jobsOf($saleId, 'reminder')->where('payload.event_type', 'cancelled_order')->values();
        $this->assertCount(1, $cancelled);
        $this->assertSame($counterB, (int) $cancelled[0]->printer_id);
        $this->assertSame($this->terminalA, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('terminal_id'), 'the order keeps its counter');
    }

    // ───────────────────────────── D-08 Direct Pay ─────────────────────────────

    public function test_direct_pay_intents_print_the_kot_and_receipt_once_through_the_shared_orchestrator(): void
    {
        $kitchen = $this->networkPrinter('Kitchen', 'kot', $this->freePort());
        $receipt = $this->networkPrinter('Receipt A', 'receipt', $this->freePort());
        $this->map(null, $kitchen);
        DB::connection('tenant')->table('terminal_printer_settings')->insert(['terminal_id' => $this->terminalA, 'receipt_printer_id' => $receipt, 'kot_printer_id' => null,
            'auto_print_receipt' => 1, 'auto_print_kot' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $paid = $this->postJson('/edge/local/pos/sales', ['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->burger, 'quantity' => 2]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 800, 'tendered_amount' => 1000]]])->assertStatus(201);
        $saleId = (int) $paid->json('sale_id');
        $sale = SalesOrder::on('tenant')->findOrFail($saleId);
        $svc = app(EdgeLocalPrintDirectPayService::class);

        $this->assertNull($svc->afterPaidSale($sale, null, null), 'no intent pair → no orchestration (Online)');
        $printing = $svc->afterPaidSale($sale, 'print', 'print');
        $this->assertSame('queued', $printing['state']['kot_status']);
        $this->assertSame('queued', $printing['state']['receipt_status']);
        $this->assertFalse($printing['retry_available']);
        $this->assertCount(1, $printing['kot_jobs']);
        $this->assertStringEndsWith('/edge/local/pos/print-jobs/' . $printing['kot_jobs'][0]['job_id'] . '/document', $printing['kot_jobs'][0]['preview_url']);
        $kotJob = PrintJob::on('tenant')->find((int) $printing['kot_jobs'][0]['job_id']);
        $this->assertSame($kitchen, (int) $kotJob->printer_id);
        $this->assertSame($receipt, (int) PrintJob::on('tenant')->find((int) $printing['receipt']['job_id'])->printer_id);
        $this->assertStringContainsString('ZINGER BURGER', strtoupper((string) $kotJob->raw_payload));

        // Replay / retry: the stored jobs are reused — never a second KOT or bill.
        $again = $svc->afterPaidSale($sale, 'print', 'print');
        $this->assertSame($printing['kot_jobs'][0]['job_id'], $again['kot_jobs'][0]['job_id']);
        $this->assertSame($printing['receipt']['job_id'], $again['receipt']['job_id']);
        $retry = $this->postJson("/edge/local/pos/sales/{$saleId}/printing/retry")->assertOk();
        $this->assertSame($printing['kot_jobs'][0]['job_id'], $retry->json('printing.kot_jobs.0.job_id'));
        $this->assertSame(1, $this->jobsOf($saleId, 'kot')->count());
        $this->assertSame(1, $this->jobsOf($saleId, 'receipt')->count());
        // the page's own ensure-once receipt after payment returns the SAME bill.
        $this->postJson("/edge/local/pos/sales/{$saleId}/receipt")->assertStatus(201)->assertJsonPath('id', (int) $printing['receipt']['job_id']);

        // "skip" intents print nothing; a sale without intents cannot be retried (Online message).
        $skipSale = SalesOrder::on('tenant')->findOrFail((int) $this->postJson('/edge/local/pos/sales', ['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->drink, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 150, 'tendered_amount' => 150]]])->assertStatus(201)->json('sale_id'));
        $this->postJson("/edge/local/pos/sales/{$skipSale->id}/printing/retry")->assertStatus(422)->assertJsonPath('message', 'This sale has no Direct Pay print intent.');
        $skipped = $svc->afterPaidSale($skipSale, 'skip', 'skip');
        $this->assertSame('skipped', $skipped['state']['kot_status']);
        $this->assertSame([], $skipped['kot_jobs']);
        $this->assertSame(0, $this->jobsOf($skipSale->id, 'kot')->count() + $this->jobsOf($skipSale->id, 'receipt')->count());
    }

    // ───────────────────────────── D-10 / D-24 ─────────────────────────────

    public function test_bill_preview_document_renders_the_canonical_bill_without_mutation_and_preferences_expose_terminal_auto_print(): void
    {
        $receipt = $this->networkPrinter('Receipt A', 'receipt', $this->freePort());
        DB::connection('tenant')->table('terminal_printer_settings')->insert(['terminal_id' => $this->terminalA, 'receipt_printer_id' => $receipt, 'kot_printer_id' => null,
            'auto_print_receipt' => 1, 'auto_print_kot' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $sales = DB::connection('tenant')->table('sales_orders')->count();
        $cart = $this->postJson('/edge/local/pos/bill-preview/document', ['order_type' => 'takeaway',
            'lines' => [['product_id' => $this->bbq, 'quantity' => 2], ['product_id' => $this->drink, 'quantity' => 1]]])->assertOk();
        $this->assertTrue($cart->json('ok'));
        $this->assertNull($cart->json('sale_id'));
        $html = (string) $cart->json('html');
        $this->assertStringContainsString('BILL PREVIEW', $html);
        $this->assertStringContainsString('Chicken Tikka', $html);
        $this->assertMatchesRegularExpression('/Total:<\/td>\s*<td class="bold">750(\.00)?</', $html, 'server-side prices and totals (2 × 300 + 150)');
        $this->assertSame($sales, DB::connection('tenant')->table('sales_orders')->count(), 'zero mutation');
        $this->assertSame(0, DB::connection('tenant')->table('print_jobs')->count());
        $this->postJson('/edge/local/pos/bill-preview/document', ['order_type' => 'takeaway'])->assertStatus(422);

        // A saved check → its own bill (Online per-table bill), and "Send to network" = the receipt on the counter's printer.
        [$saleId] = $this->holdDineIn([['product_id' => $this->burger, 'quantity' => 1]]);
        $saleNo = (string) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('sale_no');
        $held = $this->postJson('/edge/local/pos/bill-preview/document', ['sale_id' => $saleId])->assertOk();
        $this->assertSame($saleId, $held->json('sale_id'));
        $this->assertStringContainsString($saleNo, (string) $held->json('html'));
        $this->assertStringContainsString('BILL PREVIEW', (string) $held->json('html'));
        $net = $this->postJson("/edge/local/pos/sales/{$saleId}/receipt", ['reprint' => true])->assertStatus(201);
        $this->assertSame('Receipt A', $net->json('printer_name'));
        $this->assertFalse((bool) $net->json('fallback'));

        // D-24 — the synced terminal preferences + routed printer, per terminal, with the current terminal.
        $prefs = $this->getJson('/edge/local/pos/print-preferences')->assertOk();
        $this->assertSame($this->terminalA, $prefs->json('current_terminal_id'));
        $this->assertTrue($prefs->json('terminals.' . $this->terminalA . '.auto_print_receipt'));
        $this->assertFalse($prefs->json('terminals.' . $this->terminalA . '.auto_print_kot'));
        $this->assertSame('Receipt A', $prefs->json('terminals.' . $this->terminalA . '.receipt_printer.name'));
        $this->assertFalse($prefs->json('terminals.' . $this->terminalB . '.configured'));
        $this->assertFalse($prefs->json('terminals.' . $this->terminalB . '.auto_print_receipt'), 'no saved setting → not automatic (Online terminalAuto)');
    }

    // ───────────────────────────── D-13 / D-14 ─────────────────────────────

    public function test_retry_dismiss_and_retry_of_a_dismissed_job_over_http(): void
    {
        $printer = $this->networkPrinter('Dead Printer', 'receipt', $this->freePort()); // nothing listens → connect refused
        DB::connection('tenant')->table('terminal_printer_settings')->insert(['terminal_id' => $this->terminalA, 'receipt_printer_id' => $printer, 'kot_printer_id' => null,
            'auto_print_receipt' => 1, 'auto_print_kot' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $saleId = (int) $this->postJson('/edge/local/pos/sales', ['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->drink, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 150, 'tendered_amount' => 150]]])->assertStatus(201)->json('sale_id');
        $jobId = (int) $this->postJson("/edge/local/pos/sales/{$saleId}/receipt")->assertStatus(201)->json('id');

        // A queued job that has not failed terminally cannot be "retried".
        $this->postJson("/edge/local/pos/print-jobs/{$jobId}/retry")->assertStatus(422);

        $svc = app(EdgeLocalPrintDeliveryService::class);
        $terminalFail = function () use ($svc, $jobId) {
            for ($i = 0; $i < EdgeLocalPrintDeliveryService::MAX_FAILURES; $i++) {
                DB::connection('edge_local')->table('edge_local_print_deliveries')->where('print_job_id', $jobId)->update(['next_attempt_at' => null]);
                $claim = $svc->claimNext((string) Str::uuid());
                $this->assertSame($jobId, (int) $claim['job_id']);
                $svc->completeFailure($jobId, $claim['lease_token'], 'printer connect failed: refused');
            }
        };
        $terminalFail();
        $this->assertSame('failed', PrintJob::on('tenant')->find($jobId)->print_status);

        // D-13 — Retry (HTTP) → queued again, delivery reset.
        $this->postJson("/edge/local/pos/print-jobs/{$jobId}/retry")->assertOk()->assertJsonPath('print_status', 'queued')->assertJsonPath('status', 'queued');
        $this->assertSame(EdgeLocalPrintDelivery::STATE_WAITING, EdgeLocalPrintDelivery::where('print_job_id', $jobId)->value('delivery_state'));

        // D-14 — a job being written to the printer RIGHT NOW (live lease) cannot be dismissed.
        $claim = $svc->claimNext((string) Str::uuid());
        $this->postJson("/edge/local/pos/print-jobs/{$jobId}/dismiss")->assertStatus(422);
        $svc->completeFailure($jobId, $claim['lease_token'], 'printer connect failed: refused');

        // Dismiss a queued job: cancelled, no counters, never claimed again.
        $this->postJson("/edge/local/pos/print-jobs/{$jobId}/dismiss", ['reason' => 'Guest left'])->assertOk()->assertJsonPath('print_status', 'cancelled');
        $row = PrintJob::on('tenant')->find($jobId);
        $this->assertSame('Guest left', $row->error_message);
        $this->assertNull($row->printed_at);
        $this->assertSame(0, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('receipt_print_count'));
        DB::connection('edge_local')->table('edge_local_print_deliveries')->where('print_job_id', $jobId)->update(['next_attempt_at' => null]);
        $this->assertNull($svc->claimNext((string) Str::uuid()), 'a dismissed job is never delivered');
        $this->postJson("/edge/local/pos/print-jobs/{$jobId}/dismiss")->assertStatus(422); // already cancelled

        // Retry of a dismissed job (Online requeueFailed accepts cancelled) → queued, claimable again.
        $this->postJson("/edge/local/pos/print-jobs/{$jobId}/retry")->assertOk()->assertJsonPath('print_status', 'queued');
        $this->assertSame($jobId, (int) $svc->claimNext((string) Str::uuid())['job_id']);

        // A printed job can never be dismissed (shared rule).
        $fallbackId = (int) $this->postJson("/edge/local/pos/sales/{$saleId}/kot-reprint")->assertStatus(201)->json('jobs.0.id');
        $this->postJson("/edge/local/pos/print-jobs/{$fallbackId}/printed")->assertOk();
        $this->postJson("/edge/local/pos/print-jobs/{$fallbackId}/dismiss")->assertStatus(422);

        // A report job has no browser document (D-12) — a business 404, never the renderer's crash.
        $report = $this->makePrintJob(null, ['document_type' => 'report', 'print_status' => 'queued', 'printed_at' => null, 'branch_id' => $this->branchId, 'terminal_id' => $this->terminalA]);
        $this->get("/edge/local/pos/print-jobs/{$report}/document")->assertNotFound();
    }

    // ───────────────────────────── D-23 data scope ─────────────────────────────

    public function test_print_endpoints_follow_the_operator_data_scope(): void
    {
        // A sale rung on Counter B, with a job.
        $foreign = $this->makeSale($this->branchId, ['status' => 'paid', 'terminal_id' => $this->terminalB, 'order_type' => 'takeaway', 'sale_no' => 'SO-B-1']);
        $this->makeSaleLine($foreign, $this->drink, ['product_name' => 'Mint Margarita', 'quantity' => 1]);
        $job = $this->makePrintJob(null, ['document_type' => 'receipt', 'print_status' => 'queued', 'printed_at' => null, 'branch_id' => $this->branchId,
            'terminal_id' => $this->terminalB, 'reference_type' => 'sales_order', 'reference_id' => $foreign, 'reference_no' => 'SO-B-1']);

        // Unscoped operator: allowed.
        $this->postJson("/edge/local/pos/sales/{$foreign}/receipt", ['reprint' => true])->assertStatus(201);
        $this->get("/edge/local/pos/print-jobs/{$job}/document")->assertOk();

        // The operator is restricted to Counter A (terminal assignment) — Online deniesSale → 403 on every print action.
        DB::connection('tenant')->table('terminal_user')->insert(['terminal_id' => $this->terminalA, 'user_id' => $this->userId, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        auth('tenant')->user()->unsetRelation('terminals');
        $this->postJson("/edge/local/pos/sales/{$foreign}/receipt", ['reprint' => true])->assertStatus(403);
        $this->postJson("/edge/local/pos/sales/{$foreign}/kot-reprint")->assertStatus(403);
        $this->postJson("/edge/local/pos/sales/{$foreign}/kot")->assertStatus(403);
        $this->getJson("/edge/local/pos/print-jobs?sale_id={$foreign}")->assertStatus(403);
        $this->get("/edge/local/pos/print-jobs/{$job}/document")->assertStatus(403);
        $this->postJson("/edge/local/pos/print-jobs/{$job}/printed")->assertStatus(403);
        $this->postJson("/edge/local/pos/print-jobs/{$job}/dismiss")->assertStatus(403);
        $this->postJson("/edge/local/pos/print-jobs/{$job}/retry")->assertStatus(403);
        $this->postJson("/edge/local/pos/print-jobs/{$job}/reminder-reprint")->assertStatus(403);
        $this->postJson("/edge/local/pos/sales/{$foreign}/printing/retry")->assertStatus(403);
        $this->postJson('/edge/local/pos/bill-preview/document', ['sale_id' => $foreign])->assertStatus(403);
        $this->assertNotContains($job, collect($this->getJson('/edge/local/pos/print-jobs')->assertOk()->json('jobs'))->pluck('id')->all(), 'Recent Prints hides out-of-scope jobs');
    }

    // ───────────────────────────── page ─────────────────────────────

    public function test_the_cashier_page_carries_the_w5_printing_controls(): void
    {
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        foreach (['id="print-pref-panel"', 'id="auto-kot-toggle"', 'id="auto-receipt-toggle"', 'id="kot-status-hint"', 'id="receipt-status-hint"', 'id="print-terminal-label"',
            'id="printHereModal"', 'id="print-here-frame"', 'id="print-here-print-btn"', 'id="lastPrintModal"', 'id="last-print-sale-no"', 'id="last-print-modal-body"',
            'id="reprint-all-kot-btn"', 'id="reprint-receipt-btn"', 'id="send-network-receipt-btn"', 'id="print-bill-preview-btn"', 'id="bill-preview-frame"',
            'function printPrefsHtml()', 'function readPrintPrefs()', 'async function printBillPreview(payload)', 'function openPrintHere(job)',
            'async function openLastPrint(saleId', 'async function openKotReminder(saleId)', "'/print-preferences'", "'/reminders/confirm'"] as $needle) {
            $this->assertStringContainsString($needle, $html, "W5 control/interface missing: {$needle}");
        }
    }
}
