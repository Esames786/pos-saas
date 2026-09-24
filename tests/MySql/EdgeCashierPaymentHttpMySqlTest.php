<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W2 (Team 2) — the Online paymentModal on the Branch Server, over REAL HTTP (branch_server-booted app):
 *
 *  A20  the page lists the branch's payment methods the Online way (cash first): CASH is taken; card = accepted Cloud-only;
 *       bank transfer / cheque = owner decision — present, disabled, labelled; the server still refuses every non-cash tender;
 *       the Reference field is stored on the payment and in the envelope; change = tendered − total; short tender refused.
 *  A22  (Team 5 entry) the Online Direct Pay print intents are accepted + validated like SalesOrderController and persisted as the
 *       shared DirectPayPrintOrchestrator state; the response tells the page which intents were chosen.
 *  A16  Preview Bill answers the promo the way Online's promotions/quote does (valid + name / invalid + the Online message).
 *  A17  a tip is QUOTED (shared totals) but a paid Branch Server sale with a tip is refused with a business message until the
 *       sync contract carries tips (the envelope refuses them today).
 *  A12  the customer lookup (the modal's search) answers from the synced book with saved addresses, default first.
 */
class EdgeCashierPaymentHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $cashId;
    private int $cardId;
    private int $bankId;
    private int $productId;
    private int $customerId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'kot_batch_lines', 'kot_batches', 'print_jobs',
            'manager_approvals', 'model_has_permissions', 'permissions', 'promotion_targets', 'promotions', 'customer_addresses', 'customers',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'combo_components', 'combos', 'products', 'categories',
            'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'manual_discount_approval_mode' => 'manager_required']);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'default_terminal_id' => $this->terminalId, 'employee_code' => 'PAY' . Str::random(4)]);
        $cat = $this->makeCategory(['name' => 'Grills', 'is_active' => 1]);
        $this->productId = $this->makeProduct($cat, ['name' => 'Chicken Tikka', 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 250]);
        $this->cashId = $this->makePaymentMethod(['code' => 'CASH', 'name' => 'Cash', 'method_type' => 'cash']);
        $this->cardId = $this->makePaymentMethod(['code' => 'CARD', 'name' => 'Card', 'method_type' => 'card']);
        $this->bankId = $this->makePaymentMethod(['code' => 'BANK', 'name' => 'Bank Transfer', 'method_type' => 'bank_transfer']);
        DB::connection('tenant')->table('promotions')->insert(['branch_id' => null, 'name' => 'Save Ten', 'code' => 'SAVE10', 'promotion_type' => 'order', 'discount_type' => 'percent', 'discount_value' => 10, 'min_order_amount' => 0, 'requires_code' => 1, 'used_count' => 0, 'status' => 'active', 'priority' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->customerId = (int) DB::connection('tenant')->table('customers')->insertGetId(['customer_uuid' => (string) Str::ulid(), 'code' => 'CUST-001', 'name' => 'Ahmed Raza', 'phone' => '03001234567', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('customer_addresses')->insert([
            ['customer_id' => $this->customerId, 'label' => 'Office', 'address' => 'Suite 5, Liberty Plaza, Lahore', 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => $this->customerId, 'label' => 'Home', 'address' => 'House 12, Street 4, Gulberg III, Lahore', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function payload(array $extra = [], int $method = 0, float $amount = 250, ?float $tendered = 500): array
    {
        return array_merge([
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $method ?: $this->cashId, 'amount' => $amount, 'tendered_amount' => $tendered]],
        ], $extra);
    }

    public function test_the_payment_method_list_follows_online_and_only_cash_is_taken(): void
    {
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        preg_match('#<script id="edge-pos-data" type="application/json">(.*?)</script>#s', $html, $m);
        $vm = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        $methods = collect($vm['tenderMethods']);
        $this->assertSame('Cash', $methods->first()['name'], 'cash first, like the Online select');
        $this->assertTrue($methods->firstWhere('id', $this->cashId)['offline']);
        $card = $methods->firstWhere('id', $this->cardId);
        $bank = $methods->firstWhere('id', $this->bankId);
        $this->assertFalse($card['offline']);
        $this->assertSame('Card / provider payments run on the Online POS (accepted Cloud-only).', $card['hint']);
        $this->assertFalse($bank['offline']);
        $this->assertStringStartsWith('Awaiting owner decision', $bank['hint']);
        $this->assertFalse($vm['tipsSyncable']);
        foreach (['payment_method_id', 'tendered_amount', 'quick-cash-buttons', 'transaction_ref', 'change-view', 'short-tender-row', 'short-tender-message',
                  'discount-shortfall-btn', 'promo-code-input', 'apply-promo-btn', 'remove-promo-btn', 'promo-feedback', 'manual-discount-panel',
                  'manual-discount-type', 'manual-discount-value', 'apply-discount-btn', 'remove-discount-btn', 'manual-discount-feedback',
                  'tip-row', 'tip-view', 'subtotal-view', 'grand-total-view', 'complete-sale-btn', 'payment-bill-preview-btn'] as $id) {
            $this->assertTrue(str_contains($html, 'id="' . $id . '"') || str_contains($html, "'" . $id . "'"), "#{$id} is rendered by the payment modal script");
        }

        // The server refuses every non-cash tender (owner decision / accepted exclusion), whatever the page shows.
        $this->postJson('/edge/local/pos/sales', $this->payload([], $this->cardId, 250, 250))->assertStatus(422);
        $this->postJson('/edge/local/pos/sales', $this->payload([], $this->bankId, 250, 250))->assertStatus(422);
        $this->assertSame(0, DB::connection('tenant')->table('sales_orders')->count());

        // Cash with a reference: change = tendered − total; the reference rides the payment and the envelope.
        $sale = $this->postJson('/edge/local/pos/sales', $this->payload(['payments' => [['payment_method_id' => $this->cashId, 'amount' => 250, 'tendered_amount' => 500, 'transaction_ref' => 'DRAWER-7']]]))
            ->assertStatus(201)->json();
        $this->assertEquals(250.0, $sale['change_amount']);
        $pay = DB::connection('tenant')->table('sale_payments')->where('sales_order_id', $sale['sale_id'])->first();
        $this->assertSame('DRAWER-7', $pay->transaction_ref);
        $envelope = json_decode((string) DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $sale['sale_uuid'])->value('envelope'), true);
        $this->assertSame('DRAWER-7', data_get($envelope, 'payments.0.transaction_ref'));

        // Short tender is refused (Online: applied payments must cover the bill).
        $this->postJson('/edge/local/pos/sales', $this->payload([], 0, 200, 200))->assertStatus(422);
    }

    public function test_direct_pay_print_intents_are_validated_and_persisted_like_online(): void
    {
        $this->postJson('/edge/local/pos/sales', $this->payload(['kot_print_intent' => 'maybe', 'receipt_print_intent' => 'print']))->assertStatus(422);

        $sale = $this->postJson('/edge/local/pos/sales', $this->payload(['kot_print_intent' => 'skip', 'receipt_print_intent' => 'print']))->assertStatus(201)->json();
        $this->assertSame(['kot' => 'skip', 'receipt' => 'print'], $sale['print_intents']);
        $state = json_decode((string) DB::connection('tenant')->table('sales_orders')->where('id', $sale['sale_id'])->value('direct_pay_print_state'), true);
        $this->assertSame('skip', $state['kot_intent']);
        $this->assertSame('skipped', $state['kot_status']);
        $this->assertSame('print', $state['receipt_intent']);
        // initial state = DirectPayPrintOrchestrator::initialState (receipt pending); Team 5's afterPaidSale then advances it in the SAME request
        $this->assertContains($state['receipt_status'], ['pending', 'queued', 'printed', 'failed', 'ask', 'fallback'], 'receipt status comes from the shared orchestrator');
        $this->assertArrayHasKey('printing', $sale, 'the response carries the Direct Pay printing result for afterSalePrinting');

        // the intents are part of the idempotent intent (Online hashes them): a replay with the same intents replays…
        $p = $this->payload(['kot_print_intent' => 'print', 'receipt_print_intent' => 'skip']);
        $first = $this->postJson('/edge/local/pos/sales', $p)->assertStatus(201)->json();
        $this->assertSame($first['sale_id'], $this->postJson('/edge/local/pos/sales', $p)->assertStatus(201)->json('sale_id'));
        // …and a changed intent under the same client_uuid is a conflict, never a second sale.
        $this->postJson('/edge/local/pos/sales', array_merge($p, ['receipt_print_intent' => 'print']))->assertStatus(409);
    }

    public function test_preview_answers_the_promo_like_online_and_quotes_a_tip_that_a_paid_sale_cannot_carry_yet(): void
    {
        $lines = [['product_id' => $this->productId, 'quantity' => 2]];
        $bad = $this->postJson('/edge/local/pos/preview-bill', ['order_type' => 'takeaway', 'lines' => $lines, 'promo_code' => 'NOPE'])->assertOk()->json();
        $this->assertFalse($bad['promo']['valid']);
        $this->assertSame('Promo code is invalid, expired, or does not apply to this order.', $bad['promo']['message']);
        $this->assertEquals(500.0, (float) $bad['totals']['grand_total']);
        $good = $this->postJson('/edge/local/pos/preview-bill', ['order_type' => 'takeaway', 'lines' => $lines, 'promo_code' => 'SAVE10'])->assertOk()->json();
        $this->assertTrue($good['promo']['valid']);
        $this->assertSame('Save Ten', $good['promo']['promotion_name']);
        $this->assertEquals(50.0, $good['promo']['discount_amount']);
        $this->assertEquals(450.0, (float) $good['totals']['grand_total']);
        $this->assertNull($this->postJson('/edge/local/pos/preview-bill', ['order_type' => 'takeaway', 'lines' => $lines])->json('promo'));

        // A17: the tip is quoted on the shared totals (Online totals/quote carries tip_amount)…
        $tipped = $this->postJson('/edge/local/pos/preview-bill', ['order_type' => 'takeaway', 'lines' => $lines, 'tip_amount' => 25])->assertOk()->json('totals');
        $this->assertEquals(25.0, (float) $tipped['tip_amount']);
        $this->assertEquals(525.0, (float) $tipped['grand_total']);
        // …but a paid sale with a tip is refused before any mutation (the envelope refuses tips; W6 contract requirement).
        $this->postJson('/edge/local/pos/sales', $this->payload(['tip_amount' => 25], 0, 275, 300))->assertStatus(422)
            ->assertJsonFragment(['message' => 'A tip cannot be recorded on a Branch Server sale until the Cloud sync contract carries tips — remove the tip to complete this sale.']);
        $this->postJson('/edge/local/pos/sales', $this->payload(['tip_amount' => -1]))->assertStatus(422);
        $this->assertSame(0, DB::connection('tenant')->table('sales_orders')->count());
        $this->assertSame(0, DB::connection('tenant')->table('edge_sync_outbox')->count());
    }

    public function test_customer_lookup_answers_from_the_synced_book_with_saved_addresses(): void
    {
        $this->assertSame([], $this->getJson('/edge/local/pos/customers?q=A')->assertOk()->json('customers'));
        $r = $this->getJson('/edge/local/pos/customers?q=0300123')->assertOk()->json('customers');
        $this->assertCount(1, $r);
        $this->assertSame('Ahmed Raza', $r[0]['name']);
        $this->assertSame(['Home', 'Office'], array_column($r[0]['addresses'], 'label'), 'the default address first (Online preselects it)');
        $this->assertTrue($r[0]['addresses'][0]['is_default']);
        $this->assertCount(1, $this->getJson('/edge/local/pos/customers?q=ahmed')->assertOk()->json('customers'));
        // Team 1's ?customer_id= deep link: exact id lookup
        $byId = $this->getJson('/edge/local/pos/customers?id=' . $this->customerId)->assertOk()->json('customers');
        $this->assertSame([$this->customerId], array_column($byId, 'id'));
        $this->assertSame([], $this->getJson('/edge/local/pos/customers?id=999999')->assertOk()->json('customers'));
    }
}
