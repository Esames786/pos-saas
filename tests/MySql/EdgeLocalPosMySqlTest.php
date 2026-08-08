<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-POS-1 — the offline orchestrator through the REAL Edge runtime: a seeded edge_local_meta binding
 * (EdgeBranchContext authority), an authenticated local Tenant\User, an active bound terminal, and a real open
 * shift. Proves the hardened contract: authority from context (not request), required client_uuid + effective
 * intent replay/conflict, cash-only, no discount/promo/combo/dine_in, operational stock + provisional markers,
 * and NO GL/finance posting.
 */
class EdgeLocalPosMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productId;
    private int $cashMethodId;
    private int $baselineId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CASH' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime(); // EdgeBranchContext only binds on a branch_server
        // (H10/I) selling stock exists ONLY under an accepted Edge-only baseline — never official tables.
        $this->baselineId = (int) $this->acceptTestBaseline([
            ['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 10],
        ])->id;
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function openShift(): \App\Models\Tenant\Shift
    {
        return app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 0.0);
    }

    private function complete(array $overrides = [], ?int $terminalId = null): SalesOrder
    {
        $data = array_merge([
            'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]],
        ], $overrides);

        return app(EdgeLocalPosService::class)->completePaidSale($data, User::on('tenant')->find($this->userId), $terminalId ?? $this->terminalId);
    }

    private function onHand(): float
    {
        return $this->edgeOnHand($this->baselineId, $this->productId);
    }

    public function test_cash_sale_completes_operational_provisional_no_gl(): void
    {
        $shift = $this->openShift();
        $sale = $this->complete();

        $this->assertSame('paid', $sale->status);
        $this->assertSame('pending', $sale->edge_sync_state);
        $this->assertSame(1, (int) $sale->edge_activation_epoch);
        $this->assertTrue((bool) $sale->edge_operational_stock_posted);
        $this->assertFalse((bool) $sale->inventory_posted, 'official inventory_posted stays false offline');
        $this->assertStringStartsWith('SO-' . $this->branchId . '-' . $this->terminalId . '-', $sale->sale_no);
        // (E) ONE ULID: the display number's suffix IS the canonical sale identity.
        $this->assertSame('SO-' . $this->branchId . '-' . $this->terminalId . '-' . $sale->sale_uuid, $sale->sale_no, 'sale_no suffix must be the sale_uuid ULID');
        $this->assertTrue(Str::isUlid($sale->sale_uuid) && Str::isUlid($sale->lines()->first()->line_uuid) && Str::isUlid($sale->payments()->first()->payment_uuid));
        $this->assertSame(8.0, $this->onHand());
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, DB::connection('tenant')->table('cash_bank_account_transactions')->count());

        // (H10) Edge-ONLY stock: official tables are completely untouched by an offline sale.
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->count(), 'official stock_balances untouched');
        $this->assertSame(0, DB::connection('tenant')->table('stock_ledgers')->count(), 'official stock_ledgers untouched');
        $movement = DB::connection('tenant')->table('edge_operational_stock_movements')->where('sale_uuid', $sale->sale_uuid)->first();
        $this->assertNotNull($movement, 'edge movement row referenced by canonical sale_uuid');
        $this->assertSame($sale->lines()->first()->line_uuid, $movement->line_uuid, 'movement carries the canonical line_uuid');
        $this->assertSame(8.0, (float) $movement->balance_after);
        $this->assertTrue(Str::isUlid($movement->movement_uuid));
        $this->assertSame(1, (int) $movement->activation_epoch);

        // (G) SHARED operational settlement ran exactly once, in the sale transaction.
        $shift->refresh();
        $this->assertSame(200.0, (float) $shift->total_sales, 'shift total_sales incremented by the sale once');
        $this->assertSame(200.0, (float) $shift->total_cash, 'shift total_cash incremented by the applied cash');
        $this->assertSame(200.0, (float) $shift->expected_cash, 'expected_cash = opening 0 + applied cash');
        $this->assertGreaterThan(0, DB::connection('tenant')->table('sales_ledgers')->where('sales_order_id', $sale->id)->count(), 'operational sales subledger rows exist');
    }

    public function test_tendered_cash_records_payment_change_per_grounded_semantics(): void
    {
        $shift = $this->openShift();
        // grand_total = 200 (2 × 100); customer tenders 500 → amount=200 applied, change 300 on the payment row.
        $sale = $this->complete(['payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200, 'tendered_amount' => 500]]]);
        $payment = $sale->payments()->first();
        $this->assertSame(200.0, (float) $payment->amount, 'payment.amount = amount APPLIED to the invoice');
        $this->assertSame(500.0, (float) $payment->tendered_amount, 'tendered_amount = physical cash handed over');
        $this->assertSame(300.0, (float) $payment->change_amount, 'payment change = tendered - applied');
        $this->assertSame(200.0, (float) $sale->paid_amount);
        $this->assertSame(0.0, (float) $sale->change_amount, 'sale-level change 0 (applied == grand_total)');
        // shift cash counters use the APPLIED amount (net drawer cash), not the tendered amount.
        $shift->refresh();
        $this->assertSame(200.0, (float) $shift->expected_cash, 'expected_cash uses APPLIED cash, never tendered');
    }

    public function test_split_payments_are_refused_offline(): void
    {
        $this->openShift();
        $this->expectException(ValidationException::class);
        $this->complete(['payments' => [
            ['payment_method_id' => $this->cashMethodId, 'amount' => 100],
            ['payment_method_id' => $this->cashMethodId, 'amount' => 100],
        ]]);
    }

    public function test_missing_client_uuid_is_refused(): void
    {
        $this->openShift();
        $this->expectException(ValidationException::class);
        $this->complete(['client_uuid' => null]);
    }

    public function test_no_open_shift_is_refused(): void
    {
        // no openShift()
        $this->expectException(\App\Exceptions\ShiftException::class);
        $this->complete();
    }

    public function test_card_payment_is_refused_offline(): void
    {
        $this->openShift();
        $card = $this->makePaymentMethod(['method_type' => 'card']);
        $this->expectException(ValidationException::class);
        $this->complete(['payments' => [['payment_method_id' => $card, 'amount' => 200]]]);
    }

    public function test_dine_in_order_type_is_refused_this_sprint(): void
    {
        $this->openShift();
        User::on('tenant')->where('id', $this->userId)->update(['allowed_order_types' => json_encode(['dine_in', 'quick_sale'])]);
        $this->expectException(ValidationException::class);
        $this->complete(['order_type' => 'dine_in']);
    }

    public function test_discount_is_refused(): void
    {
        $this->openShift();
        $this->expectException(ValidationException::class);
        $this->complete(['discount_type' => 'fixed', 'discount_value' => 50]);
    }

    public function test_promo_is_refused(): void
    {
        $this->openShift();
        $this->expectException(ValidationException::class);
        $this->complete(['promo_code' => 'SAVE10']);
    }

    public function test_combo_line_is_refused(): void
    {
        $this->openShift();
        $this->expectException(ValidationException::class);
        $this->complete(['lines' => [['product_id' => $this->productId, 'quantity' => 1, 'line_kind' => 'combo_header']]]);
    }

    public function test_cross_branch_terminal_is_refused(): void
    {
        $this->openShift();
        $foreignTerminal = $this->makeTerminal($this->makeBranch());
        $this->expectException(ValidationException::class);
        $this->complete([], $foreignTerminal);
    }

    public function test_price_tamper_is_ignored_server_resolves_catalog(): void
    {
        $this->openShift();
        // attacker submits unit_price=0 on a standard line — server must use the catalog price (100).
        $sale = $this->complete(['lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 0]]]);
        $this->assertSame(100.0, (float) $sale->lines()->first()->unit_price, 'submitted unit_price=0 ignored; catalog price used');
    }

    public function test_idempotent_replay_and_conflict(): void
    {
        $shift = $this->openShift();
        $uuid = (string) Str::uuid();
        $first = $this->complete(['client_uuid' => $uuid]);
        $salesBefore = SalesOrder::on('tenant')->count();

        // same key + same effective intent → replay (same sale, no dup, no second stock move).
        $second = $this->complete(['client_uuid' => $uuid]);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($salesBefore, SalesOrder::on('tenant')->count());
        $this->assertSame(8.0, $this->onHand(), 'no second stock decrement on replay');
        // (G) settlement did NOT run a second time on replay.
        $shift->refresh();
        $this->assertSame(200.0, (float) $shift->total_sales, 'replay must not increment shift totals again');
        $this->assertSame(200.0, (float) $shift->expected_cash);

        // same key + different quantity → conflict.
        $this->expectException(\App\Exceptions\SaleIdempotencyConflictException::class);
        $this->complete(['client_uuid' => $uuid, 'lines' => [['product_id' => $this->productId, 'quantity' => 3]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 300]]]);
    }

    // ── REAL Edge local-auth integration: genuine credential → EdgeLocalAuthService → sale ──
    public function test_real_edge_credential_login_then_sale_attributed_to_that_cashier(): void
    {
        $this->openShift();
        // Drop the actingAs session — this test must authenticate through the REAL Edge path.
        auth('tenant')->logout();

        // Genuine Edge credential (Argon2id) bound to the CURRENT activation epoch — what enrollment produces.
        DB::connection('tenant')->table('edge_local_user_credentials')->insert([
            'user_id' => $this->userId, 'branch_id' => $this->branchId, 'activation_epoch' => 1,
            'credential_hash' => password_hash('CashierPass1', PASSWORD_ARGON2ID),
            'credential_type' => 'password', 'credential_version' => 1, 'status' => 'active',
            'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employeeCode = User::on('tenant')->find($this->userId)->employee_code;
        $auth = app(\App\Services\Edge\EdgeLocalAuthService::class);

        // The Cloud password must NOT work against the Edge credential path.
        try {
            $auth->verifyForLogin($employeeCode, 'secret'); // 'secret' = the user's Cloud bcrypt password
            $this->fail('the Cloud password must not authenticate on the Edge credential path');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        // Real Edge login: verify the Edge credential, establish the tenant session.
        $cashier = $auth->verifyForLogin($employeeCode, 'CashierPass1');
        $auth->login($cashier);
        $this->assertSame($this->userId, (int) auth('tenant')->id(), 'tenant session belongs to the enrolled Edge cashier');

        $sale = $this->complete();
        $this->assertSame($this->userId, (int) $sale->created_by_user_id, 'sale attributed to the REAL authenticated Edge cashier');
        $this->assertSame('paid', $sale->status);
    }

    // ── (2C) REAL TOCTOU: valid at preflight, deactivated in the window before the in-txn reload ──
    private function toctouService(callable $hook): EdgeLocalPosService
    {
        $svc = new class(
            app(\App\Services\Edge\EdgeBranchContext::class),
            app(ShiftService::class),
            app(\App\Services\Sales\SalePricingService::class),
            app(\App\Services\Sales\SalesTotalsService::class),
            app(\App\Services\Inventory\InventoryService::class),
            app(\App\Services\Edge\EdgeOperationalStockService::class),
            app(\App\Services\Sales\SaleIdempotencyService::class),
            app(\App\Services\Sales\SaleOperationalSettlementService::class),
        ) extends EdgeLocalPosService {
            public $toctouHook;

            protected function beforeSaleTransaction(): void
            {
                if ($this->toctouHook) {
                    ($this->toctouHook)(); // state change in the preflight→transaction window
                }
            }
        };
        $svc->toctouHook = $hook;

        return $svc;
    }

    private function assertNothingPersisted(): void
    {
        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'no sale persisted');
        $this->assertSame(0, DB::connection('tenant')->table('sale_payments')->count(), 'no payment persisted');
        $this->assertSame(10.0, $this->onHand(), 'stock unchanged');
        $this->assertSame(0, DB::connection('tenant')->table('edge_operational_stock_movements')->count(), 'no movement persisted');
        $shift = \App\Models\Tenant\Shift::on('tenant')->first();
        $this->assertSame(0.0, (float) $shift->total_sales, 'no settlement persisted');
    }

    public function test_2c_cashier_deactivated_after_preflight_is_refused_in_txn(): void
    {
        $this->openShift();
        $svc = $this->toctouService(function () {
            DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['status' => 'inactive']);
        });
        try {
            $svc->completePaidSale([
                'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(),
                'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
                'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]],
            ], User::on('tenant')->find($this->userId), $this->terminalId);
            $this->fail('a cashier deactivated after preflight must be refused inside the transaction');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user', $e->errors());
        }
        $this->assertNothingPersisted();
    }

    public function test_2c_terminal_deactivated_after_preflight_is_refused_in_txn(): void
    {
        $this->openShift();
        $svc = $this->toctouService(function () {
            DB::connection('tenant')->table('terminals')->where('id', $this->terminalId)->update(['status' => 'inactive']);
        });
        try {
            $svc->completePaidSale([
                'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(),
                'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
                'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]],
            ], User::on('tenant')->find($this->userId), $this->terminalId);
            $this->fail('a terminal deactivated after preflight must be refused inside the transaction');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('terminal_id', $e->errors());
        }
        $this->assertNothingPersisted();
    }

    // ── (2A-B) a REAL unrelated unique violation propagates — never treated as an idempotency race ──
    public function test_unrelated_unique_violation_propagates_not_treated_as_client_uuid_race(): void
    {
        $this->openShift();
        $first = $this->complete(); // its sale_uuid will collide with the next sale via frozen ULIDs
        try {
            // Freeze ULID generation so the NEXT sale mints the SAME sale_uuid → a genuine MySQL unique
            // violation on sales_orders_sale_uuid_unique through the real write path.
            Str::createUlidsUsing(fn () => \Symfony\Component\Uid\Ulid::fromString($first->sale_uuid));
            $this->complete(); // different client_uuid — this is NOT an idempotency collision
            $this->fail('the duplicate sale_uuid must surface as a real unique-constraint failure');
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('sale_uuid', $e->getMessage(), 'the real unrelated unique failure propagates');
        } catch (\App\Exceptions\SaleIdempotencyConflictException $e) {
            $this->fail('an unrelated unique violation must NOT be converted to an idempotency replay/conflict');
        } finally {
            Str::createUlidsNormally();
        }
    }

    // ── (I) baseline authority + replacement fence ───────────────────────────
    public function test_no_accepted_baseline_refuses_the_sale_before_any_mutation(): void
    {
        $this->openShift();
        foreach (['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines'] as $t) {
            DB::connection('tenant')->table($t)->delete();
        }
        try {
            $this->complete();
            $this->fail('a sale without an accepted baseline must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('baseline', strtolower($e->getMessage()));
        }
        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'no sale persisted');
        $this->assertSame(0, DB::connection('tenant')->table('sale_payments')->count(), 'no payment persisted');
    }

    public function test_same_baseline_retry_is_idempotent_and_replacement_is_fenced(): void
    {
        $shift = $this->openShift();
        $svc = app(\App\Services\Edge\EdgeOperationalBaselineService::class);
        $current = DB::connection('tenant')->table('edge_operational_stock_baselines')->first();
        $items = [['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 10]];

        // exact same baseline (uuid + hash) retry → idempotent, same row.
        $again = $svc->accept($current->baseline_uuid, $current->content_hash, $items, 'test-rev-1');
        $this->assertSame((int) $current->id, (int) $again->id, 'same-baseline retry is idempotent');

        // same identity + different hash → conflict.
        try {
            $svc->accept($current->baseline_uuid, 'different-hash', $items);
            $this->fail('same baseline uuid with a different hash must conflict');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('conflict', strtolower($e->getMessage()));
        }

        // consume 3 (balance 7) then attempt a DIFFERENT baseline → REPLACEMENT FENCE refuses.
        $sale = $this->complete(['lines' => [['product_id' => $this->productId, 'quantity' => 3]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 300]]]);
        $this->assertSame(7.0, $this->onHand());
        try {
            $svc->accept(\App\Services\Edge\EdgeOperationalBaselineService::newBaselineUuid(), \App\Services\Edge\EdgeOperationalBaselineService::hashItems($items), $items);
            $this->fail('a different baseline must be refused while an accepted baseline exists');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refused', strtolower($e->getMessage()));
        }
        // fence left everything intact: B1 active, balance 7, movement + pending sale preserved.
        $this->assertSame((int) $current->id, (int) DB::connection('tenant')->table('edge_operational_stock_baselines')->where('status', 'accepted')->value('id'));
        $this->assertSame(7.0, $this->onHand(), 'balance NOT reset by the refused replacement');
        $this->assertSame(1, DB::connection('tenant')->table('edge_operational_stock_movements')->where('sale_uuid', $sale->sale_uuid)->count(), 'movement preserved');
        $this->assertSame('pending', SalesOrder::on('tenant')->find($sale->id)->edge_sync_state, 'pending sale preserved');
    }

    public function test_effective_intent_ignores_spoofed_price_and_branch(): void
    {
        $this->openShift();
        $uuid = (string) Str::uuid();
        $this->complete(['client_uuid' => $uuid]);
        // same effective sale, but request now carries a spoofed unit_price + branch_id + print flags Edge ignores.
        $replay = $this->complete(['client_uuid' => $uuid, 'branch_id' => 99999, 'kot_print_intent' => true, 'lines' => [['product_id' => $this->productId, 'quantity' => 2, 'unit_price' => 5]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 200]]]);
        $this->assertSame((int) SalesOrder::on('tenant')->where('client_uuid', $uuid)->value('id'), $replay->id, 'ignored spoof fields do not cause a false conflict');
    }
}
