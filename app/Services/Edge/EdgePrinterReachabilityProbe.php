<?php

namespace App\Services\Edge;

/**
 * EDGE 0.8.3 SERVICE RELIABILITY (F-3, Agent C gap G2) — a bounded TCP reachability probe for a LAN thermal printer,
 * and the ONE place a socket connect error is classified (shared by the health report, print-status and the print
 * worker's transport so an operator reads the same word everywhere):
 *
 *   reachable     TCP connect succeeded (the printer's raw port accepts a connection; NOT proof of paper)
 *   refused       the host answered "connection refused" — wrong port, printer rebooting, raw printing disabled
 *   timeout       no answer within the bound — powered off, wrong IP, cable out, other VLAN
 *   unreachable   host/network unreachable (ICMP) — no route; typically a wrong subnet or a down interface
 *   unresolved    the address did not resolve (a hostname was configured and DNS/mDNS failed)
 *   error         anything else (errno kept)
 *
 * The probe NEVER writes a byte (a raw-9100 printer prints whatever it receives). It opens and closes one connection,
 * bounded by $timeout. A probe is never a print job and never changes a job's state.
 */
class EdgePrinterReachabilityProbe
{
    public const REACHABLE = 'reachable';
    public const REFUSED = 'refused';
    public const TIMEOUT = 'timeout';
    public const UNREACHABLE = 'unreachable';
    public const UNRESOLVED = 'unresolved';
    public const ERROR = 'error';

    /**
     * Health/status probes are short: a printer on the LAN answers a SYN in milliseconds. NOT shorter than 2.5 s: the
     * Windows TCP stack reports a REFUSED connect only after its SYN retransmit — measured 2.02-2.05 s for every closed
     * loopback port on the dev host (30 Oct 2026, 4/4 samples, errno 10061) — so a 1-2 s bound misreports "refused"
     * (host up, port closed: wrong port / raw printing off) as "timeout" (host down). Linux answers a RST at once.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 3.0;
    public const MIN_TIMEOUT_SECONDS = 2.5;

    /** Health probes at most this many printers per report (bounded report time: N x timeout; a branch has 1-3 printers). */
    public const MAX_PRINTERS_PER_REPORT = 4;

    /** @var null|callable(string,int,float): array test seam */
    private static $fake = null;

    /** @return array{status: string, latency_ms: int, errno: ?int, detail: string, ip: string, port: int, probed_at: string} */
    public function probe(string $ip, int $port, float $timeout = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        if (self::$fake !== null) {
            return (self::$fake)($ip, $port, $timeout);
        }
        $t0 = microtime(true);
        $errno = 0;
        $errstr = '';
        $timeout = max(self::MIN_TIMEOUT_SECONDS, $timeout);
        $socket = @stream_socket_client('tcp://' . $ip . ':' . $port, $errno, $errstr, $timeout);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if (is_resource($socket)) {
            fclose($socket);

            return ['status' => self::REACHABLE, 'latency_ms' => $ms, 'errno' => null, 'detail' => 'TCP connect ok', 'ip' => $ip, 'port' => $port, 'probed_at' => now()->toIso8601String()];
        }
        $status = self::classify((int) $errno, (string) $errstr, $ms, $timeout);

        return ['status' => $status, 'latency_ms' => $ms, 'errno' => (int) $errno ?: null, 'detail' => self::describe($status, (int) $errno, (string) $errstr), 'ip' => $ip, 'port' => $port, 'probed_at' => now()->toIso8601String()];
    }

    /** Classify a failed connect by errno (Windows WSA* and POSIX), falling back to wording and elapsed time. */
    public static function classify(int $errno, string $errstr, int $elapsedMs, float $timeout): string
    {
        $m = strtolower($errstr);
        switch ($errno) {
            case 10061: case 111: // WSAECONNREFUSED / ECONNREFUSED
                return self::REFUSED;
            case 10060: case 110: // WSAETIMEDOUT / ETIMEDOUT
                return self::TIMEOUT;
            case 10065: case 10051: case 113: case 101: // host / network unreachable
                return self::UNREACHABLE;
            case 11001: case 11004: case -2: case -3: case -5: // WSAHOST_NOT_FOUND / getaddrinfo failures
                return self::UNRESOLVED;
        }
        if (str_contains($m, 'refused')) {
            return self::REFUSED;
        }
        if (str_contains($m, 'timed out') || str_contains($m, 'did not properly respond') || str_contains($m, 'timeout')) {
            return self::TIMEOUT;
        }
        if (str_contains($m, 'unreachable')) {
            return self::UNREACHABLE;
        }
        if (str_contains($m, 'getaddrinfo') || str_contains($m, 'not known') || str_contains($m, 'no such host')) {
            return self::UNRESOLVED;
        }
        if ($elapsedMs >= (int) ($timeout * 1000) - 50) {
            return self::TIMEOUT; // PHP reports some stream timeouts with errno 0 and a generic message
        }

        return self::ERROR;
    }

    public static function describe(string $status, int $errno, string $errstr): string
    {
        $suffix = $errno ? " (errno {$errno})" : '';

        return match ($status) {
            self::REFUSED => 'connection refused - the host is up but nothing accepts on this port (wrong port, raw printing off, printer rebooting)' . $suffix,
            self::TIMEOUT => 'connect timed out - printer off, wrong IP, cable out or another network' . $suffix,
            self::UNREACHABLE => 'host or network unreachable - no route to the printer' . $suffix,
            self::UNRESOLVED => 'printer address did not resolve' . $suffix,
            default => 'connect error: ' . trim($errstr) . $suffix,
        };
    }

    /** Test seam (null restores the real socket probe). */
    public static function fake(?callable $probe): void
    {
        self::$fake = $probe;
    }
}
