<?php

/**
 * EDGE 0.9 CONTINUITY — a separate OS process that performs ONE first-touch adoption through the REAL
 * ContinuityAdoptionService on the given test DB. Two of these are released at the same instant (both queued behind a
 * lock on the mirror meta row) so EdgeContinuityAdoptionMySqlTest can prove two counters touching the POS together adopt
 * the mirrored live state exactly once (no second continuation shift, no duplicate held bill).
 *
 * Env: EDGE_TEST_TENANT_DB (must contain "test" and "edge"). Args: <user_id> <terminal_id>
 * Prints: RESULT:<status> | ERROR:<msg>
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = getenv('EDGE_TEST_TENANT_DB') ?: '';
if (stripos($db, 'test') === false || stripos($db, 'edge') === false) {
    fwrite(STDERR, "REFUSE: not an edge test database\n");
    exit(2);
}
config(['app.role' => 'branch_server', 'database.connections.tenant.database' => $db]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');

[$script, $uid, $tid] = array_pad($argv, 3, null);
try {
    $user = App\Models\Tenant\User::on('tenant')->findOrFail((int) $uid);
    $r = app(App\Services\Edge\Continuity\ContinuityAdoptionService::class)->ensureAdopted($user, (int) $tid);
    fwrite(STDOUT, 'RESULT:' . $r['status'] . "\n");
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR:' . mb_substr($e->getMessage(), 0, 300) . "\n");
    exit(1);
}
