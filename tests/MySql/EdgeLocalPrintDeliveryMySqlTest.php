<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalPrintDelivery;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeLocalPrintDeliveryService;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-PRINT-1 — the lease-safe local delivery transport, proven end-to-end on real MySQL with a
 * REAL TCP FakePrinter: exact STORED bytes delivered (payload-immutable transport — never rebuilt),
 * business events untouched by physical delivery, NULL-printer intents never claimed (§20), bounded
 * backoff with print_status staying queued until the terminal threshold (§9), explicit local retry
 * (§10), and stale-lease completions refused (§7).
 */
class EdgeLocalPrintDeliveryMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $printerPort;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_print_deliveries', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'category_printer_mappings', 'terminal_printer_settings', 'printers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'products', 'categories', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch();
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime();
        $this->printerPort = random_int(21000, 29000);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function makeNetworkPrinter(?int $port = null): int
    {
        return $this->makePrinter([
            'branch_id' => $this->branchId, 'printer_type' => 'network',
            'ip_address' => '127.0.0.1', 'port' => $port ?? $this->printerPort, 'is_active' => 1,
        ]);
    }

    /** Start the PHP FakePrinter as a REAL separate process; returns [proc, capture-file]. */
    private function startFakePrinter(int $maxConnections = 1): array
    {
        $out = sys_get_temp_dir() . '/edge_fake_printer_' . Str::random(8) . '.bin';
        @unlink($out);
        @unlink($out . '.ready');
        $proc = proc_open(
            [PHP_BINARY, base_path('tests/MySql/Support/fake_printer.php'), (string) $this->printerPort, $out, (string) $maxConnections, '25'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );
        $deadline = microtime(true) + 10;
        while (! is_file($out . '.ready')) {
            if (microtime(true) > $deadline) {
                $this->fail('FakePrinter did not start listening');
            }
            usleep(20000);
        }

        return ['proc' => $proc, 'pipes' => $pipes, 'file' => $out];
    }

    private function waitFakePrinterDone(array $fp): string
    {
        stream_get_contents($fp['pipes'][1]);
        fclose($fp['pipes'][1]);
        fclose($fp['pipes'][2]);
        proc_close($fp['proc']);

        return is_file($fp['file']) ? (string) file_get_contents($fp['file']) : '';
    }

    private function svc(): EdgeLocalPrintDeliveryService
    {
        return app(EdgeLocalPrintDeliveryService::class);
    }

    public function test_e2e_receipt_delivery_sends_exact_stored_bytes_even_after_sale_mutation(): void
    {
        $printerId = $this->makeNetworkPrinter();
        $saleId = $this->makeSale($this->branchId, ['status' => 'paid', 'sale_no' => 'SO-ORIGINAL-1']);
        $job = app(PrintJobService::class)->queueReceipt(SalesOrder::findOrFail($saleId), Printer::findOrFail($printerId));
        $stored = (string) $job->raw_payload;
        $this->assertNotSame('', $stored, 'raw_payload materialized at creation');
        $this->assertStringContainsString('SO-ORIGINAL-1', $stored);

        // mutate the sale AFTER the job exists — delivery must still send the stored payload A.
        DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->update(['sale_no' => 'SO-MUTATED-9']);

        $fp = $this->startFakePrinter();
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $captured = $this->waitFakePrinterDone($fp);

        $this->assertSame($stored . "\n\n\n", $captured, 'EXACT stored bytes + the Cloud-agent trailing feed — never a rebuilt payload');
        $this->assertStringContainsString('SO-ORIGINAL-1', $captured);
        $this->assertStringNotContainsString('SO-MUTATED-9', $captured);

        $row = DB::connection('tenant')->table('print_jobs')->where('id', $job->id)->first();
        $this->assertSame('printed', $row->print_status);
        $this->assertNotNull($row->printed_at);
        $this->assertSame(1, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('receipt_print_count'));
        $this->assertSame(EdgeLocalPrintDelivery::STATE_DELIVERED, EdgeLocalPrintDelivery::where('print_job_id', $job->id)->value('delivery_state'));
    }

    public function test_e2e_kot_delivery_completes_without_touching_the_business_event(): void
    {
        $printerId = $this->makeNetworkPrinter();
        $saleId = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'dine_in']);
        $productId = $this->makeProduct($this->makeCategory());
        $this->makeSaleLine($saleId, $productId, ['quantity' => 2, 'kot_sent_quantity' => 0, 'kot_sent' => 0]);

        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($saleId), Printer::findOrFail($printerId));
        $this->assertCount(1, $jobs);
        $this->assertSame(1, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->count());
        $batchUuid = DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->value('event_uuid');

        $fp = $this->startFakePrinter();
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $captured = $this->waitFakePrinterDone($fp);
        $this->assertSame((string) $jobs[0]->raw_payload . "\n\n\n", $captured);

        // physical completion never creates/changes a business event.
        $this->assertSame(1, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->count(), 'no new kot_batch from delivery');
        $this->assertSame($batchUuid, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->value('event_uuid'));
        $this->assertSame('printed', DB::connection('tenant')->table('print_jobs')->where('id', $jobs[0]->id)->value('print_status'));
        $this->assertSame(1, (int) DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->value('kot_print_count'));
        $this->assertSame(2.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('kot_sent_quantity'), 'sent quantity advanced exactly once');
    }

    public function test_null_printer_intents_are_never_claimed(): void
    {
        $this->makePrintJob(null, ['print_status' => 'queued', 'printed_at' => null, 'branch_id' => $this->branchId, 'raw_payload' => 'X']);
        $this->assertNull($this->svc()->claimNext((string) Str::uuid()), 'a historical browser/NULL-printer intent must stay a diagnostic, never a claim (§20)');
        $this->assertSame('queued', DB::connection('tenant')->table('print_jobs')->value('print_status'));
    }

    public function test_temporary_failures_back_off_then_terminal_fail_once_then_local_retry_delivers(): void
    {
        $printerId = $this->makeNetworkPrinter(); // nothing listens on the port yet → connect refused
        $jobId = $this->makePrintJob($printerId, ['print_status' => 'queued', 'printed_at' => null, 'attempts' => 0, 'branch_id' => $this->branchId, 'raw_payload' => "RETRY PAYLOAD\n\n\n"]);

        // attempt 1 through the REAL worker: temporary failure — job STAYS queued, Edge metadata backs off.
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $row = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $this->assertSame('queued', $row->print_status, 'temporary transport failure must NOT touch the shared status');
        $this->assertSame(0, (int) $row->attempts, 'attempts keeps its Cloud semantic (markFailed only)');
        $d = EdgeLocalPrintDelivery::where('print_job_id', $jobId)->first();
        $this->assertSame(EdgeLocalPrintDelivery::STATE_RETRY_WAIT, $d->delivery_state);
        $this->assertSame(1, $d->failure_count);
        $this->assertNotNull($d->next_attempt_at);
        $this->assertNotNull($d->last_error);

        // before next_attempt_at: NOT claimable.
        $this->assertNull($this->svc()->claimNext((string) Str::uuid()), 'no redelivery before the backoff elapses');

        // drive to the terminal threshold (time-travel the backoff; each failure re-claims + fails).
        for ($i = 2; $i <= EdgeLocalPrintDeliveryService::MAX_FAILURES; $i++) {
            EdgeLocalPrintDelivery::where('print_job_id', $jobId)->update(['next_attempt_at' => now()->subSecond()]);
            $claim = $this->svc()->claimNext((string) Str::uuid());
            $this->assertNotNull($claim, "failure $i must be claimable after backoff");
            $this->assertTrue($this->svc()->completeFailure($claim['job_id'], $claim['lease_token'], "unreachable $i"));
        }
        $row = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $this->assertSame('failed', $row->print_status, 'the terminal threshold marks the shared status failed ONCE');
        $this->assertSame(1, (int) $row->attempts, 'exactly one markFailed transition');
        $this->assertSame(EdgeLocalPrintDelivery::STATE_TERMINAL_FAILED, EdgeLocalPrintDelivery::where('print_job_id', $jobId)->value('delivery_state'));
        $this->assertNull($this->svc()->claimNext((string) Str::uuid()), 'terminal_failed is never auto-claimed');

        // explicit local retry → waiting/queued; FakePrinter online → delivered.
        $this->svc()->retryTerminalFailed($jobId);
        $this->assertSame('queued', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
        $this->assertSame(EdgeLocalPrintDelivery::STATE_WAITING, EdgeLocalPrintDelivery::where('print_job_id', $jobId)->value('delivery_state'));
        $fp = $this->startFakePrinter();
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $captured = $this->waitFakePrinterDone($fp);
        $this->assertSame("RETRY PAYLOAD\n\n\n" . "\n\n\n", $captured);
        $this->assertSame('printed', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
    }

    public function test_stale_lease_token_can_never_complete_or_fail_a_job(): void
    {
        $printerId = $this->makeNetworkPrinter();
        $jobId = $this->makePrintJob($printerId, ['print_status' => 'queued', 'printed_at' => null, 'attempts' => 0, 'branch_id' => $this->branchId, 'raw_payload' => 'S']);

        $staleClaim = $this->svc()->claimNext('worker-A');
        $this->assertNotNull($staleClaim);
        // worker A stalls; its lease expires; worker B re-claims with a NEW token.
        EdgeLocalPrintDelivery::where('print_job_id', $jobId)->update(['lease_expires_at' => now()->subSecond()]);
        $freshClaim = $this->svc()->claimNext('worker-B');
        $this->assertNotNull($freshClaim);
        $this->assertNotSame($staleClaim['lease_token'], $freshClaim['lease_token']);

        // B succeeds; then stale A reports failure — REFUSED, printed must survive.
        $this->assertTrue($this->svc()->completeSuccess($jobId, $freshClaim['lease_token']));
        $this->assertFalse($this->svc()->completeFailure($jobId, $staleClaim['lease_token'], 'stale worker error'));
        $this->assertFalse($this->svc()->completeSuccess($jobId, $staleClaim['lease_token']));
        $row = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $this->assertSame('printed', $row->print_status, 'a stale worker can NEVER overwrite the newer lease outcome');
        $this->assertNotNull($row->printed_at);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertSame(EdgeLocalPrintDelivery::STATE_DELIVERED, EdgeLocalPrintDelivery::where('print_job_id', $jobId)->value('delivery_state'));
    }

    public function test_local_print_readiness_is_truthful_and_never_an_activation_claim(): void
    {
        $report = fn () => app(\App\Services\Edge\EdgeLocalReadiness::class)->report();

        // no routable printer yet → not_configured; activation stays hard false throughout.
        $r = $report();
        $this->assertSame('not_configured', $r['local_print']);
        $this->assertFalse($r['activation_ready']);

        $this->makeNetworkPrinter();
        $this->assertSame('ready', $report()['local_print']);

        // a historical NULL-printer intent (never auto-claimed) blocks — until resolved.
        $intentId = $this->makePrintJob(null, ['print_status' => 'queued', 'printed_at' => null, 'branch_id' => $this->branchId, 'raw_payload' => 'X']);
        $this->assertSame('blocked', $report()['local_print']);
        DB::connection('tenant')->table('print_jobs')->where('id', $intentId)->delete();

        // a dangling terminal printer reference blocks too.
        $terminalId = $this->makeTerminal($this->branchId);
        DB::connection('tenant')->table('terminal_printer_settings')->insert([
            'terminal_id' => $terminalId, 'kot_printer_id' => 999999, 'auto_print_receipt' => 0, 'auto_print_kot' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('blocked', $report()['local_print']);
        DB::connection('tenant')->table('terminal_printer_settings')->where('terminal_id', $terminalId)->delete();
        $final = $report();
        $this->assertSame('ready', $final['local_print']);
        $this->assertFalse($final['activation_ready'], 'a ready print runtime NEVER implies activation');
    }

    public function test_claim_fails_closed_off_branch_server(): void
    {
        $this->resetRuntimeRole(); // cloud
        try {
            $this->svc()->claimNext((string) Str::uuid());
            $this->fail('local print delivery must only run on a Branch Server');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Branch Server', $e->getMessage());
        }
    }
}
