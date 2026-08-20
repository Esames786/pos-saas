<?php

/**
 * KASHIF-CATERING-LIFECYCLE-LOCK-1 — a separate OS process that performs ONE real
 * catering lifecycle or Rate Impact operation against the isolated Catering test
 * tenant DB.
 *
 * A race cannot be proved by two method calls in one PHP process: a single
 * connection serializes itself by construction, so the "race" always resolves in
 * whatever order the statements were written. These operations therefore run in
 * their OWN process on their OWN connection, through the REAL services, so the
 * only thing deciding the outcome is InnoDB.
 *
 * Env: EDGE_TEST_TENANT_DB (must contain 'test'), START_FILE (optional spin-barrier).
 *
 * Args:
 *   apply        <material_product_id> <snapshot_ids_csv> <user_id>
 *   revise-apply <material_product_id> <estimate_id> <user_id>
 *   send         <estimate_id>
 *   accept       <estimate_id>
 *   cancel       <event_id>
 *
 * Prints exactly one line:
 *   OK:apply:<count> | OK:revise:<version_no> | OK:send:<status>
 *   OK:accept:<status> | OK:cancel:<status> | ERR:<class>:<message>
 */
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringCommercialRateImpactService;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

$db = getenv('EDGE_TEST_TENANT_DB') ?: '';
if (stripos($db, 'test') === false) {
    fwrite(STDERR, "REFUSE non-test db\n");
    exit(2);
}

config(['database.connections.tenant.database' => $db]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');

// Nothing here should be emailing anybody; a race worker that blocks on SMTP
// would time out and read as a lock failure.
Mail::fake();

// Wait measurably rather than for the default fifty seconds: a test that hangs
// on a lock it was never going to get should fail, not stall the suite.
DB::connection('tenant')->statement('SET SESSION innodb_lock_wait_timeout = 20');

// Spin-barrier: workers block here until the test creates START_FILE, then go
// at genuinely the same moment.
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

$mode = $argv[1] ?? '';

try {
    switch ($mode) {
        case 'apply':
            [, , $materialId, $snapshotCsv, $userId] = array_pad($argv, 5, null);
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $snapshotCsv))));
            $count = app(CateringCommercialRateImpactService::class)
                ->applyToDrafts((int) $materialId, $ids, $userId === null ? null : (int) $userId);
            echo "OK:apply:{$count}";
            break;

        case 'revise-apply':
            [, , $materialId, $estimateId, $userId] = array_pad($argv, 5, null);
            $revision = app(CateringCommercialRateImpactService::class)
                ->applyThroughRevision((int) $materialId, (int) $estimateId, $userId === null ? null : (int) $userId);
            echo 'OK:revise:'.$revision->version_no;
            break;

        case 'send':
            $estimate = CateringEstimate::findOrFail((int) $argv[2]);
            echo 'OK:send:'.app(CateringEstimateService::class)->markSent($estimate)->status;
            break;

        case 'accept':
            $estimate = CateringEstimate::findOrFail((int) $argv[2]);
            echo 'OK:accept:'.app(CateringEstimateService::class)->markAccepted($estimate)->status;
            break;

        case 'cancel':
            $event = CateringEvent::findOrFail((int) $argv[2]);
            echo 'OK:cancel:'.app(CateringEstimateService::class)
                ->cancelEvent($event, 'Race test cancellation')->status;
            break;

        default:
            fwrite(STDERR, "unknown mode [{$mode}]\n");
            exit(4);
    }
} catch (\Throwable $e) {
    echo 'ERR:'.get_class($e).':'.str_replace(["\n", "\r"], ' ', $e->getMessage());
}
