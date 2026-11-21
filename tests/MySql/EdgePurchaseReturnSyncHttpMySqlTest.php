<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeHandbackOrchestrator;
use App\Services\Edge\EdgeLocalPurchaseReturnService;
use App\Services\Edge\EdgePurchaseReturnCacheService;
use App\Services\Edge\EdgePurchaseReturnEnvelopeBuilder;
use App\Services\Edge\EdgeSupplierFinanceCacheService;
use App\Services\Purchasing\PurchaseReturnService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeCloudBridgeFixture;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgePurchaseReturnFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — F3 NETWORK-DOWN END TO END, two databases (Cloud + appliance) over the real device-authenticated transport:
 *
 *   A  Internet healthy: the standby pulls the purchase-return projection advertised on the heartbeat (and the F2 supplier
 *      projection, and the stock baseline).
 *   B  WAN fails → supervised takeover records PURCHASE_RETURN_CACHE_CURRENT.
 *   C  Offline: 7 of the 10 received units go back to the supplier — local stock down once, provisional supplier payable
 *      effect, PENDING SYNC event; handback blocked by PURCHASE_RETURN_PENDING.
 *   D  WAN restored, the appliance REMAINS the writer; the Cloud posts the OFFICIAL return exactly once through
 *      PurchaseReturnService (document, FEFO stock OUT, supplier subledger credit, Dr 2100 / Cr 1400 GL, journal_entry_id).
 *   E  LOST ACK → recovered once; replay → already_applied; different hash → conflict, no mutation.
 *   F  Controlled handback → refreshed projection converges: returnable = received − Cloud returned, pending gone, the event
 *      is OFFICIAL, and the F2 supplier position shows the Cloud payable with nothing pending.
 *
 * Plus: a STALE projection fails closed at takeover (nothing recorded, no guessed quantity); the Cloud's canonical refusal
 * (official stock no longer covers the return) is terminal; a finance-incomplete posting rolls back atomically and the next
 * tick applies once.
 */
class EdgePurchaseReturnSyncHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeCloudBridgeFixture;
    use EdgePurchaseReturnFixture;

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
        $this->cleanTenant(array_merge(self::PR_TABLES, [
            'edge_branch_authority_leases', 'edge_inbound_sale_ingestions', 'edge_inbound_return_ingestions', 'edge_inbound_supplier_finance_ingestions', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'sales_orders', 'shifts', 'payment_methods', 'products', 'categories', 'units', 'model_has_permissions', 'permissions', 'terminals', 'branches', 'users',
        ]));
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch(['name' => 'Return Branch', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'PRS' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $unit = DB::connection('tenant')->table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = $this->makeProduct($this->makeCategory(), ['name' => 'Raw Item', 'unit_id' => $unit, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_purchasable' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 500]);
        $this->seedCloudPurchaseReturnTruth($this->branchId, $this->productId, $unit);
        foreach ([EdgeLocalPurchaseReturnService::PERM_STORE, EdgeLocalPurchaseReturnService::PERM_POST] as $p) {
            $this->grantEdgePermission($this->userId, $p);   // Cloud permissions are authoritative; mirrored below
        }
        $this->registerCloudTenantAndDevice($this->branchId, 1);

        // ── the appliance ──
        $this->mirrorConfigToAppliance(self::CONFIG_TABLES);
        $this->asEdge(function () {
            $this->cleanTenant(array_merge(self::PR_EDGE_TABLES, [
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
        config(['edge.testing.fail_after_official_purchase_return' => false]);
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

    private function returnLocally(float $qty, array $extra = []): array
    {
        return $this->asEdge(fn () => app(EdgeLocalPurchaseReturnService::class)->postReturn(array_merge([
            'cloud_grn_id' => $this->prGrnId, 'reason_code' => 'damaged', 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => $qty]],
        ], $extra), $this->user(), $this->terminalId));
    }

    private function localLine(): array
    {
        return $this->asEdge(fn () => app(EdgePurchaseReturnCacheService::class)->lines($this->prGrnId))[0];
    }

    private function localStock(): float
    {
        return (float) $this->asEdge(fn () => DB::connection('tenant')->table('edge_operational_stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand'));
    }

    private function cloudTruth(): array
    {
        return $this->asCloud(function () {
            $c = DB::connection('tenant');
            $ap = (int) $c->table('accounts')->where('code', '2100')->value('id');
            $inv = (int) $c->table('accounts')->where('code', '1400')->value('id');
            $journalIds = $c->table('journal_entries')->where('source_type', 'purchase_return')->where('status', 'posted')->pluck('id')->all();

            return [
                'returns_posted' => $c->table('purchase_returns')->where('status', 'posted')->count(),
                'return_lines' => $c->table('purchase_return_lines')->count(),
                'journals' => count($journalIds),
                'ap_debit' => round((float) $c->table('journal_lines')->whereIn('journal_entry_id', $journalIds ?: [0])->where('account_id', $ap)->sum('debit'), 2),
                'inventory_credit' => round((float) $c->table('journal_lines')->whereIn('journal_entry_id', $journalIds ?: [0])->where('account_id', $inv)->sum('credit'), 2),
                'subledger_credits' => $c->table('supplier_ledgers')->where('entry_type', 'purchase_return')->count(),
                'subledger_amount' => round((float) $c->table('supplier_ledgers')->where('entry_type', 'purchase_return')->sum('amount'), 2),
                'payable' => (float) $c->table('suppliers')->where('id', $this->prSupplierId)->value('current_balance'),
                'stock_out_qty' => round((float) $c->table('stock_ledgers')->where('movement_type', 'purchase_return')->where('direction', 'out')->sum('quantity'), 3),
                'on_hand' => round((float) $c->table('stock_balances')->where('branch_id', $this->branchId)->where('product_id', $this->productId)->sum('quantity_on_hand'), 3),
                'registry_applied' => $c->table('edge_inbound_purchase_return_ingestions')->where('status', 'applied')->count(),
                'linked_journal' => (int) ($c->table('purchase_returns')->where('status', 'posted')->value('journal_entry_id') ?? 0),
            ];
        });
    }

    private function outboxState(string $uuid): ?string
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $uuid)->value('state'));
    }

    private function allWanDown(): array
    {
        return ['authority/heartbeat' => 'down', 'sync/purchase-returns' => 'down', 'sync/supplier-finance' => 'down', 'sync/sales' => 'down', 'sync/returns' => 'down', 'sync/reconcile' => 'down',
            'purchase-returns/refresh' => 'down', 'supplier-finance/refresh' => 'down', 'returnable/refresh' => 'down', 'sync/baseline' => 'down', 'config/refresh' => 'down'];
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

    private function warmStandby(): void
    {
        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok'], json_encode($r['heartbeat']));
        $this->assertSame('refreshed:initial', $r['work']['stock']);
        $this->assertSame('refreshed:1-grns', $r['work']['purchase_return'], json_encode($r['work']));
        $this->assertSame('refreshed:1-suppliers', $r['work']['supplier_finance']);
        $meta = $this->edgeMeta();
        $this->assertSame($meta->standby_purchase_return_watermark_seen, $meta->purchase_return_cache_watermark, 'the projection equals what the Cloud advertised');
        $this->assertSame('current', $this->tick()['work']['purchase_return']);
        $this->assertTrue($this->asEdge(fn () => app(EdgePurchaseReturnCacheService::class)->freshness())['ok']);
        $this->assertSame(100.0, $this->localStock(), 'the local baseline mirrors the Cloud stock');
    }

    // ── tests ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_network_down_purchase_return_end_to_end_with_lost_ack_replay_conflict_handback_and_convergence(): void
    {
        // A. the standby pulled the purchase-return projection: 10 received, nothing returned.
        $this->warmStandby();
        $line = $this->localLine();
        $this->assertSame(['quantity_received' => 10.0, 'cloud_returned_quantity' => 0.0, 'returnable' => 10.0, 'unit_cost' => 300.0], array_intersect_key($line, array_flip(['quantity_received', 'cloud_returned_quantity', 'returnable', 'unit_cost'])));
        $cloudBefore = $this->cloudTruth();
        $this->assertSame(50000.0, $cloudBefore['payable']);

        // B. WAN fails; the takeover records PURCHASE_RETURN_CACHE_CURRENT=yes.
        $wm = $this->edgeMeta()->purchase_return_cache_watermark;
        $this->goLocal();
        $proof = json_decode((string) $this->edgeMeta()->authority_takeover_freshness, true);
        $this->assertSame($wm, $proof['purchase_return_cache_watermark']);
        $this->assertTrue($proof['purchase_return_cache_current']);

        // C. 7 of the 10 received units go back to the supplier, offline.
        $ev = $this->returnLocally(7, ['notes' => 'damaged in transit']);
        $this->assertSame('pending', $ev['sync']['state']);
        $this->assertSame(2100.0, $ev['grand_total']);
        $this->assertSame(93.0, $this->localStock(), 'LOCAL_STOCK_RETURN_TO_SUPPLIER once');
        $this->assertSame(['pending_local_quantity' => 7.0, 'returnable' => 3.0], array_intersect_key($this->localLine(), array_flip(['pending_local_quantity', 'returnable'])));
        $position = $this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->position($this->prSupplierId));
        $this->assertSame(['cloud_payable' => 50000.0, 'pending_delta' => -2100.0, 'available_payable' => 47900.0], array_intersect_key($position, array_flip(['cloud_payable', 'pending_delta', 'available_payable'])), 'the provisional supplier effect rides the F2 position');
        try {
            $this->returnLocally(4);
            $this->fail('never more than received');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('only 3.000 returnable', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame($cloudBefore, $this->cloudTruth(), 'the Cloud knows nothing yet');
        $codes = array_column($this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->assess())['blockers'], 'code');
        $this->assertContains('PURCHASE_RETURN_PENDING', $codes, json_encode($codes));

        // D. WAN back: the appliance REMAINS the writer; the Cloud posts the OFFICIAL return exactly once.
        $this->bridgeFailures = [];
        $t = $this->tick();
        $this->assertSame('connection_restored', $t['state']['state']);
        $this->assertSame(1, $t['work']['drained'], json_encode($t['work']) . ' registry=' . json_encode($this->asCloud(fn () => DB::connection('tenant')->table('edge_inbound_purchase_return_ingestions')->get(['status', 'failure_code', 'last_error'])->all())));
        $cloud = $this->cloudTruth();
        $this->assertSame(['returns_posted' => 1, 'return_lines' => 1, 'journals' => 1, 'ap_debit' => 2100.0, 'inventory_credit' => 2100.0, 'subledger_credits' => 1, 'subledger_amount' => 2100.0,
            'payable' => 47900.0, 'stock_out_qty' => 7.0, 'on_hand' => 93.0, 'registry_applied' => 1], array_intersect_key($cloud, array_flip(['returns_posted', 'return_lines', 'journals', 'ap_debit', 'inventory_credit', 'subledger_credits', 'subledger_amount', 'payable', 'stock_out_qty', 'on_hand', 'registry_applied'])), json_encode($cloud));
        $this->assertGreaterThan(0, $cloud['linked_journal'], 'purchase_returns.journal_entry_id links the production-fixed GL path');
        $this->asCloud(function () use ($ev) {
            $c = DB::connection('tenant');
            $return = $c->table('purchase_returns')->first();
            $this->assertSame((int) $this->prGrnId, (int) $return->goods_receipt_id);
            $this->assertSame('damaged', $return->reason_code);
            $line = $c->table('purchase_return_lines')->first();
            $this->assertSame('goods_receipt_line', $line->source_line_type);
            $this->assertSame((int) $this->prGrnLineId, (int) $line->source_line_id);
            $this->assertSame(300.0, (float) $line->unit_cost, 'valued at the receipt line cost');
            $registry = $c->table('edge_inbound_purchase_return_ingestions')->where('event_uuid', $ev['event_uuid'])->first();
            $this->assertSame((int) $return->id, (int) $registry->purchase_return_id);
            $this->assertSame($return->return_no, $registry->official_return_no);
        });
        $this->assertSame('acknowledged', $this->outboxState($ev['event_uuid']));
        $this->assertSame(93.0, $this->localStock(), 'the ACK re-applies nothing locally');
        $this->assertSame(3.0, $this->localLine()['returnable'], 'still subtracted until the projection that includes it arrives');
        $this->assertSame('synced', $this->asEdge(fn () => app(EdgePurchaseReturnCacheService::class)->event($ev['event_uuid']))['sync']['state']);

        // E. LOST ACK → recovered once; replay → already_applied; different hash → conflict.
        $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $ev['event_uuid'])->update(['state' => EdgeSyncOutbox::STATE_PENDING, 'acknowledged_at' => null, 'ack_ingestion_uuid' => null, 'ack_payload' => null]));
        $this->bridgeFailures = ['sync/purchase-returns' => 'down'];
        $t2 = $this->tick();
        $this->assertSame(1, (int) ($t2['work']['findings']['recovered_lost_ack'] ?? 0), json_encode($t2['work']));
        $this->assertTrue($t2['work']['clean']);
        $this->assertSame($cloud, $this->cloudTruth(), 'ONE document, ONE stock movement set, ONE subledger credit, ONE AP effect, ONE GL entry');
        $this->assertSame(93.0, $this->localStock());
        $this->bridgeFailures = [];
        $envelope = json_decode((string) $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $ev['event_uuid'])->value('envelope')), true);
        $headers = ['X-Edge-Device-ID' => $this->cloudDeviceUuid, 'Authorization' => 'Bearer ' . $this->cloudDeviceSecret];
        $uri = 'http://' . config('tenancy.central_domain') . '/api/edge/sync/purchase-returns';
        $this->asCloud(fn () => $this->postJson($uri, ['envelope' => $envelope], $headers)->assertOk()->assertJsonPath('status', 'already_applied'));
        $tampered = $envelope;
        $tampered['lines'][0]['quantity'] = 9;
        unset($tampered['content_hash']);
        $tampered['content_hash'] = hash('sha256', app(\App\Services\Edge\EdgeBootstrapService::class)->canonicalJson($tampered));
        $this->asCloud(fn () => $this->postJson($uri, ['envelope' => $tampered], $headers)->assertStatus(409)->assertJsonPath('failure_code', 'ENVELOPE_CONFLICT'));
        $this->assertSame($cloud, $this->cloudTruth(), 'replays mutate nothing');

        // F. Controlled handback → refresh → convergence.
        $this->tick();
        $codes = array_column($this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->assess())['blockers'], 'code');
        $this->assertSame([], $codes, json_encode($codes));
        $hb = $this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->run('supervisor'));
        $this->assertSame(EdgeHandbackOrchestrator::HANDED_BACK, $hb['status'], json_encode($hb));
        $this->assertStringStartsWith('refreshed', $hb['freshness']['purchase_return']);
        $this->assertStringStartsWith('refreshed', $hb['freshness']['supplier_finance']);
        $line = $this->localLine();
        $this->assertSame(['cloud_returned_quantity' => 7.0, 'pending_local_quantity' => 0.0, 'returnable' => 3.0], array_intersect_key($line, array_flip(['cloud_returned_quantity', 'pending_local_quantity', 'returnable'])), 'returnable = received − Cloud returned, counted once');
        $this->assertSame('official', $this->asEdge(fn () => app(EdgePurchaseReturnCacheService::class)->event($ev['event_uuid']))['sync']['state'], 'ONE official business transaction');
        $position = $this->asEdge(fn () => app(EdgeSupplierFinanceCacheService::class)->position($this->prSupplierId));
        $this->assertSame(['cloud_payable' => 47900.0, 'pending_delta' => 0.0, 'available_payable' => 47900.0, 'pending_events' => 0], array_intersect_key($position, array_flip(['cloud_payable', 'pending_delta', 'available_payable', 'pending_events'])), 'the F2 supplier position converged (the applied set lists the purchase return)');
    }

    public function test_a_stale_projection_fails_closed_at_takeover(): void
    {
        $this->warmStandby();
        // The Cloud posts an Online return of 2 while the appliance cannot pull the projection.
        $this->asCloud(function () {
            $svc = app(PurchaseReturnService::class);
            $draft = $svc->createDraft(['branch_id' => $this->branchId, 'supplier_id' => $this->prSupplierId, 'goods_receipt_id' => $this->prGrnId, 'return_date' => now()->toDateString(), 'reason_code' => 'expired'],
                [['product_id' => $this->productId, 'source_line_id' => $this->prGrnLineId, 'quantity' => 2, 'unit_cost' => 300]], $this->userId);
            $svc->post($draft, $this->userId);
        });
        $this->bridgeFailures = ['purchase-returns/refresh' => 'down'];
        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok']);
        $this->assertStringStartsWith('error:', $r['work']['purchase_return'], json_encode($r['work']));
        $this->assertFalse($this->asEdge(fn () => app(EdgePurchaseReturnCacheService::class)->freshness())['ok']);
        $this->goLocal();
        $this->assertFalse(json_decode((string) $this->edgeMeta()->authority_takeover_freshness, true)['purchase_return_cache_current']);
        try {
            $this->returnLocally(1);
            $this->fail('a stale projection must refuse');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not current on this branch server', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame(0, $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->count()));
        $this->assertSame(10.0, $this->localLine()['returnable'], 'the appliance shows what it HOLDS (flagged stale) — never the Cloud\'s 8');
        $this->assertSame(98.0, $this->localStock(), 'the STOCK projection (a separate refresh, not blocked here) followed the Cloud — the purchase-return projection did not, and that is what fails closed');
    }

    public function test_the_cloud_refusal_is_terminal_and_a_finance_incomplete_posting_rolls_back_atomically(): void
    {
        $this->warmStandby();
        // After the last heartbeat, the Cloud's official stock dropped to 5 (sold / transferred); the receipt projection is unchanged.
        $this->asCloud(fn () => DB::connection('tenant')->table('stock_balances')->where('product_id', $this->productId)->update(['quantity_on_hand' => 5]));
        $this->goLocal();
        $a = $this->returnLocally(7);   // the Cloud can never post it: official stock 5 < 7
        $b = $this->returnLocally(2);   // fine — but the finance-incomplete seam hits it first
        $this->assertSame(91.0, $this->localStock());

        config(['edge.testing.fail_after_official_purchase_return' => true]);
        $this->bridgeFailures = [];
        $t = $this->tick();
        $this->assertSame('failed_permanent', $this->outboxState($a['event_uuid']), json_encode($t['work']));
        $this->assertSame('pending', $this->outboxState($b['event_uuid']), 'finance-incomplete → not APPLIED, retryable');
        $truth = $this->cloudTruth();
        $this->assertSame(0, $truth['returns_posted'], 'nothing was saved for either event');
        $this->assertSame(5.0, $truth['on_hand']);
        $this->assertSame(50000.0, $truth['payable']);
        $this->asCloud(function () use ($a, $b) {
            $rows = DB::connection('tenant')->table('edge_inbound_purchase_return_ingestions')->get()->keyBy('event_uuid');
            $this->assertSame('PURCHASE_RETURN_REFUSED', $rows[$a['event_uuid']]->failure_code);
            $this->assertSame('refused', $rows[$a['event_uuid']]->status);
            $this->assertStringContainsString('Insufficient branch stock', (string) $rows[$a['event_uuid']]->last_error);
            $this->assertSame('INGEST_FAILED', $rows[$b['event_uuid']]->failure_code);
        });
        $this->assertSame('failed', $this->asEdge(fn () => app(EdgePurchaseReturnCacheService::class)->event($a['event_uuid']))['sync']['state']);

        config(['edge.testing.fail_after_official_purchase_return' => false]);
        $t2 = $this->tick();
        $this->assertSame('acknowledged', $this->outboxState($b['event_uuid']), json_encode($t2['work']));
        $truth = $this->cloudTruth();
        $this->assertEquals(['returns_posted' => 1, 'stock_out_qty' => 2.0, 'on_hand' => 3.0, 'payable' => 49400.0, 'registry_applied' => 1], array_intersect_key($truth, array_flip(['returns_posted', 'stock_out_qty', 'on_hand', 'payable', 'registry_applied'])), json_encode($truth));
        $this->tick();
        $codes = array_column($this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->assess())['blockers'], 'code');
        $this->assertContains('PURCHASE_RETURN_PERMANENT_FAILURE', $codes, json_encode($codes));
        $this->assertContains('PERMANENT_SYNC_FAILURE', $codes);
    }
}
