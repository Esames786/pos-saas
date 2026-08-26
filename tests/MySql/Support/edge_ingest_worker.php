<?php

/**
 * OFFLINE-SYNC-ENGINE-1C concurrency proof — a separate OS process that ingests ONE envelope through the
 * REAL EdgeInboundSaleIngestionService (Cloud authority) against the shared tenant+master test DBs, used by
 * the genuine two-process ingestion race (same sale_uuid + same hash -> exactly one official posting set).
 *
 * Env: EDGE_TEST_TENANT_DB (tenant, must contain 'test'), DB_DATABASE (master), TENANT_DB_*, START_FILE.
 * Args: <envelope json path>
 * Prints: OK:<status>:<ingestion_uuid or -> | ERR:<class>:<message>
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Edge\EdgeInboundSaleIngestionService;
use Illuminate\Support\Facades\DB;

$tenantDb = getenv('EDGE_TEST_TENANT_DB') ?: '';
$masterDb = getenv('DB_DATABASE') ?: '';
if (stripos($tenantDb, 'test') === false || stripos($masterDb, 'test') === false) {
    fwrite(STDERR, "REFUSE non-test db\n");
    exit(2);
}
// Cloud instance (role != branch_server) — ingestion is Cloud-authoritative.
config([
    'app.role' => 'cloud',
    'database.connections.tenant.database' => $tenantDb,
    'database.connections.master.database' => $masterDb,
]);
DB::purge('tenant');
DB::purge('master');
DB::setDefaultConnection('tenant');

$start = getenv('START_FILE');
if ($start) {
    $deadline = microtime(true) + 20;
    while (! is_file($start)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "barrier timeout\n");
            exit(3);
        }
        usleep(3000);
    }
}

try {
    $env = json_decode((string) file_get_contents((string) ($argv[1] ?? '')), true);
    if (! is_array($env)) {
        throw new RuntimeException('envelope file unreadable');
    }
    $ack = app(EdgeInboundSaleIngestionService::class)->ingest($env);
    echo 'OK:' . ($ack['status'] ?? '?') . ':' . ($ack['ingestion_uuid'] ?? '-') . "\n";
} catch (Throwable $e) {
    echo 'ERR:' . get_class($e) . ':' . $e->getMessage() . "\n";
    exit(1);
}
