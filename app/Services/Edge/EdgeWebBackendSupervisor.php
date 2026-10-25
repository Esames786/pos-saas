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
 * Rules (owner §1/§2):
 *   - Windows: the child's stdout and stderr go to two separate, worker-specific, size-capped LOG FILES under the appliance
 *     data root (never under the signed, immutable runtime directory); no non-blocking pipe read ever happens on Windows.
 *   - non-Windows: the pipe relay stays (non-blocking pipes work there) — behaviour unchanged.
 *   - liveness: an HTTP GET of `/edge/local/health` on the backend's OWN loopback port (the gateway is never asked — a gateway
 *     200 must not conceal a dead backend), bounded connect/read timeouts, a startup grace, N consecutive failures = hung →
 *     the child is terminated (process tree on Windows) and restarted with exponential back-off (capped) that resets after a
 *     healthy minute; only THIS backend is touched (one supervisor per backend, as the service plan prescribes).
 *   - duplicates: the OS port is the singleton; when the port already answers the probe at start, this supervisor exits
 *     instead of fighting the running backend.
 *   - record: every start / restart (reason, pid, back-off, outcome) is written to the supervisor's stdout (the task log) and to
 *     `<logs>/web-backend-<N>.status.json`, which `edge:local:health` surfaces; a backend that keeps failing is reported as
 *     'failing' (the cashier service is DEGRADED — authority and sync workers being healthy never makes the web tier healthy).
 *   - stop: the supervisor terminates its child when it exits (shutdown function; SIGTERM/SIGINT where pcntl exists) — no
 *     orphan `php -S` keeps the cashier port.
 *
 * Nothing here touches the lease, takeover fencing, the outbox, stock authority or AUTO_FAILOVER.
 */
class EdgeWebBackendSupervisor
{
    public const PROBE_PATH = '/edge/local/health';
    public const STARTUP_GRACE_SECONDS = 15;
    public const PROBE_INTERVAL_SECONDS = 5;
    public const PROBE_CONNECT_TIMEOUT = 2.0;
    public const PROBE_READ_TIMEOUT = 3;
    public const PROBE_FAILURES_TO_RESTART = 3;
    public const BACKOFF_FIRST_SECONDS = 1;
    public const BACKOFF_MAX_SECONDS = 30;
    public const BACKOFF_RESET_AFTER_HEALTHY_SECONDS = 60;
    public const FAILING_RESTARTS = 10;          // ≥ this many restarts within FAILING_WINDOW_SECONDS → state 'failing'
    public const FAILING_WINDOW_SECONDS = 600;
    public const LOG_MAX_BYTES = 5 * 1024 * 1024;  // rotate <log>.log → <log>.1.log at (re)start when larger
    public const LOOP_SLEEP_MICROSECONDS = 1000000;

    /** @var resource|null */
    private $proc = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private ?int $childPid = null;
    private float $startedAt = 0.0;
    private float $lastProbeAt = 0.0;
    private float $healthySince = 0.0;
    private int $consecutiveProbeFailures = 0;
    private int $backoffSeconds = self::BACKOFF_FIRST_SECONDS;
    private int $restarts = 0;
    /** @var list<float> */
    private array $restartTimes = [];
    private ?string $lastRestartReason = null;
    private ?string $lastRestartAt = null;
    private ?int $lastExitCode = null;
    private string $state = 'starting';
    private bool $stopRequested = false;
    private ?string $forcedPhase = null;

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
                if ($this->probe()) {
                    $this->consecutiveProbeFailures = 0;
                    if ($this->healthySince === 0.0) {
                        $this->healthySince = $this->now();
                    }
                    if ($this->state !== 'healthy') {
                        $this->state = 'healthy';
                        $this->writeStatus('healthy');
                    } elseif (($this->now() - $this->healthySince) >= self::BACKOFF_RESET_AFTER_HEALTHY_SECONDS && $this->backoffSeconds !== self::BACKOFF_FIRST_SECONDS) {
                        $this->resetBackoff();
                        $this->writeStatus('healthy');
                    }
                } else {
                    $this->consecutiveProbeFailures++;
                    $this->healthySince = 0.0;
                    $this->writeStatus($this->state === 'healthy' ? 'probe-failing' : $this->state);
                    if ($this->consecutiveProbeFailures >= self::PROBE_FAILURES_TO_RESTART) {
                        $this->write("web backend #{$this->worker} pid {$this->childPid}: {$this->consecutiveProbeFailures} liveness probes failed on {$this->host}:{$this->port}{$this->probePath()} — terminating the hung backend.");
                        $this->terminateChild();
                        $this->closeChild();
                        if ($once) {
                            $this->writeStatus('hung');

                            return 1;
                        }
                        $this->restart("hung: {$this->consecutiveProbeFailures} liveness probes failed");
                        continue;
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
            'started_at' => $this->startedAt > 0 ? gmdate('c', (int) $this->startedAt) : null,
            'restarts' => $this->restarts,
            'restarts_last_10min' => count(array_filter($this->restartTimes, fn ($t) => $this->now() - $t <= self::FAILING_WINDOW_SECONDS)),
            'last_restart_reason' => $this->lastRestartReason,
            'last_restart_at' => $this->lastRestartAt,
            'last_exit_code' => $this->lastExitCode,
            'consecutive_probe_failures' => $this->consecutiveProbeFailures,
            'last_probe_at' => $this->lastProbeAt > 0 ? gmdate('c', (int) $this->lastProbeAt) : null,
            'backoff_seconds' => $this->backoffSeconds,
            'logs' => $this->usesFileLogs() ? ['stdout' => $this->stdoutLogPath(), 'stderr' => $this->stderrLogPath()] : 'relayed to the supervisor output',
            'updated_at' => gmdate('c', (int) $this->now()),
        ];
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
        $this->healthySince = 0.0;
        $this->consecutiveProbeFailures = 0;
        $this->state = 'starting';
        $this->write("web backend #{$this->worker} → http://{$this->host}:{$this->port} pid {$this->childPid} ({$why})"
            . ($this->usesFileLogs() ? "; child stdout → {$this->stdoutLogPath()}, stderr → {$this->stderrLogPath()}" : ''));
        $this->writeStatus('starting');
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
        $this->state = $failing ? 'failing' : 'restarting';
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
                    $this->write(rtrim($chunk));
                }
            }
        }
    }

    /** HTTP liveness: GET the probe path on the backend's own loopback port with bounded timeouts; 200 = alive. */
    protected function probe(): bool
    {
        $host = in_array($this->host, ['0.0.0.0', '::'], true) ? '127.0.0.1' : $this->host;
        $fp = @fsockopen($host, $this->port, $errno, $errstr, self::PROBE_CONNECT_TIMEOUT);
        if (! is_resource($fp)) {
            return false;
        }
        stream_set_timeout($fp, self::PROBE_READ_TIMEOUT);
        fwrite($fp, "GET {$this->probePath()} HTTP/1.0\r\nHost: {$host}:{$this->port}\r\nConnection: close\r\nUser-Agent: edge-web-supervisor/{$this->worker}\r\n\r\n");
        $line = fgets($fp, 256);
        $meta = stream_get_meta_data($fp);
        fclose($fp);
        if ($line === false || ($meta['timed_out'] ?? false)) {
            return false;
        }

        return (bool) preg_match('#^HTTP/1\.[01] 200\b#', $line);
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
