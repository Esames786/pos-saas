<?php

namespace App\Services\Edge;

use RuntimeException;

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
 */
class EdgeNetworkPrinterTransport
{
    public const CONNECT_TIMEOUT_SECONDS = 8;
    public const WRITE_TIMEOUT_SECONDS = 8;

    /** The exact bytes the Cloud agent appends after raw_payload — keep in lockstep with print-agent.js. */
    public const TRAILING_FEED = "\n\n\n";

    /** @throws RuntimeException on any connect/write failure (caller applies the bounded retry policy) */
    public function send(string $ip, int $port, string $rawPayload): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $ip . ':' . $port,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS
        );
        if ($socket === false) {
            throw new RuntimeException("printer connect failed [{$ip}:{$port}]: {$errstr} ({$errno})");
        }

        try {
            stream_set_timeout($socket, self::WRITE_TIMEOUT_SECONDS);
            $data = $rawPayload . self::TRAILING_FEED;
            $total = strlen($data);
            $written = 0;
            while ($written < $total) {
                $n = @fwrite($socket, substr($data, $written));
                $meta = stream_get_meta_data($socket);
                if ($n === false || $n === 0 || ! empty($meta['timed_out'])) {
                    throw new RuntimeException("printer write failed [{$ip}:{$port}] after {$written}/{$total} bytes");
                }
                $written += $n;
            }
        } finally {
            fclose($socket);
        }
    }
}
