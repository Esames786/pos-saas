<?php

/**
 * EDGE-LOCAL-POS-1 — a separate OS process that performs ONE local paid sale (or a shift close) through the
 * REAL EdgeLocalPosService / ShiftService against the Edge-local test DB, used by the genuine two-process
 * races: same-client_uuid (completes 2A's real catch path), final-unit stock, and shift-close-vs-sale.
 *
 * Env: EDGE_TEST_TENANT_DB (must contain 'test'), TENANT_DB_*, START_FILE (spin-barrier).
 * Args (sale):  sale <client_uuid> <user_id> <terminal_id> <product_id> <qty> <method_id> <amount>
 * Args (close): close <shift_id> <user_id>
 * Prints: OK:sale:<id>:<sale_uuid> | OK:close:<id> | ERR:<class>:<message>
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant\Shift;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\DB;

$db = getenv('EDGE_TEST_TENANT_DB') ?: '';
if (stripos($db, 'test') === false) {
    fwrite(STDERR, "REFUSE non-test db\n");
    exit(2);
}
config([
    'app.role' => 'branch_server',
    'database.connections.tenant.database' => $db,
    'database.connections.master.database' => 'nonexistent_master_edge_pos_race', // no master dependency
]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');

// spin-barrier: both workers block here until the test creates START_FILE, then race.
$start = getenv('START_FILE');
if ($start) {
    $deadline = microtime(true) + 20;
    while (! is_file($start)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "barrier timeout\n");
            exit(3);
        }
        usleep(5000);
    }
}

$mode = $argv[1] ?? 'sale';

try {
    if ($mode === 'close') {
        [, , $shiftId, $userId] = array_pad($argv, 4, null);
        $shift = Shift::on('tenant')->findOrFail((int) $shiftId);
        // Mirror the REAL ShiftController close: atomic txn, row-lock + closable assertion, then close.
        DB::connection('tenant')->transaction(function () use ($shift, $userId) {
            $locked = app(ShiftService::class)->assertClosableUnderLock($shift);
            $locked->update([
                'closed_by_user_id' => (int) $userId,
                'counted_cash' => (float) $locked->expected_cash,
                'cash_variance' => 0,
                'status' => 'closed',
                'closed_at' => now(),
            ]);
        });
        echo 'OK:close:' . $shift->id . "\n";
        exit(0);
    }

    [, , $clientUuid, $userId, $terminalId, $productId, $qty, $methodId, $amount] = array_pad($argv, 9, null);
    $user = User::on('tenant')->findOrFail((int) $userId);
    $sale = app(EdgeLocalPosService::class)->completePaidSale([
        'order_type' => 'quick_sale',
        'client_uuid' => (string) $clientUuid,
        'lines' => [['product_id' => (int) $productId, 'quantity' => (float) $qty]],
        'payments' => [['payment_method_id' => (int) $methodId, 'amount' => (float) $amount]],
    ], $user, (int) $terminalId);
    echo 'OK:sale:' . $sale->id . ':' . $sale->sale_uuid . "\n";
} catch (\Throwable $e) {
    echo 'ERR:' . get_class($e) . ':' . str_replace("\n", ' ', $e->getMessage()) . "\n";
}
