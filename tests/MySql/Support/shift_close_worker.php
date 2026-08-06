<?php

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1 — worker that closes a shift the way ShiftController does:
 * inside ONE tenant transaction it calls the REAL ShiftService::assertClosableUnderLock (row-locks
 * the shift, asserts open + no unresolved work) then flips it closed. Run as a separate OS process
 * so ShiftCloseRaceTest can race it against a concurrent sale/hold/table.
 *
 * Usage: php shift_close_worker.php <shift_id>
 * Prints: CLOSED | BLOCKED | ERROR:<msg>
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

[$script, $shiftId] = array_pad($argv, 2, null);

try {
    DB::connection('tenant')->transaction(function () use ($shiftId) {
        $svc = app(App\Services\Sales\ShiftService::class);
        $shift = App\Models\Tenant\Shift::on('tenant')->findOrFail($shiftId);
        $locked = $svc->assertClosableUnderLock($shift);
        $locked->update(['status' => 'closed', 'closed_at' => now()]);
    });
    fwrite(STDOUT, "CLOSED\n");
} catch (App\Exceptions\ShiftException $e) {
    fwrite(STDOUT, "BLOCKED\n");
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR:' . $e->getMessage() . "\n");
    exit(1);
}
