<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\Continuity\ContinuityAdoptionService;
use App\Services\Edge\Continuity\LiveStateMirror;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeSaleEnvelopeBuilder;
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
 * EDGE 0.9 CONTINUITY (stream A) — MIRROR → ADOPT → MERGE end to end, real code on both ends, two databases (EdgeCloudBridgeFixture).
 *
 * The owner's day: counter T1 has been selling ONLINE (shift open, 1 200 in the drawer); table T12 has an open order (Biryani ×2
 * already in the kitchen, Raita ×1 not yet sent). The WAN breaks → supervised Switch to Offline → the counter's online shift is
 * ALREADY open on the Branch Server (opening cash 1 200), the table's order is there; the cashier sells a takeaway, adds a round
 * to the table (only the new food and the unsent Raita go to the kitchen) and takes payment. WAN back → the outbox drains:
 *
 *   ONE shift per counter   the Cloud shift's counters carry the Edge sales (no second Cloud shift, no shift-less Edge sale);
 *   ONE order               the table's EXISTING Cloud order is completed (same id / number / kitchen links), lines appended;
 *   NO duplicates           one payment per sale, cash once, official stock once, no Cloud KOT batch / print job created,
 *                           an exact replay is already_applied with zero effects;
 *   CONFLICT refused        an order changed on the Cloud after the mirror revision is refused (classified), never guessed —
 *                           nothing of it is written, the continuation sale beside it still merges.
 *
 * The Cloud live-state endpoint (K1) is stream C's: here the package is built from the Cloud rows exactly as K1 specifies.
 */
class EdgeContinuityMergeHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeCloudBridgeFixture;

    private const CONFIG_TABLES = ['branches', 'users', 'categories', 'units', 'products', 'terminals', 'payment_methods', 'restaurant_floors', 'restaurant_tables', 'restaurant_waiters'];

    private int $branchId;
    private int $userId;
    private int $t1;
    private int $biryani;
    private int $raita;
    private int $cashMethodId;
    private int $tableId;
    private int $cloudShiftId;
    private int $cloudOrderId;
    private string $cloudOrderUuid;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->provisionTwoDatabases();

        // ── the Cloud's truth ──
        $this->cleanTenant([
            'edge_branch_authority_leases', 'edge_inbound_sale_ingestions', 'kot_batch_lines', 'kot_batches', 'print_jobs',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'accounts', 'cash_bank_accounts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'shifts', 'payment_methods', 'products', 'categories', 'units', 'terminals', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch(['name' => 'Continuity Branch', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CT' . Str::random(4)]);
        $this->t1 = $this->makeTerminal($this->branchId, ['code' => 'T1', 'name' => '1-Counter']);
        $conn = DB::connection('tenant');
        $pc = $conn->table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $this->makeCategory();
        $p = ['unit_id' => $pc, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active'];
        $this->biryani = $this->makeProduct($cat, $p + ['name' => 'Biryani', 'default_selling_price' => 150]);
        $this->raita = $this->makeProduct($cat, $p + ['name' => 'Raita', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $accountId = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $accountId, 'current_balance' => 0, 'is_active' => 1, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('payment_methods')->where('id', $this->cashMethodId)->update(['cash_bank_account_id' => $cbId]);
        foreach ([$this->biryani, $this->raita] as $pid) {
            $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$pid}", 'branch_id' => $this->branchId, 'product_id' => $pid, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$pid}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $pid, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 100, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T12', 'status' => 'occupied']);

        // The ONLINE morning: T1's shift is open (1 000 opening + 200 cash sold online = 1 200 expected) …
        $shift = app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->t1), $this->userId, 1000.0);
        $conn->table('shifts')->where('id', $shift->id)->update(['total_sales' => 200, 'total_cash' => 200, 'expected_cash' => 1200]);
        $this->cloudShiftId = (int) $shift->id;
        // … and table T12 carries an open order: Biryani ×2 already in the kitchen (KOT #1), Raita ×1 not yet sent.
        $sessionUuid = (string) Str::ulid();
        $sessionId = $conn->table('restaurant_table_sessions')->insertGetId(['session_uuid' => $sessionUuid, 'session_no' => 'TS-' . $sessionUuid, 'branch_id' => $this->branchId,
            'restaurant_table_id' => $this->tableId, 'opened_by_user_id' => $this->userId, 'opened_shift_id' => $shift->id, 'business_date' => $shift->business_date, 'guest_count' => 3,
            'status' => 'open', 'opened_at' => now()->subMinutes(40), 'created_at' => now(), 'updated_at' => now()]);
        $this->cloudOrderUuid = (string) Str::ulid();
        $this->cloudOrderId = $this->makeSale($this->branchId, ['sale_no' => 'SO-ONLINE-1', 'sale_uuid' => $this->cloudOrderUuid, 'client_uuid' => (string) Str::uuid(), 'terminal_id' => $this->t1,
            'shift_id' => $shift->id, 'order_type' => 'dine_in', 'status' => 'held', 'subtotal' => 350, 'grand_total' => 350, 'inventory_posted' => 0, 'created_by_user_id' => $this->userId,
            'restaurant_table_session_id' => $sessionId, 'restaurant_table_id' => $this->tableId, 'business_date' => $shift->business_date]);
        $biryaniLine = $this->makeSaleLine($this->cloudOrderId, $this->biryani, ['line_uuid' => (string) Str::ulid(), 'product_name' => 'Biryani', 'quantity' => 2, 'unit_price' => 150, 'line_total' => 300, 'kot_sent' => 1, 'kot_sent_quantity' => 2]);
        $this->makeSaleLine($this->cloudOrderId, $this->raita, ['line_uuid' => (string) Str::ulid(), 'product_name' => 'Raita', 'quantity' => 1, 'unit_price' => 50, 'line_total' => 50, 'kot_sent' => 0, 'kot_sent_quantity' => 0]);
        $batch = $conn->table('kot_batches')->insertGetId(['event_uuid' => (string) Str::uuid(), 'sales_order_id' => $this->cloudOrderId, 'sequence_no' => 1, 'event_type' => 'normal', 'copy_no' => 1, 'created_by_user_id' => $this->userId, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('kot_batch_lines')->insert(['kot_batch_id' => $batch, 'sales_order_line_id' => $biryaniLine, 'product_id' => $this->biryani, 'product_name' => 'Biryani', 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->registerCloudTenantAndDevice($this->branchId, 1);

        // ── the appliance: mirrored config, bound, credentialed (no Cloud sales / shifts — the bootstrap never ships them) ──
        $this->mirrorConfigToAppliance(self::CONFIG_TABLES);
        $this->asEdge(function () {
            $this->cleanTenant([
                'edge_mirror_adoptions', 'edge_mirror_order_lines', 'edge_mirror_held_sales', 'edge_mirror_table_orders', 'edge_mirror_table_sessions', 'edge_mirror_shifts', 'edge_mirror_meta',
                'edge_local_print_deliveries', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_local_connection_transitions',
                'edge_baseline_cutovers', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
                'edge_local_user_credentials', 'edge_local_meta', 'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'restaurant_table_sessions', 'shifts', 'manager_approvals',
            ]);
            DB::connection('tenant')->table('restaurant_tables')->update(['status' => 'available']);   // the appliance's own view of the floor
            $this->bindEdgeLocalMeta($this->branchId, 1, $this->cloudTenantId, $this->cloudDeviceUuid, 1);
            DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'tenant_code' => $this->bridgeTenantCode]);
            $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        });
        $this->configureApplianceCloudUrls();
        config(['edge.authority.ttl_seconds' => 60, 'edge.authority.skew_margin_seconds' => 10, 'edge.authority.unstable_after_failures' => 2, 'edge.authority.lost_after_failures' => 4,
            'edge.authority.handback_min_consecutive_acks' => 1, 'edge.continuity.max_mirror_age_seconds' => 120]);
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

    /** K1 exactly as specified, built from the Cloud rows (what stream C's LiveStateService serves). */
    private function cloudLiveState(): array
    {
        return $this->asCloud(function () {
            $c = DB::connection('tenant');
            $lines = fn (int $orderId) => $c->table('sales_order_lines')->where('sales_order_id', $orderId)->orderBy('id')->get()->map(fn ($l) => [
                'line_uuid' => $l->line_uuid, 'product_id' => (int) $l->product_id, 'variant_id' => $l->product_variant_id, 'qty' => (string) $l->quantity,
                'unit_price' => (string) $l->unit_price, 'notes' => $l->kitchen_note, 'modifiers' => [], 'line_kind' => $l->line_kind ?? 'standard',
                'kot_state' => (float) $l->kot_sent_quantity + 0.0005 >= (float) $l->quantity ? 'fired' : 'pending', 'kot_batch_uuid' => null,
            ])->all();
            $order = fn ($o) => ['order_uuid' => $o->sale_uuid, 'order_no' => $o->sale_no, 'status' => 'open', 'shift_uuid' => $c->table('shifts')->where('id', $o->shift_id)->value('shift_uuid'),
                'terminal_id' => (int) $o->terminal_id, 'subtotal' => (string) $o->subtotal, 'discount' => (string) $o->discount_amount, 'tax' => (string) $o->tax_amount,
                'service_charge' => (string) ($o->service_charge_amount ?? 0), 'total' => (string) $o->grand_total, 'lines' => $lines((int) $o->id),
                'payments' => $c->table('sale_payments')->where('sales_order_id', $o->id)->get()->map(fn ($p) => ['payment_uuid' => $p->payment_uuid, 'method' => 'cash', 'amount' => (string) $p->amount])->all()];
            $pkg = [
                'generated_at' => now()->toIso8601String(), 'branch_id' => $this->branchId,
                'shifts' => $c->table('shifts')->where('branch_id', $this->branchId)->where('status', 'open')->get()->map(fn ($s) => [
                    'shift_uuid' => $s->shift_uuid, 'terminal_id' => (int) $s->terminal_id, 'status' => 'open', 'opened_at' => Carbon::parse($s->opened_at)->toIso8601String(),
                    'opened_by_user_id' => (int) $s->opened_by_user_id, 'opening_cash' => (string) $s->opening_cash, 'business_date' => $s->business_date,
                    'running' => ['cash_sales' => (string) $s->total_cash, 'card_sales' => (string) $s->total_card, 'refunds' => (string) $s->total_refunds, 'cash_in' => '0', 'cash_out' => '0', 'expected_cash' => (string) $s->expected_cash],
                ])->all(),
                'table_sessions' => $c->table('restaurant_table_sessions')->where('branch_id', $this->branchId)->whereIn('status', ['open', 'bill_requested'])->get()->map(fn ($s) => [
                    'session_uuid' => $s->session_uuid, 'table_id' => (int) $s->restaurant_table_id, 'opened_at' => Carbon::parse($s->opened_at)->toIso8601String(), 'covers' => (int) $s->guest_count,
                    'waiter_user_id' => $s->restaurant_waiter_id,
                    'orders' => $c->table('sales_orders')->where('restaurant_table_session_id', $s->id)->where('status', 'held')->get()->map($order)->all(),
                ])->all(),
                'held_sales' => $c->table('sales_orders')->where('branch_id', $this->branchId)->where('status', 'held')->whereNull('restaurant_table_session_id')->get()->map($order)->all(),
            ];
            $pkg['revision'] = substr(hash('sha256', json_encode($pkg)), 0, 16);

            return $pkg;
        });
    }

    private function goLocalAndAdopt(string $by = 'supervisor'): array
    {
        $this->bridgeFailures = ['authority/heartbeat' => 'down', 'sync/returns' => 'down', 'sync/sales' => 'down', 'sync/supplier-finance' => 'down', 'sync/purchase-returns' => 'down',
            'sync/reconcile' => 'down', 'returnable/refresh' => 'down', 'supplier-finance/refresh' => 'down', 'purchase-returns/refresh' => 'down', 'sync/baseline' => 'down', 'config/refresh' => 'down', 'live-state' => 'down'];
        for ($i = 0; $i < 4; $i++) {
            $this->tick();
        }
        Carbon::setTestNow(now()->addSeconds(71));
        $this->assertSame('preparing_local', $this->tick()['state']['state']);
        // The SAME takeOver the status-page button calls; stream B's optional automatic takeover calls it with by = 'auto-takeover'.
        $this->asEdge(fn () => app(EdgeAuthorityService::class)->takeOver(true, $by, false, null));

        return $this->asEdge(function () {
            $user = User::on('tenant')->find($this->userId);
            Auth::guard('tenant')->setUser($user);
            Auth::shouldUse('tenant');

            return app(ContinuityAdoptionService::class)->ensureAdopted($user, $this->t1);
        });
    }

    private function cloudFacts(): array
    {
        return $this->asCloud(function () {
            $c = DB::connection('tenant');

            return [
                'shifts' => $c->table('shifts')->count(),
                'sales_orders' => $c->table('sales_orders')->count(),
                'payments' => $c->table('sale_payments')->count(),
                'kot_batches' => $c->table('kot_batches')->count(),
                'print_jobs' => $c->table('print_jobs')->count(),
                'stock_out' => (float) $c->table('stock_ledgers')->where('movement_type', 'sale')->where('direction', 'out')->sum('quantity'),
                'cash_bank_txns' => $c->table('cash_bank_account_transactions')->count(),
                'journal_entries' => $c->table('journal_entries')->count(),
                'sales_ledgers' => $c->table('sales_ledgers')->count(),
            ];
        });
    }

    public function test_mirror_adopt_merge_one_shift_one_order_and_no_duplicate_financial_record(): void
    {
        $this->assertSame('refreshed:initial', $this->tick()['work']['stock']);
        $this->asEdge(fn () => app(LiveStateMirror::class)->apply($this->cloudLiveState()));
        $adopted = $this->goLocalAndAdopt();
        $this->assertSame(['adopted', 1, 1, 1], [$adopted['status'], $adopted['shifts'], $adopted['table_sessions'], $adopted['table_orders']], json_encode($adopted));
        $cloudShiftUuid = $this->asCloud(fn () => DB::connection('tenant')->table('shifts')->where('id', $this->cloudShiftId)->value('shift_uuid'));

        [$quickUuid, $edgeShiftExpected, $kotFired] = $this->asEdge(function () use ($cloudShiftUuid) {
            $user = User::on('tenant')->find($this->userId);
            $edgeShift = DB::connection('tenant')->table('shifts')->where('terminal_id', $this->t1)->where('status', 'open')->first();
            $this->assertSame($cloudShiftUuid, $edgeShift->continues_cloud_shift_uuid);
            $this->assertSame('1200.00', (string) $edgeShift->opening_cash, 'the drawer continues with the online running cash');
            $pos = app(EdgeLocalPosService::class);
            // A takeaway rung on the continued shift.
            $quick = $pos->completePaidSale(['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
                'lines' => [['product_id' => $this->biryani, 'quantity' => 1]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 150]]], $user, $this->t1);
            // Add a round to the adopted table order (+1 Biryani), send the KOT, take payment.
            $order = SalesOrder::on('tenant')->with('lines')->where('sale_uuid', $this->cloudOrderUuid)->firstOrFail();
            $revised = $pos->holdOrReviseSale(['held_sale_id' => $order->id, 'restaurant_table_session_id' => $order->restaurant_table_session_id, 'order_type' => 'dine_in',
                'lines' => array_merge($order->lines->map(fn ($l) => ['sales_order_line_id' => $l->id, 'product_id' => $l->product_id, 'quantity' => (float) $l->quantity])->all(),
                    [['product_id' => $this->biryani, 'quantity' => 1]])], $user, $this->t1);
            $pos->queueKotEvents((int) $revised->id, $user, $this->t1);
            $fired = DB::connection('tenant')->table('kot_batch_lines')->orderBy('product_id')->get(['product_id', 'quantity'])->map(fn ($r) => [(int) $r->product_id, (float) $r->quantity])->all();
            $settled = $pos->settleHeldSale((int) $revised->id, ['client_uuid' => (string) Str::uuid(), 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 500]]], $user, $this->t1);
            $this->assertSame('paid', $settled->status);
            $this->assertSame($this->cloudOrderUuid, $settled->sale_uuid, 'the Edge sale IS the online order');
            $env = json_decode((string) DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $this->cloudOrderUuid)->value('envelope'), true);
            $this->assertSame(EdgeSaleEnvelopeBuilder::SCHEMA_VERSION_V4, $env['envelope_schema_version']);
            $this->assertSame($this->cloudOrderUuid, $env['adoption']['adopted_from_order_uuid']);
            $this->assertSame($cloudShiftUuid, $env['shift']['continues_cloud_shift_uuid']);

            return [(string) $quick->sale_uuid, (string) DB::connection('tenant')->table('shifts')->where('id', $edgeShift->id)->value('expected_cash'), $fired];
        });
        // The kitchen received only what it did not have: the unsent Raita and the new Biryani — never the fired Biryani ×2 again.
        $expectedFired = [[$this->biryani, 1.0], [$this->raita, 1.0]];
        usort($expectedFired, fn ($a, $b) => $a[0] <=> $b[0]);
        $this->assertSame($expectedFired, $kotFired);
        $this->assertSame('1850.00', $edgeShiftExpected, '1 200 + 150 + 500');

        $before = $this->cloudFacts();
        $this->assertSame(1, $before['shifts']);
        $this->assertSame(1, $before['sales_orders']);

        // ── WAN back: the outbox drains; the Cloud merges ──
        $this->bridgeFailures = [];
        $t = $this->tick();
        $this->assertSame(2, $t['work']['drained'], json_encode($t['work']));
        $after = $this->cloudFacts();
        $this->assertSame(1, $after['shifts'], 'ONE shift per counter: no Cloud shift created');
        $this->assertSame(2, $after['sales_orders'], 'the table order completed in place + the takeaway: never a second copy of the order');
        $this->assertSame(2, $after['payments'], 'one payment per sale');
        $this->assertSame(1, $after['kot_batches'], 'the Cloud kitchen history is untouched: no KOT batch created by ingestion');
        $this->assertSame(0, $after['print_jobs'], 'no print job created by ingestion');
        $this->assertSame(5.0, $after['stock_out'], 'official stock once: Biryani 1 (takeaway) + Biryani 3 + Raita 1 (table)');

        $this->asCloud(function () use ($quickUuid, $cloudShiftUuid) {
            $c = DB::connection('tenant');
            $order = $c->table('sales_orders')->where('id', $this->cloudOrderId)->first();
            $this->assertSame(['paid', 'SO-ONLINE-1', $this->cloudOrderUuid, $this->cloudShiftId, '500.00', 1], [$order->status, $order->sale_no, $order->sale_uuid, (int) $order->shift_id, (string) $order->grand_total, (int) $order->inventory_posted]);
            $lines = $c->table('sales_order_lines')->where('sales_order_id', $this->cloudOrderId)->orderBy('id')->get();
            $this->assertCount(3, $lines, 'the two online lines kept (same rows), the round added on the appliance appended');
            $this->assertSame(1, (int) $c->table('kot_batch_lines')->where('sales_order_line_id', $lines[0]->id)->count(), 'the kept line still links to its online KOT');
            $quick = $c->table('sales_orders')->where('sale_uuid', $quickUuid)->first();
            $this->assertSame($this->cloudShiftId, (int) $quick->shift_id, 'the takeaway landed in the ORIGINAL online shift');
            $shift = $c->table('shifts')->where('id', $this->cloudShiftId)->first();
            $this->assertSame(['open', '850.00', '850.00', '1850.00'], [$shift->status, (string) $shift->total_sales, (string) $shift->total_cash, (string) $shift->expected_cash],
                'one shift, one report: 200 online + 150 + 500 offline; drawer 1 200 + 650');
            $session = $c->table('restaurant_table_sessions')->where('id', $order->restaurant_table_session_id)->first();
            $this->assertSame('closed', $session->status, 'payment settled the table on the Cloud too');
            $this->assertSame('available', $c->table('restaurant_tables')->where('id', $this->tableId)->value('status'));
            $acks = $c->table('edge_inbound_sale_ingestions')->where('status', 'applied')->pluck('ack_payload', 'sale_uuid')->map(fn ($a) => json_decode($a, true));
            $this->assertSame('merged', $acks[$quickUuid]['continuity']['shift']['status']);
            $this->assertSame($cloudShiftUuid, $acks[$this->cloudOrderUuid]['continuity']['shift']['cloud_shift_uuid']);
            $this->assertEquals(['sales_order_id' => $this->cloudOrderId, 'kept_lines' => 2, 'appended_lines' => 1], $acks[$this->cloudOrderUuid]['continuity']['adopted_order']);
            $this->assertSame((string) $order->sale_no, $acks[$this->cloudOrderUuid]['official_sale_no']);
        });

        // ── an exact replay of the adopted envelope: already_applied, ZERO further effects ──
        $envelope = json_decode((string) $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $this->cloudOrderUuid)->value('envelope')), true);
        $headers = ['X-Edge-Device-ID' => $this->cloudDeviceUuid, 'Authorization' => 'Bearer ' . $this->cloudDeviceSecret];
        $this->asCloud(fn () => $this->postJson('http://' . config('tenancy.central_domain') . '/api/edge/sync/sales', ['envelope' => $envelope], $headers)->assertOk()->assertJsonPath('status', 'already_applied'));
        $this->assertSame($after, $this->cloudFacts(), 'a replay writes nothing');
        $this->assertSame(['acknowledged', 'acknowledged'], $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->orderBy('id')->pluck('state')->all()));
    }

    public function test_an_order_changed_on_the_cloud_after_the_mirror_is_refused_and_nothing_of_it_is_written(): void
    {
        $this->assertSame('refreshed:initial', $this->tick()['work']['stock']);
        $this->asEdge(fn () => app(LiveStateMirror::class)->apply($this->cloudLiveState()));
        // Inside the lease window (before the Cloud fenced itself) a waiter added a Raita ONLINE — after the last mirror.
        $this->asCloud(function () {
            $this->makeSaleLine($this->cloudOrderId, $this->raita, ['line_uuid' => (string) Str::ulid(), 'product_name' => 'Raita', 'quantity' => 1, 'unit_price' => 50, 'line_total' => 50]);
            DB::connection('tenant')->table('sales_orders')->where('id', $this->cloudOrderId)->update(['subtotal' => 400, 'grand_total' => 400]);
        });
        $this->goLocalAndAdopt();
        $quickUuid = $this->asEdge(function () {
            $user = User::on('tenant')->find($this->userId);
            $pos = app(EdgeLocalPosService::class);
            $order = SalesOrder::on('tenant')->where('sale_uuid', $this->cloudOrderUuid)->firstOrFail();
            $pos->settleHeldSale((int) $order->id, ['client_uuid' => (string) Str::uuid(), 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 350]]], $user, $this->t1);

            return (string) $pos->completePaidSale(['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
                'lines' => [['product_id' => $this->biryani, 'quantity' => 1]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 150]]], $user, $this->t1)->sale_uuid;
        });
        $before = $this->cloudFacts();

        $this->bridgeFailures = [];
        $t = $this->tick();
        $this->assertSame(1, (int) ($t['work']['outcomes']['terminal'] ?? 0), json_encode($t['work']));
        $this->assertSame(1, (int) ($t['work']['outcomes']['acknowledged'] ?? 0), 'the refused order never blocks the sale queued behind it');
        $this->asCloud(function () use ($quickUuid) {
            $c = DB::connection('tenant');
            $reg = $c->table('edge_inbound_sale_ingestions')->where('sale_uuid', $this->cloudOrderUuid)->first();
            $this->assertSame(['conflict', 'ADOPTED_ORDER_CONFLICT'], [$reg->status, $reg->failure_code], 'classified terminal conflict (needs attention), never a guess');
            $order = $c->table('sales_orders')->where('id', $this->cloudOrderId)->first();
            $this->assertSame(['held', '400.00', 0], [$order->status, (string) $order->grand_total, (int) $c->table('sale_payments')->where('sales_order_id', $this->cloudOrderId)->count()], 'the online order is untouched');
            $this->assertSame('applied', $c->table('edge_inbound_sale_ingestions')->where('sale_uuid', $quickUuid)->value('status'), 'the continuation sale beside it still merges');
            $this->assertSame($this->cloudShiftId, (int) $c->table('sales_orders')->where('sale_uuid', $quickUuid)->value('shift_id'));
        });
        $after = $this->cloudFacts();
        $this->assertSame($before['sales_orders'] + 1, $after['sales_orders'], 'only the takeaway was added — no copy of the refused order');
        $this->assertSame($before['payments'] + 1, $after['payments']);
        $this->assertSame(1.0, $after['stock_out'], 'no stock moved for the refused order');
        $this->assertSame('failed_permanent', $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $this->cloudOrderUuid)->value('state')), 'parked for a supervisor: the handback stays blocked (PERMANENT_SYNC_FAILURE)');
        // Re-sending the identical envelope is answered from the registry: still the conflict, still nothing written.
        $envelope = json_decode((string) $this->asEdge(fn () => DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $this->cloudOrderUuid)->value('envelope')), true);
        $headers = ['X-Edge-Device-ID' => $this->cloudDeviceUuid, 'Authorization' => 'Bearer ' . $this->cloudDeviceSecret];
        $this->asCloud(fn () => $this->postJson('http://' . config('tenancy.central_domain') . '/api/edge/sync/sales', ['envelope' => $envelope], $headers)->assertJsonPath('status', 'conflict')->assertJsonPath('failure_code', 'ADOPTED_ORDER_CONFLICT'));
        $this->assertSame($after, $this->cloudFacts());
    }

    /**
     * AUTO TAKEOVER vs CONTINUITY (owner, 21 Nov evening): the optional automatic takeover calls the SAME takeOver with
     * by = 'auto-takeover'. Adoption is keyed on LOCAL_ACTIVE + the takeover instant only, so it adopts and merges exactly as after
     * the button — and the audit says which takeover it was.
     */
    public function test_an_automatic_takeover_adopts_and_merges_exactly_like_the_supervised_button(): void
    {
        $this->assertSame('refreshed:initial', $this->tick()['work']['stock']);
        $this->asEdge(fn () => app(LiveStateMirror::class)->apply($this->cloudLiveState()));
        $adopted = $this->goLocalAndAdopt('auto-takeover');
        $this->assertSame(['adopted', 1, 1, 1], [$adopted['status'], $adopted['shifts'], $adopted['table_sessions'], $adopted['table_orders']], json_encode($adopted));
        [$by, $modes, $metaBy] = $this->asEdge(fn () => [
            DB::connection('tenant')->table('edge_mirror_adoptions')->distinct()->pluck('takeover_by')->all(),
            DB::connection('tenant')->table('edge_mirror_adoptions')->distinct()->pluck('takeover_mode')->all(),
            DB::connection('tenant')->table('edge_mirror_meta')->value('adoption_takeover_by'),
        ]);
        $this->assertSame(['auto-takeover'], $by, 'every adoption audit row names the automatic takeover');
        $this->assertSame(['auto'], $modes);
        $this->assertSame('auto-takeover', $metaBy);

        // Same merge as after the button: the adopted order settled as-is completes the EXISTING Cloud order in the ORIGINAL shift.
        $this->asEdge(function () {
            $order = SalesOrder::on('tenant')->where('sale_uuid', $this->cloudOrderUuid)->firstOrFail();
            app(EdgeLocalPosService::class)->settleHeldSale((int) $order->id, ['client_uuid' => (string) Str::uuid(), 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 350]]], User::on('tenant')->find($this->userId), $this->t1);
        });
        $this->bridgeFailures = [];
        $this->assertSame(1, $this->tick()['work']['drained']);
        $this->asCloud(function () {
            $c = DB::connection('tenant');
            $this->assertSame(1, $c->table('sales_orders')->count(), 'completed in place — no second order');
            $this->assertSame(['paid', $this->cloudShiftId], [(string) $c->table('sales_orders')->where('id', $this->cloudOrderId)->value('status'), (int) $c->table('sales_orders')->where('id', $this->cloudOrderId)->value('shift_id')]);
            $this->assertSame(1, $c->table('shifts')->count());
            $this->assertSame('1550.00', (string) $c->table('shifts')->where('id', $this->cloudShiftId)->value('expected_cash'), '1 200 + 350, once');
            $this->assertSame(1, $c->table('sale_payments')->count());
        });
    }

    /** An automatic takeover NEVER adopts a stale mirror: fail closed → nothing adopted, the reason, the normal open-shift flow. */
    public function test_an_automatic_takeover_with_a_stale_mirror_never_adopts(): void
    {
        $this->assertSame('refreshed:initial', $this->tick()['work']['stock']);
        $this->asEdge(fn () => app(LiveStateMirror::class)->apply($this->cloudLiveState()));
        // Heartbeats keep being acknowledged for three minutes while the live-state poll is broken: the mirror falls behind.
        $this->bridgeFailures = ['live-state' => 'down'];
        for ($i = 0; $i < 6; $i++) {
            Carbon::setTestNow(now()->addSeconds(31));
            $this->assertTrue($this->tick()['heartbeat']['ok']);
        }
        $r = $this->goLocalAndAdopt('auto-takeover');
        $this->assertSame(ContinuityAdoptionService::STATUS_REFUSED_STALE, $r['status'], json_encode($r));
        $this->assertStringContainsString('(limit 120 s)', $r['reason']);
        $this->asEdge(function () {
            $c = DB::connection('tenant');
            $this->assertSame(0, $c->table('shifts')->count(), 'no continuation: the counter opens a shift by hand');
            $this->assertSame(0, $c->table('sales_orders')->count(), 'no stale order adopted');
            $this->assertSame(0, $c->table('edge_mirror_adoptions')->count());
            $meta = $c->table('edge_mirror_meta')->first();
            $this->assertSame(['refused_stale', 'auto-takeover'], [$meta->adoption_status, $meta->adoption_takeover_by]);
            // The decision holds for the whole segment; the normal flow works.
            $user = User::on('tenant')->find($this->userId);
            $this->assertSame('already', app(ContinuityAdoptionService::class)->ensureAdopted($user, $this->t1)['status']);
            app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->t1), $this->userId, 1200.0);
            $this->assertNull($c->table('shifts')->value('continues_cloud_shift_uuid'));
        });
    }
}
