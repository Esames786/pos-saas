<?php

/**
 * EDGE-LOCAL-PRINT-1 — a minimal FakePrinter for tests: listens on 127.0.0.1:<port>, accepts
 * connections until the deadline, appends every received byte stream to <outfile> (one connection's
 * bytes after the previous — physical duplicates are visible as repeated payloads), then exits.
 * Same TCP contract as tools/print-agent/fake-printer.js, no Node dependency.
 *
 * Usage: php fake_printer.php <port> <outfile> [max_connections=1] [listen_seconds=20]
 * Prints LISTENING once the socket is bound (the test waits for it via the ready file).
 */

[$script, $port, $outfile] = array_pad($argv, 3, null);
$maxConnections = (int) ($argv[3] ?? 1);
$listenSeconds = (int) ($argv[4] ?? 20);

$server = stream_socket_server('tcp://127.0.0.1:' . (int) $port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr ($errno)\n");
    exit(2);
}
file_put_contents($outfile . '.ready', '1');
echo "LISTENING\n";

$deadline = microtime(true) + $listenSeconds;
$accepted = 0;
while ($accepted < $maxConnections && microtime(true) < $deadline) {
    $conn = @stream_socket_accept($server, 1.0);
    if ($conn === false) {
        continue;
    }
    stream_set_timeout($conn, 10);
    $bytes = '';
    while (! feof($conn)) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($conn);
            if (! empty($meta['timed_out'])) {
                break;
            }
            if ($chunk === '') {
                break;
            }
        }
        $bytes .= $chunk;
    }
    fclose($conn);
    file_put_contents($outfile, $bytes, FILE_APPEND);
    $accepted++;
}
fclose($server);
echo "DONE:{$accepted}\n";
