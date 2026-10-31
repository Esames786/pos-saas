<?php

namespace App\Support;

/**
 * EDGE 0.8.3 SERVICE RELIABILITY (F-G10) — OS-level liveness of a recorded worker process.
 *
 * The singleton worker rows (print worker, authority worker) used to decide "another worker is live" from the
 * heartbeat age alone (< 90 s = live). That is wrong twice on a real appliance: after a FAST reboot (an SSD laptop
 * reboots in < 90 s) and after a crash the Scheduled Task restarts the worker within 1 minute — in both cases the
 * previous process is GONE but its heartbeat is still fresh, so the new worker exited 0 as a "duplicate" and the
 * appliance stayed without a worker until the next boot (Agent C finding G10, 30 Oct 2026).
 *
 * The fix records the worker's PROCESS IDENTITY (pid + process start time + host) next to the heartbeat and asks
 * the OS whether THAT process still exists before trusting a fresh heartbeat:
 *
 *   alive   = the pid runs AND its start time matches the recorded one (a reused pid never counts as alive)
 *   dead    = the pid is not running, or it runs with a different start time (pid reuse)
 *   unknown = nothing recorded (a pre-0.8.3 row), another host, or the OS probe failed/timed out
 *
 * Only `alive` and `dead` change the decision; `unknown` falls back to the heartbeat rule exactly as before, so
 * nothing gets WORSE on a host where the probe cannot run. This class never kills, signals or lists by name: it asks
 * about ONE pid and compares a start time. It is not a lease or authority of any kind.
 */
final class EdgeProcessLiveness
{
    public const ALIVE = 'alive';
    public const DEAD = 'dead';
    public const UNKNOWN = 'unknown';

    /** Start-time tokens of the same process read through two OS APIs may differ by rounding. */
    public const TOKEN_TOLERANCE_SECONDS = 2;

    /** Hard bound for one OS probe (PowerShell start-up on a saturated host is ~1 s; 15 s is far beyond it). */
    public const PROBE_TIMEOUT_SECONDS = 15;

    private const TOKEN_PREFIX = 'unix:';

    /** @var null|callable(int): array{exists: ?bool, token: ?string, method: string} test seam */
    private static $prober = null;

    /** The identity of THIS process, as the worker records it next to its heartbeat. */
    public static function self(): array
    {
        $pid = (int) getmypid();
        $probe = self::probe($pid);

        return ['pid' => $pid, 'host' => self::host(), 'token' => $probe['token']];
    }

    public static function host(): string
    {
        $h = @gethostname();

        return is_string($h) && $h !== '' ? mb_substr($h, 0, 64) : 'unknown-host';
    }

    /**
     * Is the recorded process still the one that is running?
     *
     * @return array{status: string, reason: string, observed_token: ?string, method: ?string}
     */
    public static function check(?int $pid, ?string $token, ?string $host): array
    {
        if ($pid === null || $pid <= 0) {
            return ['status' => self::UNKNOWN, 'reason' => 'no process identity recorded (worker row predates 0.8.3)', 'observed_token' => null, 'method' => null];
        }
        if ($host !== null && $host !== '' && strcasecmp($host, self::host()) !== 0) {
            return ['status' => self::UNKNOWN, 'reason' => "recorded on another host ({$host})", 'observed_token' => null, 'method' => null];
        }
        if ($pid === (int) getmypid()) {
            return ['status' => self::ALIVE, 'reason' => 'this very process', 'observed_token' => $token, 'method' => 'self'];
        }
        $probe = self::probe($pid);
        if ($probe['exists'] === null) {
            return ['status' => self::UNKNOWN, 'reason' => 'process probe unavailable (' . $probe['method'] . ')', 'observed_token' => null, 'method' => $probe['method']];
        }
        if ($probe['exists'] === false) {
            return ['status' => self::DEAD, 'reason' => "pid {$pid} is not running", 'observed_token' => null, 'method' => $probe['method']];
        }
        if (self::bareToken($token) === '' || $probe['token'] === null) {
            return ['status' => self::ALIVE, 'reason' => "pid {$pid} is running (start time not comparable)", 'observed_token' => $probe['token'], 'method' => $probe['method']];
        }
        if (self::tokensMatch($token, $probe['token'])) {
            return ['status' => self::ALIVE, 'reason' => "pid {$pid} is running with the recorded start time", 'observed_token' => $probe['token'], 'method' => $probe['method']];
        }

        return ['status' => self::DEAD, 'reason' => "pid {$pid} was reused by a different process (start time differs)", 'observed_token' => $probe['token'], 'method' => $probe['method']];
    }

    public static function tokensMatch(string $recorded, string $observed): bool
    {
        $a = self::tokenSeconds($recorded);
        $b = self::tokenSeconds($observed);
        if ($a === null || $b === null) {
            return self::bareToken($recorded) === self::bareToken($observed);
        }

        return abs($a - $b) <= self::TOKEN_TOLERANCE_SECONDS;
    }

    /** The token without its "@<worker_uuid>" binding suffix (EdgeWorkerSlotPolicy binds the token to the row's uuid). */
    public static function bareToken(?string $token): string
    {
        if ($token === null) {
            return '';
        }
        $at = strpos($token, '@');

        return $at === false ? $token : substr($token, 0, $at);
    }

    public static function tokenSeconds(?string $token): ?int
    {
        $bare = self::bareToken($token);
        if (! str_starts_with($bare, self::TOKEN_PREFIX)) {
            return null;
        }
        $v = substr($bare, strlen(self::TOKEN_PREFIX));

        return ctype_digit($v) ? (int) $v : null;
    }

    /**
     * Ask the OS about ONE pid: does it exist, and when did it start (unix seconds, as a token)?
     *
     * @return array{exists: ?bool, token: ?string, method: string}  exists=null means "could not tell"
     */
    public static function probe(int $pid): array
    {
        if (self::$prober !== null) {
            return (self::$prober)($pid);
        }
        if ($pid <= 0) {
            return ['exists' => false, 'token' => null, 'method' => 'invalid-pid'];
        }

        return PHP_OS_FAMILY === 'Windows' ? self::probeWindows($pid) : self::probeProc($pid);
    }

    /** Test seam: replace the OS probe (null restores the real one). */
    public static function fakeProbe(?callable $prober): void
    {
        self::$prober = $prober;
    }

    /**
     * Windows: Get-Process (fast, same user) with a Win32_Process CreationDate fallback (cross-user). A pid that is
     * not running makes Get-Process throw → DEAD. Output protocol: "ALIVE <unix-seconds>" | "ALIVE ?" | "DEAD".
     */
    private static function probeWindows(int $pid): array
    {
        $script = "try { \$p = Get-Process -Id {$pid} -ErrorAction Stop; try { 'ALIVE ' + ([DateTimeOffset]\$p.StartTime).ToUnixTimeSeconds() } catch { "
            . "\$c = Get-CimInstance Win32_Process -Filter 'ProcessId={$pid}'; if (\$c -and \$c.CreationDate) { 'ALIVE ' + ([DateTimeOffset]\$c.CreationDate).ToUnixTimeSeconds() } else { 'ALIVE ?' } } } catch { 'DEAD' }";
        $out = self::runBounded(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script]);
        if ($out === null) {
            return ['exists' => null, 'token' => null, 'method' => 'windows-powershell (failed or timed out)'];
        }
        $out = trim($out);
        if ($out === 'DEAD') {
            return ['exists' => false, 'token' => null, 'method' => 'windows-powershell'];
        }
        if (preg_match('/^ALIVE (\d{9,11})$/', $out, $m) === 1) {
            return ['exists' => true, 'token' => self::TOKEN_PREFIX . $m[1], 'method' => 'windows-powershell'];
        }
        if (str_starts_with($out, 'ALIVE')) {
            return ['exists' => true, 'token' => null, 'method' => 'windows-powershell'];
        }

        return ['exists' => null, 'token' => null, 'method' => 'windows-powershell (unexpected output)'];
    }

    /** Linux/BSD-like: /proc/<pid>/stat field 22 (start ticks after boot) + /proc/stat btime. */
    private static function probeProc(int $pid): array
    {
        if (! is_dir('/proc')) {
            return ['exists' => null, 'token' => null, 'method' => 'no-procfs'];
        }
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return ['exists' => false, 'token' => null, 'method' => 'procfs'];
        }
        $close = strrpos($stat, ')');
        $fields = $close === false ? [] : preg_split('/\s+/', trim(substr($stat, $close + 1)));
        $startTicks = isset($fields[19]) && ctype_digit($fields[19]) ? (int) $fields[19] : null; // field 22 overall = index 19 after "state"
        $btime = null;
        if (preg_match('/^btime (\d+)/m', (string) @file_get_contents('/proc/stat'), $m) === 1) {
            $btime = (int) $m[1];
        }
        if ($startTicks === null || $btime === null) {
            return ['exists' => true, 'token' => null, 'method' => 'procfs'];
        }
        $hz = 100; // CLK_TCK on every mainstream Linux; a 2 s tolerance absorbs any rounding

        return ['exists' => true, 'token' => self::TOKEN_PREFIX . ($btime + intdiv($startTicks, $hz)), 'method' => 'procfs'];
    }

    /** Run a short probe process with a hard wall-clock bound; null on failure/timeout. Never throws. */
    private static function runBounded(array $cmd): ?string
    {
        $pipes = [];
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + self::PROBE_TIMEOUT_SECONDS;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (! $status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                return null;
            }
            usleep(20_000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $out;
    }
}
