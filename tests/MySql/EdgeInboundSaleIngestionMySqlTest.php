<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\EdgeInboundSaleIngestion;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeInboundSaleIngestionService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/** §Q forced-failure atomicity: the container auto-wires this subclass; only the post-stock seam differs. */
class FailAfterStockIngestionService extends EdgeInboundSaleIngestionService
{
    protected function afterOfficialStock(): void
    {
        throw new RuntimeException('INJECTED_POST_STOCK_FAILURE');
    }
}

/**
 * §7 sanctioned test double: a JournalPostingService that posts GL normally (real parent) but SILENTLY omits
 * a required mapped cash-bank movement — simulating the shared report-and-swallow contract. The production
 * verifier must detect the MISSING durable evidence; it does not depend on this double throwing.
 */
class OmitCashBankJournalPostingService extends \App\Services\Finance\JournalPostingService
{
    public static bool $omitAll = false;
    public static bool $omitLast = false;

    public function postSalesCashBankMovement(\App\Models\Tenant\SalesOrder $sale, ?int $userId = null): void
    {
        if (self::$omitAll) {
            return; // simulate the whole cash-bank step swallowing an internal error
        }
        parent::postSalesCashBankMovement($sale, $userId);
        if (self::$omitLast) {
            // simulate the LAST mapped payment's movement failing to durably land (a partial finance state)
            $lastPaymentId = $sale->payments()->orderByDesc('id')->value('id');
            DB::connection('tenant')->table('cash_bank_account_transactions')
                ->where('reference_type', 'sale_payment')->where('reference_id', $lastPaymentId)->delete();
        }
    }
}

/**
 * OFFLINE-SYNC-ENGINE-1C — Cloud-authoritative ingestion of ONE immutable Edge paid-sale envelope, proven
 * against the REAL Cloud authorities (InventoryService FEFO, JournalPostingService GL + cash-bank,
 * RecipeConsumptionService) with a real master EdgeDevice + activation epoch and a branch handed to its
 * Branch Server. Covers §Q: one official sale, official FEFO/recipe/COGS, non-stock, GL balanced, cash
 * effect, replay/conflict idempotency, every authority guard, customer identity, atomic rollback, and
 * two-process concurrency. NEVER calls SalesService::finalizePaidSale.
 */
class EdgeInboundSaleIngestionMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const TENANT_ID = 7777;
    private const DEVICE_UUID = 'edge-device-ingest';
    private const EPOCH = 3;

    private int $branchId;
    private int $productId;      // stock-tracked
    private int $serviceId;      // non-stock (consumption 'none')
    private int $recipeProductId;
    private int $rawId;          // recipe ingredient (stock-tracked)
    private int $userId;
    private int $terminalId;
    private int $cashMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'edge_inbound_sale_ingestions',
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'accounts', 'cash_bank_accounts',
            'recipe_consumptions', 'recipe_ingredients', 'recipes',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders',
            'customers', 'payment_methods', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);
        (new DefaultChartOfAccountsSeeder())->run();

        // A branch HANDED to its Branch Server (Local Mode active) — the fenced case ingestion must pass.
        $this->branchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $cat = $this->makeCategory();
        $this->productId = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 100]);
        $this->serviceId = $this->makeProduct($cat, ['product_type' => 'service', 'product_kind' => 'service', 'inventory_consumption_method' => 'none', 'is_stock_tracked' => 0, 'default_selling_price' => 50]);
        $this->recipeProductId = $this->makeProduct($cat, ['inventory_consumption_method' => 'recipe', 'is_stock_tracked' => 0, 'default_selling_price' => 200]);
        $this->rawId = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 0]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);

        $this->seedStock($this->productId, 50, 40);
        $this->seedStock($this->rawId, 1000, 2);
        $this->seedRecipe();
        $this->seedCashBankMapping();

        // Master-side device + activation generation for this tenant+branch.
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
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function seedStock(int $productId, float $qty, float $cost): void
    {
        $conn = DB::connection('tenant');
        $batchId = $conn->table('inventory_batches')->insertGetId([
            'batch_key' => "b-{$this->branchId}-{$productId}", 'branch_id' => $this->branchId, 'product_id' => $productId,
            'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => $cost, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('stock_balances')->insert([
            'balance_key' => "{$this->branchId}-{$productId}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $productId,
            'inventory_batch_id' => $batchId, 'quantity_on_hand' => $qty, 'average_cost' => $cost, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedRecipe(): void
    {
        $conn = DB::connection('tenant');
        $recipeId = $conn->table('recipes')->insertGetId([
            'product_id' => $this->recipeProductId, 'name' => 'R', 'yield_quantity' => 1,
            'yield_unit_id' => $conn->table('products')->where('id', $this->recipeProductId)->value('unit_id'),
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('recipe_ingredients')->insert([
            'recipe_id' => $recipeId, 'product_id' => $this->rawId,
            'unit_id' => $conn->table('products')->where('id', $this->rawId)->value('unit_id'),
            'quantity' => 5, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
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

    // ── envelope builder (mirrors EdgeSaleEnvelopeBuilder's shape + hash) ────────

    private function envelope(array $overrides = [], array $lineOverrides = null, array $paymentOverrides = null): array
    {
        $lines = $lineOverrides ?? [[
            'line_uuid' => (string) Str::ulid(), 'line_kind' => 'standard', 'product_id' => $this->productId,
            'product_variant_id' => null, 'combo_id' => null, 'product_name' => 'Widget',
            'quantity' => 2.0, 'unit_price' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0,
            'line_total' => 200.0, 'modifiers' => [],
        ]];
        $payments = $paymentOverrides ?? [[
            'payment_uuid' => (string) Str::ulid(), 'payment_method_id' => $this->cashMethodId, 'method_type' => 'cash',
            'amount' => 200.0, 'tendered_amount' => 200.0, 'change_amount' => 0.0, 'transaction_ref' => null, 'paid_at' => now()->toIso8601String(),
        ]];
        $grand = array_sum(array_map(fn ($l) => (float) $l['line_total'], $lines));

        $env = array_merge([
            'envelope_schema_version' => 'edge-sale-envelope-v1',
            'tenant_id' => self::TENANT_ID, 'tenant_code' => 'ingesttenant', 'branch_id' => $this->branchId,
            'device_public_uuid' => self::DEVICE_UUID, 'activation_epoch' => self::EPOCH,
            'config_revision' => 5, 'config_schema_version' => 'edge-config-v1',
            'sale_uuid' => (string) Str::ulid(), 'sale_no' => 'SO-EDGE-' . Str::random(6),
            'client_uuid' => (string) Str::uuid(), 'business_date' => now()->toDateString(),
            'sale_date' => now()->toIso8601String(), 'completed_at' => now()->toIso8601String(), 'created_at' => now()->toIso8601String(),
            'order_type' => 'takeaway', 'order_source' => 'pos', 'vehicle_number' => null,
            'terminal_id' => $this->terminalId, 'terminal_code' => 'T1', 'user_id' => $this->userId, 'employee_code' => 'E1',
            'restaurant_waiter_id' => null,
            'shift' => ['shift_uuid' => (string) Str::ulid(), 'business_date' => now()->toDateString(), 'opened_at' => now()->toIso8601String(), 'terminal_id' => $this->terminalId, 'opened_by_user_id' => $this->userId],
            'table_session' => null, 'kot_events' => [], 'customer' => ['kind' => 'walk_in', 'name' => null, 'phone' => null],
            'totals' => ['subtotal' => $grand, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'service_charge_amount' => 0.0, 'tip_amount' => 0.0, 'grand_total' => $grand, 'paid_amount' => $grand, 'change_amount' => 0.0],
            'lines' => $lines, 'payments' => $payments,
            'operational_stock' => ['posted' => true, 'baseline_uuid' => (string) Str::ulid()],
            'local_state' => ['edge_sync_state' => 'pending', 'edge_activation_epoch' => self::EPOCH, 'inventory_posted' => false, 'is_draft' => false],
        ], $overrides);

        return $this->hashed($env);
    }

    private function hashed(array $env): array
    {
        unset($env['content_hash']);
        $env['content_hash'] = hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($env));

        return $env;
    }

    private function ingest(array $env): array
    {
        return app(EdgeInboundSaleIngestionService::class)->ingest($env);
    }

    // ── §Q happy path ────────────────────────────────────────────────────────────

    public function test_a_valid_envelope_becomes_exactly_one_official_cloud_sale_with_fefo_cogs_gl_and_cash(): void
    {
        $env = $this->envelope();
        $ack = $this->ingest($env);

        $this->assertSame('applied', $ack['status']);
        $this->assertSame($env['sale_uuid'], $ack['sale_uuid']);
        $this->assertNotEmpty($ack['ingestion_uuid']);
        $this->assertNotEmpty($ack['official_sale_no']);

        // Exactly one official Cloud sale, keyed by the preserved canonical identity, NOT the Edge sale_no.
        $sale = SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->firstOrFail();
        $this->assertSame(1, SalesOrder::on('tenant')->count());
        $this->assertSame('paid', $sale->status);
        $this->assertTrue((bool) $sale->inventory_posted, 'official inventory posted');
        $this->assertSame('synced', $sale->edge_sync_state);
        $this->assertSame($ack['official_sale_no'], $sale->sale_no);
        $this->assertNotSame($env['sale_no'], $sale->sale_no, 'the Cloud assigns its OWN official sale number');
        $this->assertSame($env['lines'][0]['line_uuid'], $sale->lines()->first()->line_uuid, 'canonical line identity preserved');
        $this->assertSame($env['payments'][0]['payment_uuid'], $sale->payments()->first()->payment_uuid, 'canonical payment identity preserved');

        // Official FEFO once: 2 units out at cost 40 -> on-hand 48, COGS 80 on the line.
        $this->assertSame(48.0, (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand'));
        $this->assertSame(1, DB::connection('tenant')->table('stock_ledgers')->where('reference_id', $sale->id)->where('movement_type', 'sale')->count());
        $this->assertSame(80.0, (float) $sale->lines()->first()->cost_total);

        // GL balanced (debits == credits) for this sale.
        $entryId = DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->where('source_id', $sale->id)->value('id');
        $this->assertNotNull($entryId, 'a GL entry was posted');
        $sum = DB::connection('tenant')->table('journal_lines')->where('journal_entry_id', $entryId)
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
        $this->assertEqualsWithDelta((float) $sum->d, (float) $sum->c, 0.001, 'GL debits equal credits');
        $this->assertGreaterThan(0, (float) $sum->d);

        // Cash effect once: the till balance moved by the applied cash.
        $this->assertSame(1, DB::connection('tenant')->table('cash_bank_account_transactions')->where('reference_type', 'sale_payment')->count());
        $this->assertSame(200.0, (float) DB::connection('tenant')->table('cash_bank_accounts')->sum('current_balance'));
    }

    public function test_a_recipe_line_consumes_ingredients_and_a_non_stock_line_moves_no_stock(): void
    {
        $env = $this->envelope(['order_type' => 'takeaway'], [
            ['line_uuid' => (string) Str::ulid(), 'line_kind' => 'standard', 'product_id' => $this->recipeProductId, 'product_variant_id' => null, 'combo_id' => null, 'product_name' => 'Dish', 'quantity' => 1.0, 'unit_price' => 200.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => 200.0, 'modifiers' => []],
            ['line_uuid' => (string) Str::ulid(), 'line_kind' => 'standard', 'product_id' => $this->serviceId, 'product_variant_id' => null, 'combo_id' => null, 'product_name' => 'Service', 'quantity' => 1.0, 'unit_price' => 50.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => 50.0, 'modifiers' => []],
        ], [
            ['payment_uuid' => (string) Str::ulid(), 'payment_method_id' => $this->cashMethodId, 'method_type' => 'cash', 'amount' => 250.0, 'tendered_amount' => 250.0, 'change_amount' => 0.0, 'transaction_ref' => null, 'paid_at' => now()->toIso8601String()],
        ]);
        $ack = $this->ingest($env);
        $this->assertSame('applied', $ack['status'], json_encode($ack));

        $sale = SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->firstOrFail();
        // Recipe consumed 5 raw units (recipe qty 5 × 1): raw on-hand 1000 -> 995; a recipe_consumptions row exists.
        $this->assertSame(995.0, (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $this->rawId)->sum('quantity_on_hand'));
        $this->assertGreaterThan(0, DB::connection('tenant')->table('recipe_consumptions')->where('sales_order_id', $sale->id)->count());
        // The non-stock service line never moved stock.
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->where('product_id', $this->serviceId)->count());
    }

    // ── §Q idempotency + conflict ────────────────────────────────────────────────

    public function test_same_uuid_same_hash_replay_returns_the_same_result_with_zero_further_effects(): void
    {
        $env = $this->envelope();
        $ack1 = $this->ingest($env);
        $onHand = DB::connection('tenant')->table('stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand');

        $ack2 = $this->ingest($env);
        // Same stored result (JSON round-trip may reorder keys — compare the identity fields that matter).
        $this->assertSame('applied', $ack2['status']);
        foreach (['sale_uuid', 'ingestion_uuid', 'sales_order_id', 'official_sale_no'] as $k) {
            $this->assertSame($ack1[$k], $ack2[$k], "replay must return the same $k");
        }
        $this->assertSame(1, SalesOrder::on('tenant')->count(), 'no second sale');
        $this->assertSame(1, DB::connection('tenant')->table('stock_ledgers')->count(), 'no second stock movement');
        $this->assertSame($onHand, DB::connection('tenant')->table('stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand'));
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count(), 'no second GL entry');
        $this->assertSame(1, DB::connection('tenant')->table('cash_bank_account_transactions')->count(), 'no second cash effect');
    }

    public function test_same_uuid_different_hash_is_a_hard_conflict_with_zero_mutation(): void
    {
        $env = $this->envelope();
        $this->ingest($env);

        $tampered = $env;
        $tampered['totals']['grand_total'] = 999.0; // different content, same sale_uuid
        $tampered = $this->hashed($tampered);
        $ack = $this->ingest($tampered);

        $this->assertSame('conflict', $ack['status']);
        $this->assertSame('ENVELOPE_CONFLICT', $ack['failure_code']);
        $this->assertSame(1, SalesOrder::on('tenant')->count(), 'the first accepted truth is never overwritten or duplicated');
        $this->assertSame('applied', EdgeInboundSaleIngestion::query()->where('sale_uuid', $env['sale_uuid'])->value('status'));
    }

    // ── §Q authority guards ──────────────────────────────────────────────────────

    public function test_authority_guards_refuse_bad_envelopes_with_no_mutation(): void
    {
        $cases = [
            'WRONG_TENANT' => fn ($e) => tap($e, fn (&$x) => $x['tenant_id'] = 4242),
            'WRONG_BRANCH' => fn ($e) => tap($e, fn (&$x) => $x['branch_id'] = 999999),
            'DEVICE_UNKNOWN' => fn ($e) => tap($e, fn (&$x) => $x['device_public_uuid'] = 'no-such-device'),
            'STALE_ACTIVATION' => fn ($e) => tap($e, fn (&$x) => $x['activation_epoch'] = self::EPOCH - 1),
            'SCHEMA_UNSUPPORTED' => fn ($e) => tap($e, fn (&$x) => $x['envelope_schema_version'] = 'edge-sale-envelope-v9'),
            'ORDER_TYPE_UNSUPPORTED' => fn ($e) => tap($e, fn (&$x) => $x['order_type'] = 'delivery'),
            'PAYMENT_UNSUPPORTED' => fn ($e) => tap($e, fn (&$x) => $x['payments'][0]['method_type'] = 'card'),
        ];
        foreach ($cases as $expected => $mutate) {
            $env = $this->hashed($mutate($this->envelope()));
            $ack = $this->ingest($env);
            $this->assertSame('refused', $ack['status'], "$expected should refuse");
            $this->assertSame($expected, $ack['failure_code'], "wrong code for $expected");
        }
        // A tampered hash (bytes changed WITHOUT recompute) is refused.
        $bad = $this->envelope();
        $bad['totals']['grand_total'] = 123.0; // hash no longer matches
        $this->assertSame('HASH_INVALID', $this->ingest($bad)['failure_code']);

        // A revoked device is refused.
        DB::connection('master')->table('edge_devices')->where('public_uuid', self::DEVICE_UUID)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->assertSame('DEVICE_REVOKED', $this->ingest($this->envelope())['failure_code']);

        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'no refused envelope ever created a sale');
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count());
    }

    // ── §Q customer identity ─────────────────────────────────────────────────────

    public function test_customer_uuid_resolves_or_fails_closed_and_walk_in_needs_no_customer(): void
    {
        // walk-in already covered; here an ATTACHED customer by canonical uuid.
        $uuid = (string) Str::ulid();
        $customerId = DB::connection('tenant')->table('customers')->insertGetId(['customer_uuid' => $uuid, 'name' => 'Ayesha', 'created_at' => now(), 'updated_at' => now()]);
        $env = $this->envelope(['customer' => ['kind' => 'customer', 'customer_uuid' => $uuid, 'name' => 'Ayesha K', 'phone' => '0300']]);
        $ack = $this->ingest($env);
        $this->assertSame('applied', $ack['status']);
        $this->assertSame($customerId, (int) SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->value('customer_id'));

        // An unknown customer_uuid FAILS CLOSED — never bound by phone or a local id.
        $env2 = $this->envelope(['customer' => ['kind' => 'customer', 'customer_uuid' => (string) Str::ulid(), 'name' => 'Ghost', 'phone' => '0300']]);
        $ack2 = $this->ingest($env2);
        $this->assertSame('CUSTOMER_UNKNOWN', $ack2['failure_code']);
        $this->assertSame(1, SalesOrder::on('tenant')->count(), 'the failed-closed customer sale was not created');
    }

    // ── §Q insufficient stock + forced rollback ──────────────────────────────────

    public function test_insufficient_official_stock_with_negatives_disallowed_is_an_atomic_exception(): void
    {
        $env = $this->envelope(['order_type' => 'takeaway'], [
            ['line_uuid' => (string) Str::ulid(), 'line_kind' => 'standard', 'product_id' => $this->productId, 'product_variant_id' => null, 'combo_id' => null, 'product_name' => 'Widget', 'quantity' => 9999.0, 'unit_price' => 100.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'line_total' => 999900.0, 'modifiers' => []],
        ], [
            ['payment_uuid' => (string) Str::ulid(), 'payment_method_id' => $this->cashMethodId, 'method_type' => 'cash', 'amount' => 999900.0, 'tendered_amount' => 999900.0, 'change_amount' => 0.0, 'transaction_ref' => null, 'paid_at' => now()->toIso8601String()],
        ]);
        $ack = $this->ingest($env);

        $this->assertSame('exception', $ack['status']);
        $this->assertSame('INSUFFICIENT_STOCK', $ack['failure_code']);
        // No partial official state.
        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'no half-created sale');
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count(), 'no stock moved');
        $this->assertSame(50.0, (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand'));
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count(), 'no GL');
        $this->assertSame(0, DB::connection('tenant')->table('cash_bank_account_transactions')->count(), 'no cash effect');
    }

    public function test_a_failure_after_official_stock_but_before_gl_rolls_the_whole_ingestion_back(): void
    {
        $env = $this->envelope();
        try {
            app(FailAfterStockIngestionService::class)->ingest($env);
        } catch (\Throwable $e) {
            // the service records an exception ACK; a raw throw would also be a full rollback
        }
        $ack = EdgeInboundSaleIngestion::query()->where('sale_uuid', $env['sale_uuid'])->first();

        $this->assertNotNull($ack);
        $this->assertNotSame('applied', $ack->status, 'the registry must NOT claim success');
        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'sale rolled back');
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count(), 'official stock rolled back');
        $this->assertSame(50.0, (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand'));
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count());

        // Retry with the REAL service applies cleanly, exactly once.
        $ack2 = $this->ingest($env);
        $this->assertSame('applied', $ack2['status']);
        $this->assertSame(1, SalesOrder::on('tenant')->count());
    }

    // ── finance atomicity closure (§6/§7): APPLIED means financially complete ─────

    public function test_a_swallowed_gl_posting_failure_fails_the_ingestion_closed_and_rolls_everything_back(): void
    {
        // Remove the revenue account the takeaway paid-sale journal requires (4120). postPaidSale resolves it
        // via accountId(), which throws INSIDE postPaidSale's try/catch — the REAL swallow path — so no journal
        // is posted. The ingestion's finance verifier must then detect the missing GL and fail closed.
        DB::connection('tenant')->table('accounts')->where('code', '4120')->delete();

        $env = $this->envelope();
        $ack = $this->ingest($env);

        $this->assertSame('exception', $ack['status']);
        $this->assertSame('FINANCE_GL_MISSING', $ack['failure_code']);
        // FULL rollback: no sale, no official stock, no payments, no GL, no cash — registry NOT applied.
        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'sale rolled back');
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count(), 'FEFO rolled back');
        $this->assertSame(50.0, (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand'));
        $this->assertSame(0, DB::connection('tenant')->table('sale_payments')->count(), 'payments rolled back');
        $this->assertSame(0, DB::connection('tenant')->table('cash_bank_account_transactions')->count(), 'cash rolled back');
        $this->assertNotSame('applied', EdgeInboundSaleIngestion::query()->where('sale_uuid', $env['sale_uuid'])->value('status'));

        // Repair the chart of accounts and retry the SAME envelope: exactly one full official posting set.
        (new DefaultChartOfAccountsSeeder())->run();
        $ack2 = $this->ingest($env);
        $this->assertSame('applied', $ack2['status']);
        $this->assertSame(1, SalesOrder::on('tenant')->count());
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count());
        $this->assertSame(1, DB::connection('tenant')->table('cash_bank_account_transactions')->count());
    }

    public function test_a_swallowed_cash_bank_failure_fails_the_ingestion_closed_and_rolls_everything_back(): void
    {
        // The mapped cash method's movement is silently omitted (shared report-and-swallow, via the double).
        // The verifier must detect the missing durable cash-bank evidence and fail the ingestion closed.
        OmitCashBankJournalPostingService::$omitAll = true;
        $this->app->bind(\App\Services\Finance\JournalPostingService::class, OmitCashBankJournalPostingService::class);

        $env = $this->envelope();
        $ack = $this->ingest($env);

        $this->assertSame('exception', $ack['status']);
        $this->assertSame('FINANCE_CASHBANK_MISSING', $ack['failure_code']);
        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'sale rolled back');
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count(), 'FEFO rolled back');
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count(), 'GL rolled back too');
        $this->assertSame(0, DB::connection('tenant')->table('cash_bank_account_transactions')->count());

        // Restore the real finance authority and retry the SAME envelope: applied exactly once, cash present.
        OmitCashBankJournalPostingService::$omitAll = false;
        $this->app->forgetInstance(\App\Services\Finance\JournalPostingService::class);
        $this->app->bind(\App\Services\Finance\JournalPostingService::class, \App\Services\Finance\JournalPostingService::class);
        $ack2 = $this->ingest($env);
        $this->assertSame('applied', $ack2['status']);
        $this->assertSame(1, SalesOrder::on('tenant')->count());
        $this->assertSame(1, DB::connection('tenant')->table('cash_bank_account_transactions')->where('transaction_type', 'sales_payment')->count());
    }

    public function test_two_mapped_payments_with_the_second_movement_missing_rolls_the_entire_ingestion_back(): void
    {
        // Two mapped cash payments; the FIRST movement posts, the SECOND is silently omitted (the double drops
        // the last mapped payment's movement) — a PARTIAL finance state. The verifier must roll back the ENTIRE
        // ingestion, including the first (already-posted) cash movement and its balance effect.
        $cbId = DB::connection('tenant')->table('payment_methods')->where('id', $this->cashMethodId)->value('cash_bank_account_id');
        $method2 = $this->makePaymentMethod(['method_type' => 'cash', 'code' => 'CASH2', 'name' => 'Cash2', 'cash_bank_account_id' => $cbId]);
        OmitCashBankJournalPostingService::$omitLast = true;
        $this->app->bind(\App\Services\Finance\JournalPostingService::class, OmitCashBankJournalPostingService::class);

        $env = $this->envelope(['order_type' => 'takeaway'], null, [
            ['payment_uuid' => (string) Str::ulid(), 'payment_method_id' => $this->cashMethodId, 'method_type' => 'cash', 'amount' => 120.0, 'tendered_amount' => 120.0, 'change_amount' => 0.0, 'transaction_ref' => null, 'paid_at' => now()->toIso8601String()],
            ['payment_uuid' => (string) Str::ulid(), 'payment_method_id' => $method2, 'method_type' => 'cash', 'amount' => 80.0, 'tendered_amount' => 80.0, 'change_amount' => 0.0, 'transaction_ref' => null, 'paid_at' => now()->toIso8601String()],
        ]);
        $ack = $this->ingest($env);

        $this->assertSame('exception', $ack['status']);
        $this->assertSame('FINANCE_CASHBANK_MISSING', $ack['failure_code']);
        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'the whole sale rolled back');
        $this->assertSame(0, DB::connection('tenant')->table('cash_bank_account_transactions')->count(), 'the first (partial) cash movement rolled back too');
        $this->assertSame(0.0, (float) DB::connection('tenant')->table('cash_bank_accounts')->sum('current_balance'), 'the first cash balance effect rolled back');

        // Restore the real authority and retry SAME envelope -> applied once, both movements present.
        OmitCashBankJournalPostingService::$omitLast = false;
        $this->app->forgetInstance(\App\Services\Finance\JournalPostingService::class);
        $this->app->bind(\App\Services\Finance\JournalPostingService::class, \App\Services\Finance\JournalPostingService::class);
        $ack2 = $this->ingest($env);
        $this->assertSame('applied', $ack2['status']);
        $this->assertSame(2, DB::connection('tenant')->table('cash_bank_account_transactions')->where('transaction_type', 'sales_payment')->count(), 'both mapped payments posted exactly once');
    }

    // ── §Q two-process concurrency ───────────────────────────────────────────────

    public function test_two_concurrent_workers_same_uuid_same_hash_post_exactly_one_official_sale(): void
    {
        $env = $this->envelope();
        $file = sys_get_temp_dir() . '/edge_ingest_env_' . Str::random(8) . '.json';
        file_put_contents($file, json_encode($env));
        $start = sys_get_temp_dir() . '/edge_ingest_start_' . Str::random(8);
        @unlink($start);

        $a = $this->ingestWorker($file, $start);
        $b = $this->ingestWorker($file, $start);
        sleep(3);
        file_put_contents($start, '1');
        $outA = $this->finishWorker($a);
        $outB = $this->finishWorker($b);
        @unlink($file);
        @unlink($start);

        $this->assertStringStartsWith('OK:', $outA, "A: $outA");
        $this->assertStringStartsWith('OK:', $outB, "B: $outB");
        // Exactly one official posting set — both converge on the same sale/result.
        $this->assertSame(1, SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count(), 'exactly one official sale');
        $this->assertSame(1, DB::connection('tenant')->table('stock_ledgers')->where('movement_type', 'sale')->count(), 'FEFO posted once');
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order_paid')->count(), 'GL posted once');
        $this->assertSame(1, EdgeInboundSaleIngestion::query()->where('sale_uuid', $env['sale_uuid'])->count(), 'one registry row');
        $this->assertSame('applied', EdgeInboundSaleIngestion::query()->where('sale_uuid', $env['sale_uuid'])->value('status'));
    }

    public function test_two_concurrent_workers_same_uuid_different_hash_keep_one_truth(): void
    {
        // First establish an applied truth, then race TWO tampered variants (different content, same uuid).
        $env = $this->envelope();
        $this->ingest($env);

        $tamperedA = $this->hashed(tap($env, function (&$x) { $x['totals']['grand_total'] = 111.0; }));
        $tamperedB = $this->hashed(tap($env, function (&$x) { $x['totals']['grand_total'] = 222.0; }));
        $fileA = sys_get_temp_dir() . '/edge_ic_a_' . Str::random(8) . '.json';
        $fileB = sys_get_temp_dir() . '/edge_ic_b_' . Str::random(8) . '.json';
        file_put_contents($fileA, json_encode($tamperedA));
        file_put_contents($fileB, json_encode($tamperedB));
        $start = sys_get_temp_dir() . '/edge_ic_start_' . Str::random(8);
        @unlink($start);

        $a = $this->ingestWorker($fileA, $start);
        $b = $this->ingestWorker($fileB, $start);
        sleep(3);
        file_put_contents($start, '1');
        $outA = $this->finishWorker($a);
        $outB = $this->finishWorker($b);
        @unlink($fileA);
        @unlink($fileB);
        @unlink($start);

        // Both deterministically conflict against the one accepted truth; nothing new is posted.
        $this->assertStringContainsString('conflict', $outA, "A: $outA");
        $this->assertStringContainsString('conflict', $outB, "B: $outB");
        $this->assertSame(1, SalesOrder::on('tenant')->where('sale_uuid', $env['sale_uuid'])->count(), 'still exactly one official sale');
        $this->assertSame('applied', EdgeInboundSaleIngestion::query()->where('sale_uuid', $env['sale_uuid'])->value('status'), 'the first truth stands');
    }

    private function ingestWorker(string $envFile, string $startFile): array
    {
        $cmd = [PHP_BINARY, base_path('tests/MySql/Support/edge_ingest_worker.php'), $envFile];
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'EDGE_TEST_TENANT_DB' => $this->tenantDb, 'DB_DATABASE' => config('database.connections.master.database'), 'APP_ENV' => 'testing', 'START_FILE' => $startFile,
        ]));

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function finishWorker(array $h): string
    {
        $out = trim((string) stream_get_contents($h['pipes'][1]));
        $err = trim((string) stream_get_contents($h['pipes'][2]));
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        proc_close($h['proc']);

        return $out !== '' ? $out : 'STDERR:' . $err;
    }
}
