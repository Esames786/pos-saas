<?php

/**
 * EDGE-RUNTIME-BOUNDARY-1 (S) — worker that opens a restaurant table via the REAL
 * RestaurantTableSessionController@open (through the container, authed), run as a separate OS process
 * so the carried-forward open-check race can be proven with genuine concurrency.
 *
 * Prints OPENED:<sessionId> / LOSER / ERROR:<msg>.
 *
 * Usage: php open_table_worker.php <branch_id> <table_id> <terminal_id> <user_id>
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$tenantDb = getenv('EDGE_TEST_TENANT_DB') ?: 'pos_test_tenant';
if (stripos($tenantDb, 'test') === false) {
    fwrite(STDERR, "REFUSE: not a test database\n");
    exit(2);
}
config(['database.connections.tenant.database' => $tenantDb]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');

[$script, $branchId, $tableId, $terminalId, $userId] = array_pad($argv, 5, null);

try {
    $user = App\Models\Tenant\User::on('tenant')->findOrFail($userId);
    Auth::guard('tenant')->setUser($user);
    Auth::shouldUse('tenant');

    $table = App\Models\Tenant\RestaurantTable::on('tenant')->findOrFail($tableId);
    $request = Illuminate\Http\Request::create('/x', 'POST', ['terminal_id' => $terminalId, 'guest_count' => 2]);
    $request->headers->set('Accept', 'application/json');

    $response = app()->call([app(App\Http\Controllers\Tenant\RestaurantTableSessionController::class), 'open'], [
        'request' => $request,
        'restaurantTable' => $table,
    ]);

    $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
    if ($status === 200) {
        $session = App\Models\Tenant\RestaurantTableSession::on('tenant')
            ->where('restaurant_table_id', $tableId)->where('status', 'open')->latest('id')->first();
        fwrite(STDOUT, 'OPENED:' . ($session?->id ?? '?') . "\n");
    } else {
        fwrite(STDOUT, "LOSER\n"); // controlled 422 (table already open / no shift)
    }
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR:' . $e->getMessage() . "\n");
    exit(1);
}
