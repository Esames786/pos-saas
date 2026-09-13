<?php
/**
 * P5 CERTIFICATION PROBE — proves which files a runtime process actually executed.
 *
 * Run any appliance command (or the web backend) with
 *     php -d auto_prepend_file=<this file> ... ; EDGE_INCLUDE_PROBE_OUT=<report.json>
 * and this shutdown hook writes every included/required PHP file of that process to the report. The certification
 * step then asserts that each path lies under the installed runtime version directory (or the bundled PHP runtime) —
 * i.e. the installed release executes ONLY files from the installed package (no developer tree, no shared vendor).
 *
 * Read-only: it never changes behaviour, never touches the database, never prints to the console.
 */
register_shutdown_function(static function (): void {
    $out = getenv('EDGE_INCLUDE_PROBE_OUT') ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-include-probe-' . getmypid() . '.json');
    $files = get_included_files();
    $report = [
        'pid' => getmypid(),
        'script' => $_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? null),
        'argv' => array_values(array_map(static fn ($a) => (string) $a, $_SERVER['argv'] ?? [])),
        'count' => count($files),
        'files' => $files,
        'php_binary' => PHP_BINARY,
        'written_at' => date('c'),
    ];
    // Append (one JSON object per line) so a multi-request web backend accumulates its requests.
    @file_put_contents($out, json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
});
