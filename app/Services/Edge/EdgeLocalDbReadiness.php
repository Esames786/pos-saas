<?php

namespace App\Services\Edge;

use App\Support\EdgeLocalDatabase;
use PDO;
use PDOException;
use Throwable;

/**
 * EDGE 0.8.3 SERVICE RELIABILITY (F-1) — bounded, CLASSIFIED readiness of the Edge-local database.
 *
 * After a Windows reboot the appliance tasks (AtStartup, all in parallel) can start before MySQL/MariaDB listens. The
 * workers used to wait 20 x 3 s on "any exception" and then exit 1. That hides the difference between "MySQL is still
 * booting" (keep waiting), "MySQL refuses the credentials" (never going to work — a configuration defect to name), and
 * "the database does not exist" (same). This service makes ONE probe with a hard connect timeout and classifies it:
 *
 *   ready             SELECT 1 answered on the configured Edge-local database
 *   refused           TCP connect refused — nothing listens on host:port yet (MySQL still starting / service not up)
 *   timeout           connect timed out (host unreachable or the listener is wedged)
 *   auth_failed       1045 access denied — wrong EDGE_DB_USERNAME/EDGE_DB_PASSWORD (NOT retryable)
 *   unknown_database  1049 — EDGE_DB_DATABASE does not exist (NOT retryable)
 *   not_initialised   connected, but the Edge schema (edge_local_meta) is absent — run edge:local:db-init (NOT retryable)
 *   not_configured    EDGE_DB_HOST / EDGE_DB_DATABASE missing (NOT retryable)
 *   error             anything else (retryable — MySQL answers odd things while it initialises)
 *
 * await() repeats the probe at a fixed interval until ready, until a NON-retryable class appears, or until the wall-clock
 * budget is spent. Bounded — never an unbounded spin; the Scheduled Task's own restart-on-failure provides the
 * "effectively unbounded" outer loop. Non-secret by construction: the report names host, port, database and the class,
 * never the user or password (the message of a PDO exception may carry the user — it is NOT copied into the report).
 */
class EdgeLocalDbReadiness
{
    public const READY = 'ready';
    public const REFUSED = 'refused';
    public const TIMEOUT = 'timeout';
    public const AUTH_FAILED = 'auth_failed';
    public const UNKNOWN_DATABASE = 'unknown_database';
    public const NOT_INITIALISED = 'not_initialised';
    public const NOT_CONFIGURED = 'not_configured';
    public const ERROR = 'error';

    public const NON_RETRYABLE = [self::AUTH_FAILED, self::UNKNOWN_DATABASE, self::NOT_INITIALISED, self::NOT_CONFIGURED];

    /** Hard bound for ONE connect attempt (PDO::ATTR_TIMEOUT); MySQL on loopback answers in milliseconds when it is up. */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** @var null|callable(): array test seam replacing the real probe */
    private static $fakeProbe = null;

    /**
     * One classified probe. Never throws.
     *
     * @return array{ready: bool, status: string, retryable: bool, host: ?string, port: ?int, database: ?string, latency_ms: int, detail: ?string, errno: ?int}
     */
    public function probe(): array
    {
        if (self::$fakeProbe !== null) {
            return (self::$fakeProbe)();
        }
        $cfg = (array) config('database.connections.' . EdgeLocalDatabase::CONNECTION, []);
        $host = isset($cfg['host']) ? (string) $cfg['host'] : null;
        $port = isset($cfg['port']) ? (int) $cfg['port'] : 3306;
        $database = isset($cfg['database']) ? (string) $cfg['database'] : null;
        $base = ['ready' => false, 'status' => self::ERROR, 'retryable' => true, 'host' => $host, 'port' => $port, 'database' => $database, 'latency_ms' => 0, 'detail' => null, 'errno' => null];

        // Target SAFETY (loopback-only, dedicated name) is enforced by the schema-work commands (db-init / schema-upgrade /
        // EdgeLocalDatabase::assertSafeTarget), not by the readiness wait: the workers never gated on it and the MySQL
        // suites point edge_local at tenant-named test databases. The wait only answers "is it reachable and initialised?".
        if ($host === null || $host === '' || $database === null || $database === '') {
            return ['status' => self::NOT_CONFIGURED, 'retryable' => false, 'detail' => 'EDGE_DB_HOST / EDGE_DB_DATABASE not configured'] + $base;
        }

        $t0 = microtime(true);
        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database}",
                (string) ($cfg['username'] ?? ''),
                (string) ($cfg['password'] ?? ''),
                [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->query('SELECT 1')->fetchColumn();
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $hasMeta = (bool) $pdo->query("SHOW TABLES LIKE 'edge_local_meta'")->fetchColumn();
            $pdo = null;
            if (! $hasMeta) {
                return ['status' => self::NOT_INITIALISED, 'retryable' => false, 'latency_ms' => $ms, 'detail' => 'connected; the Edge schema is absent (run edge:local:db-init)'] + $base;
            }

            return ['ready' => true, 'status' => self::READY, 'retryable' => false, 'latency_ms' => $ms, 'detail' => 'SELECT 1 answered'] + $base;
        } catch (PDOException $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);
            [$status, $retryable, $errno] = self::classify($e, $ms);

            return ['status' => $status, 'retryable' => $retryable, 'latency_ms' => $ms, 'detail' => self::sanitizedDetail($status, $errno, $host, $port, $database), 'errno' => $errno] + $base;
        } catch (Throwable $e) {
            return ['latency_ms' => (int) round((microtime(true) - $t0) * 1000), 'detail' => 'probe error (' . class_basename($e) . ')'] + $base;
        }
    }

    /**
     * Wait (bounded) for READY. Stops early on a non-retryable class. $onAttempt receives every probe result.
     *
     * @return array{ready: bool, attempts: int, waited_seconds: int, last: array}
     */
    public function await(int $timeoutSeconds, int $intervalSeconds = 3, ?callable $onAttempt = null): array
    {
        $timeoutSeconds = max(0, $timeoutSeconds);
        $intervalSeconds = max(1, $intervalSeconds);
        $start = microtime(true);
        $attempts = 0;
        do {
            $attempts++;
            $r = $this->probe();
            if ($onAttempt !== null) {
                $onAttempt($r, $attempts);
            }
            if ($r['ready'] || ! $r['retryable']) {
                break;
            }
            $remaining = $timeoutSeconds - (microtime(true) - $start);
            if ($remaining <= 0) {
                break;
            }
            sleep((int) min($intervalSeconds, max(1, ceil($remaining))));
        } while (true);

        return ['ready' => (bool) $r['ready'], 'attempts' => $attempts, 'waited_seconds' => (int) round(microtime(true) - $start), 'last' => $r];
    }

    /** Test seam (null restores the real probe). */
    public static function fakeProbe(?callable $probe): void
    {
        self::$fakeProbe = $probe;
    }

    /** @return array{0: string, 1: bool, 2: ?int} status, retryable, driver errno */
    public static function classify(PDOException $e, int $elapsedMs): array
    {
        $errno = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : (is_int($e->getCode()) ? (int) $e->getCode() : null);
        $msg = strtolower($e->getMessage());
        if ($errno === 1045 || str_contains($msg, 'access denied for user')) {
            return [self::AUTH_FAILED, false, $errno ?: 1045];
        }
        if ($errno === 1049 || str_contains($msg, 'unknown database')) {
            return [self::UNKNOWN_DATABASE, false, $errno ?: 1049];
        }
        if ($errno === 1044) {
            return [self::AUTH_FAILED, false, 1044]; // access denied to the database
        }
        if ($errno === 2002 || str_contains($msg, 'actively refused') || str_contains($msg, 'connection refused') || str_contains($msg, 'no connection could be made')) {
            // 2002 also covers "timed out" on some builds: separate by the wording or the elapsed time.
            if (str_contains($msg, 'timed out') || str_contains($msg, 'did not properly respond') || $elapsedMs >= self::CONNECT_TIMEOUT_SECONDS * 1000 - 200) {
                return [self::TIMEOUT, true, $errno ?: 2002];
            }

            return [self::REFUSED, true, $errno ?: 2002];
        }
        if (str_contains($msg, 'timed out') || str_contains($msg, 'did not properly respond') || $errno === 2003 && $elapsedMs >= self::CONNECT_TIMEOUT_SECONDS * 1000 - 200) {
            return [self::TIMEOUT, true, $errno];
        }
        if ($errno === 2003 || $errno === 2006 || $errno === 2013) {
            return [self::REFUSED, true, $errno];
        }

        return [self::ERROR, true, $errno];
    }

    private static function sanitizedDetail(string $status, ?int $errno, string $host, int $port, string $database): string
    {
        return match ($status) {
            self::REFUSED => "nothing listens on {$host}:{$port} (connect refused" . ($errno ? ", errno {$errno}" : '') . ') - MySQL still starting or its service is down',
            self::TIMEOUT => "connect to {$host}:{$port} timed out after " . self::CONNECT_TIMEOUT_SECONDS . 's',
            self::AUTH_FAILED => "MySQL at {$host}:{$port} rejected the Edge credentials (errno {$errno}) - check EDGE_DB_USERNAME / EDGE_DB_PASSWORD",
            self::UNKNOWN_DATABASE => "database [{$database}] does not exist on {$host}:{$port} (errno {$errno})",
            default => 'database error' . ($errno ? " (errno {$errno})" : ''),
        };
    }
}
