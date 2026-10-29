<?php

namespace App\Services\Edge;

use App\Support\EdgeApplianceLayout;
use RuntimeException;

/**
 * F-FB4-1 (owner hotfix 0.8.1, 24 Oct 2026) — the supervisor of ONE loopback PHP web backend (`edge:local:serve --worker=N`).
 *
 * ROOT CAUSE it replaces: the P4 command relayed the child's stdout/stderr through proc_open PIPES switched to non-blocking
 * mode. On Windows `stream_set_blocking(false)` is NOT supported on proc_open pipes (it returns false), so the parent's first
 * `stream_get_contents` on the stdout pipe BLOCKED forever, the child's stderr pipe (the CLI server logs every request there)
 * filled after ~37 requests, and the child blocked on its next write — both cashier backends hung while every other worker
 * stayed healthy (LAB appliance, 24 Oct 2026; reproduced with a plain `php -S` under the same loop).
 *
 * F-FB5-1 (owner hotfix 0.8.2, 29 Oct 2026) — BUSY-AWARE liveness. The 0.8.1 policy (3 s read, 3 misses) declared a busy
 * single-threaded `php -S` "hung" while it was rendering legitimate multi-second pages on a saturated host (real LAB, 29 Oct:
 * 28 false restarts, two gateway 502s; the children were serving the whole time). The supervisor now distinguishes:
 *
 *   HEALTHY         the probe answered 200 within the read window
 *   BUSY_OR_SLOW    the probe did not answer, but the child made PROGRESS since the previous probe (its request log advanced —
 *                   `php -S` writes "Accepted"/"Closing" lines per connection) AND the last successful answer is at most
 *                   BUSY_GRACE_SECONDS old. Such a miss is NOT counted towards a restart. Log advancement alone never grants
 *                   indefinite health: once the last success is older than the grace, every miss counts.
 *   SUSPECTED_HUNG  a miss without progress (or any miss beyond the busy grace): counted; PROBE_FAILURES_TO_RESTART in a row →
 *   CONFIRMED_HUNG  the child is terminated (process tree on Windows) and
 *   RESTARTING      restarted with the exponential, capped back-off (reset after a healthy minute);
 *   FAILING         ≥ FAILING_RESTARTS restarts within FAILING_WINDOW_SECONDS — surfaced by edge:local:health as a cashier-tier problem.
 *
 * Bound (documented, tested): a child that never answers again is confirmed hung and restarted within
 * HANG_DETECTION_BOUND_SECONDS of its last successful answer — the busy grace plus PROBE_FAILURES_TO_RESTART probe cycles, each
 * at most connect timeout + read timeout + probe interval. Probes are synchronous: they never overlap, each one is bounded.
 *
 * Rules kept from 0.8.1: Windows child output → two size-capped files under the appliance DATA root (never the runtime), no
 * non-blocking pipe read on Windows; non-Windows pipe relay unchanged; the probe hits the backend's OWN loopback port (a gateway
 * 200 must never conceal a dead backend); the OS port is the singleton; every transition → task log + status file; stop()
 * terminates the child (no orphan `php -S`). Nothing here touches the lease, takeover fencing, the outbox, stock authority or
 * AUTO_FAILOVER.
 */
class EdgeWebBackendSupervisor
{
    public const PROBE_PATH = '/edge/local/health';
    public const STARTUP_GRACE_SECONDS = 15;
    public const PROBE_INTERVAL_SECONDS = 5;
    public const PROBE_CONNECT_TIMEOUT = 2.0;
    public const PROBE_READ_TIMEOUT = 10;            // F-FB5-1: was 3 — a legitimate page render on a loaded host takes seconds
    public const PROBE_FAILURES_TO_RESTART = 5;      // F-FB5-1: was 3 — consecutive COUNTED misses (busy misses inside the grace do not count)
    public const BUSY_GRACE_SECONDS = 120;           // F-FB5-1: a miss WITH child progress (requests still completing) is "busy" only while the last 200 is at most this old; silent misses count at once
    public const BACKOFF_FIRST_SECONDS = 1;
    public const BACKOFF_MAX_SECONDS = 30;
    public const BACKOFF_RESET_AFTER_HEALTHY_SECONDS = 60;
    public const FAILING_RESTARTS = 10;          // ≥ this many restarts within FAILING_WINDOW_SECONDS → state 'failing'
    public const FAILING_WINDOW_SECONDS = 600;
    public const LOG_MAX_BYTES = 5 * 1024 * 1024;  // rotate <log>.log → <log>.1.log at (re)start when larger
    public const LOOP_SLEEP_MICROSECONDS = 1000000;

    /** Worst-case seconds from the last successful answer to the termination of a child that never answers again. */
    public const HANG_DETECTION_BOUND_SECONDS = self::BUSY_GRACE_SECONDS
        + self::PROBE_FAILURES_TO_RESTART * (self::PROBE_INTERVAL_SECONDS + self::PROBE_READ_TIMEOUT + 2);

    public const CLASS_STARTING = 'starting';
    public const CLASS_HEALTHY = 'healthy';
    public const CLASS_BUSY = 'busy_or_slow';
    public const CLASS_SUSPECTED = 'suspected_hung';
    public const CLASS_CONFIRMED = 'confirmed_hung';
    public const CLASS_RESTARTING = 'restarting';
    public const CLASS_FAILING = 'failing';

    /** @var resource|null */
    private $proc = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private ?int $childPid = null;
    private float $startedAt = 0.0;
    private float $lastProbeAt = 0.0;
    private float $lastOkAt = 0.0;
    private float $healthySince = 0.0;
    private int $consecutiveProbeFailures = 0;
    private int $busyTicks = 0;
    private int $relayedBytes = 0;
    private ?string $lastProgressMarker = null;
    private string $lastProbeOutcome = 'none';
    private int $lastProbeMs = 0;
    private int $backoffSeconds = self::BACKOFF_FIRST_SECONDS;
    private int $restarts = 0;
    /** @var list<float> */
    private array $restartTimes = [];
    private ?string $lastRestartReason = null;
    private ?string $lastRestartAt = null;
    private ?int $lastExitCode = null;
    private string $state = self::CLASS_STARTING;
    private bool $stopRequested = false;

    /** @var callable(string):void */
    private $log;

    /**
     * @param  list<string>  $command  the child command (array form — no shell)
     * @param  array<string,string>  $env  the child environment
     */
    public function __construct(
        private readonly int $worker,
        private readonly string $host,
        private readonly int $port,
        private readonly array $command,
        private readonly array $env,
        private readonly string $cwd,
        private readonly string $logsDir,
        ?callable $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void { fwrite(STDOUT, $line . PHP_EOL); };
    }

    public static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    /** Where the child's output goes on Windows: distinct per backend, under the DATA root, never inside the runtime. */
    public function stdoutLogPath(): string
    {
        return $this->logsDir . DIRECTORY_SEPARATOR . "web-backend-{$this->worker}.out.log";
    }

    public function stderrLogPath(): string
    {
        return $this->logsDir . DIRECTORY_SEPARATOR . "web-backend-{$this->worker}.err.log";
    }

    public function statusFilePath(): string
    {
        return $this->logsDir . DIRECTORY_SEPARATOR . "web-backend-{$this->worker}.status.json";
    }

    public static function statusFileFor(int $worker): string
    {
        return EdgeApplianceLayout::logsDir() . DIRECTORY_SEPARATOR . "web-backend-{$worker}.status.json";
    }

    /** The policy, for the status file / health report / docs. */
    public static function policy(): array
    {
        return [
            'probe_path' => self::PROBE_PATH,
            'startup_grace_seconds' => self::STARTUP_GRACE_SECONDS,
            'probe_interval_seconds' => self::PROBE_INTERVAL_SECONDS,
            'probe_connect_timeout_seconds' => self::PROBE_CONNECT_TIMEOUT,
            'probe_read_timeout_seconds' => self::PROBE_READ_TIMEOUT,
            'busy_grace_seconds' => self::BUSY_GRACE_SECONDS,
            'failures_to_restart' => self::PROBE_FAILURES_TO_RESTART,
            'hang_detection_bound_seconds' => self::HANG_DETECTION_BOUND_SECONDS,
            'backoff_seconds' => [self::BACKOFF_FIRST_SECONDS, self::BACKOFF_MAX_SECONDS],
            'failing_restarts_per_window' => [self::FAILING_RESTARTS, self::FAILING_WINDOW_SECONDS],
        ];
    }

    /**
     * The proc_open descriptor spec. Windows: FILES (append) for stdout/stderr — the one change that removes the deadlock;
     * elsewhere: pipes, relayed non-blocking as before.
     *
     * @return array<int, array<int, string>>
     */
    public function descriptorSpec(): array
    {
        if ($this->usesFileLogs()) {
            $this->rotateIfLarge($this->stdoutLogPath());
            $this->rotateIfLarge($this->stderrLogPath());

            return [0 => ['pipe', 'r'], 1 => ['file', $this->stdoutLogPath(), 'a'], 2 => ['file', $this->stderrLogPath(), 'a']];
        }

        return [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    }

    protected function usesFileLogs(): bool
    {
        return self::isWindows();
    }

    /** Size cap: keep one previous generation (<name>.1.log); the live file restarts empty. */
    public function rotateIfLarge(string $path): bool
    {
        clearstatcache(true, $path);
        if (! is_file($path) || (int) @filesize($path) <= self::LOG_MAX_BYTES) {
            return false;
        }
        $rotated = preg_replace('/\.log$/', '.1.log', $path) ?: $path . '.1';
        @unlink($rotated);
        @rename($path, $rotated);

        return true;
    }

    /** Back-off after a failure: 1, 2, 4 … capped; a healthy minute resets it. */
    public function nextBackoff(): int
    {
        $wait = $this->backoffSeconds;
        $this->backoffSeconds = min(self::BACKOFF_MAX_SECONDS, $this->backoffSeconds * 2);

        return $wait;
    }

    public function resetBackoff(): void
    {
        $this->backoffSeconds = self::BACKOFF_FIRST_SECONDS;
    }

    /** The supervision loop. Returns the exit code the task should report (0 = asked to stop). */
    public function run(bool $once = false, int $maxIterations = PHP_INT_MAX): int
    {
        if (! is_dir($this->logsDir)) {
            @mkdir($this->logsDir, 0775, true);
        }
        if ($this->probe()) {
            // Port singleton: a live backend already answers here — a second supervisor must not fight it.
            $this->write("web backend #{$this->worker}: another backend already answers on {$this->host}:{$this->port} — exiting (one supervisor per port).");
            $this->writeStatus('duplicate');

            return 0;
        }
        $this->installStopHandlers();
        $this->spawn('start');
        $iterations = 0;
        while (! $this->stopRequested && $iterations++ < $maxIterations) {
            $status = $this->processStatus();
            $this->relayPipes();
            if (! $status['running']) {
                $this->lastExitCode = (int) $status['exitcode'];
                $this->closeChild();
                if ($once) {
                    $this->write("web backend #{$this->worker} exited with code {$this->lastExitCode} (--once: not restarting).");
                    $this->writeStatus('exited');

                    return $this->lastExitCode === 0 ? 0 : 1;
                }
                $this->restart("exited with code {$this->lastExitCode}");
                continue;
            }
            $elapsed = $this->now() - $this->startedAt;
            if ($elapsed >= self::STARTUP_GRACE_SECONDS && ($this->now() - $this->lastProbeAt) >= self::PROBE_INTERVAL_SECONDS) {
                $this->lastProbeAt = $this->now();
                $ok = $this->probe();
                $progressed = $this->childProgressed();
                if ($ok) {
                    $this->consecutiveProbeFailures = 0;
                    $this->busyTicks = 0;
                    $this->lastOkAt = $this->now();
                    if ($this->healthySince === 0.0) {
                        $this->healthySince = $this->now();
                    }
                    if ($this->state !== self::CLASS_HEALTHY) {
                        if (in_array($this->state, [self::CLASS_BUSY, self::CLASS_SUSPECTED], true)) {
                            $this->write("web backend #{$this->worker} pid {$this->childPid}: answering again ({$this->state} → healthy).");
                        }
                        $this->writeStatus(self::CLASS_HEALTHY);
                    } elseif (($this->now() - $this->healthySince) >= self::BACKOFF_RESET_AFTER_HEALTHY_SECONDS && $this->backoffSeconds !== self::BACKOFF_FIRST_SECONDS) {
                        $this->resetBackoff();
                        $this->writeStatus(self::CLASS_HEALTHY);
                    }
                } else {
                    $this->healthySince = 0.0;
                    $sinceOk = $this->secondsSinceLastOk();
                    if ($progressed && $sinceOk <= self::BUSY_GRACE_SECONDS) {
                        // F-FB5-1: the child is serving (its request log advanced) — a slow page, not a hang. Not counted.
                        $this->busyTicks++;
                        if ($this->state !== self::CLASS_BUSY) {
                            $this->write("web backend #{$this->worker} pid {$this->childPid}: probe {$this->lastProbeOutcome} but the child is making progress (last 200 {$sinceOk}s ago) — busy/slow, not restarting.");
                        }
                        $this->writeStatus(self::CLASS_BUSY);
                    } else {
                        $this->consecutiveProbeFailures++;
                        if ($this->consecutiveProbeFailures >= self::PROBE_FAILURES_TO_RESTART) {
                            $why = "hung: {$this->consecutiveProbeFailures} liveness probes failed"
                                . ($progressed ? " (progress continued but no answer for {$sinceOk}s — beyond the {$this->busyGrace()}s busy grace)" : " (no progress, no answer for {$sinceOk}s)");
                            $this->writeStatus(self::CLASS_CONFIRMED);
                            $this->write("web backend #{$this->worker} pid {$this->childPid}: {$why} on {$this->host}:{$this->port}{$this->probePath()} — terminating the hung backend.");
                            $this->terminateChild();
                            $this->closeChild();
                            if ($once) {
                                return 1;
                            }
                            $this->restart($why);
                            continue;
                        }
                        if ($this->state !== self::CLASS_SUSPECTED) {
                            $this->write("web backend #{$this->worker} pid {$this->childPid}: probe {$this->lastProbeOutcome}, " . ($progressed ? "progress but beyond the busy grace" : 'no progress') . " (last 200 {$sinceOk}s ago) — suspected hung ({$this->consecutiveProbeFailures}/" . self::PROBE_FAILURES_TO_RESTART . ').');
                        }
                        $this->writeStatus(self::CLASS_SUSPECTED);
                    }
                }
            }
            $this->sleep();
        }
        $this->stop('supervisor stop');

        return 0;
    }

    /** Terminate the child (if any) and record the stop. Safe to call twice. */
    public function stop(string $reason = 'stop'): void
    {
        $this->stopRequested = true;
        if ($this->proc !== null) {
            $this->write("web backend #{$this->worker} pid {$this->childPid}: stopping ({$reason}).");
            $this->terminateChild();
            $this->closeChild();
        }
        $this->writeStatus('stopped');
    }

    public function status(): array
    {
        return [
            'worker' => $this->worker,
            'listen' => "{$this->host}:{$this->port}",
            'pid' => $this->childPid,
            'supervisor_pid' => getmypid(),
            'state' => $this->state,
            'classification' => strtoupper($this->state),
            'started_at' => $this->startedAt > 0 ? gmdate('c', (int) $this->startedAt) : null,
            'restarts' => $this->restarts,
            'restarts_last_10min' => count(array_filter($this->restartTimes, fn ($t) => $this->now() - $t <= self::FAILING_WINDOW_SECONDS)),
            'last_restart_reason' => $this->lastRestartReason,
            'last_restart_at' => $this->lastRestartAt,
            'last_exit_code' => $this->lastExitCode,
            'consecutive_probe_failures' => $this->consecutiveProbeFailures,
            'busy_ticks' => $this->busyTicks,
            'last_probe_at' => $this->lastProbeAt > 0 ? gmdate('c', (int) $this->lastProbeAt) : null,
            'last_probe_outcome' => $this->lastProbeOutcome,
            'last_probe_ms' => $this->lastProbeMs,
            'last_ok_at' => $this->lastOkAt > 0 ? gmdate('c', (int) $this->lastOkAt) : null,
            'seconds_since_last_ok' => $this->startedAt > 0 ? $this->secondsSinceLastOk() : null,
            'progress_marker' => $this->lastProgressMarker,
            'backoff_seconds' => $this->backoffSeconds,
            'policy' => self::policy(),
            'logs' => $this->usesFileLogs() ? ['stdout' => $this->stdoutLogPath(), 'stderr' => $this->stderrLogPath()] : 'relayed to the supervisor output',
            'updated_at' => gmdate('c', (int) $this->now()),
        ];
    }

    // ── classification helpers ──────────────────────────────────────────────────────────────────────────────────────

    /** Seconds since the last successful probe — or since the start when the child never answered yet. */
    private function secondsSinceLastOk(): int
    {
        $ref = $this->lastOkAt > 0 ? $this->lastOkAt : $this->startedAt;

        return (int) max(0, $this->now() - $ref);
    }

    private function busyGrace(): int
    {
        return self::BUSY_GRACE_SECONDS;
    }

    /**
     * Did the child make observable progress since the previous probe? Windows: its request log (stderr file) grew; elsewhere:
     * bytes relayed from its pipes. The first evaluation after a spawn only records the marker (no progress claimed).
     */
    private function childProgressed(): bool
    {
        $marker = $this->progressMarker();
        $progressed = $marker !== null && $this->lastProgressMarker !== null && $marker !== $this->lastProgressMarker;
        $this->lastProgressMarker = $marker;

        return $progressed;
    }

    /** Opaque progress marker (test seam). null = no signal available (never counts as progress). */
    protected function progressMarker(): ?string
    {
        if ($this->usesFileLogs()) {
            clearstatcache(true, $this->stderrLogPath());
            clearstatcache(true, $this->stdoutLogPath());
            $err = is_file($this->stderrLogPath()) ? (int) @filesize($this->stderrLogPath()) : 0;
            $out = is_file($this->stdoutLogPath()) ? (int) @filesize($this->stdoutLogPath()) : 0;

            return "err:{$err};out:{$out}";
        }

        return 'relayed:' . $this->relayedBytes;
    }

    // ── process plumbing (protected = test seams) ───────────────────────────────────────────────────────────────────

    protected function spawn(string $why): void
    {
        $spec = $this->descriptorSpec();
        $proc = proc_open($this->command, $spec, $pipes, $this->cwd, $this->env);
        if (! is_resource($proc)) {
            throw new RuntimeException("Could not start the PHP web server process for backend #{$this->worker}.");
        }
        $this->proc = $proc;
        $this->pipes = $pipes;
        if (isset($this->pipes[0])) {
            fclose($this->pipes[0]);
            unset($this->pipes[0]);
        }
        if (! $this->usesFileLogs()) {
            // non-Windows only: the pipes exist and non-blocking mode is supported there
            foreach ([1, 2] as $i) {
                if (isset($this->pipes[$i])) {
                    stream_set_blocking($this->pipes[$i], false);
                }
            }
        }
        $status = proc_get_status($proc);
        $this->childPid = (int) ($status['pid'] ?? 0) ?: null;
        $this->startedAt = $this->now();
        $this->lastProbeAt = 0.0;
        $this->lastOkAt = 0.0;
        $this->healthySince = 0.0;
        $this->consecutiveProbeFailures = 0;
        $this->busyTicks = 0;
        $this->lastProgressMarker = $this->progressMarker();
        $this->state = self::CLASS_STARTING;
        $this->write("web backend #{$this->worker} → http://{$this->host}:{$this->port} pid {$this->childPid} ({$why})"
            . ($this->usesFileLogs() ? "; child stdout → {$this->stdoutLogPath()}, stderr → {$this->stderrLogPath()}" : ''));
        $this->writeStatus(self::CLASS_STARTING);
    }

    private function restart(string $reason): void
    {
        $this->restarts++;
        $this->restartTimes[] = $this->now();
        $this->restartTimes = array_values(array_filter($this->restartTimes, fn ($t) => $this->now() - $t <= self::FAILING_WINDOW_SECONDS));
        $this->lastRestartReason = $reason;
        $this->lastRestartAt = gmdate('c', (int) $this->now());
        $wait = $this->nextBackoff();
        $failing = count($this->restartTimes) >= self::FAILING_RESTARTS;
        $this->state = $failing ? self::CLASS_FAILING : self::CLASS_RESTARTING;
        $this->write("web backend #{$this->worker}: {$reason}; restart #{$this->restarts} in {$wait}s" . ($failing ? " — FAILING ({$this->restartTimes[0]} … {$this->restartTimes[count($this->restartTimes) - 1]}: " . count($this->restartTimes) . ' restarts in 10 min)' : ''));
        $this->writeStatus($this->state);
        $this->sleep($wait);
        if ($this->stopRequested) {
            return;
        }
        $this->spawn("restart #{$this->restarts}: {$reason}");
    }

    /** @return array{running: bool, exitcode: int} */
    protected function processStatus(): array
    {
        if ($this->proc === null) {
            return ['running' => false, 'exitcode' => $this->lastExitCode ?? -1];
        }
        $s = proc_get_status($this->proc);

        return ['running' => (bool) ($s['running'] ?? false), 'exitcode' => (int) ($s['exitcode'] ?? -1)];
    }

    protected function terminateChild(): void
    {
        if ($this->proc === null) {
            return;
        }
        if (self::isWindows() && $this->childPid) {
            // the whole tree (php -S may have spawned helpers); /F because a hung process ignores a polite close
            @exec('taskkill /PID ' . (int) $this->childPid . ' /T /F >NUL 2>&1');
        } else {
            @proc_terminate($this->proc, 15);
            for ($i = 0; $i < 20; $i++) {
                $s = proc_get_status($this->proc);
                if (! ($s['running'] ?? false)) {
                    break;
                }
                usleep(100000);
            }
            if (($s['running'] ?? false)) {
                @proc_terminate($this->proc, 9);
            }
        }
    }

    private function closeChild(): void
    {
        if ($this->proc === null) {
            return;
        }
        $this->relayPipes();
        foreach ($this->pipes as $p) {
            if (is_resource($p)) {
                @fclose($p);
            }
        }
        $this->pipes = [];
        @proc_close($this->proc);
        $this->proc = null;
    }

    /** non-Windows pipe relay (non-blocking there). Never called with pipes on Windows: there are none. */
    private function relayPipes(): void
    {
        foreach ([1, 2] as $i) {
            if (isset($this->pipes[$i]) && is_resource($this->pipes[$i])) {
                $chunk = @stream_get_contents($this->pipes[$i]);
                if ($chunk !== false && $chunk !== '') {
                    $this->relayedBytes += strlen($chunk);
                    $this->write(rtrim($chunk));
                }
            }
        }
    }

    /**
     * HTTP liveness: GET the probe path on the backend's own loopback port with bounded connect/read timeouts; 200 = alive.
     * Records the outcome (ok / refused / timeout / bad_status) and the elapsed milliseconds for the status file.
     */
    protected function probe(): bool
    {
        $t0 = microtime(true);
        $host = in_array($this->host, ['0.0.0.0', '::'], true) ? '127.0.0.1' : $this->host;
        $fp = @fsockopen($host, $this->port, $errno, $errstr, self::PROBE_CONNECT_TIMEOUT);
        if (! is_resource($fp)) {
            $this->lastProbeOutcome = 'refused';
            $this->lastProbeMs = (int) ((microtime(true) - $t0) * 1000);

            return false;
        }
        stream_set_timeout($fp, self::PROBE_READ_TIMEOUT);
        fwrite($fp, "GET {$this->probePath()} HTTP/1.0\r\nHost: {$host}:{$this->port}\r\nConnection: close\r\nUser-Agent: edge-web-supervisor/{$this->worker}\r\n\r\n");
        $line = fgets($fp, 256);
        $meta = stream_get_meta_data($fp);
        fclose($fp);
        $this->lastProbeMs = (int) ((microtime(true) - $t0) * 1000);
        if ($line === false || ($meta['timed_out'] ?? false)) {
            $this->lastProbeOutcome = 'timeout';

            return false;
        }
        $ok = (bool) preg_match('#^HTTP/1\.[01] 200\b#', $line);
        $this->lastProbeOutcome = $ok ? 'ok' : 'bad_status';

        return $ok;
    }

    protected function probePath(): string
    {
        return self::PROBE_PATH;
    }

    protected function now(): float
    {
        return microtime(true);
    }

    protected function sleep(?int $seconds = null): void
    {
        if ($seconds === null) {
            usleep(self::LOOP_SLEEP_MICROSECONDS);

            return;
        }
        for ($i = 0; $i < $seconds && ! $this->stopRequested; $i++) {
            sleep(1);
        }
    }

    private function installStopHandlers(): void
    {
        register_shutdown_function(function (): void {
            if ($this->proc !== null) {
                $this->stop('shutdown');
            }
        });
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $sig) {
                pcntl_signal($sig, function () { $this->stopRequested = true; });
            }
        }
    }

    protected function write(string $line): void
    {
        ($this->log)('[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $line);
    }

    protected function writeStatus(string $state): void
    {
        $this->state = $state;
        $status = $this->status();
        $tmp = $this->statusFilePath() . '.tmp';
        if (@file_put_contents($tmp, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $this->statusFilePath());
        }
    }
}
