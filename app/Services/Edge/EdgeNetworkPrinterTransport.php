<?php

namespace App\Services\Edge;

/**
 * EDGE-LOCAL-PRINT-1 (§12) — the minimal Branch Server TCP transport to a LAN thermal printer.
 *
 * Cloud parity, pinned byte-for-byte: the Windows print agent writes the stored raw_payload followed
 * by "\n\n\n" to TCP port 9100 (print-agent.js sendToNetworkPrinter). This transport does EXACTLY the
 * same — the exact STORED payload (never rebuilt at delivery time, §2) plus the same trailing feed.
 * No ESC/POS init/cut/codepage commands in this slice (payload capability work is a separate pass).
 *
 * A completed socket write is transport success only — NOT proof paper physically emerged. Physical
 * printing is AT-LEAST-ONCE by design.
 *
 * 0.8.3 (F-3): every failure is an EdgePrinterTransportException whose category is the SAME classification the
 * health/print-status reachability probe uses (refused | timeout | unreachable | unresolved | error) for the connect
 * phase, or write_timeout | write_failed once the connection was accepted. The caller's bounded retry ladder is
 * unchanged; the category tells the operator what to fix and lands verbatim in the delivery row's last_error.
 */
class EdgeNetworkPrinterTransport
{
    public const CONNECT_TIMEOUT_SECONDS = 8;
    public const WRITE_TIMEOUT_SECONDS = 8;

    /** The exact bytes the Cloud agent appends after raw_payload — keep in lockstep with print-agent.js. */
    public const TRAILING_FEED = "\n\n\n";

    /** @throws EdgePrinterTransportException on any connect/write failure (caller applies the bounded retry policy) */
    public function send(string $ip, int $port, string $rawPayload): void
    {
        $errno = 0;
        $errstr = '';
        $t0 = microtime(true);
        $socket = @stream_socket_client(
            'tcp://' . $ip . ':' . $port,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS
        );
        if ($socket === false) {
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $category = EdgePrinterReachabilityProbe::classify((int) $errno, (string) $errstr, $ms, (float) self::CONNECT_TIMEOUT_SECONDS);
            throw new EdgePrinterTransportException($category, "printer connect failed [{$ip}:{$port}] after {$ms} ms: " . trim((string) $errstr) . " ({$errno})", (int) $errno ?: null);
        }

        try {
            stream_set_timeout($socket, self::WRITE_TIMEOUT_SECONDS);
            $data = $rawPayload . self::TRAILING_FEED;
            $total = strlen($data);
            $written = 0;
            while ($written < $total) {
                $n = @fwrite($socket, substr($data, $written));
                $meta = stream_get_meta_data($socket);
                if (! empty($meta['timed_out'])) {
                    throw new EdgePrinterTransportException(EdgePrinterTransportException::WRITE_TIMEOUT, "printer write timed out [{$ip}:{$port}] after {$written}/{$total} bytes (" . self::WRITE_TIMEOUT_SECONDS . 's)');
                }
                if ($n === false || $n === 0) {
                    throw new EdgePrinterTransportException(EdgePrinterTransportException::WRITE_FAILED, "printer write failed [{$ip}:{$port}] after {$written}/{$total} bytes");
                }
                $written += $n;
            }
        } finally {
            fclose($socket);
        }
    }
}
