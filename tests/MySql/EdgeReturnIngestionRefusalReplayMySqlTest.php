<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeIngestionVerdicts;
use App\Services\Edge\EdgeReturnEnvelopeBuilder;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeCloudBridgeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * CANONICAL REHEARSAL Phase E, owner item R-3 (30 Oct 2026) — the CLOUD side of a return-quantity refusal: an Edge
 * `edge-return-envelope-v2` event whose quantity the shared arithmetic (SalesReturnService::computeReturn, W2-B rules)
 * refuses is answered `refused` / RETURN_REFUSED (a TERMINAL verdict → the appliance parks it failed_permanent), its
 * replay is the identical refusal, a same-uuid replay with other content is a conflict, and the return accounting
 * (sales_returns, stock, GL, cash/bank, ledger, returned_quantity, order status) never moves for a refused event — while
 * a valid event still applies exactly once and replays as already_applied.
 */
class EdgeReturnIngestionRefusalReplayMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeCloudBridgeFixture;

    private int $branchId;
    private int $userId;
    private int $terminalId;
    private int $burgerId;
    private int $muttonId;
    private int $cashMethodId;
    private int $cloudSaleId;
    private int $burgerLineId;
    private object $shift;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->cloudDb = (string) config('database.connections.tenant.database');

        $this->cleanTenant([
            'edge_branch_authority_leases', 'edge_inbound_return_ingestions', 'edge_inbound_sale_ingestions', 'sales_return_lines', 'sales_returns', 'sales_ledgers',
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'accounts', 'cash_bank_accounts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'payment_methods', 'products', 'categories', 'units', 'terminals', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch(['name' => 'Return Branch', 'allow_negative_stock' => 0, 'sales_return_approval_mode' => 'auto_approve']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RR' . Str::random(4)]);
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
        $this->burgerLineId = (int) $conn->table('sales_order_lines')->where('sales_order_id', $this->cloudSaleId)->where('product_id', $this->burgerId)->value('id');
        $this->registerCloudTenantAndDevice($this->branchId, 1);
        $this->configureApplianceCloudUrls(); // entitlement stub + edge feature flag (the Cloud ingestion gate)
        // The paying shift the appliance names by STABLE identity (REFUND-SHIFT-H1): an open Cloud shift on the counter.
        app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 1000.0);
        $this->shift = $conn->table('shifts')->where('status', 'open')->first();
    }

    protected function tearDown(): void
    {
        $this->cleanupCloudRegistration();
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

    /** An appliance return event (v2 wire shape of EdgeReturnEnvelopeBuilder) returning $qty Burger of the Online sale. */
    private function envelope(float $qty, ?string $returnUuid = null): array
    {
        $total = round($qty * 100, 2);
        $env = [
            'envelope_schema_version' => EdgeReturnEnvelopeBuilder::SCHEMA,
            'event_type' => 'sales_return',
            'return_uuid' => $returnUuid ?? (string) Str::ulid(),
            'tenant_id' => $this->cloudTenantId,
            'tenant_code' => $this->bridgeTenantCode,
            'branch_id' => $this->branchId,
            'device_public_uuid' => $this->cloudDeviceUuid,
            'activation_epoch' => 1,
            'config_revision' => 1,
            'original' => ['kind' => 'cloud', 'cloud_sales_order_id' => $this->cloudSaleId, 'sale_uuid' => null, 'sale_no' => (string) DB::connection('tenant')->table('sales_orders')->where('id', $this->cloudSaleId)->value('sale_no')],
            'return_no' => 'SR-' . $this->branchId . '-' . $this->terminalId . '-LOCAL',
            'return_date' => now()->toIso8601String(),
            'business_date' => now()->toDateString(),
            'reason' => 'wrong item',
            'refund_method' => 'cash',
            'refund_amount' => $total,
            'lines' => [[
                'return_line_uuid' => (string) Str::ulid(), 'cloud_sales_order_line_id' => $this->burgerLineId, 'line_uuid' => null,
                'product_id' => $this->burgerId, 'product_variant_id' => null, 'quantity' => round($qty, 3), 'unit_code' => 'pc',
                'unit_price' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => $total,
            ]],
            'totals' => ['subtotal' => $total, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'delivery_charge_amount' => 0.0, 'grand_total' => $total],
            'approval' => null,
            'actor' => ['user_id' => $this->userId, 'employee_code' => null, 'terminal_id' => $this->terminalId],
            'processing_shift' => [
                'shift_uuid' => (string) $this->shift->shift_uuid, 'terminal_id' => $this->terminalId,
                'business_date' => (string) $this->shift->business_date, 'opened_at' => now()->toIso8601String(), 'opened_by_user_id' => $this->userId,
            ],
            'freshness' => ['returnable_cache_watermark' => null, 'returnable_cache_as_of' => null],
            'created_at' => now()->toIso8601String(),
        ];
        $env['content_hash'] = hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($env));

        return $env;
    }

    private function send(array $envelope)
    {
        $headers = ['X-Edge-Device-ID' => $this->cloudDeviceUuid, 'Authorization' => 'Bearer ' . $this->cloudDeviceSecret];

        return $this->postJson('http://' . config('tenancy.central_domain') . '/api/edge/sync/returns', ['envelope' => $envelope], $headers);
    }

    /** The return accounting on the Cloud — what a refused event must never move. */
    private function books(): array
    {
        $c = DB::connection('tenant');

        return [
            'returns' => $c->table('sales_returns')->count(),
            'return_lines' => $c->table('sales_return_lines')->count(),
            'stock_in' => $c->table('stock_ledgers')->where('movement_type', 'sale_return')->where('direction', 'in')->count(),
            'journals' => $c->table('journal_entries')->where('source_type', 'sales_return')->count(),
            'cashbank_out' => $c->table('cash_bank_account_transactions')->where('transaction_type', 'sales_return_refund')->count(),
            'ledger' => $c->table('sales_ledgers')->where('entry_type', 'sale_return')->count(),
            'burger_on_hand' => (float) $c->table('stock_balances')->where('branch_id', $this->branchId)->where('product_id', $this->burgerId)->sum('quantity_on_hand'),
            'burger_returned_qty' => (float) $c->table('sales_order_lines')->where('id', $this->burgerLineId)->value('returned_quantity'),
            'order_status' => (string) $c->table('sales_orders')->where('id', $this->cloudSaleId)->value('status'),
            'till_balance' => (float) $c->table('cash_bank_accounts')->where('code', 'TILL')->value('current_balance'),
        ];
    }

    private function registry(): array
    {
        return DB::connection('tenant')->table('edge_inbound_return_ingestions')->orderBy('id')->get(['return_uuid', 'status', 'failure_code', 'content_hash'])->map(fn ($r) => (array) $r)->all();
    }

    public function test_an_over_quantity_return_event_is_refused_terminally_its_replay_is_identical_and_the_books_never_move(): void
    {
        $before = $this->books();
        $env = $this->envelope(5);

        $first = $this->send($env);
        $this->assertSame(422, $first->getStatusCode(), $first->getContent());
        $this->assertSame('refused', $first->json('status'));
        $this->assertSame('RETURN_REFUSED', $first->json('failure_code'));
        $this->assertStringContainsString('Burger: only 3 Piece can still be returned, not 5.', (string) $first->json('message'), 'the shared arithmetic speaks, in the line\'s unit');
        $this->assertSame($env['return_uuid'], $first->json('return_uuid'));
        $this->assertTrue(EdgeIngestionVerdicts::isTerminal('RETURN_REFUSED'), 'terminal: the appliance parks the event failed_permanent instead of retrying forever');
        $this->assertSame($before, $this->books(), 'a refused event posts nothing — no return, no stock, no GL, no cash/bank, no ledger');
        $reg = $this->registry();
        $this->assertCount(1, $reg);
        $this->assertSame(['refused', 'RETURN_REFUSED', $env['content_hash']], [$reg[0]['status'], $reg[0]['failure_code'], $reg[0]['content_hash']]);

        // REPLAY of the same immutable event (a retry the appliance would make before it reads the terminal ack): identical.
        $second = $this->send($env);
        $this->assertSame(422, $second->getStatusCode(), $second->getContent());
        $this->assertSame(['refused', 'RETURN_REFUSED'], [$second->json('status'), $second->json('failure_code')]);
        $this->assertSame($before, $this->books(), 'the replay moves nothing either');
        $this->assertCount(1, $this->registry(), 'one registry row per return_uuid — the replay re-used it');

        // The SAME uuid with OTHER content (a corrected quantity re-sent under the old identity) is a conflict, not a return:
        // the first content the Cloud saw for that uuid is authoritative even when it was refused.
        $corrected = $this->envelope(1, $env['return_uuid']);
        $conflict = $this->send($corrected);
        $this->assertSame(409, $conflict->getStatusCode(), $conflict->getContent());
        $this->assertSame('ENVELOPE_CONFLICT', $conflict->json('failure_code'));
        $this->assertSame($before, $this->books());
        $this->assertCount(1, $this->registry());

        // A NEW identity with the corrected quantity is a new event: it applies once.
        $ok = $this->send($this->envelope(1));
        $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());
        $this->assertSame('applied', $ok->json('status'));
        $after = $this->books();
        $this->assertSame(['returns' => 1, 'return_lines' => 1, 'stock_in' => 1, 'journals' => 1, 'cashbank_out' => 1, 'ledger' => 1], array_intersect_key($after, array_flip(['returns', 'return_lines', 'stock_in', 'journals', 'cashbank_out', 'ledger'])), json_encode($after));
        $this->assertSame(1.0, $after['burger_returned_qty']);
        $this->assertSame('partially_returned', $after['order_status']);
    }

    public function test_a_return_event_valid_when_queued_but_overtaken_online_is_refused_and_the_books_do_not_move_twice(): void
    {
        // The customer came back twice: once ONLINE (2 Burger, posted first) and the appliance's offline event (2 Burger,
        // valid against the position it saw) arrives later. The Cloud's arithmetic refuses the second; nothing posts twice.
        $sale = SalesOrder::on('tenant')->with(['lines.product', 'lines.variant', 'lines.returnLines'])->findOrFail($this->cloudSaleId);
        app(SalesReturnService::class)->processReturn($sale, [['sales_order_line_id' => $this->burgerLineId, 'quantity' => 2]], 'online first', 'cash', 200.0, $this->userId, refundShiftId: (int) $this->shift->id);
        $afterOnline = $this->books();
        $this->assertSame(1, $afterOnline['returns']);
        $this->assertSame(2.0, $afterOnline['burger_returned_qty']);

        $env = $this->envelope(2);
        $res = $this->send($env);
        $this->assertSame(422, $res->getStatusCode(), $res->getContent());
        $this->assertSame('RETURN_REFUSED', $res->json('failure_code'));
        $this->assertStringContainsString('Burger: only 1 Piece can still be returned, not 2.', (string) $res->json('message'));
        $this->assertSame($afterOnline, $this->books(), 'the Online return stands alone; the overtaken event moves nothing');
        $this->send($env)->assertStatus(422)->assertJsonPath('failure_code', 'RETURN_REFUSED');
        $this->assertSame($afterOnline, $this->books());
        $this->assertSame(['refused'], array_column($this->registry(), 'status'));
    }

    public function test_a_valid_event_applies_exactly_once_and_a_later_over_return_is_refused_against_what_is_left(): void
    {
        $before = $this->books();
        $env = $this->envelope(1);
        $this->send($env)->assertOk()->assertJsonPath('status', 'applied');
        $applied = $this->books();
        $this->assertSame($before['burger_on_hand'] + 1, $applied['burger_on_hand'], 'official FEFO stock reversal once');
        $this->assertSame(-100.0, $applied['till_balance'], 'the cash/bank refund left the books once');
        $this->assertSame(1.0, $applied['burger_returned_qty']);

        $this->send($env)->assertOk()->assertJsonPath('status', 'already_applied');
        $this->assertSame($applied, $this->books(), 'a replay of an applied event changes nothing');

        $over = $this->send($this->envelope(3));
        $this->assertSame(422, $over->getStatusCode(), $over->getContent());
        $this->assertStringContainsString('Burger: only 2 Piece can still be returned, not 3.', (string) $over->json('message'));
        $this->assertSame($applied, $this->books());
        $this->assertSame(['applied', 'refused'], array_column($this->registry(), 'status'));
    }
}
