<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeHandbackOrchestrator;
use App\Services\Edge\EdgeInboundReturnIngestionService;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeLocalReturnService;
use App\Services\Edge\EdgeReturnEnvelopeBuilder;
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
 * F1 — the NETWORK-DOWN sales-return proof, real code on both ends, TWO databases (see EdgeCloudBridgeFixture):
 *
 *   A. Internet healthy: an ONLINE sale exists; the standby mirrors it (returnable cache current).
 *   B–C. WAN dies; supervised Local Mode through the P/Q authority (RETURN_CACHE_WATERMARK recorded at takeover).
 *   D–F. The cashier finds the previously-online sale locally and returns part of it: local operational stock once,
 *        the till once, the immutable event pending, returnable quantity down at once.
 *   G–I. WAN back: the appliance remains the writer; the event syncs; the Cloud posts the OFFICIAL return exactly once
 *        (document, FEFO stock in, COGS reversal journal, cash/bank refund, sales ledger, order status); ACK.
 *   LOST ACK: Cloud applied, ACK lost → reconciliation recovers: ONE of everything, locally and on the Cloud.
 *   Replay same hash → already_applied; same uuid different hash → conflict; finance-complete-or-refuse; a return of
 *   an Edge sale the Cloud has not ingested yet is retryable and applies once the sale is in.
 *   J–K. Reconcile, controlled handback only when clean; the standby's cache then equals the Cloud (no double count).
 */
class EdgeReturnSyncHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeCloudBridgeFixture;

    private const CONFIG_TABLES = ['branches', 'users', 'categories', 'units', 'products', 'terminals', 'payment_methods'];

    private int $branchId;
    private int $userId;
    private int $terminalId;
    private int $burgerId;
    private int $muttonId;
    private int $cashMethodId;
    private int $cloudSaleId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->provisionTwoDatabases();

        // ── the Cloud's truth ──
        $this->cleanTenant([
            'edge_branch_authority_leases', 'edge_inbound_return_ingestions', 'edge_inbound_sale_ingestions', 'sales_return_lines', 'sales_returns', 'sales_ledgers',
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'accounts', 'cash_bank_accounts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'payment_methods', 'products', 'categories', 'units', 'terminals', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch(['name' => 'Return Branch', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RS' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $conn = DB::connection('tenant');
        $pc = $conn->table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $kg = $conn->table('units')->insertGetId(['code' => 'kg', 'name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $this->makeCategory();
        $this->burgerId = $this->makeProduct($cat, ['name' => 'Burger', 'unit_id' => $pc, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->muttonId = $this->makeProduct($cat, ['name' => 'Mutton', 'unit_id' => $kg, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 200]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $accountId = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $accountId, 'current_balance' => 0, 'is_active' => 1, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('payment_methods')->where('id', $this->cashMethodId)->update(['cash_bank_account_id' => $cbId]);
        foreach ([$this->burgerId => 100, $this->muttonId => 50] as $pid => $qty) {
            $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$pid}", 'branch_id' => $this->branchId, 'product_id' => $pid, 'batch_no' => 'B' . $pid, 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$pid}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $pid, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => $qty, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->cloudSaleId = $this->insertCloudSale();
        $this->registerCloudTenantAndDevice($this->branchId, 1);

        // ── the appliance ──
        $this->mirrorConfigToAppliance(self::CONFIG_TABLES);
        $this->asEdge(function () {
            $this->cleanTenant([
                'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_local_connection_transitions', 'edge_baseline_cutovers', 'edge_sync_outbox',
                'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta',
                'sales_return_lines', 'sales_returns', 'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'manager_approvals',
            ]);
            $this->bindEdgeLocalMeta($this->branchId, 1, $this->cloudTenantId, $this->cloudDeviceUuid, 1);
            DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'tenant_code' => $this->bridgeTenantCode]);
            $this->seedEdgeCredential($this->userId, $this->branchId, 1);
            $this->grantEdgePermission($this->userId, 'tenant.sales-returns.store');
        });
        $this->configureApplianceCloudUrls();
        config(['edge.authority.ttl_seconds' => 60, 'edge.authority.skew_margin_seconds' => 10, 'edge.authority.unstable_after_failures' => 2, 'edge.authority.lost_after_failures' => 4, 'edge.authority.handback_min_consecutive_acks' => 2]);
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

    /** An ONLINE sale as the Cloud tables hold it: 3 Burger @100 + 2 kg Mutton @200, paid cash 700. */
    private function insertCloudSale(): int
    {
        $conn = DB::connection('tenant');
        $saleId = $conn->table('sales_orders')->insertGetId([
            'sale_no' => 'SO-ONLINE-' . Str::random(6), 'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId, 'order_source' => 'pos', 'order_type' => 'takeaway',
            'sale_date' => now()->subHours(2), 'business_date' => now()->toDateString(), 'subtotal' => 700, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
            'tax_amount' => 0, 'service_charge_amount' => 0, 'delivery_charge_amount' => 0, 'tip_amount' => 0, 'grand_total' => 700, 'paid_amount' => 700, 'change_amount' => 0,
            'status' => 'paid', 'inventory_posted' => 1, 'completed_at' => now()->subHours(2), 'created_by_user_id' => $this->userId, 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);
        foreach ([[$this->burgerId, 'Burger', 3, 100, 300, 'pc'], [$this->muttonId, 'Mutton', 2, 200, 400, 'kg']] as [$pid, $name, $qty, $price, $total, $unit]) {
            $conn->table('sales_order_lines')->insert([
                'sales_order_id' => $saleId, 'line_kind' => 'standard', 'product_id' => $pid, 'product_name' => $name, 'unit_code' => $unit, 'quantity' => $qty, 'returned_quantity' => 0,
                'unit_price' => $price, 'unit_cost' => 40, 'cost_total' => 40 * $qty, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => $total, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $payment = new \App\Models\Tenant\SalePayment(['sales_order_id' => $saleId, 'payment_method_id' => $this->cashMethodId, 'amount' => 700, 'tendered_amount' => 700, 'change_amount' => 0]);
        $payment->payment_uuid = (string) Str::ulid();
        $payment->save();

        return (int) $saleId;
    }

    private function tick(): array
    {
        return $this->asEdge(fn () => app()->make(EdgeAuthorityTick::class)->run('test-worker'));
    }

    private function edgeMeta(): object
    {
        return $this->asEdge(fn () => DB::connection('tenant')->table('edge_local_meta')->first());
    }

    private function cloudCounts(): array
    {
        return $this->asCloud(fn () => [
            'returns' => DB::connection('tenant')->table('sales_returns')->count(),
            'return_lines' => DB::connection('tenant')->table('sales_return_lines')->count(),
            'stock_in' => DB::connection('tenant')->table('stock_ledgers')->where('movement_type', 'sale_return')->where('direction', 'in')->count(),
            'journals' => DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_return')->count(),
            'cashbank_out' => DB::connection('tenant')->table('cash_bank_account_transactions')->where('transaction_type', 'sales_return_refund')->count(),
            'ledger' => DB::connection('tenant')->table('sales_ledgers')->where('entry_type', 'sale_return')->count(),
            'registry_applied' => DB::connection('tenant')->table('edge_inbound_return_ingestions')->where('status', 'applied')->count(),
            'burger_on_hand' => (float) DB::connection('tenant')->table('stock_balances')->where('branch_id', $this->branchId)->where('product_id', $this->burgerId)->sum('quantity_on_hand'),
            'burger_returned_qty' => (float) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $this->cloudSaleId)->where('product_id', $this->burgerId)->value('returned_quantity'),
            'order_status' => (string) DB::connection('tenant')->table('sales_orders')->where('id', $this->cloudSaleId)->value('status'),
            'till_balance' => (float) DB::connection('tenant')->table('cash_bank_accounts')->where('code', 'TILL')->value('current_balance'),
        ]);
    }

    private function localCounts(): array
    {
        return $this->asEdge(function () {
            $b = DB::connection('tenant')->table('edge_operational_stock_baselines')->where('status', 'accepted')->first();

            return [
                'op_return_movements' => DB::connection('tenant')->table('edge_operational_stock_movements')->where('movement_type', 'sale_return')->count(),
                'burger_on_hand' => $b ? (float) DB::connection('tenant')->table('edge_operational_stock_balances')->where('baseline_id', $b->id)->where('product_id', $this->burgerId)->sum('quantity_on_hand') : -1,
                'expected_cash' => (float) DB::connection('tenant')->table('shifts')->where('status', 'open')->value('expected_cash'),
                'cash_refunds' => (float) DB::connection('tenant')->table('shifts')->where('status', 'open')->value('total_cash_refunds'),
                'outbox_state' => (string) DB::connection('tenant')->table('edge_sync_outbox')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->value('state'),
                'local_returns' => DB::connection('tenant')->table('sales_returns')->where('edge_origin', 'local')->count(),
            ];
        });
    }

    private function goLocal(): void
    {
        $this->bridgeFailures = ['authority/heartbeat' => 'down', 'sync/returns' => 'down', 'sync/sales' => 'down', 'sync/reconcile' => 'down', 'returnable/refresh' => 'down', 'sync/baseline' => 'down'];
        for ($i = 0; $i < 4; $i++) {
            $this->tick();
        }
        Carbon::setTestNow(now()->addSeconds(71));
        $this->assertSame('preparing_local', $this->tick()['state']['state']);
        $this->asEdge(fn () => app(EdgeAuthorityService::class)->takeOver(true, 'supervisor'));
        $this->assertSame('local_active', $this->asEdge(fn () => app(EdgeAuthorityService::class)->state()));
    }

    public function test_an_online_sale_is_returned_offline_and_the_cloud_posts_the_official_return_exactly_once_even_with_a_lost_ack(): void
    {
        // A. Internet healthy: the standby pulls the initial baseline AND the returnable projection of the Online sale.
        $r = $this->tick();
        $this->assertTrue($r['heartbeat']['ok'], json_encode($r['heartbeat']));
        $this->assertSame('refreshed:initial', $r['work']['stock']);
        $this->assertSame('refreshed:1-sales', $r['work']['returnable'], json_encode($r['work']));
        $shadowId = (int) $this->asEdge(fn () => DB::connection('tenant')->table('edge_returnable_sales')->where('cloud_sales_order_id', $this->cloudSaleId)->value('local_sales_order_id'));
        $this->assertGreaterThan(0, $shadowId);
        $meta = $this->edgeMeta();
        $this->assertSame($meta->standby_returnable_watermark_seen, $meta->returnable_cache_watermark, 'the cache equals what the Cloud advertised');
        $this->assertSame('current', $this->tick()['work']['returnable']);

        // B–C. WAN dies; supervised Local Mode. RETURN_CACHE_WATERMARK is part of the recorded takeover freshness.
        $this->goLocal();
        $proof = json_decode((string) $this->edgeMeta()->authority_takeover_freshness, true);
        $this->assertSame($meta->returnable_cache_watermark, $proof['return_cache_watermark']);
        $this->assertTrue($proof['return_cache_fresh']);

        // D–F. The cashier finds the ONLINE sale locally and returns 1 Burger for cash out of the open till.
        $this->asEdge(fn () => app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 1000.0));
        $user = $this->asEdge(fn () => User::on('tenant')->find($this->userId));
        $found = $this->asEdge(fn () => app(EdgeLocalReturnService::class)->search('SO-ONLINE'));
        $this->assertSame('online', $found[0]['origin']);
        $view = $this->asEdge(fn () => app(EdgeLocalReturnService::class)->returnable($shadowId, $user));
        $this->assertTrue($view['fresh']);
        $burgerLine = collect($view['lines'])->firstWhere('product_id', $this->burgerId);
        $this->assertSame(3.0, $burgerLine['returnable']);
        $before = $this->localCounts();
        $ret = $this->asEdge(fn () => app(EdgeLocalReturnService::class)->processReturn($shadowId, [['sales_order_line_id' => $burgerLine['sales_order_line_id'], 'quantity' => 1]], 'wrong item', 'cash', 100.0, $user, $this->terminalId));
        $after = $this->localCounts();
        $this->assertSame(100.0, $ret['totals']['grand_total']);
        $this->assertSame($before['burger_on_hand'] + 1, $after['burger_on_hand'], 'local operational stock changes exactly once');
        $this->assertSame($before['expected_cash'] - 100.0, $after['expected_cash'], 'the till moves exactly once');
        $this->assertSame('pending', $after['outbox_state']);
        $this->assertSame(2.0, collect($this->asEdge(fn () => app(EdgeLocalReturnService::class)->returnable($shadowId, $user))['lines'])->firstWhere('product_id', $this->burgerId)['returnable'], 'returnable qty decreases at once');
        $this->assertSame(0, $this->cloudCounts()['returns'], 'the Cloud knows nothing yet');

        // G–I. WAN back: the appliance REMAINS the writer; the event syncs; the Cloud posts the OFFICIAL return once.
        $this->bridgeFailures = [];
        $t = $this->tick();
        $this->assertSame('connection_restored', $t['state']['state']);
        $this->assertSame(1, $t['work']['drained'], json_encode($t['work']));
        $this->assertTrue($this->asEdge(function () { try { app(EdgeAuthorityService::class)->assertLocalMutationAllowed(); return true; } catch (\Throwable) { return false; } }), 'still the writer');
        $cloud = $this->cloudCounts();
        $this->assertSame(['returns' => 1, 'return_lines' => 1, 'stock_in' => 1, 'journals' => 1, 'cashbank_out' => 1, 'ledger' => 1, 'registry_applied' => 1], array_intersect_key($cloud, array_flip(['returns', 'return_lines', 'stock_in', 'journals', 'cashbank_out', 'ledger', 'registry_applied'])), json_encode($cloud));
        $this->assertSame(101.0, $cloud['burger_on_hand'], 'official FEFO stock reversal');
        $this->assertSame(1.0, $cloud['burger_returned_qty']);
        $this->assertSame('partially_returned', $cloud['order_status']);
        $this->assertSame(-100.0, $cloud['till_balance'], 'the cash/bank refund left the books once');
        $journal = $this->asCloud(fn () => DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_return')->first());
        $this->assertSame((float) $journal->total_debit, (float) $journal->total_credit, 'balanced GL (revenue reversal + inventory restock / COGS reversal / refund)');
        $local = $this->localCounts();
        $this->assertSame('acknowledged', $local['outbox_state']);
        $this->assertSame($after['expected_cash'], $local['expected_cash'], 'the ACK never applies the till refund again');
        $this->assertSame($after['burger_on_hand'], $local['burger_on_hand'], 'the ACK never applies the local stock return again');
        $this->assertSame(1, $local['op_return_movements']);

        // LOST ACK: the appliance never saw the ACK (row back to pending) while the return wire is flaky.
        $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->update(['state' => EdgeSyncOutbox::STATE_PENDING, 'acknowledged_at' => null, 'ack_ingestion_uuid' => null, 'ack_payload' => null, 'lease_owner' => null, 'lease_expires_at' => null]));
        $this->bridgeFailures = ['sync/returns' => 'down'];
        $t2 = $this->tick();
        $this->assertSame(1, (int) ($t2['work']['findings']['recovered_lost_ack'] ?? 0), 'reconciliation recovered the lost ACK: ' . json_encode($t2['work']));
        $this->assertTrue($t2['work']['clean']);
        $cloud2 = $this->cloudCounts();
        $this->assertSame($cloud, $cloud2, 'ONE Cloud return, ONE stock reversal, ONE journal, ONE cash/bank refund, ONE ledger entry');
        $local2 = $this->localCounts();
        $this->assertSame('acknowledged', $local2['outbox_state']);
        $this->assertSame(1, $local2['op_return_movements'], 'ONE local operational return');
        $this->assertSame($after['expected_cash'], $local2['expected_cash'], 'ONE local till refund');
        $this->bridgeFailures = [];

        // Replay the SAME immutable event → already_applied; the SAME uuid with DIFFERENT content → conflict, no mutation.
        $envelope = json_decode((string) $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->value('envelope')), true);
        $headers = ['X-Edge-Device-ID' => $this->cloudDeviceUuid, 'Authorization' => 'Bearer ' . $this->cloudDeviceSecret];
        $uri = 'http://' . config('tenancy.central_domain') . '/api/edge/sync/returns';
        $this->asCloud(fn () => $this->postJson($uri, ['envelope' => $envelope], $headers)->assertOk()->assertJsonPath('status', 'already_applied'));
        $tampered = $envelope;
        $tampered['lines'][0]['quantity'] = 3;
        $tampered['totals']['grand_total'] = 300;
        unset($tampered['content_hash']);
        $tampered['content_hash'] = hash('sha256', app(\App\Services\Edge\EdgeBootstrapService::class)->canonicalJson($tampered));
        $this->asCloud(fn () => $this->postJson($uri, ['envelope' => $tampered], $headers)->assertStatus(409)->assertJsonPath('failure_code', 'ENVELOPE_CONFLICT'));
        $this->assertSame($cloud, $this->cloudCounts(), 'a conflicting replay mutates nothing');

        // J–K. Handback only when clean: the open shift blocks (explicit), close it, hand back; the cache then equals the Cloud.
        $this->tick();
        $codes = array_column($this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->assess())['blockers'], 'code');
        $this->assertSame(['OPEN_SHIFTS'], $codes, json_encode($codes));
        $this->asEdge(fn () => DB::connection('tenant')->table('shifts')->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->userId]));
        $hb = $this->asEdge(fn () => app(EdgeHandbackOrchestrator::class)->run('supervisor'));
        $this->assertSame(EdgeHandbackOrchestrator::HANDED_BACK, $hb['status'], json_encode($hb));
        $this->assertStringStartsWith('refreshed', $hb['freshness']['returnable'], 'the standby re-pulled the returnable position after the Cloud posted the return');
        $returnedOnShadow = (float) $this->asEdge(fn () => DB::connection('tenant')->table('sales_order_lines')->where('id', $burgerLine['sales_order_line_id'])->value('returned_quantity'));
        $this->assertSame(1.0, $returnedOnShadow, 'Cloud returned 1 (the acknowledged local return) — counted once, never twice');
    }

    public function test_finance_incomplete_is_refused_atomically_and_a_return_before_its_sale_is_retryable(): void
    {
        $this->tick();
        $this->goLocal();
        $user = $this->asEdge(fn () => User::on('tenant')->find($this->userId));
        $this->asEdge(fn () => app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 500.0));

        // A LOCAL sale and its return while offline: two events, sale first.
        $sale = $this->asEdge(function () use ($user) {
            Auth::guard('tenant')->setUser($user);
            Auth::shouldUse('tenant');

            return app(EdgeLocalPosService::class)->completePaidSale(['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $this->burgerId, 'quantity' => 2]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]]], $user, $this->terminalId);
        });
        $lineId = (int) $this->asEdge(fn () => DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale->id)->value('id'));
        $this->asEdge(fn () => app(EdgeLocalReturnService::class)->processReturn((int) $sale->id, [['sales_order_line_id' => $lineId, 'quantity' => 1]], null, 'cash', 100.0, $user, $this->terminalId));
        $envelopes = $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->orderBy('id')->get(['envelope_schema_version', 'envelope', 'sale_uuid']));
        $this->assertSame(2, $envelopes->count());
        $returnEnvelope = json_decode((string) $envelopes->last()->envelope, true);
        $this->assertSame('edge', $returnEnvelope['original']['kind']);

        $headers = ['X-Edge-Device-ID' => $this->cloudDeviceUuid, 'Authorization' => 'Bearer ' . $this->cloudDeviceSecret];
        $uri = 'http://' . config('tenancy.central_domain') . '/api/edge/sync/returns';
        // The return arrives BEFORE its sale: retryable, nothing applied.
        $this->asCloud(fn () => $this->postJson($uri, ['envelope' => $returnEnvelope], $headers)->assertStatus(500)->assertJsonPath('failure_code', 'ORIGINAL_SALE_NOT_INGESTED'));
        $this->assertSame(0, $this->cloudCounts()['returns']);

        // FINANCE-COMPLETE OR REFUSE: with the official effects broken AFTER the return posted, the Cloud answers exception
        // and rolls everything back — the sale (its own ingestion) goes in, the return does not.
        config(['edge.testing.fail_after_official_return' => true]);
        $this->bridgeFailures = [];
        $t = $this->tick(); // WAN back: the worker drains the SALE first, then the return hits the broken path (exception → retry)
        $this->assertSame(1, $this->asCloud(fn () => DB::connection('tenant')->table('edge_inbound_sale_ingestions')->where('status', 'applied')->count()), 'the sale is in: ' . json_encode($t['work']));
        $res = $this->asCloud(fn () => $this->postJson($uri, ['envelope' => $returnEnvelope], $headers));
        $this->assertSame(500, $res->getStatusCode(), $res->getContent());
        $this->assertSame('exception', $res->json('status'));
        $c = $this->cloudCounts();
        $this->assertSame(0, $c['returns'] + $c['stock_in'] + $c['journals'] + $c['cashbank_out'] + $c['ledger'], 'nothing half-posted: ' . json_encode($c));
        $this->assertSame('exception', $this->asCloud(fn () => DB::connection('tenant')->table('edge_inbound_return_ingestions')->value('status')));
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->value('state')), 'the event stays pending for a retry');
        // Healthy again: the next tick delivers the return exactly once.
        config(['edge.testing.fail_after_official_return' => false]);
        $t = $this->tick();
        $c = $this->cloudCounts();
        $this->assertSame(1, $c['returns'], json_encode($t['work']));
        $this->assertSame(1, $c['registry_applied']);
        $this->assertSame('acknowledged', $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->value('state')));
    }
}
