<?php

/**
 * EDGE-LOCAL-AUTH-1 (Section 2) — a separate OS process that performs ONE local login attempt via the
 * REAL EdgeLocalAuthService against the Edge-local DB. Two of these with a WRONG credential are launched
 * simultaneously so EdgeLoginLockoutRace can prove the row-locked failed_attempts counter never loses
 * an increment (the account ends locked, no lost update).
 *
 * Env: EDGE_TEST_LOCAL_DB, TENANT_DB_*, EDGE_ENROLLMENT_PUBLIC_KEY, EDGE_LOGIN_SLEEP_MS.
 * Args: <employee_code> <credential>
 * Prints: OK | FAIL:<msg>
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Edge\EdgeLocalAuthService;
use Illuminate\Support\Facades\DB;

$edgeDb = getenv('EDGE_TEST_LOCAL_DB') ?: '';
if (stripos($edgeDb, 'edge') === false || stripos($edgeDb, 'test') === false) {
    fwrite(STDERR, "REFUSE\n");
    exit(2);
}
config(['app.role' => 'branch_server', 'database.connections.edge_local.database' => $edgeDb, 'database.connections.tenant.database' => $edgeDb]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');

[$script, $emp, $cred] = array_pad($argv, 3, null);
usleep(((int) (getenv('EDGE_LOGIN_SLEEP_MS') ?: 250)) * 1000); // align the processes

try {
    app(EdgeLocalAuthService::class)->verifyForLogin((string) $emp, (string) $cred);
    echo "OK\n";
} catch (\Throwable $e) {
    echo 'FAIL:' . $e->getMessage() . "\n";
}
