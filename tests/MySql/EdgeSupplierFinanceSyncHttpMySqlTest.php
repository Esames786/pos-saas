<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeHandbackOrchestrator;
use App\Services\Edge\EdgeLocalSupplierFinanceService;
use App\Services\Edge\EdgeSupplierFinanceCacheService;
use App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder;
use App\Services\Finance\SupplierPayableService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeCloudBridgeFixture;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgeSupplierFinanceFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — F2 NETWORK-DOWN END TO END, two databases (Cloud + appliance), the real device-authenticated HTTP
 * transport bridged into the Cloud kernel:
 *
 *   A  Internet healthy: the standby pulls the supplier-finance projection advertised on the heartbeat.
 *   B  WAN fails → supervised takeover records SUPPLIER_FINANCE_CACHE_CURRENT.
 *   C  Offline: a supplier is paid on account (no bill), another payment allocated to a Purchase Bill, a manual AP
 *      journal raises a supplier's payable, another (Dr AP / Cr Cash) reduces it — provisional positions, pending events.
 *   D  Handback is blocked while supplier-finance events are pending.
 *   E  WAN restored, the appliance REMAINS the writer; the Cloud posts each OFFICIAL transaction exactly once
 *      (supplier payment + subledger + bill + cash/bank + Dr 2100 / Cr cash-bank GL; manual journal + subledger mirror).
 *   F  LOST ACK for a payment and a journal → recovered once; replay → already_applied; different hash → conflict.
 *   G  Controlled handback; the refreshed projection converges: Cloud payable = subledger = Edge position, pending
 *      markers gone, exactly ONE visible ledger transaction per event; AP control moved exactly as the subledger did.
 *
 * Plus: a STALE projection fails closed at takeover time (payments refused, nothing recorded); the Cloud's canonical
 * refusals are terminal (a payment that would create a supplier advance; an operator the Cloud no longer permits);
 * a finance-incomplete posting rolls back atomically and the next tick applies once.
 */
class EdgeSupplierFinanceSyncHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeCloudBridgeFixture;
    use EdgeSupplierFinanceFixture;

    private const CONFIG_TABLES = ['branches', 'users', 'terminals', 'units', 'categories', 'products', 'permissions', 'model_has_permissions'];

    private int $branchId;
    private int $userId;
    private int $terminalId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->provisionTwoDatabases();

        // ── the Cloud's truth ──
        $this->cleanTenant(array_merge(self::SF_TABLES, [
            'edge_branch_authority_leases', 'edge_inbound_sale_ingestions', 'edge_inbound_return_ingestions', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'units', 'model_has_permissions', 'permissions', 'terminals', 'branches', 'users',
        ]));
        $this->branchId = $this->makeBranch(['name' => 'Finance Branch', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'SF' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $conn = DB::connection('tenant');
        $pc = $conn->table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = $this->makeProduct($this->makeCategory(), ['name' => 'Burger', 'unit_id' => $pc, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active']);
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$this->productId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$this->productId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 100, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedCloudSupplierFinance($this->branchId);
        foreach ([EdgeLocalSupplierFinanceService::PERM_LEDGER, EdgeLocalSupplierFinanceService::PERM_PAYMENT, EdgeLocalSupplierFinanceService::PERM_JOURNAL] as $p) {
            $this->grantEdgePermission($this->userId, $p);   // Cloud permissions are authoritative; mirrored to the appliance below
        }
        $this->registerCloudTenantAndDevice($this->branchId, 1);

        // ── the appliance ──
        $this->mirrorConfigToAppliance(self::CONFIG_TABLES);
        $this->asEdge(function () {
            $this->cleanTenant(array_merge(self::SF_EDGE_TABLES, [
                'edge_local_connection_transitions', 'edge_baseline_cutovers', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances',
                'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta', 'shifts',
            ]));
            $this->bindEdgeLocalMeta($this->branchId, 1, $this->cloudTenantId, $this->cloudDeviceUuid, 1);
            DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'tenant_code' => $this->bridgeTenantCode]);
            $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        });
        $this->configureApplianceCloudUrls();
        config(['edge.authority.ttl_seconds' => 60, 'edge.authority.skew_margin_seconds' => 10, 'edge.authority.unstable_after_failures' => 2, 'edge.authority.lost_after_failures' => 4, 'edge.authority.handback_min_consecutive_acks' => 2]);
        $this->bridgeCloud();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        config(['edge.testing.fail_after_official_supplier_finance' => false]);
        $this->cleanupCloudRegistration();
        $this->useDb($this->cloudDb);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function tick(): array
    {
        return $this->asEdge(fn () => app()->make(EdgeAuthorityTick::class)->run('test-worker'));
    }

    private function edgeMeta(): object
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('edge_local_meta')->first());
    }

    private function user(): User
    {
        return $this->asEdge(fn () => User::on('tenant')->find($this->userId));
    }

    private function position(int $supplierId): array
    {
        return $this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->position($supplierId));
    }

    private function pay(int $supplierId, float $amount, array $extra = []): array
    {
        return $this->asEdge(fn () => app(EdgeLocalSupplierFinanceService::class)->recordPayment(array_merge([
            'cloud_supplier_id' => $supplierId, 'cloud_cash_bank_account_id' => $this->tillCbId, 'amount' => $amount, 'payment_method' => 'cash',
        ], $extra), $this->user(), $this->terminalId));
    }

    private function journal(array $lines, string $description): array
    {
        return $this->asEdge(fn () => app(EdgeLocalSupplierFinanceService::class)->postApJournal(['description' => $description, 'lines' => $lines], $this->user(), $this->terminalId));
    }

    private function cloudFinance(): array
    {
        return $this->asCloud(function () {
            $c = DB::connection('tenant');

            return [
                'payments' => $c->table('supplier_payments')->count(),
                'ledger_payments' => $c->table('supplier_ledgers')->where('entry_type', 'payment')->count(),
                'ledger_adjustments' => $c->table('supplier_ledgers')->where('entry_type', 'journal_adjustment')->count(),
                'journals_payment' => $c->table('journal_entries')->where('source_type', 'supplier_payment')->where('status', 'posted')->count(),
                'journals_manual' => $c->table('journal_entries')->where('source_type', 'manual_journal')->where('status', 'posted')->count(),
                'cashbank_payment_out' => $c->table('cash_bank_account_transactions')->where('transaction_type', 'supplier_payment')->count(),
                'cashbank_manual' => $c->table('cash_bank_account_transactions')->where('transaction_type', 'manual_journal')->count(),
                'registry_applied' => $c->table('edge_inbound_supplier_finance_ingestions')->where('status', 'applied')->count(),
                'payable_a' => (float) $c->table('suppliers')->where('id', $this->supplierAId)->value('current_balance'),
                'payable_b' => (float) $c->table('suppliers')->where('id', $this->supplierBId)->value('current_balance'),
                'till' => (float) $c->table('cash_bank_accounts')->where('id', $this->tillCbId)->value('current_balance'),
                'bill' => (array) $c->table('purchase_bills')->where('id', $this->billAId)->first(['amount_paid', 'balance_due', 'status']),
            ];
        });
    }

    private function outboxStates(): array
    {
        return $this->asEdge(fn () => EdgeSyncOutbox::on('tenant')->whereIn('envelope_schema_version', EdgeSupplierFinanceEnvelopeBuilder::SCHEMAS)->orderBy('id')->pluck('state', 'sale_uuid')->all());
    }

    private function allWanDown(): array
    {
        return ['authority/heartbeat' => 'down', 'sync/supplier-finance' => 'down', 'sync/sales' => 'down', 'sync/returns' => 'down', 'sync/reconcile' => 'down', 'supplier-finance/refresh' => 'down', 'returnable/refresh' => 'down', 'sync/baseline' => 'down', 'config/refresh' => 'down'];
    }

    private function goLocal(): void
    {
        $this->bridgeFailures = $this->allWanDown();
        for ($i = 0; $i < 4; $i++) {
            $this->tick();
        }
        Carbon::setTestNow(now()->addSeconds(71));
        $this->assertSame('preparing_local', $this->tick()['state']['state']);
        $this->asEdge(fn () => app(EdgeAuthorityService::class)->takeOver(true, 'supervisor'));
        $this->assertSame('local_active', $this->asEdge(fn () => app(EdgeAuthorityService::class)->state()));
    }

    private function warmStandby(): array
    {
        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok'], json_encode($r['heartbeat']));
        $this->assertSame('refreshed:3-suppliers', $r['work']['supplier_finance'], json_encode($r['work']));
        $meta = $this->edgeMeta();
        $this->assertNotEmpty($meta->supplier_finance_cache_watermark);
        $this->assertSame($meta->standby_supplier_finance_watermark_seen, $meta->supplier_finance_cache_watermark, 'the projection equals what the Cloud advertised');
        $this->assertSame('current', $this->tick()['work']['supplier_finance']);
        $this->assertTrue($this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->freshness())['ok']);

        return $r;
    }

    // ── tests ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_network_down_supplier_finance_end_to_end_with_lost_ack_replay_conflict_handback_and_convergence(): void
    {
        // A. Internet healthy: the standby pulled the supplier-finance projection (suppliers, bill, cash/bank, chart, applied set).
        $this->warmStandby();
        $this->assertSame(['cloud_payable' => 10000.0, 'available_payable' => 10000.0], array_intersect_key($this->position($this->supplierAId), array_flip(['cloud_payable', 'available_payable'])));
        $this->assertSame(4000.0, $this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->openBills($this->supplierAId))[0]['balance_due']);
        $this->assertContains($this->accountId('2100'), $this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->apAccountIds()));
        $cloudBefore = $this->cloudFinance();

        // B. WAN fails; supervised Local Mode records SUPPLIER_FINANCE_CACHE_CURRENT=yes.
        $wm = $this->edgeMeta()->supplier_finance_cache_watermark;
        $this->goLocal();
        $proof = json_decode((string) $this->edgeMeta()->authority_takeover_freshness, true);
        $this->assertSame($wm, $proof['supplier_finance_cache_watermark']);
        $this->assertTrue($proof['supplier_finance_cache_current']);

        // C. Offline supplier finance on the appliance.
        $p1 = $this->pay($this->supplierAId, 7000, ['reference_no' => 'CASH-1', 'notes' => 'on account']);       // no bill
        $p2 = $this->pay($this->supplierAId, 2000, ['cloud_bill_id' => $this->billAId, 'reference_no' => 'CASH-2']); // against PB-A-1
        $j1 = $this->journal([
            ['cloud_account_id' => $this->accountId('6100'), 'debit' => 1500, 'description' => 'rent accrual'],
            ['cloud_account_id' => $this->accountId('2100'), 'credit' => 1500, 'cloud_supplier_id' => $this->supplierBId],
        ], 'Dr Rent / Cr AP Beta');
        $j2 = $this->journal([
            ['cloud_account_id' => $this->accountId('2100'), 'debit' => 500, 'cloud_supplier_id' => $this->supplierBId],
            ['cloud_account_id' => $this->accountId('1110'), 'credit' => 500, 'cloud_cash_bank_account_id' => $this->tillCbId],
        ], 'Dr AP Beta / Cr Till');
        $this->assertSame(1000.0, $this->position($this->supplierAId)['available_payable'], 'LOCAL_SUPPLIER_POSITION: 10,000 − 7,000 − 2,000');
        $this->assertSame(6000.0, $this->position($this->supplierBId)['available_payable'], '5,000 + 1,500 − 500');
        $till = collect($this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->cashBankAccounts()))->firstWhere('cloud_cash_bank_account_id', $this->tillCbId);
        $this->assertSame(50000.0 - 9500.0, $till['projected_balance'], 'LOCAL_CASH_EFFECT once per event');
        $this->assertSame(array_fill(0, 4, 'pending'), array_values($this->outboxStates()));
        $this->assertSame($cloudBefore, $this->cloudFinance(), 'the Cloud knows nothing yet');
        try {
            $this->pay($this->supplierAId, 1001);
            $this->fail('the boundary holds across events');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('advance', collect($e->errors())->flatten()->implode(' '));
        }

        // D. Handback is blocked while the finance events are pending (and the WAN is down).
        $codes = array_column($this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->assess())['blockers'], 'code');
        $this->assertContains('SUPPLIER_FINANCE_PENDING', $codes, json_encode($codes));
        $this->assertContains('OUTBOX_PENDING', $codes);

        // E. WAN restored: the appliance REMAINS the writer; the Cloud posts the OFFICIAL transactions exactly once.
        $this->bridgeFailures = [];
        $t = $this->tick();
        $this->assertSame('connection_restored', $t['state']['state']);
        $this->assertSame(4, $t['work']['drained'], json_encode($t['work']));
        $this->assertTrue($this->asEdge(function () { try { app(EdgeAuthorityService::class)->assertLocalMutationAllowed(); return true; } catch (\Throwable) { return false; } }), 'still the writer');
        $cloud = $this->cloudFinance();
        $this->assertSame(2, $cloud['payments']);
        $this->assertSame(2, $cloud['ledger_payments']);
        $this->assertSame(2, $cloud['ledger_adjustments']);
        $this->assertSame(2, $cloud['journals_payment']);
        $this->assertSame(2, $cloud['journals_manual']);
        $this->assertSame(2, $cloud['cashbank_payment_out']);
        $this->assertSame(1, $cloud['cashbank_manual']);
        $this->assertSame(4, $cloud['registry_applied']);
        $this->assertSame(1000.0, $cloud['payable_a'], 'Cloud payable after the two official payments');
        $this->assertSame(6000.0, $cloud['payable_b'], 'Cloud payable after the two official AP journals');
        $this->assertSame(50000.0 - 9500.0, $cloud['till'], 'cash/bank moved once per event');
        $this->assertSame(['amount_paid' => 2000.0, 'balance_due' => 2000.0, 'status' => 'partial'], ['amount_paid' => (float) $cloud['bill']['amount_paid'], 'balance_due' => (float) $cloud['bill']['balance_due'], 'status' => $cloud['bill']['status']]);
        $this->asCloud(function () use ($p1) {
            $payment = DB::connection('tenant')->table('supplier_payments')->where('amount', 7000)->first();
            $this->assertNull($payment->purchase_bill_id, 'PAYMENT_WITHOUT_PURCHASE_BILL at the Cloud too');
            $this->assertSame((int) $this->tillCbId, (int) $payment->cash_bank_account_id);
            $journal = DB::connection('tenant')->table('journal_entries')->where('source_type', 'supplier_payment')->where('source_id', $payment->id)->first();
            $lines = DB::connection('tenant')->table('journal_lines')->where('journal_entry_id', $journal->id)->get();
            $this->assertSame(7000.0, (float) $lines->where('account_id', $this->accountId('2100'))->sum('debit'), 'Dr Accounts Payable');
            $this->assertSame(7000.0, (float) $lines->where('account_id', $this->accountId('1110'))->sum('credit'), 'Cr the chosen cash/bank COA account');
            $registry = DB::connection('tenant')->table('edge_inbound_supplier_finance_ingestions')->where('event_uuid', $p1['event_uuid'])->first();
            $this->assertSame((int) $payment->id, (int) $registry->supplier_payment_id);
            $this->assertSame($payment->payment_no, $registry->official_reference_no);
            $apLine = DB::connection('tenant')->table('journal_lines')->where('account_id', $this->accountId('2100'))->whereNotNull('supplier_id')->where('debit', 500)->first();
            $this->assertSame('supplier', $apLine->counterparty_type, 'the manual AP line carries the supplier dimension');
            $this->assertSame((int) $this->supplierBId, (int) $apLine->supplier_id);
        });
        $states = $this->outboxStates();
        $this->assertSame(array_fill(0, 4, 'acknowledged'), array_values($states));
        $this->assertSame(1000.0, $this->position($this->supplierAId)['available_payable'], 'the ACK re-applies nothing locally (still subtracted until the projection that includes it arrives)');
        $this->assertSame('synced', $this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->event($p1['event_uuid']))['sync']['state']);

        // F. LOST ACK for a payment and a journal while the finance wire is flaky → recovered once; nothing doubles.
        $this->asEdge(fn () => EdgeSyncOutbox::on('tenant')->whereIn('sale_uuid', [$p1['event_uuid'], $j1['event_uuid']])->update(['state' => EdgeSyncOutbox::STATE_PENDING, 'acknowledged_at' => null, 'ack_ingestion_uuid' => null, 'ack_payload' => null]));
        $this->bridgeFailures = ['sync/supplier-finance' => 'down'];
        $t2 = $this->tick();
        $this->assertSame(2, (int) ($t2['work']['findings']['recovered_lost_ack'] ?? 0), 'reconciliation recovered both lost ACKs: ' . json_encode($t2['work']));
        $this->assertTrue($t2['work']['clean']);
        $this->assertSame($cloud, $this->cloudFinance(), 'ONE payment, ONE subledger movement, ONE AP effect, ONE journal, ONE cash/bank movement — per event');
        $this->assertSame(array_fill(0, 4, 'acknowledged'), array_values($this->outboxStates()));
        $this->bridgeFailures = [];

        // Replay the SAME immutable event → already_applied; the SAME uuid with DIFFERENT content → conflict, no mutation.
        $envelope = json_decode((string) $this->asEdge(fn () => EdgeSyncOutbox::on('tenant')->where('sale_uuid', $p1['event_uuid'])->value('envelope')), true);
        $headers = ['X-Edge-Device-ID' => $this->cloudDeviceUuid, 'Authorization' => 'Bearer ' . $this->cloudDeviceSecret];
        $uri = 'http://' . config('tenancy.central_domain') . '/api/edge/sync/supplier-finance';
        $this->asCloud(fn () => $this->postJson($uri, ['envelope' => $envelope], $headers)->assertOk()->assertJsonPath('status', 'already_applied'));
        $tampered = $envelope;
        $tampered['amount'] = 9000;
        unset($tampered['content_hash']);
        $tampered['content_hash'] = hash('sha256', app(\App\Services\Edge\EdgeBootstrapService::class)->canonicalJson($tampered));
        $this->asCloud(fn () => $this->postJson($uri, ['envelope' => $tampered], $headers)->assertStatus(409)->assertJsonPath('failure_code', 'ENVELOPE_CONFLICT'));
        $jEnvelope = json_decode((string) $this->asEdge(fn () => EdgeSyncOutbox::on('tenant')->where('sale_uuid', $j2['event_uuid'])->value('envelope')), true);
        $this->asCloud(fn () => $this->postJson($uri, ['envelope' => $jEnvelope], $headers)->assertOk()->assertJsonPath('status', 'already_applied'));
        $this->assertSame($cloud, $this->cloudFinance(), 'replays and a conflicting replay mutate nothing');

        // G. Controlled handback (nothing pending) → the standby re-pulls the projection → CONVERGENCE.
        $this->tick();
        $codes = array_column($this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->assess())['blockers'], 'code');
        $this->assertSame([], $codes, json_encode($codes));
        $hb = $this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->run('supervisor'));
        $this->assertSame(EdgeHandbackOrchestrator::HANDED_BACK, $hb['status'], json_encode($hb));
        $this->assertStringStartsWith('refreshed', $hb['freshness']['supplier_finance'], 'the standby re-pulled the supplier-finance position after the Cloud posted');
        $a = $this->position($this->supplierAId);
        $b = $this->position($this->supplierBId);
        $this->assertSame(['cloud_payable' => 1000.0, 'pending_delta' => 0.0, 'available_payable' => 1000.0, 'pending_events' => 0], array_intersect_key($a, array_flip(['cloud_payable', 'pending_delta', 'available_payable', 'pending_events'])), 'SUPPLIER_LEDGER_CONVERGENCE: Cloud payable = Edge position, nothing pending');
        $this->assertSame(['cloud_payable' => 6000.0, 'pending_delta' => 0.0, 'available_payable' => 6000.0, 'pending_events' => 0], array_intersect_key($b, array_flip(['cloud_payable', 'pending_delta', 'available_payable', 'pending_events'])));
        $this->assertSame(4, $this->asEdge(fn () => DB::connection('tenant')->table(EdgeSupplierFinanceCacheService::T_APPLIED)->count()), 'the projection lists the four applied events');
        $ledgerA = $this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->ledger($this->supplierAId));
        $this->assertSame(0, $ledgerA['provisional_count'], 'the PENDING marker is gone');
        $official = collect($ledgerA['rows'])->where('kind', 'official');
        $this->assertSame(3, $official->count(), 'opening balance + the two official payments');
        $this->assertEqualsCanonicalizing([$p1['event_uuid'], $p2['event_uuid']], $official->pluck('edge_event_uuid')->filter()->values()->all(), 'exactly ONE visible transaction per Edge event, labelled');
        $this->assertSame(1000.0, $official->first()['balance_after'], 'the Cloud running balance');
        $ledgerB = $this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->ledger($this->supplierBId));
        $this->assertSame(0, $ledgerB['provisional_count']);
        $this->assertEqualsCanonicalizing([$j1['event_uuid'], $j2['event_uuid']], collect($ledgerB['rows'])->pluck('edge_event_uuid')->filter()->values()->all());
        foreach ([$p1, $p2, $j1, $j2] as $ev) {
            $this->assertSame('official', $this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->event($ev['event_uuid']))['sync']['state']);
        }
        // AP_CONTROL_RECONCILIATION on the Cloud: the AP control account moved exactly as the supplier subledger did.
        $this->asCloud(function () {
            $c = DB::connection('tenant');
            $ap = $this->accountId('2100');
            $edgeJournalIds = $c->table('edge_inbound_supplier_finance_ingestions')->where('status', 'applied')->pluck('journal_entry_id')->filter()->all();
            $edgePaymentIds = $c->table('edge_inbound_supplier_finance_ingestions')->where('status', 'applied')->pluck('supplier_payment_id')->filter()->all();
            $paymentJournalIds = $c->table('journal_entries')->where('source_type', 'supplier_payment')->whereIn('source_id', $edgePaymentIds)->pluck('id')->all();
            $apLines = $c->table('journal_lines')->where('account_id', $ap)->whereIn('journal_entry_id', array_merge($edgeJournalIds, $paymentJournalIds))->get();
            $apNetDebit = round((float) $apLines->sum('debit') - (float) $apLines->sum('credit'), 2);
            $sub = $c->table('supplier_ledgers')->whereIn('entry_type', ['payment', 'journal_adjustment'])->get();
            $subNet = round((float) $sub->where('direction', 'debit')->sum('amount') - (float) $sub->where('direction', 'credit')->sum('amount'), 2);
            $this->assertSame(8000.0, $apNetDebit, 'AP control: 7,000 + 2,000 + 500 debits − 1,500 credit');
            $this->assertSame(-8000.0, $subNet, 'the supplier subledger moved by the same amount the other way');
            foreach ([$this->supplierAId, $this->supplierBId] as $sid) {
                $last = $c->table('supplier_ledgers')->where('supplier_id', $sid)->orderByDesc('id')->value('balance_after');
                $this->assertSame((float) $c->table('suppliers')->where('id', $sid)->value('current_balance'), (float) $last, 'supplier payable = subledger running balance');
            }
        });
    }

    public function test_a_stale_projection_fails_closed_at_takeover_and_the_appliance_never_guesses_a_payable(): void
    {
        $this->warmStandby();
        $wmBefore = $this->edgeMeta()->supplier_finance_cache_watermark;

        // The Cloud moves (an Online payment) and ADVERTISES the new watermark, but the appliance cannot pull the projection.
        $this->asCloud(fn () => app(SupplierPayableService::class)->recordPayment(['supplier_id' => $this->supplierBId, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->tillCbId, 'payment_date' => now()->toDateString(), 'amount' => 1000, 'payment_method' => 'cash'], $this->userId));
        $this->bridgeFailures = ['supplier-finance/refresh' => 'down'];
        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok']);
        $this->assertStringStartsWith('error:', $r['work']['supplier_finance'], json_encode($r['work']));
        $meta = $this->edgeMeta();
        $this->assertNotSame($meta->standby_supplier_finance_watermark_seen, $meta->supplier_finance_cache_watermark, 'the Cloud advertised a NEWER position than the appliance holds');
        $this->assertSame($wmBefore, $meta->supplier_finance_cache_watermark);
        $this->assertFalse($this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->freshness())['ok']);

        // The partition: takeover proceeds (selling is not held hostage) but SUPPLIER_FINANCE_CACHE_CURRENT=no is recorded …
        $this->goLocal();
        $proof = json_decode((string) $this->edgeMeta()->authority_takeover_freshness, true);
        $this->assertFalse($proof['supplier_finance_cache_current']);
        $this->assertNotSame($proof['supplier_finance_cache_advertised'], $proof['supplier_finance_cache_watermark']);

        // … and every supplier-finance action FAILS CLOSED with a business message; nothing is recorded, nothing guessed.
        try {
            $this->pay($this->supplierBId, 100);
            $this->fail('a stale projection must refuse payments');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not current on this branch server', collect($e->errors())->flatten()->implode(' '));
        }
        try {
            $this->journal([['cloud_account_id' => $this->accountId('6100'), 'debit' => 10], ['cloud_account_id' => $this->accountId('2100'), 'credit' => 10, 'cloud_supplier_id' => $this->supplierBId]], 'x');
            $this->fail('a stale projection must refuse journals');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not current on this branch server', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame([], $this->outboxStates());
        $this->assertSame(0, $this->asEdge(fn () => DB::connection('tenant')->table(EdgeSupplierFinanceCacheService::T_EVENTS)->count()));
        $this->assertSame(5000.0, $this->position($this->supplierBId)['cloud_payable'], 'the appliance still shows the position it HOLDS (flagged stale) — it never invents the Cloud\'s 4,000');
    }

    public function test_cloud_refusals_are_terminal_and_a_finance_incomplete_posting_rolls_back_atomically(): void
    {
        $this->warmStandby();
        $this->goLocal();
        $pa = $this->pay($this->supplierAId, 7000);                                   // will collide with an Online payment made during the partition
        $pb = $this->pay($this->supplierBId, 1000);                                   // will hit the finance-incomplete seam first, then apply once
        $j = $this->journal([['cloud_account_id' => $this->accountId('6100'), 'debit' => 100], ['cloud_account_id' => $this->accountId('2100'), 'credit' => 100, 'cloud_supplier_id' => $this->supplierAId]], 'accrual'); // operator loses the Cloud permission

        // Meanwhile at the Cloud (the Cloud is never fenced for tenant-wide supplier finance): an Online payment of 5,000 to SUP-A …
        $this->asCloud(fn () => app(SupplierPayableService::class)->recordPayment(['supplier_id' => $this->supplierAId, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->tillCbId, 'payment_date' => now()->toDateString(), 'amount' => 5000, 'payment_method' => 'cash'], $this->userId));
        // … and the operator's manual-journal permission is revoked (Online permissions are authoritative).
        $this->asCloud(function () {
            $permId = DB::connection('tenant')->table('permissions')->where('name', EdgeLocalSupplierFinanceService::PERM_JOURNAL)->value('id');
            DB::connection('tenant')->table('model_has_permissions')->where('permission_id', $permId)->where('model_id', $this->userId)->delete();
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        });
        $before = $this->cloudFinance();
        $this->assertSame(5000.0, $before['payable_a']);

        // WAN back with the finance-incomplete seam armed: the 7,000 payment is REFUSED (would create an advance) — terminal;
        // the 1,000 payment posts officially, the seam fails after → the whole transaction rolls back → retryable.
        config(['edge.testing.fail_after_official_supplier_finance' => true]);
        $this->bridgeFailures = [];
        $t = $this->tick();
        $states = $this->outboxStates();
        $this->assertSame('failed_permanent', $states[$pa['event_uuid']], json_encode(['states' => $states, 'work' => $t['work']]));
        $this->assertSame('pending', $states[$pb['event_uuid']], 'finance-incomplete → not APPLIED, retryable');
        $mid = $this->cloudFinance();
        $this->assertSame($before['payments'], $mid['payments'], 'nothing was saved for either event');
        $this->assertSame($before['payable_a'], $mid['payable_a']);
        $this->assertSame($before['payable_b'], $mid['payable_b']);
        $this->assertSame($before['till'], $mid['till']);
        $this->asCloud(function () use ($pa, $pb) {
            $rows = DB::connection('tenant')->table('edge_inbound_supplier_finance_ingestions')->whereIn('event_uuid', [$pa['event_uuid'], $pb['event_uuid']])->get()->keyBy('event_uuid');
            $this->assertSame('PAYMENT_REFUSED', $rows[$pa['event_uuid']]->failure_code);
            $this->assertStringContainsString('advance', strtolower((string) $rows[$pa['event_uuid']]->last_error));
            $this->assertSame('INGEST_FAILED', $rows[$pb['event_uuid']]->failure_code);
            $this->assertNotSame('applied', $rows[$pb['event_uuid']]->status);
        });
        $this->assertSame('failed', $this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->event($pa['event_uuid']))['sync']['state']);

        // Seam off: the next tick applies the 1,000 payment exactly once; the journal is refused — ACTOR_UNAUTHORIZED, terminal.
        config(['edge.testing.fail_after_official_supplier_finance' => false]);
        $t2 = $this->tick();
        $states = $this->outboxStates();
        $this->assertSame('acknowledged', $states[$pb['event_uuid']], json_encode(['states' => $states, 'work' => $t2['work']]));
        $this->assertSame('failed_permanent', $states[$j['event_uuid']]);
        $after = $this->cloudFinance();
        $this->assertSame($before['payments'] + 1, $after['payments']);
        $this->assertSame(4000.0, $after['payable_b']);
        $this->assertSame($before['till'] - 1000.0, $after['till']);
        $this->assertSame(0, $after['journals_manual'], 'no manual journal for an operator the Cloud does not permit');
        $this->assertSame(1, $after['registry_applied']);
        $this->asCloud(fn () => $this->assertSame('ACTOR_UNAUTHORIZED', DB::connection('tenant')->table('edge_inbound_supplier_finance_ingestions')->where('event_uuid', $j['event_uuid'])->value('failure_code')));

        // The permanent failures block the handback explicitly until a supervisor resolves them.
        $this->tick();
        $codes = array_column($this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->assess())['blockers'], 'code');
        $this->assertContains('SUPPLIER_FINANCE_PERMANENT_FAILURE', $codes, json_encode($codes));
        $this->assertContains('PERMANENT_SYNC_FAILURE', $codes);
        $this->assertNotContains('SUPPLIER_FINANCE_PENDING', $codes);
    }
}
