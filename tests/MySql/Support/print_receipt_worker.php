<?php

/**
 * EDGE-RUNTIME-BOUNDARY-1 (S) — worker that queues an ensure-once auto receipt via the REAL
 * PrintJobService, run as a separate OS process so the carried-forward logical_key race can be
 * proven with genuine concurrency. Prints OK:<jobId>:<copyNo>:<logicalKey> / ERROR:<msg>.
 *
 * Usage: php print_receipt_worker.php <sale_id>
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tenantDb = getenv('EDGE_TEST_TENANT_DB') ?: 'pos_test_tenant';
if (stripos($tenantDb, 'test') === false) {
    fwrite(STDERR, "REFUSE: not a test database\n");
    exit(2);
}
config(['database.connections.tenant.database' => $tenantDb]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');

[$script, $saleId] = array_pad($argv, 2, null);

try {
    $sale = App\Models\Tenant\SalesOrder::on('tenant')->findOrFail($saleId);
    $job = app(App\Services\Printing\PrintJobService::class)->queueReceipt($sale, ensureOnce: true);
    fwrite(STDOUT, 'OK:' . $job->id . ':' . $job->copy_no . ':' . $job->logical_key . "\n");
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR:' . $e->getMessage() . "\n");
    exit(1);
}
