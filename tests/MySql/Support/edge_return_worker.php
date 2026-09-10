<?php
/**
 * OFFLINE EDGE — F1 race worker: ONE independent OS process = ONE terminal posting a return on the appliance.
 *
 *   ROLE=branch_server  EDGE_WORKER_DB=<edge test db>
 *   argv: return <sale_id> <sales_order_line_id> <qty> <user_id> <terminal_id> <refund_amount|->
 *
 * Prints ONE line: OK:<json> or ERR:<class>:<message>.
 */
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalReturnService;
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
    'database.connections.master.database' => 'nonexistent_master_return_race',
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
        $amount = ($argv[7] ?? '-') === '-' ? null : (float) $argv[7];
        // Every process waits at the same barrier so the two returns hit the locked rows together.
        $barrier = getenv('EDGE_RETURN_BARRIER_AT');
        if ($barrier) {
            while (microtime(true) < (float) $barrier) {
                usleep(2000);
            }
        }
        $r = app(EdgeLocalReturnService::class)->processReturn((int) $argv[2], [['sales_order_line_id' => (int) $argv[3], 'quantity' => (float) $argv[4]]], 'race', 'cash', $amount, $user, (int) $argv[6]);
        echo 'OK:' . json_encode(['return_uuid' => $r['return_uuid'], 'qty' => $r['lines'][0]['quantity'], 'grand_total' => $r['totals']['grand_total']]) . "\n";
        exit(0);
    }
    fwrite(STDERR, "unknown mode {$mode}\n");
    exit(2);
} catch (\Throwable $e) {
    echo 'ERR:' . get_class($e) . ':' . str_replace("\n", ' ', $e->getMessage()) . "\n";
}
