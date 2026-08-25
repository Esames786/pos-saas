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
    if ($mode === 'baseline') {
        // Args: baseline <baseline_uuid> <product_id> <qty> — races INITIAL baseline acceptance.
        [, , $baselineUuid, $productId, $qty] = array_pad($argv, 5, null);
        $rev = (string) DB::connection('tenant')->table('edge_local_meta')->value('source_revision');
        $row = app(\App\Services\Edge\EdgeOperationalBaselineService::class)->accept(
            (string) $baselineUuid,
            null,
            [['product_id' => (int) $productId, 'product_variant_id' => null, 'quantity' => (float) $qty]],
            $rev
        );
        echo 'OK:baseline:' . $row->id . ':' . $row->baseline_uuid . "\n";
        exit(0);
    }

    // ── EDGE-LOCAL-POS-1 restaurant races: every mode drives the REAL EdgeLocalPosService with an
    //    authenticated principal (master unreachable — config above). ──
    if (in_array($mode, ['open_table', 'hold', 'revise', 'kot', 'settle'], true)) {
        $user = User::on('tenant')->findOrFail((int) $argv[2]);
        \Illuminate\Support\Facades\Auth::guard('tenant')->setUser($user);
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');
        $terminalId = (int) $argv[3];
        $pos = app(EdgeLocalPosService::class);

        switch ($mode) {
            case 'open_table': // open_table <user> <terminal> <table_id>
                $session = $pos->openTableSession((int) $argv[4], ['guest_count' => 1], $user, $terminalId);
                echo 'OK:open_table:' . $session->id . "\n";
                exit(0);
            case 'hold': // hold <user> <terminal> <session_id> <product_id> <qty>
                $sale = $pos->holdOrReviseSale([
                    'order_type' => 'dine_in',
                    'restaurant_table_session_id' => (int) $argv[4],
                    'lines' => [['product_id' => (int) $argv[5], 'quantity' => (float) $argv[6]]],
                ], $user, $terminalId);
                echo 'OK:hold:' . $sale->id . "\n";
                exit(0);
            case 'revise': // revise <user> <terminal> <session_id> <sale_id> <old_line_id> <old_product_id> <old_qty> <new_product_id> <new_qty>
                $sale = $pos->holdOrReviseSale([
                    'held_sale_id' => (int) $argv[5],
                    'order_type' => 'dine_in',
                    'restaurant_table_session_id' => (int) $argv[4],
                    'lines' => [
                        ['sales_order_line_id' => (int) $argv[6], 'product_id' => (int) $argv[7], 'quantity' => (float) $argv[8]],
                        ['product_id' => (int) $argv[9], 'quantity' => (float) $argv[10]],
                    ],
                ], $user, $terminalId);
                echo 'OK:revise:' . $sale->id . ':' . $sale->lines()->count() . "\n";
                exit(0);
            case 'kot': // kot <user> <terminal> <sale_id>
                $result = $pos->queueKotEvents((int) $argv[4], $user, $terminalId);
                echo 'OK:kot:' . ($result['batch']?->id ?? 'none') . ':' . ($result['batch']?->sequence_no ?? 0) . "\n";
                exit(0);
            case 'settle': // settle <user> <terminal> <sale_id> <client_uuid> <method_id> <amount>
                $sale = $pos->settleHeldSale((int) $argv[4], [
                    'client_uuid' => (string) $argv[5],
                    'payments' => [['payment_method_id' => (int) $argv[6], 'amount' => (float) $argv[7]]],
                ], $user, $terminalId);
                echo 'OK:settle:' . $sale->id . ':' . $sale->status . "\n";
                exit(0);
        }
    }

    if ($mode === 'print_worker_acquire') {
        // print_worker_acquire <worker_uuid> — races the SINGLETON worker-slot acquisition (Slice-2
        // closure §2: the zero-row first-ever-start race on a fresh appliance).
        $won = app(\App\Services\Edge\EdgeLocalPrintWorkerSupervisor::class)->acquire((string) $argv[2]);
        echo $won ? "OK:worker_acquire:won\n" : "OK:worker_acquire:refused\n";
        exit(0);
    }

    // ── EDGE-LOCAL-PRINT-1 transport races: lease-token claim / delivery / stale completion. ──
    if (in_array($mode, ['print_claim', 'print_cycle', 'print_success', 'print_failure', 'print_deliver_die'], true)) {
        if ($mode === 'print_deliver_die') {
            // claim + REAL TCP delivery, then die WITHOUT completing (simulated power loss before
            // markPrinted) — prints the token so the test can later prove the stale-completion refusal.
            $svc = app(\App\Services\Edge\EdgeLocalPrintDeliveryService::class);
            $claim = $svc->claimNext((string) $argv[2]);
            if (! $claim) {
                echo "OK:deliver_die:none\n";
                exit(0);
            }
            app(\App\Services\Edge\EdgeNetworkPrinterTransport::class)->send($claim['ip'], $claim['port'], $claim['raw_payload']);
            echo 'OK:deliver_die:' . $claim['job_id'] . ':' . $claim['lease_token'] . "\n";
            exit(0); // no completeSuccess — the lease dies with this process
        }
    }

    if (in_array($mode, ['print_claim', 'print_cycle', 'print_success', 'print_failure'], true)) {
        $svc = app(\App\Services\Edge\EdgeLocalPrintDeliveryService::class);
        switch ($mode) {
            case 'print_claim': // print_claim <worker_uuid> — claim only; prints the token; NO completion (simulated death).
                $claim = $svc->claimNext((string) $argv[2]);
                echo $claim ? 'OK:claim:' . $claim['job_id'] . ':' . $claim['lease_token'] . "\n" : "OK:claim:none\n";
                exit(0);
            case 'print_cycle': // print_cycle <worker_uuid> — full claim → real TCP → token-verified success.
                $claim = $svc->claimNext((string) $argv[2]);
                if (! $claim) {
                    echo "OK:cycle:none\n";
                    exit(0);
                }
                app(\App\Services\Edge\EdgeNetworkPrinterTransport::class)->send($claim['ip'], $claim['port'], $claim['raw_payload']);
                $done = $svc->completeSuccess($claim['job_id'], $claim['lease_token']);
                echo 'OK:cycle:' . $claim['job_id'] . ':' . ($done ? 'delivered' : 'stale') . "\n";
                exit(0);
            case 'print_success': // print_success <job_id> <token>
                echo $svc->completeSuccess((int) $argv[2], (string) $argv[3]) ? "OK:success\n" : "REFUSED:stale\n";
                exit(0);
            case 'print_failure': // print_failure <job_id> <token> <error>
                echo $svc->completeFailure((int) $argv[2], (string) $argv[3], (string) ($argv[4] ?? 'err')) ? "OK:failure\n" : "REFUSED:stale\n";
                exit(0);
        }
    }

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
    // The service REQUIRES an authenticated tenant principal (a bare User model is not authority). This
    // worker process establishes it explicitly; the real credential-login path is proven separately by the
    // EdgeLocalAuthService integration test.
    \Illuminate\Support\Facades\Auth::guard('tenant')->setUser($user);
    \Illuminate\Support\Facades\Auth::shouldUse('tenant');
    $sale = app(EdgeLocalPosService::class)->completePaidSale([
        'order_type' => 'takeaway', // PHASE 2b: quick_sale now requires vehicle + waiter; the race is about concurrency, not attribution
        'client_uuid' => (string) $clientUuid,
        'lines' => [['product_id' => (int) $productId, 'quantity' => (float) $qty]],
        'payments' => [['payment_method_id' => (int) $methodId, 'amount' => (float) $amount]],
    ], $user, (int) $terminalId);
    echo 'OK:sale:' . $sale->id . ':' . $sale->sale_uuid . "\n";
} catch (\Throwable $e) {
    echo 'ERR:' . get_class($e) . ':' . str_replace("\n", ' ', $e->getMessage()) . "\n";
}
