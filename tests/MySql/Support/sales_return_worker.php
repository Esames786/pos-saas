<?php

/**
 * UB-06 — worker that posts one sales return through the REAL SalesReturnService, run as a separate OS process so
 * "the same form posted twice at once" and "two people returning one bill at once" are proven with genuine
 * concurrency (SalesReturnPostedOnceMySqlTest).
 *
 * Prints POSTED:<id> / ALREADY:<id>:same|other / REFUSED:<message> / ERROR:<message>.
 *
 * Usage: php sales_return_worker.php <job.json>
 *   job: {mode: "bill"|"no_bill", sale_id?, branch_id, user_id, lines, key?, hash?}
 *        {mode: "supplier_post", return_id, user_id} — posts one supplier-return draft
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

$job = json_decode((string) file_get_contents($argv[1] ?? ''), true);

try {
    if ($job['mode'] === 'bill_post') {   // the bill for one receipt (SupplierNetBillMySqlTest)
        $bill = app(App\Services\Purchasing\PurchaseBillService::class)->createFromReceipt(
            App\Models\Tenant\GoodsReceipt::findOrFail($job['grn_id']),
            array_filter(['bill_date' => now()->toDateString(), 'returns_on_invoice' => $job['choice'] ?? null], fn ($v) => $v !== null),
            (int) $job['user_id'],
        );
        fwrite(STDOUT, 'POSTED:' . $bill->id . "\n");
        exit(0);
    }
    if ($job['mode'] === 'supplier_post') {   // post one supplier-return draft (PurchaseReturnPostedOnceMySqlTest)
        $posted = app(App\Services\Purchasing\PurchaseReturnService::class)
            ->post(App\Models\Tenant\PurchaseReturn::findOrFail($job['return_id']), (int) $job['user_id']);
        fwrite(STDOUT, 'POSTED:' . $posted->id . "\n");
        exit(0);
    }
    $service = app(App\Services\Sales\SalesReturnService::class);
    $return = $job['mode'] === 'no_bill'
        ? $service->processNoBillReturn(
            branch: App\Models\Tenant\Branch::findOrFail($job['branch_id']),
            lines: $job['lines'],
            reason: 'race test',
            refundMethod: 'cash',
            // stated, as the screen does (UB-04)
            refundAmount: round(array_sum(array_map(fn ($l) => (float) $l['quantity'] * (float) $l['unit_price'], $job['lines'])), 2),
            userId: (int) $job['user_id'],
            submissionKey: $job['key'] ?? null,
            submissionHash: $job['hash'] ?? null,
        )
        : $service->processReturn(
            salesOrder: App\Models\Tenant\SalesOrder::findOrFail($job['sale_id']),
            lines: $job['lines'],
            reason: 'race test',
            refundMethod: 'cash',
            refundAmount: null,
            userId: (int) $job['user_id'],
            submissionKey: $job['key'] ?? null,
            submissionHash: $job['hash'] ?? null,
        );
    fwrite(STDOUT, 'POSTED:' . $return->id . "\n");
} catch (App\Support\AlreadySubmitted $e) {
    fwrite(STDOUT, 'ALREADY:' . $e->record->getKey() . ':' . ($e->same ? 'same' : 'other') . "\n");
} catch (Illuminate\Database\QueryException $e) {   // a database failure is never a "refusal"
    fwrite(STDOUT, 'ERROR:QueryException: ' . $e->getMessage() . "\n");
    exit(1);
} catch (RuntimeException $e) {
    fwrite(STDOUT, 'REFUSED:' . $e->getMessage() . "\n");
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR:' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
