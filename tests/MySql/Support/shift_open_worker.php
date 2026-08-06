<?php

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 — worker that opens a shift via the REAL ShiftService, run as a
 * separate OS process so ShiftServiceTest can prove genuine two-process concurrency (not a
 * hand-reproduced lock). Prints OPENED:<id> / ALREADY_OPEN / ERROR:<msg>.
 *
 * Usage: php tests/MySql/Support/shift_open_worker.php <branch_id> <terminal_id> <user_id>
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

[$script, $bid, $tid, $uid] = array_pad($argv, 4, null);

try {
    $branch   = App\Models\Tenant\Branch::on('tenant')->findOrFail($bid);
    $terminal = App\Models\Tenant\Terminal::on('tenant')->findOrFail($tid);
    $shift    = app(App\Services\Sales\ShiftService::class)->open($branch, $terminal, (int) $uid, 0.0, null);
    fwrite(STDOUT, 'OPENED:' . $shift->id . "\n");
} catch (App\Exceptions\ShiftException $e) {
    fwrite(STDOUT, "ALREADY_OPEN\n");
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR:' . $e->getMessage() . "\n");
    exit(1);
}
