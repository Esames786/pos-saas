<?php

/**
 * OFFLINE EDGE — F3 §11: one terminal returning goods to a supplier from an independent OS process (own PHP process, own
 * DB connection, own transaction). Used by EdgePurchaseReturnRaceTest.
 *
 *   php edge_purchase_return_worker.php return <cloud_grn_id> <cloud_grn_line_id> <qty> <user_id> <terminal_id>
 *
 * EDGE_WORKER_DB names the (test) tenant database; EDGE_PR_BARRIER_AT (unix float) aligns the terminals' starts.
 */
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPurchaseReturnService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$db = getenv('EDGE_WORKER_DB') ?: '';
if (stripos($db, 'test') === false) {
    fwrite(STDERR, "REFUSE non-test db\n");
    exit(2);
}
config([
    'app.role' => 'branch_server',
    'database.connections.tenant.database' => $db,
    'database.connections.master.database' => 'nonexistent_master_purchase_return_race',
]);
DB::purge('master');
DB::purge('tenant');
DB::setDefaultConnection('tenant');

$mode = $argv[1] ?? '';
try {
    if ($mode === 'return') {
        $user = User::on('tenant')->findOrFail((int) $argv[5]);
        Auth::guard('tenant')->setUser($user);
        Auth::shouldUse('tenant');
        $barrier = getenv('EDGE_PR_BARRIER_AT');
        if ($barrier) {
            while (microtime(true) < (float) $barrier) {
                usleep(2000);
            }
        }
        $event = app(EdgeLocalPurchaseReturnService::class)->postReturn([
            'cloud_grn_id' => (int) $argv[2], 'reason_code' => 'damaged', 'lines' => [['cloud_grn_line_id' => (int) $argv[3], 'quantity' => (float) $argv[4]]],
        ], $user, (int) $argv[6]);
        echo 'OK:' . json_encode(['event_uuid' => $event['event_uuid'], 'quantity' => $event['lines'][0]['quantity'], 'grand_total' => $event['grand_total']]) . "\n";
        exit(0);
    }
    fwrite(STDERR, "unknown mode {$mode}\n");
    exit(2);
} catch (\Throwable $e) {
    echo 'ERR:' . get_class($e) . ':' . str_replace("\n", ' ', $e->getMessage()) . "\n";
}
