<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalPrintDelivery;
use App\Models\Edge\EdgeLocalPrintWorkerState;
use App\Models\Tenant\Printer;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeLocalPrintDeliveryService;
use App\Services\Edge\EdgeLocalPrintWorkerSupervisor;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-PRINT-1 Slice 2 — the worker as a REAL appliance runtime component: singleton
 * double-start policy, heartbeat liveness, cooperative stop (real background artisan process),
 * reboot/restart recovery (§11 A–F), and the §13 supervised start through the ACTUAL command line the
 * Scheduled Task runs — with the master DB unreachable.
 */
class EdgeLocalPrintWorkerLifecycleMySqlTest extends MySqlTenantTestCase
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
        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
             'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
             'password' => config('database.connections.tenant.password')]
        )]);
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_print_worker_state', 'edge_local_print_deliveries', 'edge_local_meta', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'category_printer_mappings', 'terminal_printer_settings', 'printers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'products', 'categories', 'terminals', 'branches', 'users']);
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

    private function startFakePrinter(int $maxConnections = 1): array
    {
        $out = sys_get_temp_dir() . '/edge_worker_lc_' . Str::random(8) . '.bin';
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

    /** The ENV the real Scheduled Task command line runs with (appliance path, master DEAD). */
    private function applianceEnv(): array
    {
        $t = config('database.connections.tenant');

        return array_merge(getenv() ?: [], [
            'APP_ENV' => 'testing',
            'APP_ROLE' => 'branch_server',
            'EDGE_LOCAL_APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'EDGE_DB_HOST' => (string) $t['host'],
            'EDGE_DB_PORT' => (string) $t['port'],
            'EDGE_DB_DATABASE' => $this->tenantDb,
            'EDGE_DB_USERNAME' => (string) $t['username'],
            'EDGE_DB_PASSWORD' => (string) ($t['password'] ?? ''),
            'DB_DATABASE' => 'nonexistent_master_worker_lifecycle', // master is DEAD for the whole run
        ]);
    }

    /** Spawn the REAL `php artisan edge:local:print-worker ...` process (the Scheduled Task action). */
    private function spawnRealWorker(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('artisan'), 'edge:local:print-worker'], $args);
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $this->applianceEnv());

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function finishProc(array $h): array
    {
        $out = trim(stream_get_contents($h['pipes'][1]));
        $err = trim(stream_get_contents($h['pipes'][2]) ?: '');
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        $code = proc_close($h['proc']);

        return ['code' => $code, 'out' => $out !== '' ? $out : $err];
    }

    // ── §13: supervised start through the REAL command line, master unreachable ─────────────────
    public function test_real_supervised_start_delivers_with_master_dead(): void
    {
        $jobId = $this->queuedJob("SUPERVISED PAYLOAD\n\n\n");
        $fp = $this->startFakePrinter();

        $res = $this->finishProc($this->spawnRealWorker(['--once']));
        $this->assertSame(0, $res['code'], $res['out']);
        $this->assertStringContainsString('delivered', $res['out'], $res['out']);

        $this->assertSame("SUPERVISED PAYLOAD\n\n\n" . "\n\n\n", $this->fakePrinterBytes($fp));
        $this->assertSame('printed', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
        // the process recorded its lifecycle in the state row and exited gracefully.
        $row = EdgeLocalPrintWorkerState::current();
        $this->assertSame(EdgeLocalPrintWorkerState::STATE_STOPPED, $row->state);
        $this->assertNotNull($row->last_graceful_stop_at);
        $this->assertNotNull($row->heartbeat_at);
    }

    // ── §12: double-start — a duplicate daemon exits cleanly; a stale one is taken over ─────────
    public function test_duplicate_worker_exits_cleanly_and_stale_worker_is_taken_over(): void
    {
        $jobId = $this->queuedJob("DOUBLE-START\n\n\n");

        // a LIVE worker (fresh heartbeat) holds the slot → the second start must refuse + claim nothing.
        EdgeLocalPrintWorkerState::create([
            'state' => EdgeLocalPrintWorkerState::STATE_RUNNING, 'worker_uuid' => 'live-worker-uuid',
            'started_at' => now(), 'heartbeat_at' => now(),
        ]);
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $this->assertStringContainsString('already RUNNING', Artisan::output());
        $this->assertSame('queued', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'), 'a refused duplicate must not claim jobs');
        $this->assertSame('live-worker-uuid', EdgeLocalPrintWorkerState::current()->worker_uuid, 'the live worker keeps the slot');

        // the same row with a STALE heartbeat = crashed worker → the next start takes over and works.
        EdgeLocalPrintWorkerState::query()->update(['heartbeat_at' => now()->subSeconds(EdgeLocalPrintWorkerSupervisor::HEARTBEAT_STALE_SECONDS + 5)]);
        $fp = $this->startFakePrinter();
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $this->fakePrinterBytes($fp);
        $this->assertSame('printed', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
        $this->assertNotSame('live-worker-uuid', EdgeLocalPrintWorkerState::current()->worker_uuid, 'the stale slot was taken over');
    }

    // ── §11 B: restart before lease expiry cannot steal; after expiry it resumes ────────────────
    public function test_restart_respects_live_lease_then_resumes_after_expiry(): void
    {
        $jobId = $this->queuedJob("LEASE-RESPECT\n\n\n");
        $claim = app(EdgeLocalPrintDeliveryService::class)->claimNext('dead-worker'); // dies before send
        $this->assertNotNull($claim);

        // restart BEFORE lease expiry: the new worker must not steal the leased job.
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $this->assertSame('queued', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
        $this->assertSame($claim['lease_token'], EdgeLocalPrintDelivery::where('print_job_id', $jobId)->value('lease_token'), 'the live lease survived the restart untouched');

        // after expiry the restarted worker resumes the queue normally.
        EdgeLocalPrintDelivery::where('print_job_id', $jobId)->update(['lease_expires_at' => now()->subSecond()]);
        $fp = $this->startFakePrinter();
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $this->fakePrinterBytes($fp);
        $this->assertSame('printed', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
    }

    // ── §11 D + E: retry_wait and terminal_failed survive a restart exactly ─────────────────────
    public function test_restart_preserves_backoff_and_terminal_state(): void
    {
        $jobId = $this->queuedJob("RESTART-BACKOFF\n\n\n"); // no listener → temporary failure
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $before = EdgeLocalPrintDelivery::where('print_job_id', $jobId)->first();
        $this->assertSame(EdgeLocalPrintDelivery::STATE_RETRY_WAIT, $before->delivery_state);

        // D: restart during retry_wait — next_attempt_at is PRESERVED, nothing redelivers early.
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $after = EdgeLocalPrintDelivery::where('print_job_id', $jobId)->first();
        $this->assertSame('queued', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
        $this->assertSame((string) $before->next_attempt_at, (string) $after->next_attempt_at, 'restart preserves the backoff schedule — no hot-loop');
        $this->assertSame(1, (int) $after->failure_count);

        // E: drive to terminal; a restart never resets it.
        $svc = app(EdgeLocalPrintDeliveryService::class);
        for ($i = 2; $i <= EdgeLocalPrintDeliveryService::MAX_FAILURES; $i++) {
            EdgeLocalPrintDelivery::where('print_job_id', $jobId)->update(['next_attempt_at' => now()->subSecond()]);
            $claim = $svc->claimNext('w');
            $this->assertNotNull($claim);
            $this->assertTrue($svc->completeFailure($jobId, $claim['lease_token'], "down $i"));
        }
        $this->assertSame('failed', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $this->assertSame(EdgeLocalPrintDelivery::STATE_TERMINAL_FAILED, EdgeLocalPrintDelivery::where('print_job_id', $jobId)->value('delivery_state'), 'terminal_failed persists across restart — never auto-reset');
        $this->assertSame('failed', DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->value('print_status'));
    }

    // ── §11 F: a clean restart never regenerates KOT business events ────────────────────────────
    public function test_clean_restart_never_regenerates_business_events(): void
    {
        $saleId = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'dine_in']);
        $productId = $this->makeProduct($this->makeCategory());
        $this->makeSaleLine($saleId, $productId, ['quantity' => 1, 'kot_sent_quantity' => 0, 'kot_sent' => 0]);
        app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($saleId), Printer::findOrFail($this->printerId));
        $this->assertSame(1, DB::connection('tenant')->table('kot_batches')->count());

        $fp = $this->startFakePrinter();
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $this->fakePrinterBytes($fp);
        // restart (idle — nothing to claim) → still exactly one business event, no new jobs.
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $this->assertSame(1, DB::connection('tenant')->table('kot_batches')->count(), 'restart must never regenerate KOT events');
        $this->assertSame(1, DB::connection('tenant')->table('print_jobs')->count());
    }

    // ── §7/§8: COOPERATIVE stop of a real long-running background worker ────────────────────────
    public function test_cooperative_stop_of_a_real_background_worker(): void
    {
        // a REAL looping worker process (idle, 1s sleep) through the actual command line.
        $h = $this->spawnRealWorker(['--idle-sleep=1']);
        $deadline = microtime(true) + 25;
        while (microtime(true) < $deadline) {
            $row = EdgeLocalPrintWorkerState::current();
            if ($row && $row->state === EdgeLocalPrintWorkerState::STATE_RUNNING) {
                break;
            }
            usleep(200_000);
        }
        $row = EdgeLocalPrintWorkerState::current();
        $this->assertNotNull($row, 'worker must register its state row');
        $this->assertSame(EdgeLocalPrintWorkerState::STATE_RUNNING, $row->state, 'worker must report RUNNING');
        $this->assertSame('running', app(EdgeLocalPrintWorkerSupervisor::class)->health()['state']);

        // request the cooperative stop through the same --stop entrypoint the maintenance script uses.
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--stop' => true, '--stop-wait' => 20]));
        $res = $this->finishProc($h);
        $this->assertSame(0, $res['code'], $res['out']);

        $row = EdgeLocalPrintWorkerState::current();
        $this->assertSame(EdgeLocalPrintWorkerState::STATE_STOPPED, $row->state);
        $this->assertNotNull($row->last_graceful_stop_at, 'the stop was GRACEFUL — recorded by the worker itself');
        $this->assertNull($row->stop_requested_at);
        $this->assertSame('stopped', app(EdgeLocalPrintWorkerSupervisor::class)->health()['state']);
        // §8: no lease was touched by the stop — there were no leases to rewrite.
        $this->assertSame(0, EdgeLocalPrintDelivery::count());
    }

    // ── §10/§14: readiness distinguishes config from process; diagnostics are read-only ─────────
    public function test_readiness_splits_config_from_process_and_status_command_reports(): void
    {
        // configured printer + NO worker ever started → config ready, process not_installed.
        $report = app(\App\Services\Edge\EdgeLocalReadiness::class)->report();
        $this->assertSame('ready', $report['local_print']);
        $this->assertSame('not_installed', $report['local_print_worker'], 'a configured printer with no worker must NOT look healthy');
        $this->assertFalse($report['activation_ready']);

        // after a run: stopped. After a stale-heartbeat crash: stale.
        $this->assertSame(0, Artisan::call('edge:local:print-worker', ['--once' => true]));
        $this->assertSame('stopped', app(\App\Services\Edge\EdgeLocalReadiness::class)->report()['local_print_worker']);
        EdgeLocalPrintWorkerState::query()->update(['state' => EdgeLocalPrintWorkerState::STATE_RUNNING, 'heartbeat_at' => now()->subSeconds(EdgeLocalPrintWorkerSupervisor::HEARTBEAT_STALE_SECONDS + 5)]);
        $this->assertSame('stale', app(\App\Services\Edge\EdgeLocalReadiness::class)->report()['local_print_worker']);

        // the diagnostic command is read-only and reports the queue shape.
        $this->queuedJob("STATUS\n\n\n");
        $this->makePrintJob(null, ['print_status' => 'queued', 'printed_at' => null, 'branch_id' => $this->branchId, 'raw_payload' => 'N']);
        $before = DB::connection('tenant')->table('print_jobs')->orderBy('id')->get();
        $this->assertSame(0, Artisan::call('edge:local:print-status', ['--json' => true]));
        $status = json_decode(Artisan::output(), true);
        $this->assertSame('branch_server', $status['runtime_mode']);
        $this->assertSame($this->branchId, $status['bound_branch_id']);
        $this->assertSame('stale', $status['worker']['state']);
        $this->assertSame(1, $status['queue']['queued_printer_jobs']);
        $this->assertSame(1, $status['queue']['unresolved_null_printer_intents']);
        $this->assertCount(1, $status['printers']);
        $after = DB::connection('tenant')->table('print_jobs')->orderBy('id')->get();
        $this->assertEquals($before, $after, 'diagnostics mutate NOTHING');
        $this->assertStringNotContainsString('lease_token', json_encode($status), 'no tokens/secrets in diagnostics');
    }
}
