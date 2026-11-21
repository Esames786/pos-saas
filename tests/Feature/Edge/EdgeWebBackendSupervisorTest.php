<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeWebBackendSupervisor;
use Tests\TestCase;

/**
 * F-FB4-1 (0.8.1 hotfix) + F-FB5-1 (0.8.2 hotfix) — the cashier web-backend supervisor, driven with scripted process/probe/progress
 * seams (no real processes).
 *
 * What the LAB showed on 24 Oct 2026: the P4 supervisor relayed the child's output through non-blocking proc_open pipes;
 * Windows does not support non-blocking mode on those pipes, the parent blocked on its first read, the child's stderr pipe
 * filled after ~37 requests and both cashier backends hung — while a port check still said "listening".
 *
 * What the LAB showed on 29 Oct 2026 (F-FB5-1): the 0.8.1 policy (3 s read / 3 misses) restarted BUSY backends 28 times on a
 * saturated host while they were rendering legitimate multi-second pages (their request logs kept advancing).
 *
 * Contract proven here: on Windows the child's stdout/stderr are FILES (distinct per backend, under the data-root logs dir,
 * never inside the runtime, size-capped with one rotation); elsewhere pipes stay; a probe miss WITH child progress inside the
 * busy grace is BUSY_OR_SLOW and never counted; a miss without progress (or any miss beyond the grace) is SUSPECTED_HUNG and
 * counted; PROBE_FAILURES_TO_RESTART counted misses = CONFIRMED_HUNG → terminate + restart with exponential, capped back-off;
 * the bound from the last successful answer to the restart of a child that never answers again is HANG_DETECTION_BOUND_SECONDS
 * even when its log keeps advancing; an exited backend is restarted; a backend already answering on the port makes a second
 * supervisor exit; repeated restarts surface as 'failing'; --once never restarts; stop() terminates the child; every transition
 * writes the status file the health report reads.
 */
class EdgeWebBackendSupervisorTest extends TestCase
{
    private string $logsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-sup-' . uniqid();
        @mkdir($this->logsDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logsDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->logsDir);
        parent::tearDown();
    }

    public function test_on_windows_the_child_output_goes_to_distinct_capped_log_files_under_the_data_root_never_pipes(): void
    {
        $sup = $this->scripted(worker: 2, windows: true);
        $spec = $sup->descriptorSpec();
        $this->assertSame(['pipe', 'r'], $spec[0], 'stdin stays a pipe (closed at once)');
        $this->assertSame('file', $spec[1][0]);
        $this->assertSame('file', $spec[2][0]);
        $this->assertSame('a', $spec[1][2], 'append — a restart never truncates the previous diagnostics');
        $this->assertSame($this->logsDir . DIRECTORY_SEPARATOR . 'web-backend-2.out.log', $spec[1][1]);
        $this->assertSame($this->logsDir . DIRECTORY_SEPARATOR . 'web-backend-2.err.log', $spec[2][1]);
        $this->assertNotSame($spec[1][1], $spec[2][1], 'stdout and stderr are separate files');
        $this->assertStringStartsNotWith(base_path(), $spec[1][1], 'child logs never live inside the runtime directory');
        $other = $this->scripted(worker: 1, windows: true)->descriptorSpec();
        $this->assertNotSame($other[1][1], $spec[1][1], 'each backend has its own destination');
    }

    public function test_off_windows_the_pipe_relay_behaviour_is_unchanged(): void
    {
        $spec = $this->scripted(worker: 1, windows: false)->descriptorSpec();
        $this->assertSame(['pipe', 'w'], $spec[1]);
        $this->assertSame(['pipe', 'w'], $spec[2]);
    }

    public function test_a_log_larger_than_the_cap_is_rotated_once_and_the_live_file_restarts_empty(): void
    {
        $sup = $this->scripted(worker: 1, windows: true);
        $out = $sup->stdoutLogPath();
        file_put_contents($out, str_repeat('x', EdgeWebBackendSupervisor::LOG_MAX_BYTES + 10));
        $this->assertTrue($sup->rotateIfLarge($out));
        $this->assertFileExists($this->logsDir . DIRECTORY_SEPARATOR . 'web-backend-1.out.1.log');
        $this->assertFileDoesNotExist($out, 'the live file is recreated empty by the next append');
        file_put_contents($out, 'small');
        $this->assertFalse($sup->rotateIfLarge($out), 'below the cap: nothing rotates');
        $this->assertSame('small', file_get_contents($out));
        // a second rotation replaces the previous generation — logs cannot grow without bound
        file_put_contents($out, str_repeat('y', EdgeWebBackendSupervisor::LOG_MAX_BYTES + 1));
        $this->assertTrue($sup->rotateIfLarge($out));
        $this->assertSame(EdgeWebBackendSupervisor::LOG_MAX_BYTES + 1, filesize($this->logsDir . DIRECTORY_SEPARATOR . 'web-backend-1.out.1.log'));
    }

    public function test_backoff_doubles_to_the_cap_and_resets(): void
    {
        $sup = $this->scripted(worker: 1, windows: true);
        $seen = [];
        for ($i = 0; $i < 7; $i++) {
            $seen[] = $sup->nextBackoff();
        }
        $this->assertSame([1, 2, 4, 8, 16, 30, 30], $seen);
        $sup->resetBackoff();
        $this->assertSame(1, $sup->nextBackoff());
    }

    public function test_the_policy_is_the_owner_approved_one_and_the_detection_bound_follows_from_it(): void
    {
        $this->assertSame(10, EdgeWebBackendSupervisor::PROBE_READ_TIMEOUT);
        $this->assertSame(5, EdgeWebBackendSupervisor::PROBE_FAILURES_TO_RESTART);
        $this->assertSame(120, EdgeWebBackendSupervisor::BUSY_GRACE_SECONDS);
        $this->assertSame(120 + 5 * (5 + 10 + 2), EdgeWebBackendSupervisor::HANG_DETECTION_BOUND_SECONDS, 'busy grace + N probe cycles of (interval + read + connect)');
        $this->assertSame(205, EdgeWebBackendSupervisor::HANG_DETECTION_BOUND_SECONDS);
        $policy = EdgeWebBackendSupervisor::policy();
        $this->assertSame(205, $policy['hang_detection_bound_seconds']);
        $this->assertSame([10, 600], $policy['failing_restarts_per_window']);
    }

    public function test_a_genuinely_hung_backend_no_progress_is_terminated_after_five_counted_misses_and_restarted(): void
    {
        // probe script: start = not running yet (false → duplicate check passes), then after the grace: 5 misses → restart → ok
        $sup = $this->scripted(worker: 1, windows: true, probes: [false, false, false, false, false, false, true, true], running: array_fill(0, 40, true));
        $code = $sup->run(once: false, maxIterations: 40);
        $this->assertSame(0, $code);
        $this->assertGreaterThanOrEqual(2, count($sup->spawns), 'the hung backend was restarted');
        $this->assertSame(2, $sup->terminations, 'one termination for the hung backend + one when run() ends and stops the supervised child');
        $this->assertStringContainsString('hung: 5 liveness probes failed (no progress', $sup->spawns[1]['why']);
        $status = json_decode((string) file_get_contents($sup->statusFilePath()), true);
        $this->assertSame(1, $status['restarts']);
        $this->assertStringStartsWith('hung: 5 liveness probes failed', $status['last_restart_reason']);
        $this->assertContains('suspected_hung', $sup->states, 'misses without progress are SUSPECTED_HUNG while counting');
        $this->assertContains('confirmed_hung', $sup->states, 'the fifth counted miss confirms the hang');
        $this->assertNotContains('busy_or_slow', $sup->states, 'no progress → never classified busy');
        $this->assertContains('healthy', array_slice($sup->states, array_search('restarting', $sup->states, true) ?: 0), 'after the restart the probe answered → healthy');
        $this->assertSame(1, $status['worker']);
        $this->assertArrayHasKey('pid', $status);
        $this->assertArrayHasKey('logs', $status);
        $this->assertSame('HEALTHY', strtoupper($sup->statesBefore('stopped')), 'the last live classification before the stop was healthy');
        $this->assertNotEmpty(array_filter($sup->lines, fn ($l) => str_contains($l, 'terminating the hung backend')), 'the reason is written to the task log');
    }

    public function test_three_misses_alone_no_longer_restart_a_backend_the_old_policy_would_have_killed(): void
    {
        // 0.8.1 restarted after three misses; now three misses (no progress) are SUSPECTED and the fourth probe answering clears them
        $sup = $this->scripted(worker: 1, windows: true, probes: [false, false, false, false, true, true, true], running: array_fill(0, 20, true));
        $sup->run(once: false, maxIterations: 12);
        $this->assertCount(1, $sup->spawns, 'no restart');
        $this->assertSame(1, $sup->terminations, 'only the final stop');
        $status = json_decode((string) file_get_contents($sup->statusFilePath()), true);
        $this->assertSame(0, $status['restarts']);
        $this->assertSame(0, $status['consecutive_probe_failures'], 'the counter resets on the first answer');
        $this->assertContains('suspected_hung', $sup->states);
        $this->assertContains('healthy', $sup->states);
    }

    public function test_a_busy_backend_whose_log_keeps_advancing_is_classified_busy_or_slow_and_never_restarted_inside_the_grace(): void
    {
        // the child answers once (→ last 200 known), then misses 8 probes (40 s) while its request log advances every time → BUSY, no restart
        $probes = [false, true];
        $progress = ['p0', 'p1'];
        for ($i = 0; $i < 8; $i++) {
            $probes[] = false;
            $progress[] = 'p' . ($i + 2);
        }
        $probes[] = true;
        $progress[] = 'p99';
        $sup = $this->scripted(worker: 1, windows: true, probes: $probes, running: array_fill(0, 40, true), progress: $progress);
        $sup->run(once: false, maxIterations: 16);
        $this->assertCount(1, $sup->spawns, 'a busy backend is NEVER restarted inside the busy grace');
        $this->assertSame(1, $sup->terminations, 'only the final stop');
        $status = json_decode((string) file_get_contents($sup->statusFilePath()), true);
        $this->assertSame(0, $status['restarts']);
        $this->assertContains('busy_or_slow', $sup->states);
        $this->assertNotContains('suspected_hung', $sup->states, 'misses with progress inside the grace are not even suspected');
        $this->assertNotContains('confirmed_hung', $sup->states);
        $this->assertGreaterThanOrEqual(8, max(array_map(fn ($s) => (int) ($s['busy_ticks'] ?? 0), $sup->statusHistory)), 'busy ticks are counted for the status file');
        $this->assertNotEmpty(array_filter($sup->lines, fn ($l) => str_contains($l, 'busy/slow, not restarting')), 'the classification is written to the task log');
        $this->assertNotEmpty(array_filter($sup->lines, fn ($l) => str_contains($l, 'answering again (busy_or_slow → healthy)')));
    }

    public function test_log_advancement_alone_never_grants_indefinite_health_the_bound_holds_when_the_child_never_answers_again(): void
    {
        // the child answers once, then NEVER again while its log keeps advancing forever → busy only until the grace is spent,
        // then five counted misses → CONFIRMED_HUNG → restart. Measured from the last 200 on the scripted clock (5 s per probe cycle).
        $probes = [false, true];
        $progress = ['p0', 'p1'];
        for ($i = 0; $i < 60; $i++) {
            $probes[] = false;
            $progress[] = 'p' . ($i + 2);
        }
        $sup = $this->scripted(worker: 1, windows: true, probes: $probes, running: array_fill(0, 80, true), progress: $progress);
        $sup->run(once: false, maxIterations: 60);
        $this->assertGreaterThanOrEqual(2, count($sup->spawns), 'eventually restarted despite continuous log advancement');
        $this->assertStringContainsString('progress continued but no answer', $sup->spawns[1]['why']);
        $this->assertContains('busy_or_slow', $sup->states, 'busy first…');
        $this->assertContains('suspected_hung', $sup->states, '…then counted once the grace is spent…');
        $this->assertContains('confirmed_hung', $sup->states, '…then confirmed');
        $okAt = $sup->clockAt('healthy');
        $hungAt = $sup->clockAt('confirmed_hung');
        $this->assertNotNull($okAt);
        $this->assertNotNull($hungAt);
        $elapsed = $hungAt - $okAt;
        $this->assertGreaterThan(EdgeWebBackendSupervisor::BUSY_GRACE_SECONDS, $elapsed, 'the busy grace was honoured first');
        $this->assertLessThanOrEqual(EdgeWebBackendSupervisor::HANG_DETECTION_BOUND_SECONDS, $elapsed, 'confirmed within the documented bound (scripted probes take no time, so this is the interval-only lower envelope of the bound)');
    }

    public function test_an_exited_backend_is_restarted_with_the_exit_code_recorded(): void
    {
        $sup = $this->scripted(worker: 1, windows: true, probes: [false, true, true, true], running: [true, false, true, true, true, true]);
        $sup->exitCode = 137;
        $sup->run(once: false, maxIterations: 6);
        $this->assertCount(2, $sup->spawns);
        $this->assertSame('restart #1: exited with code 137', $sup->spawns[1]['why']);
        $this->assertSame(1, $sup->terminations, 'an exited child is not killed again — the only termination is the final stop when run() ends');
        $status = json_decode((string) file_get_contents($sup->statusFilePath()), true);
        $this->assertSame(137, $status['last_exit_code']);
        $this->assertSame(1, $status['restarts']);
    }

    public function test_once_mode_never_restarts_and_reports_the_exit(): void
    {
        $sup = $this->scripted(worker: 1, windows: true, probes: [false], running: [true, false]);
        $sup->exitCode = 3;
        $this->assertSame(1, $sup->run(once: true, maxIterations: 10));
        $this->assertCount(1, $sup->spawns);
        $this->assertSame('exited', json_decode((string) file_get_contents($sup->statusFilePath()), true)['state']);
    }

    public function test_a_backend_already_answering_on_the_port_makes_the_supervisor_exit_instead_of_fighting_it(): void
    {
        $sup = $this->scripted(worker: 1, windows: true, probes: [true]);
        $this->assertSame(0, $sup->run(once: false, maxIterations: 5));
        $this->assertCount(0, $sup->spawns, 'nothing spawned — the OS port singleton is respected');
        $this->assertSame('duplicate', json_decode((string) file_get_contents($sup->statusFilePath()), true)['state']);
    }

    public function test_repeated_restarts_surface_as_failing_and_backoff_is_capped(): void
    {
        // the child exits every time it is checked
        $running = [];
        for ($i = 0; $i < 30; $i++) {
            $running[] = false;
        }
        $sup = $this->scripted(worker: 1, windows: true, probes: [false], running: $running);
        $sup->exitCode = 1;
        $sup->run(once: false, maxIterations: 12);
        $status = json_decode((string) file_get_contents($sup->statusFilePath()), true);
        $this->assertGreaterThanOrEqual(EdgeWebBackendSupervisor::FAILING_RESTARTS, $status['restarts']);
        $this->assertContains('failing', $sup->states, 'a backend that keeps dying is reported as failing in the status file while it restarts (the health report surfaces it)');
        $this->assertGreaterThanOrEqual(EdgeWebBackendSupervisor::FAILING_RESTARTS, $status['restarts_last_10min']);
        $this->assertLessThanOrEqual(EdgeWebBackendSupervisor::BACKOFF_MAX_SECONDS, max($sup->sleeps), 'back-off never exceeds the cap');
        $this->assertSame([1, 2, 4, 8, 16, 30, 30, 30, 30, 30, 30], array_slice($sup->sleeps, 0, 11), 'exponential back-off, capped — no rapid restart loop');
    }

    public function test_stop_terminates_the_running_child_and_records_stopped(): void
    {
        $sup = $this->scripted(worker: 1, windows: true, probes: [false, true], running: [true, true]);
        $sup->run(once: false, maxIterations: 2);
        $afterRun = $sup->terminations;
        $this->assertSame(1, $afterRun, 'run() ending stops the supervised child exactly once');
        $sup->stop('test stop');
        $this->assertSame($afterRun + 1, $sup->terminations, 'an explicit stop terminates the child — no orphan php -S keeps the cashier port');
        $this->assertSame('stopped', json_decode((string) file_get_contents($sup->statusFilePath()), true)['state']);
    }

    // ── the scripted double ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<bool>  $probes  answers of successive probe() calls (the first one is the duplicate check at start)
     * @param  list<bool>  $running  answers of successive processStatus() calls
     * @param  list<string>|null  $progress  successive progress markers (null = a constant marker: the child never progresses)
     */
    private function scripted(int $worker, bool $windows, array $probes = [false], array $running = [true], ?array $progress = null): EdgeWebBackendSupervisor
    {
        $logsDir = $this->logsDir;

        return new class($worker, $windows, $probes, $running, $progress, $logsDir) extends EdgeWebBackendSupervisor {
            public array $spawns = [];
            public int $terminations = 0;
            public array $lines = [];
            public array $states = [];
            public array $statusHistory = [];
            public array $stateClock = [];
            public array $sleeps = [];
            public int $exitCode = 0;
            private float $clock = 1_000_000.0;
            private int $fakePid = 4000;

            public function __construct(int $worker, private bool $windows, private array $probes, private array $running, private ?array $progress, string $logsDir)
            {
                parent::__construct($worker, '127.0.0.1', 8190 + $worker - 1, ['php', '-S'], [], sys_get_temp_dir(), $logsDir, function (string $line): void { $this->lines[] = $line; });
            }

            protected function usesFileLogs(): bool { return $this->windows; }

            protected function spawn(string $why): void
            {
                $this->spawns[] = ['why' => $why, 'count' => count($this->spawns) + 1];
                $this->fakePid++;
                // mirror the parent's bookkeeping without a process: reach the private state through reflection
                $ref = new \ReflectionClass(EdgeWebBackendSupervisor::class);
                foreach (['childPid' => $this->fakePid, 'startedAt' => $this->clock, 'lastProbeAt' => 0.0, 'lastOkAt' => 0.0, 'healthySince' => 0.0, 'consecutiveProbeFailures' => 0, 'busyTicks' => 0, 'lastProgressMarker' => $this->progressMarker(), 'state' => 'starting'] as $prop => $val) {
                    $p = $ref->getProperty($prop); $p->setAccessible(true); $p->setValue($this, $val);
                }
                $p = $ref->getProperty('proc'); $p->setAccessible(true); $p->setValue($this, null);
                $this->lines[] = "spawn: {$why}";
                $this->writeStatus('starting');
                $this->clock += EdgeWebBackendSupervisor::STARTUP_GRACE_SECONDS; // the grace elapses before the next loop check
            }

            protected function processStatus(): array
            {
                $running = $this->running === [] ? true : array_shift($this->running);

                return ['running' => (bool) $running, 'exitcode' => $this->exitCode];
            }

            protected function terminateChild(): void { $this->terminations++; }

            protected function probe(): bool
            {
                return $this->probes === [] ? true : (bool) array_shift($this->probes);
            }

            protected function progressMarker(): ?string
            {
                if ($this->progress === null) {
                    return 'constant';
                }

                return $this->progress === [] ? 'end' : (string) array_shift($this->progress);
            }

            protected function now(): float { return $this->clock; }

            protected function sleep(?int $seconds = null): void
            {
                $this->sleeps[] = $seconds ?? 0;
                $this->clock += $seconds ?? EdgeWebBackendSupervisor::PROBE_INTERVAL_SECONDS;
            }

            protected function writeStatus(string $state): void
            {
                $this->states[] = $state;
                $this->stateClock[] = [$state, $this->clock];
                parent::writeStatus($state);
                $this->statusHistory[] = json_decode((string) file_get_contents($this->statusFilePath()), true) ?: [];
            }

            /** the clock value when $state was first recorded */
            public function clockAt(string $state): ?float
            {
                foreach ($this->stateClock as [$s, $c]) {
                    if ($s === $state) {
                        return $c;
                    }
                }

                return null;
            }

            /** the state recorded immediately before the first $state */
            public function statesBefore(string $state): string
            {
                $prev = '';
                foreach ($this->states as $s) {
                    if ($s === $state) {
                        return $prev;
                    }
                    $prev = $s;
                }

                return $prev;
            }

            public function stop(string $reason = 'stop'): void
            {
                // the scripted double has no proc resource: emulate the parent's "child present" branch
                $this->terminations++;
                $this->lines[] = "stop: {$reason}";
                $this->writeStatus('stopped');
            }
        };
    }
}
