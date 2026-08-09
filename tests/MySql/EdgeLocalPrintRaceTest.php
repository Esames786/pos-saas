<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalPrintDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-PRINT-1 (§14) — power-loss / at-least-once transport proofs with GENUINE separate OS
 * processes (master DB nonexistent inside every worker):
 *
 *   C. two workers claim simultaneously → exactly ONE live lease token wins.
 *   A. delivery happened but the process died before markPrinted → the lease expires and a second
 *      worker redelivers the SAME stored bytes (physical duplicate is POSSIBLE BY DESIGN — printing
 *      is at-least-once) → the job ends printed, the business event unchanged.
 *   B. the dead worker's stale token can NEVER flip the completed job to failed.
 *   D. a worker that died BEFORE sending leaves nothing on the wire; after its lease expires another
 *      worker completes normally with exactly one delivery.
 */
class EdgeLocalPrintRaceTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $printerPort;
    private int $printerId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        // the reworked worker maps tenant := edge_local (the REAL appliance path) — point the
        // edge_local connection at the test tenant DB so the mapping lands on the right database.
        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
             'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
             'password' => config('database.connections.tenant.password')]
        )]);
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_print_deliveries', 'edge_local_meta', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'printers', 'sales_order_lines', 'sales_orders', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch();
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime();
        $this->printerPort = random_int(21000, 29000);
        $this->printerId = $this->makePrinter([
            'branch_id' => $this->branchId, 'printer_type' => 'network',
            'ip_address' => '127.0.0.1', 'port' => $this->printerPort, 'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function queuedJob(string $payload): int
    {
        return $this->makePrintJob($this->printerId, [
            'print_status' => 'queued', 'printed_at' => null, 'attempts' => 0,
            'branch_id' => $this->branchId, 'raw_payload' => $payload,
        ]);
    }

    private function worker(array $args, ?string $startFile = null): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('tests/MySql/Support/edge_pos_sale_worker.php')], array_map('strval', $args));
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'EDGE_TEST_TENANT_DB' => $this->tenantDb,
            'APP_ENV' => 'testing',
            'START_FILE' => $startFile ?? '',
        ]));

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function finish(array $h): string
    {
        $out = trim(stream_get_contents($h['pipes'][1]));
        $err = trim(stream_get_contents($h['pipes'][2]) ?: '');
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        proc_close($h['proc']);

        return $out !== '' ? $out : 'STDERR:' . $err;
    }

    private function runWorker(array $args): string
    {
        return $this->finish($this->worker($args));
    }

    private function startFakePrinter(int $maxConnections): array
    {
        $out = sys_get_temp_dir() . '/edge_print_race_' . Str::random(8) . '.bin';
        @unlink($out);
        @unlink($out . '.ready');
        $proc = proc_open(
            [PHP_BINARY, base_path('tests/MySql/Support/fake_printer.php'), (string) $this->printerPort, $out, (string) $maxConnections, '30'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );
        $deadline = microtime(true) + 10;
        while (! is_file($out . '.ready')) {
            if (microtime(true) > $deadline) {
                $this->fail('FakePrinter did not start');
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

    // ── C. simultaneous claim: exactly one live lease token ─────────────────────────────────────
    public function test_race_simultaneous_claim_exactly_one_lease_wins(): void
    {
        $jobId = $this->queuedJob("RACE-C\n\n\n");

        $startFile = sys_get_temp_dir() . '/edge_print_claim_' . Str::random(8) . '.start';
        @unlink($startFile);
        $a = $this->worker(['print_claim', 'worker-A'], $startFile);
        $b = $this->worker(['print_claim', 'worker-B'], $startFile);
        sleep(4);
        file_put_contents($startFile, '1');
        $outA = $this->finish($a);
        $outB = $this->finish($b);
        @unlink($startFile);

        $claims = array_filter([$outA, $outB], fn ($o) => str_starts_with($o, "OK:claim:{$jobId}:"));
        $nones = array_filter([$outA, $outB], fn ($o) => $o === 'OK:claim:none');
        $this->assertCount(1, $claims, "exactly one worker may hold the lease: A=$outA B=$outB");
        $this->assertCount(1, $nones, "the loser gets a clean no-claim: A=$outA B=$outB");
        $this->assertSame(EdgeLocalPrintDelivery::STATE_LEASED, EdgeLocalPrintDelivery::where('print_job_id', $jobId)->value('delivery_state'));
    }

    // ── A + B. delivered-then-died → redelivery; stale token can never demote ───────────────────
    public function test_race_death_before_mark_redelivers_and_stale_failure_is_refused(): void
    {
        $payload = "RACE-AB PAYLOAD\n\n\n";
        $jobId = $this->queuedJob($payload);
        $fp = $this->startFakePrinter(2); // the SAME job may hit the printer twice — by design

        // P1 delivers for real, then dies before markPrinted (its token leaks to us for step B).
        $out1 = $this->runWorker(['print_deliver_die', 'worker-A']);
        $this->assertStringStartsWith("OK:deliver_die:{$jobId}:", $out1, $out1);
        $staleToken = substr($out1, strlen("OK:deliver_die:{$jobId}:"));
        $this->assertSame('queued', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'), 'no completion happened — the job is still queued');

        // its lease expires; a second worker redelivers the SAME stored bytes and completes.
        EdgeLocalPrintDelivery::where('print_job_id', $jobId)->update(['lease_expires_at' => now()->subSecond()]);
        $out2 = $this->runWorker(['print_cycle', 'worker-B']);
        $this->assertSame("OK:cycle:{$jobId}:delivered", $out2, $out2);

        $bytes = $this->fakePrinterBytes($fp);
        $expected = $payload . "\n\n\n";
        $this->assertSame($expected . $expected, $bytes, 'the printer received the SAME stored bytes twice — physical duplicate is possible BY DESIGN (at-least-once)');
        $this->assertSame('printed', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
        $this->assertSame(1, DB::connection('tenant')->table('print_jobs')->count(), 'redelivery never creates another job/business event');

        // B: the dead worker's stale token reports failure — REFUSED, printed survives.
        $out3 = $this->runWorker(['print_failure', $jobId, $staleToken, 'stale power-loss error']);
        $this->assertSame('REFUSED:stale', $out3, $out3);
        $row = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $this->assertSame('printed', $row->print_status, 'stale failure can never overwrite the newer success');
        $this->assertSame(0, (int) $row->attempts);
    }

    // ── closure fix 2: completion RACING reclaim at the lease boundary — one lock order, no leaks ─
    public function test_race_completion_vs_reclaim_at_lease_boundary_is_deadlock_free_and_coherent(): void
    {
        $jobId = $this->queuedJob("RACE-LOCK\n\n\n");
        $claim = null;
        // claim in-process to hold a real token, then push the lease to the exact boundary.
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');
        $svc = app(\App\Services\Edge\EdgeLocalPrintDeliveryService::class);
        $claim = $svc->claimNext('worker-A');
        $this->assertNotNull($claim);
        EdgeLocalPrintDelivery::where('print_job_id', $jobId)->update(['lease_expires_at' => now()]);

        // GENUINE two processes on the barrier: completion(with A's token) vs reclaim(worker-B).
        $startFile = sys_get_temp_dir() . '/edge_print_boundary_' . Str::random(8) . '.start';
        @unlink($startFile);
        $p1 = $this->worker(['print_success', $jobId, $claim['lease_token']], $startFile);
        $p2 = $this->worker(['print_claim', 'worker-B'], $startFile);
        sleep(4);
        file_put_contents($startFile, '1');
        $out1 = $this->finish($p1);
        $out2 = $this->finish($p2);
        @unlink($startFile);

        // no deadlock/SQL leakage — every outcome is a controlled protocol answer.
        foreach ([$out1, $out2] as $out) {
            $this->assertMatchesRegularExpression('/^(OK:|REFUSED:)/', $out, "raw error leaked: $out");
            $this->assertStringNotContainsString('Deadlock', $out);
            $this->assertStringNotContainsString('QueryException', $out);
            $this->assertStringNotContainsString('PDOException', $out);
        }

        // coherent final state, whichever side won the locks:
        $row = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $d = EdgeLocalPrintDelivery::where('print_job_id', $jobId)->first();
        if ($row->print_status === 'printed') {
            // completion won while its token was still active → delivered, no live token.
            $this->assertSame(EdgeLocalPrintDelivery::STATE_DELIVERED, $d->delivery_state);
            $this->assertNull($d->lease_token);
            $this->assertSame('OK:claim:none', $out2, 'a delivered job is not reclaimable');
        } else {
            // the expired lease lost authority → still queued; at most ONE current token (B's or none).
            $this->assertSame('queued', $row->print_status);
            $this->assertSame('REFUSED:stale', $out1, 'the boundary token was refused');
            if ($d->delivery_state === EdgeLocalPrintDelivery::STATE_LEASED) {
                $this->assertNotNull($d->lease_token);
                $this->assertNotSame($claim['lease_token'], $d->lease_token, 'exactly one CURRENT token — the reclaimer\'s');
            }
        }
        $this->assertNotSame('failed', $row->print_status, 'the race can never fabricate a failure');
    }

    // ── closure fix 3: two workers + two queued jobs on ONE printer must preserve FIFO ───────────
    public function test_race_two_workers_same_printer_never_deliver_out_of_order(): void
    {
        $payloadA = "FIFO-FIRST\n\n\n";
        $payloadB = "FIFO-SECOND\n\n\n";
        $jobA = $this->makePrintJob($this->printerId, ['print_status' => 'queued', 'printed_at' => null, 'attempts' => 0, 'branch_id' => $this->branchId, 'raw_payload' => $payloadA, 'created_at' => now()->subMinute()]);
        $jobB = $this->makePrintJob($this->printerId, ['print_status' => 'queued', 'printed_at' => null, 'attempts' => 0, 'branch_id' => $this->branchId, 'raw_payload' => $payloadB]);

        $fp = $this->startFakePrinter(2);
        $startFile = sys_get_temp_dir() . '/edge_print_fifo_' . Str::random(8) . '.start';
        @unlink($startFile);
        $p1 = $this->worker(['print_cycle', 'worker-1'], $startFile);
        $p2 = $this->worker(['print_cycle', 'worker-2'], $startFile);
        sleep(4);
        file_put_contents($startFile, '1');
        $out1 = $this->finish($p1);
        $out2 = $this->finish($p2);
        @unlink($startFile);

        // legitimate interleavings: (a) one racer delivers A while the FIFO gate returns none to the
        // other; (b) the second racer runs after A completed and delivers B. NEVER B before/without A.
        $joined = $out1 . ' ' . $out2;
        foreach ([$out1, $out2] as $out) {
            $this->assertMatchesRegularExpression('/^OK:cycle:/', $out, "raw error leaked: $out");
        }
        $this->assertStringContainsString("OK:cycle:{$jobA}:delivered", $joined, "A must be the first delivery: 1=$out1 2=$out2");
        $this->assertSame('printed', DB::connection('tenant')->table('print_jobs')->where('id', $jobA)->value('print_status'));

        // drain B if the race left it queued, then prove the PHYSICAL byte order: A strictly before B.
        if (! str_contains($joined, "OK:cycle:{$jobB}:delivered")) {
            $out3 = $this->runWorker(['print_cycle', 'worker-3']);
            $this->assertSame("OK:cycle:{$jobB}:delivered", $out3, $out3);
        }
        $bytes = $this->fakePrinterBytes($fp);
        $this->assertSame($payloadA . "\n\n\n" . $payloadB . "\n\n\n", $bytes, 'the printer received A then B — never reordered');
    }

    // ── D. died BEFORE sending → nothing on the wire; the next worker completes normally ────────
    public function test_race_death_before_send_leaves_no_bytes_and_next_worker_completes(): void
    {
        $payload = "RACE-D PAYLOAD\n\n\n";
        $jobId = $this->queuedJob($payload);

        // P1 claims and dies without ever opening a socket.
        $out1 = $this->runWorker(['print_claim', 'worker-A']);
        $this->assertStringStartsWith("OK:claim:{$jobId}:", $out1, $out1);

        // before the lease expires nobody else may claim it.
        $out2 = $this->runWorker(['print_cycle', 'worker-B']);
        $this->assertSame('OK:cycle:none', $out2, 'the live lease blocks a second claim');

        EdgeLocalPrintDelivery::where('print_job_id', $jobId)->update(['lease_expires_at' => now()->subSecond()]);
        $fp = $this->startFakePrinter(1);
        $out3 = $this->runWorker(['print_cycle', 'worker-C']);
        $this->assertSame("OK:cycle:{$jobId}:delivered", $out3, $out3);
        $this->assertSame($payload . "\n\n\n", $this->fakePrinterBytes($fp), 'exactly ONE delivery reached the printer');
        $this->assertSame('printed', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
    }
}
