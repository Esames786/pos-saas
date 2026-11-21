<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\EdgeInboundSaleIngestion;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeInboundSaleIngestionService;
use App\Services\Edge\EdgeSyncSender;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/** An OLDER Cloud (pre-W6) that only speaks envelope v1 — the Cloud-first deploy-ordering double. */
class V1OnlyCloudSaleIngestionService extends EdgeInboundSaleIngestionService
{
    protected function supportedEnvelopeSchemas(): array
    {
        return [self::ENVELOPE_SCHEMA_V1];
    }
}

/**
 * W6 (Team 6, wave 2) — the CLOUD half of the 0.7.0-edge sale contract, against the REAL Cloud authorities (InventoryService
 * FEFO, SalesService::consumeLineModifiers, JournalPostingService GL + cash-bank, EdgeFinancePostingVerifier), a real master
 * EdgeDevice + activation epoch (docs/status/edge-w6-contract-and-reconcile-plan.md §B0–§B5):
 *
 *  B4  a tipped v1 envelope applies; the tip is projected and credited to the Tips account (4140); finance verified;
 *  B3  a line-ONLY discount (discount_type none) applies with a balanced GL (revenue grossed up by the header discount);
 *  B5  the top-level `notes` key is projected to sales_orders.notes; an envelope without it keeps notes NULL;
 *  B1  v1 semantics are FROZEN — a v1 envelope never consumes modifier stock; an `edge-sale-envelope-v2` posts the official
 *      modifier linked-stock FEFO through the SHARED Cloud rule (+ its cost on the line COGS), exactly once (replay =
 *      already_applied, zero effects); a misconfigured stock modifier rolls the WHOLE ingestion back as a RETRYABLE
 *      exception that applies once the Cloud config is fixed;
 *  B0.2.4 Cloud-first deploy ordering through the REAL sender: an old Cloud answers SCHEMA_UNSUPPORTED → the appliance row
 *      is deferred with a bounded backoff (never failed_permanent) → after the Cloud "upgrades" the SAME immutable row is
 *      re-sent, applies once and is acknowledged — no duplicate sale.
 */
class EdgeW6ContractIngestionMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const TENANT_ID = 7778;
    private const DEVICE_UUID = 'edge-device-w6-contract';
    private const EPOCH = 4;

    private int $branchId;
    private int $productId;
    private int $cheeseId;       // stock-tracked product linked from a modifier
    private int $groupId;
    private int $modifierId;     // consume_stock option: 2 × cheese per unit
    private int $userId;
    private int $terminalId;
    private int $cashMethodId;
    private string $url = 'https://cloud.example.test/api/edge/sync/sales';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_inbound_sale_ingestions',
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'accounts', 'cash_bank_accounts',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders',
            'product_modifier_group', 'modifiers', 'modifier_groups',
            'customers', 'payment_methods', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);
        (new DefaultChartOfAccountsSeeder())->run();

        $this->branchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $cat = $this->makeCategory();
        $this->productId = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 100]);
        $this->cheeseId = $this->makeProduct($cat, ['name' => 'Cheese', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_pos_visible' => 0, 'default_selling_price' => 0]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->seedStock($this->productId, 50, 40);
        $this->seedStock($this->cheeseId, 100, 3);
        $this->seedCashBankMapping();

        $conn = DB::connection('tenant');
        $this->groupId = (int) $conn->table('modifier_groups')->insertGetId(['branch_id' => null, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 2, 'is_required' => 0, 'sort_order' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->modifierId = (int) $conn->table('modifiers')->insertGetId(['modifier_group_id' => $this->groupId, 'name' => 'Extra Cheese', 'price_delta' => 30,
            'linked_product_id' => $this->cheeseId, 'consume_stock' => 1, 'linked_quantity' => 2, 'linked_unit_id' => null, 'is_default' => 0, 'sort_order' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        DB::connection('master')->table('edge_devices')->where('public_uuid', self::DEVICE_UUID)->delete();
        DB::connection('master')->table('edge_devices')->insert([
            'public_uuid' => self::DEVICE_UUID, 'tenant_id' => self::TENANT_ID, 'branch_id' => $this->branchId,
            'installation_uuid' => (string) Str::uuid(), 'device_secret_hash' => hash('sha256', 'x'),
            'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('master')->table('edge_branch_activations')->where('tenant_id', self::TENANT_ID)->where('branch_id', $this->branchId)->delete();
        DB::connection('master')->table('edge_branch_activations')->insert([
            'tenant_id' => self::TENANT_ID, 'branch_id' => $this->branchId, 'generation' => self::EPOCH,
            'device_public_uuid' => self::DEVICE_UUID, 'reason' => 'initial', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('master')->table('edge_devices')->where('public_uuid', self::DEVICE_UUID)->delete();
            DB::connection('master')->table('edge_branch_activations')->where('tenant_id', self::TENANT_ID)->delete();
        } catch (\Throwable $e) {
        }
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function seedStock(int $productId, float $qty, float $cost): void
    {
        $conn = DB::connection('tenant');
        $batchId = $conn->table('inventory_batches')->insertGetId([
            'batch_key' => "w6-{$this->branchId}-{$productId}", 'branch_id' => $this->branchId, 'product_id' => $productId,
            'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => $cost, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('stock_balances')->insert([
            'balance_key' => "{$this->branchId}-{$productId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $productId,
            'inventory_batch_id' => $batchId, 'quantity_on_hand' => $qty, 'average_cost' => $cost, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedCashBankMapping(): void
    {
        $conn = DB::connection('tenant');
        $accountId = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cbId = $conn->table('cash_bank_accounts')->insertGetId([
            'code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $accountId, 'current_balance' => 0,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('payment_methods')->where('id', $this->cashMethodId)->update(['cash_bank_account_id' => $cbId]);
    }

    /** An envelope in EdgeSaleEnvelopeBuilder's shape; `$line` / `$totals` / top-level overrides compose the W6 cases. */
    private function envelope(array $top = [], array $line = [], array $totals = []): array
    {
        $l = array_merge([
            'line_uuid' => (string) Str::ulid(), 'line_kind' => 'standard', 'product_id' => $this->productId,
            'product_variant_id' => null, 'combo_id' => null, 'product_name' => 'Widget',
            'quantity' => 2.0, 'unit_price' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => 200.0, 'modifiers' => [],
        ], $line);
        $t = array_merge(['subtotal' => 200.0, 'discount_amount' => 0.0, 'discount_type' => 'none', 'discount_value' => 0.0, 'promo_code' => null,
            'tax_amount' => 0.0, 'service_charge_amount' => 0.0, 'delivery_charge_amount' => 0.0, 'tip_amount' => 0.0,
            'grand_total' => 200.0, 'paid_amount' => 200.0, 'change_amount' => 0.0], $totals);
        $env = array_merge([
            'envelope_schema_version' => 'edge-sale-envelope-v1',
            'tenant_id' => self::TENANT_ID, 'tenant_code' => 'w6tenant', 'branch_id' => $this->branchId,
            'device_public_uuid' => self::DEVICE_UUID, 'activation_epoch' => self::EPOCH,
            'config_revision' => 7, 'config_schema_version' => 'edge-config-v1',
            'sale_uuid' => (string) Str::ulid(), 'sale_no' => 'SO-EDGE-' . Str::random(6),
            'client_uuid' => (string) Str::uuid(), 'business_date' => now()->toDateString(),
            'sale_date' => now()->toIso8601String(), 'completed_at' => now()->toIso8601String(), 'created_at' => now()->toIso8601String(),
            'order_type' => 'takeaway', 'order_source' => 'pos', 'vehicle_number' => null,
            'terminal_id' => $this->terminalId, 'terminal_code' => 'T1', 'user_id' => $this->userId, 'employee_code' => 'E1',
            'restaurant_waiter_id' => null,
            'shift' => ['shift_uuid' => (string) Str::ulid(), 'business_date' => now()->toDateString(), 'opened_at' => now()->toIso8601String(), 'terminal_id' => $this->terminalId, 'opened_by_user_id' => $this->userId],
            'table_session' => null, 'kot_events' => [], 'customer' => ['kind' => 'walk_in', 'name' => null, 'phone' => null],
            'delivery' => null,
            'totals' => $t,
            'lines' => [$l],
            'payments' => [[
                'payment_uuid' => (string) Str::ulid(), 'payment_method_id' => $this->cashMethodId, 'method_type' => 'cash',
                'amount' => (float) $t['paid_amount'], 'tendered_amount' => (float) $t['paid_amount'], 'change_amount' => 0.0, 'transaction_ref' => null, 'paid_at' => now()->toIso8601String(),
            ]],
            'operational_stock' => ['posted' => true, 'baseline_uuid' => (string) Str::ulid()],
            'local_state' => ['edge_sync_state' => 'pending', 'edge_activation_epoch' => self::EPOCH, 'inventory_posted' => false, 'is_draft' => false],
        ], $top);

        return $this->hashed($env);
    }

    private function hashed(array $env): array
    {
        unset($env['content_hash']);
        $env['content_hash'] = hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($env));

        return $env;
    }

    /** v2 envelope: 2 × Widget @ (100 + 30 option) with the stock-consuming Extra Cheese option. */
    private function modifierEnvelope(string $schema): array
    {
        return $this->envelope(['envelope_schema_version' => $schema], [
            'unit_price' => 130.0, 'line_total' => 260.0,
            'modifiers' => [['modifier_group_id' => $this->groupId, 'modifier_group_name' => 'Extras', 'modifier_id' => $this->modifierId, 'name' => 'Extra Cheese', 'price_delta' => 30.0]],
        ], ['subtotal' => 260.0, 'grand_total' => 260.0, 'paid_amount' => 260.0]);
    }

    private function ingest(array $env): array
    {
        return app(EdgeInboundSaleIngestionService::class)->ingest($env);
    }

    private function glCredit(int $saleId, string $accountCode): float
    {
        return (float) DB::connection('tenant')->table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.source_type', 'sales_order_paid')->where('je.source_id', $saleId)->where('a.code', $accountCode)
            ->sum('jl.credit');
    }

    private function assertBalancedGl(int $saleId): void
    {
        $entryId = DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->where('source_id', $saleId)->value('id');
        $this->assertNotNull($entryId, 'a GL entry was posted');
        $sum = DB::connection('tenant')->table('journal_lines')->where('journal_entry_id', $entryId)->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
        $this->assertEqualsWithDelta((float) $sum->d, (float) $sum->c, 0.001, 'GL debits equal credits');
    }

    private function cheeseLedgers(?int $saleId = null): int
    {
        $q = DB::connection('tenant')->table('stock_ledgers')->where('product_id', $this->cheeseId)->where('movement_type', 'modifier_consumption');

        return $saleId ? $q->where('reference_id', $saleId)->count() : $q->count();
    }

    // ── B4 tips ───────────────────────────────────────────────────────────────────

    public function test_a_tipped_envelope_applies_and_the_tip_is_credited_to_the_tips_account(): void
    {
        $env = $this->envelope([], [], ['tip_amount' => 20.0, 'grand_total' => 220.0, 'paid_amount' => 220.0]);
        $ack = $this->ingest($env);
        $this->assertSame('applied', $ack['status'], json_encode($ack));

        $sale = SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->firstOrFail();
        $this->assertEquals(20.0, (float) $sale->tip_amount);
        $this->assertEquals(220.0, (float) $sale->grand_total);
        $this->assertEqualsWithDelta(20.0, $this->glCredit((int) $sale->id, '4140'), 0.001, 'Cr 4140 Tips');
        $this->assertBalancedGl((int) $sale->id);
        $this->assertSame(220.0, (float) DB::connection('tenant')->table('cash_bank_accounts')->sum('current_balance'), 'the till moved by the tipped cash');
    }

    // ── B3 line-only discount ─────────────────────────────────────────────────────

    public function test_a_line_only_discount_envelope_applies_with_a_balanced_gl(): void
    {
        $env = $this->envelope([], ['discount_amount' => 30.0, 'line_total' => 170.0], ['discount_amount' => 30.0, 'discount_type' => 'none', 'grand_total' => 170.0, 'paid_amount' => 170.0]);
        $ack = $this->ingest($env);
        $this->assertSame('applied', $ack['status'], json_encode($ack));

        $sale = SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->firstOrFail();
        $this->assertEquals(30.0, (float) $sale->discount_amount);
        $this->assertSame('none', (string) $sale->discount_type);
        $this->assertEquals(30.0, (float) $sale->lines()->first()->discount_amount);
        $this->assertBalancedGl((int) $sale->id);
        $this->assertSame(170.0, (float) DB::connection('tenant')->table('cash_bank_accounts')->sum('current_balance'));
    }

    // ── B5 notes ──────────────────────────────────────────────────────────────────

    public function test_the_note_is_projected_and_an_envelope_without_it_keeps_notes_null(): void
    {
        $withNote = $this->envelope(['notes' => 'birthday table']);
        $this->assertSame('applied', $this->ingest($withNote)['status']);
        $this->assertSame('birthday table', SalesOrder::on('tenant')->where('sale_uuid', $withNote['sale_uuid'])->value('notes'));

        $plain = $this->envelope();
        $this->assertArrayNotHasKey('notes', $plain);
        $this->assertSame('applied', $this->ingest($plain)['status']);
        $this->assertNull(SalesOrder::on('tenant')->where('sale_uuid', $plain['sale_uuid'])->value('notes'));
    }

    // ── B1 modifiers: v1 frozen, v2 posts official modifier stock exactly once ────

    public function test_v1_keeps_modifier_semantics_frozen_and_v2_posts_official_modifier_stock_exactly_once(): void
    {
        // v1 with the same option: stored on the line, NO modifier stock (pre-W6 semantics, an old appliance's envelope).
        $v1 = $this->modifierEnvelope('edge-sale-envelope-v1');
        $this->assertSame('applied', $this->ingest($v1)['status']);
        $v1Sale = SalesOrder::on('tenant')->where('sale_uuid', $v1['sale_uuid'])->firstOrFail();
        $this->assertSame('Extra Cheese', $v1Sale->lines()->first()->modifiers[0]['name']);
        $this->assertSame(0, $this->cheeseLedgers(), 'a v1 envelope never consumes modifier stock');

        // v2: the shared Cloud rule posts linked_quantity 2 × line qty 2 = 4 cheese (FEFO @3 = 12) on top of the product COGS (2 × 40).
        $v2 = $this->modifierEnvelope('edge-sale-envelope-v2');
        $ack = $this->ingest($v2);
        $this->assertSame('applied', $ack['status'], json_encode($ack));
        $sale = SalesOrder::on('tenant')->where('sale_uuid', $v2['sale_uuid'])->firstOrFail();
        $this->assertSame(1, $this->cheeseLedgers((int) $sale->id));
        $this->assertEquals(4.0, abs((float) DB::connection('tenant')->table('stock_ledgers')->where('product_id', $this->cheeseId)->where('reference_id', $sale->id)->value('quantity')));
        $this->assertEqualsWithDelta(12.0, abs((float) DB::connection('tenant')->table('stock_ledgers')->where('product_id', $this->cheeseId)->where('reference_id', $sale->id)->value('total_cost')), 0.001);
        $this->assertSame(96.0, (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $this->cheeseId)->sum('quantity_on_hand'));
        $this->assertEqualsWithDelta(92.0, (float) $sale->lines()->first()->cost_total, 0.001, 'COGS = product 80 + modifier 12');
        $this->assertBalancedGl((int) $sale->id);
        $this->assertSame('edge-sale-envelope-v2', EdgeInboundSaleIngestion::query()->where('sale_uuid', $v2['sale_uuid'])->value('envelope_schema_version'));

        // replay: already_applied, ZERO further effects
        $replay = $this->ingest($v2);
        $this->assertSame('already_applied', $replay['status']);
        $this->assertSame($ack['sales_order_id'], $replay['sales_order_id']);
        $this->assertSame(1, $this->cheeseLedgers(), 'no second modifier movement');
        $this->assertSame(96.0, (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $this->cheeseId)->sum('quantity_on_hand'));
        $this->assertSame(2, SalesOrder::on('tenant')->count());
    }

    public function test_a_misconfigured_stock_modifier_rolls_the_whole_v2_ingestion_back_and_applies_after_the_fix(): void
    {
        DB::connection('tenant')->table('products')->where('id', $this->cheeseId)->update(['is_stock_tracked' => 0]);
        $v2 = $this->modifierEnvelope('edge-sale-envelope-v2');

        $ack = $this->ingest($v2);
        $this->assertSame('exception', $ack['status'], 'retryable — the appliance keeps the row and retries');
        $this->assertSame('MODIFIER_STOCK_FAILED', $ack['failure_code']);
        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'no half-created sale');
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count(), 'the product FEFO rolled back too');
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count());

        // the Cloud config is fixed → the SAME immutable envelope applies once
        DB::connection('tenant')->table('products')->where('id', $this->cheeseId)->update(['is_stock_tracked' => 1]);
        $this->assertSame('applied', $this->ingest($v2)['status']);
        $this->assertSame(1, SalesOrder::on('tenant')->count());
        $this->assertSame(1, $this->cheeseLedgers());
    }

    // ── B0.2.4 Cloud-first deploy ordering through the REAL sender ────────────────

    public function test_schema_unsupported_is_deferred_with_backoff_and_the_same_row_applies_once_after_the_cloud_upgrades(): void
    {
        config([
            'edge.sync.url' => $this->url, 'edge.sync.device_id' => self::DEVICE_UUID, 'edge.sync.device_secret' => 'secret',
            'edge.sync.schema_retry_base_seconds' => 60, 'edge.sync.schema_retry_max_seconds' => 900,
        ]);
        $env = $this->modifierEnvelope('edge-sale-envelope-v2');
        $json = app(EdgeBootstrapService::class)->canonicalJson($env);
        $row = EdgeSyncOutbox::create([
            'sale_uuid' => $env['sale_uuid'], 'envelope_schema_version' => $env['envelope_schema_version'], 'config_revision' => 7,
            'activation_epoch' => self::EPOCH, 'envelope' => $json, 'content_hash' => $env['content_hash'], 'state' => 'pending',
        ]);

        // The Cloud that answers: first an OLD one (v1 only), then — after the "upgrade" — the current one.
        $cloud = V1OnlyCloudSaleIngestionService::class;
        Http::fake([$this->url => function ($request) use (&$cloud) {
            return Http::response(app($cloud)->ingest((array) data_get($request->data(), 'envelope')), 200);
        }]);
        $sender = app(EdgeSyncSender::class);

        $this->assertSame('retry', $sender->sendNext('worker-1'));
        $fresh = $row->fresh();
        $this->assertSame('leased', $fresh->state, 'deferred under a backoff lease — NOT failed_permanent');
        $this->assertStringStartsWith('backoff:', (string) $fresh->lease_owner);
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($fresh->lease_expires_at, false), 5, 'first retry after the base backoff');
        $this->assertStringContainsString('SCHEMA_UNSUPPORTED', (string) $fresh->last_error);
        $this->assertSame('refused', EdgeInboundSaleIngestion::query()->where('sale_uuid', $env['sale_uuid'])->value('status'));
        $this->assertSame(0, SalesOrder::on('tenant')->count());

        // During the backoff the row is not leasable (other rows keep flowing).
        $this->assertSame('idle', $sender->sendNext('worker-1'));
        // Bounded: the delay doubles per attempt and caps at the configured maximum.
        $this->assertSame(120, EdgeSyncSender::schemaRetryDelaySeconds(2));
        $this->assertSame(900, EdgeSyncSender::schemaRetryDelaySeconds(50));

        // The Cloud is upgraded; the backoff elapses; the SAME immutable row is re-sent and applies exactly once.
        $cloud = EdgeInboundSaleIngestionService::class;
        DB::connection('tenant')->table('edge_sync_outbox')->where('id', $row->id)->update(['lease_expires_at' => now()->subSecond()]);
        $this->assertSame('acknowledged', $sender->sendNext('worker-1'));
        $acked = $row->fresh();
        $this->assertSame('acknowledged', $acked->state);
        $this->assertSame($env['content_hash'], $acked->content_hash, 'the envelope bytes never changed');
        $this->assertSame(2, (int) $acked->attempts);
        $this->assertSame(1, SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count(), 'exactly one official sale');
        $this->assertSame('applied', EdgeInboundSaleIngestion::query()->where('sale_uuid', $env['sale_uuid'])->value('status'));
        $this->assertSame(1, $this->cheeseLedgers());

        // A later duplicate delivery of the same envelope converges (already_applied) — still one sale.
        $this->assertSame('already_applied', app(EdgeInboundSaleIngestionService::class)->ingest($env)['status']);
        $this->assertSame(1, SalesOrder::on('tenant')->count());
    }
}
