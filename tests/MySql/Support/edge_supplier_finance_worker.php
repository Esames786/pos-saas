<?php

/**
 * OFFLINE EDGE — F2 §18: an independent OS process acting as one terminal recording a supplier payment on the
 * Branch Server (its own PHP process, own DB connection, own transaction). Used by EdgeSupplierFinanceRaceTest.
 *
 *   php edge_supplier_finance_worker.php pay <cloud_supplier_id> <amount> <cloud_cash_bank_account_id> <user_id> <terminal_id>
 *
 * EDGE_WORKER_DB names the (test) tenant database; EDGE_SF_BARRIER_AT (unix float) aligns the terminals' starts.
 */
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalSupplierFinanceService;
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
    'database.connections.master.database' => 'nonexistent_master_supplier_finance_race',
]);
DB::purge('master');
DB::purge('tenant');
DB::setDefaultConnection('tenant');

$mode = $argv[1] ?? '';
try {
    if ($mode === 'pay') {
        $user = User::on('tenant')->findOrFail((int) $argv[5]);
        Auth::guard('tenant')->setUser($user);
        Auth::shouldUse('tenant');
        $barrier = getenv('EDGE_SF_BARRIER_AT');
        if ($barrier) {
            while (microtime(true) < (float) $barrier) {
                usleep(2000);
            }
        }
        $event = app(EdgeLocalSupplierFinanceService::class)->recordPayment([
            'cloud_supplier_id' => (int) $argv[2], 'amount' => (float) $argv[3], 'cloud_cash_bank_account_id' => (int) $argv[4], 'payment_method' => 'cash', 'reference_no' => 'race',
        ], $user, (int) $argv[6]);
        echo 'OK:' . json_encode(['event_uuid' => $event['event_uuid'], 'amount' => $event['amount'], 'available' => $event['position']['available_payable']]) . "\n";
        exit(0);
    }
    fwrite(STDERR, "unknown mode {$mode}\n");
    exit(2);
} catch (\Throwable $e) {
    echo 'ERR:' . get_class($e) . ':' . str_replace("\n", ' ', $e->getMessage()) . "\n";
}
