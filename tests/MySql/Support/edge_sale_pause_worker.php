<?php

/**
 * OFFLINE-SYNC-ENGINE-1B true-overlap proof — a separate OS process that performs ONE real takeaway paid
 * sale through the REAL EdgeLocalPosService against the Edge test DB. If READY_FILE + RELEASE_FILE are set,
 * it PAUSES inside the sale transaction (at beforeOutboxInsert — holding the product FK lock, having priced
 * + stamped its generation) until the test releases it, so a concurrent config refresh genuinely serializes
 * on the sale's real row locks. No pause env => a plain sale.
 *
 * Env: EDGE_TEST_TENANT_DB (must contain 'test'), TENANT_DB_*, READY_FILE?, RELEASE_FILE?.
 * Args: <client_uuid> <user_id> <terminal_id> <product_id> <qty> <method_id> <amount>
 * Prints: OK:sale:<id>:<sale_uuid> | ERR:<class>:<message>
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPosService;
use Illuminate\Support\Facades\DB;

$db = getenv('EDGE_TEST_TENANT_DB') ?: '';
if (stripos($db, 'test') === false) {
    fwrite(STDERR, "REFUSE non-test db\n");
    exit(2);
}
config([
    'app.role' => 'branch_server',
    'database.connections.tenant.database' => $db,
    'database.connections.master.database' => 'nonexistent_master_edge_sale_pause',
]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');

$readyFile = getenv('READY_FILE') ?: '';
$releaseFile = getenv('RELEASE_FILE') ?: '';

// A subclass whose ONLY difference is the pause at beforeOutboxInsert (inside the sale transaction).
$service = new class(
    app(\App\Services\Edge\EdgeBranchContext::class),
    app(\App\Services\Sales\ShiftService::class),
    app(\App\Services\Sales\SalePricingService::class),
    app(\App\Services\Sales\SalesTotalsService::class),
    app(\App\Services\Inventory\InventoryService::class),
    app(\App\Services\Edge\EdgeOperationalStockService::class),
    app(\App\Services\Sales\SaleIdempotencyService::class),
    app(\App\Services\Sales\SaleOperationalSettlementService::class),
    app(\App\Services\Printing\PrintJobService::class),
    app(\App\Services\Sales\KotCancellationService::class),
    app(\App\Services\Sales\SalesService::class),
    app(\App\Services\Edge\EdgeSyncOutboxService::class),
) extends EdgeLocalPosService {
    public string $readyFile = '';
    public string $releaseFile = '';

    protected function beforeOutboxInsert(): void
    {
        if ($this->readyFile === '' || $this->releaseFile === '') {
            return;
        }
        file_put_contents($this->readyFile, '1'); // "sale has priced + stamped + holds its locks"
        $deadline = microtime(true) + 60;
        while (! is_file($this->releaseFile)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('sale pause timeout waiting for release');
            }
            usleep(3000);
        }
    }
};
$service->readyFile = $readyFile;
$service->releaseFile = $releaseFile;

[$clientUuid, $userId, $terminalId, $productId, $qty, $methodId, $amount] = array_slice($argv, 1) + array_fill(0, 7, null);

try {
    $user = User::on('tenant')->find((int) $userId);
    // The service REQUIRES an authenticated tenant principal (a bare User is not authority).
    \Illuminate\Support\Facades\Auth::guard('tenant')->setUser($user);
    \Illuminate\Support\Facades\Auth::shouldUse('tenant');
    $sale = $service->completePaidSale([
        'order_type' => 'takeaway', 'client_uuid' => (string) $clientUuid,
        'lines' => [['product_id' => (int) $productId, 'quantity' => (float) $qty]],
        'payments' => [['payment_method_id' => (int) $methodId, 'amount' => (float) $amount]],
    ], $user, (int) $terminalId);
    echo 'OK:sale:' . $sale->id . ':' . $sale->sale_uuid . "\n";
} catch (Throwable $e) {
    echo 'ERR:' . get_class($e) . ':' . $e->getMessage() . "\n";
    exit(1);
}
