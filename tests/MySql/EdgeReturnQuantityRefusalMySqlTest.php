<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalReturnService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * CANONICAL REHEARSAL Phase E, owner item R-3 (30 Oct 2026) — the W2-B return-quantity rules now live in
 * SalesReturnService::computeReturn (canonical 0831cd41, moved there in rehearsal chunk 3), so the Edge till REFUSES what
 * the Online form refuses, on the SERVER (EdgeLocalReturnService → the shared arithmetic → ValidationException), never
 * only in the page:
 *   - a valid quantity posts once and moves the till / operational stock / outbox exactly once;
 *   - more than is still returnable is refused with what is left, in the line's unit ("only N … can still be returned");
 *   - a partially returned line is measured against what is LEFT, not the original quantity (BUG-011 kept);
 *   - a closed line / a fully returned bill refuses a repeat;
 *   - a double submit of the same return (the page re-posting; an Online-style submission_key is ignored by the till) posts once;
 *   - a counted item comes back whole, a weighed item takes parts;
 *   - a refusal leaves the return accounting untouched: no sales_returns row, no return lines, no outbox event, the shift's
 *     expected cash / refunds, operational stock and returned_quantity exactly as before, the sale still `paid`.
 * The Cloud-side twins of these rules are canonical QuantityRulesMySqlTest + SalesReturnPostedOnceMySqlTest; the Cloud's
 * handling of a refused Edge return EVENT (replay / idempotency) is EdgeReturnIngestionRefusalReplayMySqlTest.
 */
class EdgeReturnQuantityRefusalMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $zingerId;   // counted (no unit → whole numbers)
    private int $muttonId;   // weighed (kg → parts allowed)
    private int $cashMethodId;
    private int $baselineId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), [
            'host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
            'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions', 'manager_approvals', 'terminal_user', 'branch_user',
            'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'units', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'sales_return_approval_mode' => 'auto_approve']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RQ' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $kg = DB::connection('tenant')->table('units')->insertGetId(['code' => 'kg', 'name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $this->makeCategory();
        $this->zingerId = $this->makeProduct($cat, ['name' => 'Zinger', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->muttonId = $this->makeProduct($cat, ['name' => 'Mutton', 'unit_id' => $kg, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 200]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->baselineId = (int) $this->acceptTestBaseline([
            ['product_id' => $this->zingerId, 'product_variant_id' => null, 'quantity' => 30],
            ['product_id' => $this->muttonId, 'product_variant_id' => null, 'quantity' => 50],
        ])->id;
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        foreach (['tenant.sales-returns.store', 'tenant.sales-returns.index', 'tenant.sales-returns.show'] as $p) {
            $this->grantEdgePermission($this->userId, $p);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500])->assertStatus(201);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    /** A paid cash sale of the given [product_id => quantity] at catalog prices. */
    private function cashSale(array $items): int
    {
        $lines = [];
        $total = 0.0;
        foreach ($items as $pid => $qty) {
            $lines[] = ['product_id' => $pid, 'quantity' => $qty];
            $total += $qty * ($pid === $this->zingerId ? 100 : 200);
        }

        return (int) $this->postJson('/edge/local/pos/sales', [
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => $lines, 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => $total]],
        ])->assertStatus(201)->json('sale_id');
    }

    private function lineId(int $saleId, int $productId): int
    {
        return (int) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->where('product_id', $productId)->value('id');
    }

    private function giveBack(int $saleId, array $lines, float $refund, array $extra = [])
    {
        return $this->postJson('/edge/local/pos/returns', ['sales_order_id' => $saleId, 'refund_method' => 'cash', 'refund_amount' => $refund, 'reason' => 'brought back', 'lines' => $lines] + $extra);
    }

    /** Everything a return moves on the appliance — the "accounting" a refusal must leave alone. */
    private function state(int $saleId): array
    {
        $c = DB::connection('tenant');
        $shift = $c->table('shifts')->where('status', 'open')->first();

        return [
            'returns' => $c->table('sales_returns')->count(),
            'return_lines' => $c->table('sales_return_lines')->count(),
            'outbox_returns' => $c->table('edge_sync_outbox')->where('envelope_schema_version', 'like', 'edge-return-envelope-%')->count(),
            'expected_cash' => (float) $shift->expected_cash,
            'cash_refunds' => (float) $shift->total_cash_refunds,
            'zinger_on_hand' => $this->edgeOnHand($this->baselineId, $this->zingerId),
            'mutton_on_hand' => $this->edgeOnHand($this->baselineId, $this->muttonId),
            'returned' => $c->table('sales_order_lines')->where('sales_order_id', $saleId)->orderBy('id')->pluck('returned_quantity')->map(fn ($q) => (float) $q)->all(),
            'sale_status' => (string) $c->table('sales_orders')->where('id', $saleId)->value('status'),
        ];
    }

    private function assertRefused($res, string $says, array $before, int $saleId): void
    {
        $this->assertSame(422, $res->getStatusCode(), $res->getContent());
        $this->assertStringContainsString($says, (string) $res->json('message'));
        $this->assertSame((string) $res->json('message'), (string) $res->json('errors.lines.0'), 'the refusal is the shared arithmetic\'s own words, keyed like the Online form');
        $this->assertSame($before, $this->state($saleId), 'a refused return moves nothing: no row, no event, no till, no stock, no returned_quantity');
    }

    public function test_a_valid_quantity_posts_once_and_moves_the_till_stock_and_outbox_exactly_once(): void
    {
        $saleId = $this->cashSale([$this->zingerId => 3]);
        $line = $this->lineId($saleId, $this->zingerId);
        $before = $this->state($saleId);
        $this->assertSame(3.0, (float) $this->getJson("/edge/local/pos/returns/sales/{$saleId}")->assertOk()->json('lines.0.returnable'));

        $r = $this->giveBack($saleId, [['sales_order_line_id' => $line, 'quantity' => 1]], 100)->assertStatus(201)->json('return');
        $this->assertSame(100.0, (float) $r['refund_amount']);
        $after = $this->state($saleId);
        $this->assertSame($before['returns'] + 1, $after['returns']);
        $this->assertSame($before['return_lines'] + 1, $after['return_lines']);
        $this->assertSame($before['outbox_returns'] + 1, $after['outbox_returns'], 'exactly one return event queued');
        $this->assertSame($before['expected_cash'] - 100.0, $after['expected_cash'], 'the till moved once');
        $this->assertSame($before['cash_refunds'] + 100.0, $after['cash_refunds']);
        $this->assertSame($before['zinger_on_hand'] + 1, $after['zinger_on_hand'], 'operational stock back once');
        $this->assertSame([1.0], $after['returned']);
        $this->assertSame(2.0, (float) $this->getJson("/edge/local/pos/returns/sales/{$saleId}")->assertOk()->json('lines.0.returnable'), 'what is left shrinks at once');
    }

    public function test_more_than_was_sold_is_refused_by_the_server_and_nothing_moves(): void
    {
        $saleId = $this->cashSale([$this->zingerId => 3]);
        $line = $this->lineId($saleId, $this->zingerId);
        $before = $this->state($saleId);

        $this->assertRefused($this->giveBack($saleId, [['sales_order_line_id' => $line, 'quantity' => 5]], 500), 'Zinger: only 3 can still be returned, not 5.', $before, $saleId);
        // Capping silently (the 0.8.2 behaviour, BUG-011) is gone: NOTHING was posted for the 3 that were returnable either.
        $this->assertSame(0, DB::connection('tenant')->table('sales_returns')->count());
        $this->assertTrue((bool) $this->getJson("/edge/local/pos/returns/sales/{$saleId}")->assertOk()->json('can_return'), 'the bill stays returnable for a corrected quantity');
    }

    public function test_a_partially_returned_line_is_measured_against_what_is_left(): void
    {
        $saleId = $this->cashSale([$this->zingerId => 3]);
        $line = $this->lineId($saleId, $this->zingerId);
        $this->giveBack($saleId, [['sales_order_line_id' => $line, 'quantity' => 1]], 100)->assertStatus(201);
        $before = $this->state($saleId);

        $this->assertRefused($this->giveBack($saleId, [['sales_order_line_id' => $line, 'quantity' => 3]], 300), 'Zinger: only 2 can still be returned, not 3.', $before, $saleId);
        $this->giveBack($saleId, [['sales_order_line_id' => $line, 'quantity' => 2]], 200)->assertStatus(201);
        $after = $this->state($saleId);
        $this->assertSame([3.0], $after['returned']);
        $this->assertSame(2, $after['returns']);
        $this->assertSame($before['expected_cash'] - 200.0, $after['expected_cash']);
    }

    public function test_a_repeated_return_of_a_closed_line_is_refused(): void
    {
        $saleId = $this->cashSale([$this->zingerId => 2]);
        $line = $this->lineId($saleId, $this->zingerId);
        $this->giveBack($saleId, [['sales_order_line_id' => $line, 'quantity' => 2]], 200)->assertStatus(201);
        $before = $this->state($saleId);
        $this->assertSame([2.0], $before['returned']);

        $res = $this->giveBack($saleId, [['sales_order_line_id' => $line, 'quantity' => 1]], 100);
        $this->assertSame(422, $res->getStatusCode(), $res->getContent());
        $message = (string) $res->json('message');
        $this->assertTrue(
            str_contains($message, 'nothing is left to return') || str_contains($message, 'already fully returned'),
            "a closed bill refuses a repeat (service or loader): {$message}"
        );
        $this->assertSame($before, $this->state($saleId));
        $this->assertSame(1, DB::connection('tenant')->table('sales_returns')->count());
    }

    public function test_the_same_return_posted_twice_posts_once_and_the_online_submission_key_is_harmless_on_the_till(): void
    {
        $saleId = $this->cashSale([$this->zingerId => 3]);
        $line = $this->lineId($saleId, $this->zingerId);
        $before = $this->state($saleId);
        // The shared return screen posts an Online UB-06 submission_key; the till's API ignores the unknown field and the
        // ARITHMETIC is what stops the second post (nothing left) — the money and the stock move once.
        $payload = [['sales_order_line_id' => $line, 'quantity' => 3]];
        $key = \App\Support\SubmissionKey::fresh();
        $this->giveBack($saleId, $payload, 300, ['submission_key' => $key])->assertStatus(201);
        $second = $this->giveBack($saleId, $payload, 300, ['submission_key' => $key]);
        $this->assertSame(422, $second->getStatusCode(), $second->getContent());

        $after = $this->state($saleId);
        $this->assertSame($before['returns'] + 1, $after['returns'], 'ONE return');
        $this->assertSame($before['outbox_returns'] + 1, $after['outbox_returns'], 'ONE event');
        $this->assertSame($before['expected_cash'] - 300.0, $after['expected_cash'], 'the till moved ONCE');
        $this->assertSame($before['zinger_on_hand'] + 3, $after['zinger_on_hand'], 'stock back ONCE');
        $this->assertSame([3.0], $after['returned']);
    }

    public function test_a_counted_item_comes_back_whole_and_a_weighed_item_takes_parts(): void
    {
        $saleId = $this->cashSale([$this->zingerId => 3, $this->muttonId => 2]);
        $zinger = $this->lineId($saleId, $this->zingerId);
        $mutton = $this->lineId($saleId, $this->muttonId);
        $before = $this->state($saleId);

        $this->assertRefused($this->giveBack($saleId, [['sales_order_line_id' => $zinger, 'quantity' => 0.5]], 50),
            'Zinger comes back in whole numbers — 0.5 is not a whole number (or return all 3 still left).', $before, $saleId);
        $this->assertRefused($this->giveBack($saleId, [['sales_order_line_id' => $mutton, 'quantity' => 2.5]], 500),
            'Mutton: only 2 Kilogram can still be returned, not 2.5.', $before, $saleId);

        $this->giveBack($saleId, [['sales_order_line_id' => $mutton, 'quantity' => 0.5]], 100)->assertStatus(201);
        $after = $this->state($saleId);
        $this->assertSame([0.0, 0.5], $after['returned'], 'half a kilo comes back');
        $this->assertSame($before['mutton_on_hand'] + 0.5, $after['mutton_on_hand']);
        $this->assertSame($before['expected_cash'] - 100.0, $after['expected_cash']);
    }

    public function test_the_refusal_is_the_service_not_the_page(): void
    {
        $saleId = $this->cashSale([$this->zingerId => 3]);
        $line = $this->lineId($saleId, $this->zingerId);
        $before = $this->state($saleId);
        $user = User::on('tenant')->find($this->userId);
        try {
            app(EdgeLocalReturnService::class)->processReturn($saleId, [['sales_order_line_id' => $line, 'quantity' => 4]], 'direct', 'cash', 400.0, $user, $this->terminalId);
            $this->fail('the service must refuse an over-return without any HTTP layer');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Zinger: only 3 can still be returned, not 4.', (string) ($e->errors()['lines'][0] ?? ''));
        }
        $this->assertSame($before, $this->state($saleId));
    }
}
