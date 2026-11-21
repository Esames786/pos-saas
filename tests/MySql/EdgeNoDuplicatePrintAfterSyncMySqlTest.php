<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeHandbackOrchestrator;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Printing\PrintJobService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeCloudBridgeFixture;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * P4 §4 — NO DUPLICATE PRINT AFTER SYNC (mandatory proof), two real databases + a real TCP FakePrinter.
 *
 * An offline sale prints its KOT and its receipt ONCE, locally, through the appliance's print worker straight to the
 * network printer (P4 §3: NETWORK_PRINTER_EDGE_DIRECT). When the WAN returns the sale syncs and the Cloud ingests it
 * — and the Cloud creates NO print job and NO KOT batch for it: not on ingestion, not on an ACK replay, not on a
 * lost-ACK recovery, not on handback. The printer receives exactly two payloads for the whole lifecycle, and the
 * cashier's ensure-once receipt request never produces a second bill.
 */
class EdgeNoDuplicatePrintAfterSyncMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeCloudBridgeFixture;

    private const CONFIG_TABLES = ['branches', 'users', 'categories', 'units', 'products', 'terminals', 'payment_methods', 'printers', 'terminal_printer_settings'];

    private int $branchId;
    private int $userId;
    private int $terminalId;
    private int $burgerId;
    private int $cashMethodId;
    private int $printerId;
    private int $printerPort;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->provisionTwoDatabases();
        // The REAL print worker maps tenant := edge_local (the appliance path): point edge_local at the appliance DB so the
        // mapping lands on the same database the bridge switches to with asEdge().
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), [
            'host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
            'database' => $this->edgeDb, 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        $this->printerPort = 9600 + random_int(0, 199);

        // ── the Cloud's truth ──
        $this->cleanTenant([
            'edge_branch_authority_leases', 'edge_inbound_return_ingestions', 'edge_inbound_sale_ingestions', 'kot_batches', 'print_jobs', 'terminal_printer_settings', 'printers',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'accounts', 'cash_bank_accounts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'payment_methods', 'products', 'categories', 'units', 'terminals', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch(['name' => 'Print Branch', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'PR' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $conn = DB::connection('tenant');
        $pc = $conn->table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $this->makeCategory();
        $this->burgerId = $this->makeProduct($cat, ['name' => 'Burger', 'unit_id' => $pc, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $accountId = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $accountId, 'current_balance' => 0, 'is_active' => 1, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('payment_methods')->where('id', $this->cashMethodId)->update(['cash_bank_account_id' => $cbId]);
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$this->burgerId}", 'branch_id' => $this->branchId, 'product_id' => $this->burgerId, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$this->burgerId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $this->burgerId, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 100, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        // ONE network printer for the branch (KOT + receipt) — the printer IS the FakePrinter on loopback.
        $this->printerId = $this->makePrinter(['branch_id' => $this->branchId, 'printer_type' => 'network', 'print_role' => 'both', 'ip_address' => '127.0.0.1', 'port' => $this->printerPort, 'is_active' => 1, 'name' => 'Counter LAN']);
        $conn->table('terminal_printer_settings')->insert(['terminal_id' => $this->terminalId, 'receipt_printer_id' => $this->printerId, 'kot_printer_id' => $this->printerId, 'auto_print_receipt' => 1, 'auto_print_kot' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->registerCloudTenantAndDevice($this->branchId, 1);

        // ── the appliance ──
        $this->mirrorConfigToAppliance(self::CONFIG_TABLES);
        $this->asEdge(function () {
            $this->cleanTenant([
                'edge_local_print_deliveries', 'edge_local_print_worker_state', 'kot_batches', 'print_jobs',
                'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_local_connection_transitions', 'edge_baseline_cutovers', 'edge_sync_outbox',
                'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta',
                'sales_return_lines', 'sales_returns', 'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'manager_approvals',
            ]);
            $this->bindEdgeLocalMeta($this->branchId, 1, $this->cloudTenantId, $this->cloudDeviceUuid, 1);
            DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'tenant_code' => $this->bridgeTenantCode]);
            $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        });
        $this->configureApplianceCloudUrls();
        config(['edge.authority.ttl_seconds' => 60, 'edge.authority.skew_margin_seconds' => 10, 'edge.authority.unstable_after_failures' => 2, 'edge.authority.lost_after_failures' => 4, 'edge.authority.handback_min_consecutive_acks' => 1]);
        $this->bridgeCloud();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->cleanupCloudRegistration();
        $this->useDb($this->cloudDb);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function tick(): array
    {
        return $this->asEdge(fn () => app()->make(EdgeAuthorityTick::class)->run('test-worker'));
    }

    private function goLocal(): void
    {
        $this->bridgeFailures = ['authority/heartbeat' => 'down', 'sync/returns' => 'down', 'sync/sales' => 'down', 'sync/supplier-finance' => 'down', 'sync/purchase-returns' => 'down',
            'sync/reconcile' => 'down', 'returnable/refresh' => 'down', 'supplier-finance/refresh' => 'down', 'purchase-returns/refresh' => 'down', 'sync/baseline' => 'down', 'config/refresh' => 'down'];
        for ($i = 0; $i < 4; $i++) {
            $this->tick();
        }
        Carbon::setTestNow(now()->addSeconds(71));
        $this->assertSame('preparing_local', $this->tick()['state']['state']);
        $this->asEdge(fn () => app(EdgeAuthorityService::class)->takeOver(true, 'supervisor'));
        $this->assertSame('local_active', $this->asEdge(fn () => app(EdgeAuthorityService::class)->state()));
        $this->asEdge(fn () => app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 500.0));
    }

    /** Start the PHP FakePrinter as a REAL separate process (same TCP contract as the physical printer / Cloud agent). */
    private function startFakePrinter(int $maxConnections, int $listenSeconds = 25): array
    {
        $out = sys_get_temp_dir() . '/edge_nodup_printer_' . Str::random(8) . '.bin';
        @unlink($out);
        @unlink($out . '.ready');
        $proc = proc_open([PHP_BINARY, base_path('tests/MySql/Support/fake_printer.php'), (string) $this->printerPort, $out, (string) $maxConnections, (string) $listenSeconds],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $deadline = microtime(true) + 10;
        while (! is_file($out . '.ready')) {
            if (microtime(true) > $deadline) {
                $this->fail('FakePrinter did not start listening');
            }
            usleep(20000);
        }

        return ['proc' => $proc, 'pipes' => $pipes, 'file' => $out];
    }

    private function waitFakePrinterDone(array $fp): string
    {
        stream_get_contents($fp['pipes'][1]);
        fclose($fp['pipes'][1]);
        fclose($fp['pipes'][2]);
        proc_close($fp['proc']);
        $bytes = is_file($fp['file']) ? (string) file_get_contents($fp['file']) : '';
        @unlink($fp['file']);
        @unlink($fp['file'] . '.ready');

        return $bytes;
    }

    /** One claim/delivery cycle of the REAL print worker command, as the appliance (the branch context is resolved fresh). */
    private function printWorkerOnce(): int
    {
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);

        return Artisan::call('edge:local:print-worker', ['--once' => true]);
    }

    private function localPrintCounts(int $saleId): array
    {
        return $this->asEdge(fn () => [
            'receipt' => DB::connection('tenant')->table('print_jobs')->where('reference_type', 'sales_order')->where('reference_id', $saleId)->where('document_type', 'receipt')->count(),
            'kot' => DB::connection('tenant')->table('print_jobs')->where('reference_type', 'sales_order')->where('reference_id', $saleId)->where('document_type', 'kot')->count(),
            'printed' => DB::connection('tenant')->table('print_jobs')->where('reference_type', 'sales_order')->where('reference_id', $saleId)->where('print_status', 'printed')->count(),
            'all_jobs' => DB::connection('tenant')->table('print_jobs')->count(),
            'kot_batches' => DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)->count(),
        ]);
    }

    private function cloudPrintCounts(string $saleUuid): array
    {
        return $this->asCloud(function () use ($saleUuid) {
            $cloudSaleId = DB::connection('tenant')->table('sales_orders')->where('sale_uuid', $saleUuid)->value('id');

            return [
                'sale_exists' => $cloudSaleId !== null,
                'print_jobs_for_sale' => $cloudSaleId ? DB::connection('tenant')->table('print_jobs')->where('reference_type', 'sales_order')->where('reference_id', $cloudSaleId)->count() : 0,
                'print_jobs_total' => DB::connection('tenant')->table('print_jobs')->count(),
                'kot_batches_for_sale' => $cloudSaleId ? DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $cloudSaleId)->count() : 0,
                'kot_batches_total' => DB::connection('tenant')->table('kot_batches')->count(),
                'registry_applied' => DB::connection('tenant')->table('edge_inbound_sale_ingestions')->where('sale_uuid', $saleUuid)->where('status', 'applied')->count(),
            ];
        });
    }

    public function test_an_offline_sale_prints_kot_and_receipt_once_locally_and_the_cloud_never_prints_it_again(): void
    {
        $this->assertSame('refreshed:initial', $this->tick()['work']['stock']);
        $this->goLocal();

        // ── the offline sale: the cashier sends the KOT and prints the bill (the SAME service calls the cashier page makes) ──
        [$saleId, $saleUuid, $receiptJobId] = $this->asEdge(function () {
            $user = User::on('tenant')->find($this->userId);
            Auth::guard('tenant')->setUser($user);
            Auth::shouldUse('tenant');
            $sale = app(EdgeLocalPosService::class)->completePaidSale(['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
                'lines' => [['product_id' => $this->burgerId, 'quantity' => 2]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]]], $user, $this->terminalId);
            $kot = app(EdgeLocalPosService::class)->queueKotEvents((int) $sale->id, $user, $this->terminalId);
            $this->assertCount(1, $kot['jobs'], 'ONE kitchen ticket for the sale');
            $this->assertSame((int) $this->printerId, (int) $kot['jobs'][0]->printer_id, 'the KOT routes to the network printer (Edge direct)');
            $receipt = app(PrintJobService::class)->queueReceipt($sale->fresh(), terminalId: (string) $this->terminalId, ensureOnce: true);
            $this->assertSame((int) $this->printerId, (int) $receipt->printer_id, 'the receipt routes to the network printer (Edge direct)');
            // The cashier double-clicks "Print bill": ensure-once returns the SAME job — never a second bill.
            $again = app(PrintJobService::class)->queueReceipt($sale->fresh(), terminalId: (string) $this->terminalId, ensureOnce: true);
            $this->assertSame((int) $receipt->id, (int) $again->id, 'ensure-once: one receipt job');

            return [(int) $sale->id, (string) $sale->sale_uuid, (int) $receipt->id];
        });
        $local = $this->localPrintCounts($saleId);
        $this->assertSame(['receipt' => 1, 'kot' => 1, 'printed' => 0, 'all_jobs' => 2, 'kot_batches' => 1], $local);

        // ── physical delivery: the appliance's print worker sends BOTH jobs to the printer, exactly once each ──
        $fp = $this->startFakePrinter(2);
        $this->asEdge(function () {
            $this->assertSame(0, $this->printWorkerOnce(), Artisan::output());
            $this->assertSame(0, $this->printWorkerOnce(), Artisan::output());
        });
        $captured = $this->waitFakePrinterDone($fp);
        // EXACT bytes: the stored KOT payload then the stored receipt payload (per-printer FIFO), each followed by the
        // Cloud-agent trailing feed — nothing more, nothing twice.
        [$kotRaw, $receiptRaw] = $this->asEdge(fn () => [
            (string) DB::connection('tenant')->table('print_jobs')->where('reference_id', $saleId)->where('document_type', 'kot')->value('raw_payload'),
            (string) DB::connection('tenant')->table('print_jobs')->where('reference_id', $saleId)->where('document_type', 'receipt')->value('raw_payload'),
        ]);
        $this->assertNotSame('', $kotRaw);
        $this->assertNotSame('', $receiptRaw);
        $this->assertSame($kotRaw . "\n\n\n" . $receiptRaw . "\n\n\n", $captured, 'the printer received exactly TWO payloads (one KOT, one receipt), each exactly once');
        $local = $this->localPrintCounts($saleId);
        $this->assertSame(2, $local['printed'], 'both local jobs are printed');
        $this->assertSame(2, $local['all_jobs']);
        $cloudBefore = $this->cloudPrintCounts($saleUuid);
        $this->assertFalse($cloudBefore['sale_exists'], 'the Cloud knows nothing yet');
        $this->assertSame(0, $cloudBefore['print_jobs_total']);

        // ── WAN back: the sale syncs; the Cloud ingests the OFFICIAL sale and prints NOTHING ──
        $this->bridgeFailures = [];
        $t = $this->tick();
        // The appliance REMAINS the writer while the connection is restored / reconciling (never a silent handback).
        $this->assertContains($t['state']['state'], ['connection_restored', 'reconciling'], json_encode($t['state']));
        $this->assertSame('local_active', $this->asEdge(fn () => app(EdgeAuthorityService::class)->state()));
        $this->assertSame(1, $t['work']['drained'], json_encode($t['work']));
        $cloud = $this->cloudPrintCounts($saleUuid);
        $this->assertTrue($cloud['sale_exists']);
        $this->assertSame(1, $cloud['registry_applied']);
        $this->assertSame(0, $cloud['print_jobs_for_sale'], 'Cloud ingestion never creates a print job');
        $this->assertSame(0, $cloud['print_jobs_total']);
        $this->assertSame(0, $cloud['kot_batches_for_sale'], 'Cloud ingestion never creates a KOT batch');
        $this->assertSame(0, $cloud['kot_batches_total']);
        $this->assertSame($local, $this->localPrintCounts($saleId), 'the ACK changes nothing on the appliance: no re-print');

        // ── replay the SAME immutable envelope straight at the Cloud → already_applied, still no print ──
        $envelope = json_decode((string) $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $saleUuid)->value('envelope')), true);
        $headers = ['X-Edge-Device-ID' => $this->cloudDeviceUuid, 'Authorization' => 'Bearer ' . $this->cloudDeviceSecret];
        $this->asCloud(fn () => $this->postJson('http://' . config('tenancy.central_domain') . '/api/edge/sync/sales', ['envelope' => $envelope], $headers)->assertOk()->assertJsonPath('status', 'already_applied'));
        $this->assertSame($cloud, $this->cloudPrintCounts($saleUuid), 'a replayed ACK prints nothing');

        // ── LOST ACK: the appliance forgot the ACK; reconciliation recovers it — no re-send, no print ──
        $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $saleUuid)->update(['state' => EdgeSyncOutbox::STATE_PENDING, 'acknowledged_at' => null, 'ack_ingestion_uuid' => null]));
        $this->bridgeFailures = ['sync/sales' => 'down'];
        $t2 = $this->tick();
        $this->assertSame(1, (int) ($t2['work']['findings']['recovered_lost_ack'] ?? 0), 'reconciliation recovered the lost ACK: ' . json_encode($t2['work']));
        $this->bridgeFailures = [];
        $this->assertSame('acknowledged', $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $saleUuid)->value('state')));
        $this->assertSame($cloud, $this->cloudPrintCounts($saleUuid), 'lost-ACK recovery prints nothing');
        $this->assertSame($local, $this->localPrintCounts($saleId), 'lost-ACK recovery re-prints nothing locally');

        // ── the local print worker finds nothing new to print after the sync (a second printer window stays empty) ──
        $fp2 = $this->startFakePrinter(1, 3);
        $this->asEdge(fn () => $this->assertSame(0, $this->printWorkerOnce(), 'print worker after sync: ' . Artisan::output()));
        $this->assertSame('', $this->waitFakePrinterDone($fp2), 'no payload reaches the printer after the sync');

        // ── handback: close the shift, hand authority back — the Cloud still never printed the offline sale ──
        $this->tick();
        $this->asEdge(fn () => DB::connection('tenant')->table('shifts')->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->userId]));
        $hb = $this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->run('supervisor'));
        $this->assertSame(EdgeHandbackOrchestrator::HANDED_BACK, $hb['status'], json_encode($hb));
        $this->assertSame($cloud, $this->cloudPrintCounts($saleUuid), 'handback prints nothing');
        $this->assertSame($local, $this->localPrintCounts($saleId), 'ONE KOT, ONE receipt — for the whole lifecycle');
        $this->assertSame(1, $local['receipt']);
        $this->assertSame(1, $local['kot']);
        // Architecture lock (P4 §3), asserted from configuration so the classification cannot drift silently.
        $this->assertTrue((bool) config('edge.print_architecture.network_printer_edge_direct'));
        $this->assertFalse((bool) config('edge.print_architecture.second_edge_agent_for_network_printer'));
        $this->assertSame('ONLINE_REQUIRED', config('edge.print_architecture.usb_status_for_pilot'));
    }
}
