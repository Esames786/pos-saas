<?php

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1 — worker that performs a POS commercial mutation the way
 * the controllers do: inside ONE tenant transaction it locks the open shift via the REAL
 * ShiftService (lockOpenShiftForTerminal / lockOpenShiftForBranch), then writes the representative
 * row (paid sale / held sale / open table). Run as a separate OS process so ShiftCloseRaceTest can
 * race it against a real shift close.
 *
 * SHIFT_OP_SLEEP_MS (env) holds the shift row lock for a while after acquiring it, to widen the
 * contention window deterministically.
 *
 * Usage: php shift_op_worker.php <branch_id> <terminal_id> <user_id> <mode:sale|hold|table> [table_id]
 * Prints: COMMITTED:<shift_id> | REJECTED_NO_SHIFT | ERROR:<msg>
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

[$script, $bid, $tid, $uid, $mode, $tableId] = array_pad($argv, 6, null);
$sleepMs = (int) (getenv('SHIFT_OP_SLEEP_MS') ?: 0);

try {
    $shiftId = null;
    DB::connection('tenant')->transaction(function () use ($bid, $tid, $uid, $mode, $tableId, $sleepMs, &$shiftId) {
        $svc = app(App\Services\Sales\ShiftService::class);

        // Lock the open shift FIRST (same contract the controllers use).
        if ($mode === 'table') {
            $shift = $svc->lockOpenShiftForBranch((int) $bid);
        } else {
            $terminal = App\Models\Tenant\Terminal::on('tenant')->findOrFail($tid);
            $shift = $svc->lockOpenShiftForTerminal($terminal);
        }
        $shiftId = $shift->id;
        $businessDate = $shift->business_date->toDateString();

        // Signal (outside the txn's visibility, via the filesystem) that the shift lock is now held,
        // so the test can start the racing close ONLY after this worker owns the lock — making the
        // race deterministic regardless of each worker's app-boot time.
        $readyFile = getenv('SHIFT_OP_READY_FILE');
        if ($readyFile) {
            @file_put_contents($readyFile, '1');
        }

        // Hold the lock to widen the race window before committing the write.
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        if ($mode === 'table') {
            DB::connection('tenant')->table('restaurant_table_sessions')->insert([
                'session_no' => 'TS-' . uniqid(), 'branch_id' => $bid, 'restaurant_table_id' => $tableId,
                'opened_by_user_id' => $uid, 'opened_shift_id' => $shift->id, 'business_date' => $businessDate,
                'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            DB::connection('tenant')->table('sales_orders')->insert([
                'sale_no' => 'SO-' . uniqid(), 'branch_id' => $bid, 'order_source' => 'pos',
                'order_type' => 'quick_sale', 'sale_date' => now(), 'business_date' => $businessDate,
                'shift_id' => $shift->id, 'status' => $mode === 'hold' ? 'held' : 'paid',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });
    fwrite(STDOUT, 'COMMITTED:' . $shiftId . "\n");
} catch (App\Exceptions\ShiftException $e) {
    fwrite(STDOUT, "REJECTED_NO_SHIFT\n");
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR:' . $e->getMessage() . "\n");
    exit(1);
}
