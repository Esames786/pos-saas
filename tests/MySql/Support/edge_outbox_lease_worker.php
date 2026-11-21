<?php

/**
 * OFFLINE-SYNC-ENGINE-1B closure — a separate OS process that performs ONE outbox lease claim through the
 * REAL EdgeSyncOutboxService against the Edge test DB, used by the genuine two-process lease races
 * (one row / two workers, two rows / two workers, expired-lease reclaim).
 *
 * Env: EDGE_TEST_TENANT_DB (must contain 'test'), TENANT_DB_*, START_FILE (spin-barrier).
 * Args: <owner>
 * Prints: OK:lease:<row id or none>:<lease_owner or ->  |  ERR:<class>:<message>
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Edge\EdgeSyncOutboxService;
use Illuminate\Support\Facades\DB;

$db = getenv('EDGE_TEST_TENANT_DB') ?: '';
if (stripos($db, 'test') === false) {
    fwrite(STDERR, "REFUSE non-test db\n");
    exit(2);
}
config([
    'app.role' => 'branch_server',
    'database.connections.tenant.database' => $db,
    'database.connections.master.database' => 'nonexistent_master_edge_outbox_race',
]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');

$start = getenv('START_FILE');
if ($start) {
    $deadline = microtime(true) + 20;
    while (! is_file($start)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "barrier timeout\n");
            exit(3);
        }
        usleep(2000);
    }
}

try {
    $row = app(EdgeSyncOutboxService::class)->lease((string) ($argv[1] ?? 'worker'));
    echo 'OK:lease:' . ($row?->id ?? 'none') . ':' . ($row?->lease_owner ?? '-') . "\n";
} catch (Throwable $e) {
    echo 'ERR:' . get_class($e) . ':' . $e->getMessage() . "\n";
    exit(1);
}
