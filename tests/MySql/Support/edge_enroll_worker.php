<?php

/**
 * EDGE-LOCAL-AUTH-1 (Section 17 case K) — a separate OS process that consumes a pre-signed enrollment
 * assertion via the REAL EdgeEnrollmentConsumer against the Edge-local DB. Two of these are launched
 * simultaneously on the SAME assertion (same jti) so EdgeEnrollRace can prove exactly one succeeds
 * (the jti UNIQUE index + transaction), never both.
 *
 * Env: EDGE_TEST_LOCAL_DB (edge db), EDGE_ENROLLMENT_PUBLIC_KEY, TENANT_DB_* creds, EDGE_ENROLL_SLEEP_MS.
 * Args: <assertion.json> <credential>
 * Prints: SUCCESS | REPLAY | ERROR:<msg>
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Edge\EdgeEnrollmentConsumer;
use Illuminate\Support\Facades\DB;

$edgeDb = getenv('EDGE_TEST_LOCAL_DB') ?: '';
if (stripos($edgeDb, 'edge') === false || stripos($edgeDb, 'test') === false) {
    fwrite(STDERR, "REFUSE: not an edge test database\n");
    exit(2);
}

config([
    'app.role' => 'branch_server',
    'database.connections.edge_local.database' => $edgeDb,
    'database.connections.tenant.database' => $edgeDb,
    'edge.enrollment.public_key' => getenv('EDGE_ENROLLMENT_PUBLIC_KEY') ?: '',
]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');

[$script, $assertionFile, $credential] = array_pad($argv, 3, null);
$assertion = json_decode((string) file_get_contents($assertionFile), true);

// Align both processes so the jti insert genuinely races.
usleep(((int) (getenv('EDGE_ENROLL_SLEEP_MS') ?: 200)) * 1000);

try {
    app(EdgeEnrollmentConsumer::class)->consume($assertion, (string) $credential);
    echo "SUCCESS\n";
} catch (\Throwable $e) {
    echo (str_contains($e->getMessage(), 'replay') ? "REPLAY\n" : 'ERROR:' . $e->getMessage() . "\n");
}
