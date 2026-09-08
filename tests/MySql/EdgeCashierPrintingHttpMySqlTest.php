<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPrintDeliveryService;
use App\Services\Printing\EscPosPayloadService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-CASHIER-UI-4 — printing parity through the endpoints the browser cashier page calls, over REAL HTTP
 * on a branch_server-booted app, on the SHARED PrintJobService/PrintRoutingService/EscPos/renderer:
 *  - receipt after payment is ensure-once (a retry never duplicates the bill); Reprint makes a fresh job;
 *  - no printer → Print Here fallback: the canonical document renders for the browser, the operator marks it printed;
 *  - RECALL-REPRINT-TERMINAL: a reprint from another counter routes to THAT counter's receipt printer, the
 *    job carries the current terminal, the sale keeps its original terminal; the Edge print authority
 *    (EdgeLocalPrintDeliveryService) claims the network job with the EXACT stored bytes;
 *  - KOT-REPRINT-BLANK-1: after Add Round churns the lines, the ORIGINAL ticket's roll bytes still come from
 *    the stored copy (line_snapshots) — never blank; a KOT reprint is a duplicate event that renders.
 */
class EdgeCashierPrintingHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;
    private int $tableId;
    private int $productP;
    private int $productQ;
    private int $cashMethodId;
    private int $baselineId;

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
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs',
            'category_printer_mappings', 'terminal_printer_settings', 'printers',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'PRT' . Str::random(4)]);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Counter B']);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available']);
        $categoryId = $this->makeCategory(['name' => 'Karahi']);
        $this->productP = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->productQ = $this->makeProduct($categoryId, ['name' => 'Roghni Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->baselineId = (int) $this->acceptTestBaseline([
            ['product_id' => $this->productP, 'product_variant_id' => null, 'quantity' => 20],
            ['product_id' => $this->productQ, 'product_variant_id' => null, 'quantity' => 20],
        ])->id;
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function paidTakeaway(): array
    {
        $sale = $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productP, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100, 'tendered_amount' => 100]],
        ])->assertStatus(201);

        return [(int) $sale->json('sale_id'), (string) $sale->json('sale_no')];
    }

    private function networkReceiptPrinter(string $name, int $port): int
    {
        return $this->makePrinter(['name' => $name, 'branch_id' => $this->branchId, 'printer_type' => 'network', 'print_role' => 'receipt', 'ip_address' => '127.0.0.1', 'port' => $port]);
    }

    public function test_receipt_is_ensure_once_reprint_is_fresh_and_print_here_fallback_completes(): void
    {
        [$saleId, $saleNo] = $this->paidTakeaway();

        // Auto receipt after payment: no printer configured → Print Here fallback (browser document).
        $first = $this->postJson("/edge/local/pos/sales/{$saleId}/receipt")->assertStatus(201);
        $this->assertTrue((bool) $first->json('fallback'));
        $this->assertSame('browser', $first->json('printer_type'));
        $this->assertSame('receipt', $first->json('document_type'));
        $jobId = (int) $first->json('id');
        $this->assertStringEndsWith("/edge/local/pos/print-jobs/{$jobId}/document", $first->json('preview_url'));

        // ENSURE-ONCE: a retry of the auto receipt returns the SAME job — never a duplicate bill.
        $this->postJson("/edge/local/pos/sales/{$saleId}/receipt")->assertStatus(201)->assertJsonPath('id', $jobId);
        $this->assertSame(1, PrintJob::on('tenant')->where('reference_id', $saleId)->where('document_type', 'receipt')->count());

        // REPRINT: a fresh job.
        $reprintId = (int) $this->postJson("/edge/local/pos/sales/{$saleId}/receipt", ['reprint' => true])->assertStatus(201)->json('id');
        $this->assertNotSame($jobId, $reprintId);

        // Recent Prints lists both, newest first, with the Print Here document link.
        $list = $this->getJson("/edge/local/pos/print-jobs?sale_id={$saleId}")->assertOk();
        $this->assertCount(2, $list->json('jobs'));
        $this->assertSame($reprintId, (int) $list->json('jobs.0.id'));
        $this->assertGreaterThanOrEqual(2, count($this->getJson('/edge/local/pos/print-jobs')->assertOk()->json('jobs')));

        // PRINT HERE: the canonical receipt document renders for the browser, carrying the bill.
        $this->get("/edge/local/pos/print-jobs/{$jobId}/document")->assertOk()->assertSee($saleNo)->assertSee('Chicken Karahi');

        // The operator confirms the browser print → printed, and the sale's receipt counter advances.
        $this->postJson("/edge/local/pos/print-jobs/{$jobId}/printed")->assertOk()->assertJsonPath('print_status', 'printed');
        $this->assertSame(1, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('receipt_print_count'));

        // Fails closed for an unauthenticated browser.
        auth('tenant')->logout();
        $this->flushSession();
        $this->get("/edge/local/pos/print-jobs/{$jobId}/document")->assertStatus(302);
    }

    public function test_recall_reprint_routes_to_the_current_counter_printer_and_edge_delivery_claims_the_stored_bytes(): void
    {
        $printerA = $this->networkReceiptPrinter('Receipt A', 9101);
        $printerB = $this->networkReceiptPrinter('Receipt B', 9102);
        foreach ([[$this->terminalA, $printerA], [$this->terminalB, $printerB]] as [$terminalId, $printerId]) {
            DB::connection('tenant')->table('terminal_printer_settings')->insert([
                'terminal_id' => $terminalId, 'receipt_printer_id' => $printerId, 'kot_printer_id' => null,
                'auto_print_receipt' => 1, 'auto_print_kot' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // The sale is rung on Counter A …
        [$saleId] = $this->paidTakeaway();

        // … and reprinted from Counter B (recall at another counter).
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalB])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        $job = $this->postJson("/edge/local/pos/sales/{$saleId}/receipt", ['reprint' => true])->assertStatus(201);

        // RECALL-REPRINT-TERMINAL: routed to B's receipt printer, stamped with the current counter; the sale keeps A.
        $this->assertFalse((bool) $job->json('fallback'));
        $this->assertSame('Receipt B', $job->json('printer_name'));
        $this->assertSame($this->terminalB, (int) $job->json('terminal_id'));
        $this->assertSame($this->terminalA, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('terminal_id'), 'the order keeps its original counter');
        $row = PrintJob::on('tenant')->find((int) $job->json('id'));
        $this->assertSame($printerB, (int) $row->printer_id);
        $this->assertNotSame('', (string) $row->raw_payload, 'a network job carries its stored ESC/POS bytes');

        // The EDGE PRINT AUTHORITY claims it for B's printer with the EXACT stored bytes (per-printer isolation is certified separately).
        $claim = app(EdgeLocalPrintDeliveryService::class)->claimNext((string) Str::uuid());
        $this->assertNotNull($claim, 'the network reprint is claimable by the local print worker');
        $this->assertSame((int) $row->id, (int) $claim['job_id']);
        $this->assertSame('127.0.0.1', $claim['ip']);
        $this->assertSame(9102, (int) $claim['port']);
        $this->assertSame((string) $row->raw_payload, (string) $claim['raw_payload']);

        // A network job is never "marked printed" from the browser.
        $this->postJson("/edge/local/pos/print-jobs/{$row->id}/printed")->assertStatus(422);
    }

    public function test_kot_reprint_falls_back_to_the_stored_copy_after_line_churn_and_reprint_renders(): void
    {
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->json('session_id');
        $hold = $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productP, 'quantity' => 2]],
        ])->assertStatus(201);
        $saleId = (int) $hold->json('sale_id');
        $line1 = (int) $hold->json('lines.0.id');

        $kot1 = $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk();
        $kotJobId = (int) $kot1->json('jobs.0.id');
        $kotJob = PrintJob::on('tenant')->find($kotJobId);
        $this->assertNotEmpty($kotJob->payload['line_snapshots'] ?? [], 'a KOT job stores the copy it was sent with');

        // Print Here renders the ticket with the product names (canonical KOT renderer prints them UPPERCASE).
        $this->get("/edge/local/pos/print-jobs/{$kotJobId}/document")->assertOk()->assertSee('CHICKEN KARAHI');

        // Add Round churns the lines (delete + recreate) — the original ticket's line ids no longer exist …
        $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $saleId, 'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [
                ['sales_order_line_id' => $line1, 'product_id' => $this->productP, 'quantity' => 2],
                ['product_id' => $this->productQ, 'quantity' => 1],
            ],
        ])->assertOk();
        $this->assertFalse(DB::connection('tenant')->table('sales_order_lines')->where('id', $line1)->exists(), 'line churn happened');

        // … yet KOT-REPRINT-BLANK-1: the ORIGINAL ticket's roll bytes still come from the stored copy — never blank.
        $bytes = app(EscPosPayloadService::class)->build($kotJob->fresh());
        $this->assertTrue(stripos($bytes, 'chicken karahi') !== false, 'historical KOT reprint falls back to the stored copy (never blank)');

        // A KOT reprint (duplicate event) is routed at the current counter and renders the current ticket.
        $reprint = $this->postJson("/edge/local/pos/sales/{$saleId}/kot-reprint")->assertStatus(201);
        $this->assertNotEmpty($reprint->json('jobs'));
        $this->assertSame('duplicate', $reprint->json('jobs.0.event_type'));
        $this->assertSame($this->terminalA, (int) $reprint->json('jobs.0.terminal_id'));
        $this->get('/edge/local/pos/print-jobs/' . $reprint->json('jobs.0.id') . '/document')->assertOk()->assertSee('CHICKEN KARAHI')->assertSee('ROGHNI NAAN');
        // The duplicate never advances the sent bookkeeping (nothing new for the kitchen).
        $this->postJson("/edge/local/pos/held-sales/{$saleId}/kot")->assertOk()->assertJsonPath('batch.sequence_no', 2); // round 2 delta (Naan) is still due and goes now
    }
}
