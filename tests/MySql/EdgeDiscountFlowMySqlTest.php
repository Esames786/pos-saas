<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * A2 (owner decision, final) — the manual discount on the Branch Server is aligned to Online: a discount + its manager
 * approval is a PAYMENT / settlement concern. Over REAL HTTP on a branch_server-booted app:
 *
 *  1. a hold (new or revision) never persists a manual order discount (Online's exact refusal; a legacy stored one is cleared);
 *  2. the discount is applied at settle (fixed / percent) — the paid row and the sync envelope carry it;
 *  3. the branch approval mode gates it; the approval is payload-bound to THIS held sale and amount;
 *  4. the approval is consumed at settle (not at verify), and Direct Pay consumes it too;
 *  5. a consumed approval cannot be replayed on another check;
 *  6. percent / fixed validation;
 *  7. discount_type none at settle removes a stored discount;
 *  8. retry: same client_uuid + same payload replays (approval consumed once, one outbox row); a different discount is a 409.
 */
class EdgeDiscountFlowMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const HOLD_REFUSAL = 'Manual discounts are applied with manager approval when taking payment, not while holding an order.';

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $managerId;
    private string $managerCode;
    private int $karahi;
    private int $naan;
    private int $cashMethodId;

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
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'printers', 'terminal_printer_settings',
            'manager_approvals', 'manager_pins', 'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'promotion_targets', 'promotions', 'customer_addresses', 'customers', 'delivery_riders', 'delivery_channels',
            'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'combo_components', 'combos', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'manual_discount_approval_mode' => 'manager_required']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'DSC' . Str::random(4)]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'MGR' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $categoryId = $this->makeCategory(['name' => 'Karahi']);
        $this->karahi = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->naan = $this->makeProduct($categoryId, ['name' => 'Roghni Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);

        $permId = (int) DB::connection('tenant')->table('permissions')->insertGetId(['name' => 'tenant.pos.void-kot-item', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('model_has_permissions')->insert(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $this->managerId]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->karahi, 'product_variant_id' => null, 'quantity' => 100],
            ['product_id' => $this->naan, 'product_variant_id' => null, 'quantity' => 100],
        ]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->managerId, $this->branchId, 1, 'MgrPass1');
        $this->managerCode = (string) User::on('tenant')->find($this->managerId)->employee_code;
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

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────

    private function cash(float $amount): array
    {
        return [['payment_method_id' => $this->cashMethodId, 'amount' => $amount, 'tendered_amount' => $amount]];
    }

    /** A takeaway held check: 2 × Karahi (100) = 200. */
    private function holdCheck(array $extra = []): int
    {
        return (int) $this->postJson('/edge/local/pos/held-sales', array_merge([
            'order_type' => 'takeaway',
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2]],
        ], $extra))->assertStatus(201)->assertJsonPath('grand_total', 200)->json('sale_id');
    }

    private function approve(array $payload): int
    {
        return (int) $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $this->managerCode, 'manager_credential' => 'MgrPass1',
            'action_type' => 'manual_discount',
            'payload' => $payload,
        ])->assertOk()->assertJsonPath('ok', true)->json('approval_id');
    }

    /** An approval bound exactly the way settleHeldSale consumes it (Online: sales_order_id = the held sale id). */
    private function approveForSettle(int $saleId, string $clientUuid, string $type, float $value, float $amount): int
    {
        return $this->approve([
            'sales_order_id' => $saleId, 'branch_id' => $this->branchId, 'client_uuid' => $clientUuid,
            'discount_type' => $type, 'discount_value' => $value, 'discount_amount' => $amount,
        ]);
    }

    private function settle(int $saleId, array $body)
    {
        return $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", $body);
    }

    private function sale(int $id): object
    {
        return DB::connection('tenant')->table('sales_orders')->where('id', $id)->first();
    }

    private function envelope(object $sale): array
    {
        return json_decode((string) DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $sale->sale_uuid)->value('envelope'), true);
    }

    private function consumedAt(int $approvalId): ?string
    {
        return DB::connection('tenant')->table('manager_approvals')->where('id', $approvalId)->value('consumed_at');
    }

    private function autoApprove(): void
    {
        DB::connection('tenant')->table('branches')->where('id', $this->branchId)->update(['manual_discount_approval_mode' => 'auto_approve']);
    }

    /** Give a held check a stored (pre-A2 legacy) fixed order discount of 20 directly in the DB. */
    private function plantLegacyDiscount(int $saleId): void
    {
        DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->update([
            'discount_type' => 'fixed', 'discount_value' => 20, 'discount_amount' => 20, 'grand_total' => 180,
        ]);
    }

    // ── 1. hold without discount persistence ─────────────────────────────────────────────────────────────────

    public function test_hold_never_persists_a_manual_order_discount(): void
    {
        // Hold WITH a discount → Online's exact refusal on discount_value, nothing written.
        foreach ([['discount_type' => 'fixed', 'discount_value' => 20], ['discount_type' => 'percent', 'discount_value' => 10], ['discount_type' => 'none', 'discount_value' => 5]] as $discount) {
            $this->postJson('/edge/local/pos/held-sales', array_merge([
                'order_type' => 'takeaway',
                'lines' => [['product_id' => $this->karahi, 'quantity' => 2]],
            ], $discount))->assertStatus(422)->assertJsonPath('errors.discount_value.0', self::HOLD_REFUSAL);
        }
        $this->assertSame(0, DB::connection('tenant')->table('sales_orders')->count(), 'a refused hold writes nothing');
        $this->assertSame(0, DB::connection('tenant')->table('sales_order_lines')->count());

        // Hold WITHOUT a discount → discount_type none persisted.
        $saleId = $this->holdCheck(['discount_type' => 'none', 'discount_value' => 0]);
        $row = $this->sale($saleId);
        $this->assertSame('none', $row->discount_type);
        $this->assertSame(0.0, (float) $row->discount_value);
        $this->assertSame(0.0, (float) $row->discount_amount);

        // A revision that tries to add a discount is refused too, and the check is untouched.
        $lineId = (int) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('id');
        $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $saleId, 'order_type' => 'takeaway', 'discount_type' => 'fixed', 'discount_value' => 10,
            'lines' => [['sales_order_line_id' => $lineId, 'product_id' => $this->karahi, 'quantity' => 3]],
        ])->assertStatus(422)->assertJsonPath('errors.discount_value.0', self::HOLD_REFUSAL);
        $this->assertSame(200.0, (float) $this->sale($saleId)->grand_total);
        $this->assertSame(2.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('quantity'));

        // A LEGACY check with a stored order discount loses it on revision (Online's revision writes none).
        $this->plantLegacyDiscount($saleId);
        $this->postJson('/edge/local/pos/held-sales', [
            'held_sale_id' => $saleId, 'order_type' => 'takeaway',
            'lines' => [['sales_order_line_id' => $lineId, 'product_id' => $this->karahi, 'quantity' => 3]],
        ])->assertOk()->assertJsonPath('grand_total', 300);
        $row = $this->sale($saleId);
        $this->assertSame('none', $row->discount_type);
        $this->assertSame(0.0, (float) $row->discount_value);
        $this->assertSame(0.0, (float) $row->discount_amount);
        $this->assertSame(0, DB::connection('tenant')->table('edge_sync_outbox')->count(), 'holds never create an outbox row');
    }

    // ── 2. apply discount at settle ──────────────────────────────────────────────────────────────────────────

    public function test_discount_is_applied_at_settle_and_travels_on_the_paid_row_and_the_envelope(): void
    {
        $this->autoApprove();

        // FIXED 30 on 200 → 170.
        $fixedId = $this->holdCheck();
        $this->settle($fixedId, ['client_uuid' => (string) Str::uuid(), 'discount_type' => 'fixed', 'discount_value' => 30, 'payments' => $this->cash(170)])
            ->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('grand_total', 170);
        $row = $this->sale($fixedId);
        $this->assertSame('paid', $row->status);
        $this->assertSame('fixed', $row->discount_type);
        $this->assertSame(30.0, (float) $row->discount_value);
        $this->assertSame(30.0, (float) $row->discount_amount);
        $this->assertSame(200.0, (float) $row->subtotal);
        $this->assertSame(170.0, (float) $row->grand_total);
        $env = $this->envelope($row);
        $this->assertSame(30.0, (float) data_get($env, 'totals.discount_amount'));
        $this->assertSame(170.0, (float) data_get($env, 'totals.grand_total'));
        $this->assertSame('fixed', data_get($env, 'totals.discount_type'));

        // PERCENT 10 on 200 → 180.
        $pctId = $this->holdCheck();
        $this->settle($pctId, ['client_uuid' => (string) Str::uuid(), 'discount_type' => 'percent', 'discount_value' => 10, 'payments' => $this->cash(180)])
            ->assertOk()->assertJsonPath('grand_total', 180);
        $row = $this->sale($pctId);
        $this->assertSame('percent', $row->discount_type);
        $this->assertSame(10.0, (float) $row->discount_value);
        $this->assertSame(20.0, (float) $row->discount_amount);
        $this->assertSame(180.0, (float) $row->grand_total);
        $env = $this->envelope($row);
        $this->assertSame(20.0, (float) data_get($env, 'totals.discount_amount'));
        $this->assertSame(180.0, (float) data_get($env, 'totals.grand_total'));

        // Payments must still cover the DISCOUNTED total.
        $shortId = $this->holdCheck();
        $this->settle($shortId, ['client_uuid' => (string) Str::uuid(), 'discount_type' => 'fixed', 'discount_value' => 30, 'payments' => $this->cash(150)])
            ->assertStatus(422);
        $this->assertSame('held', $this->sale($shortId)->status);
        $this->assertSame(200.0, (float) $this->sale($shortId)->grand_total, 'a refused settle leaves the check unrepriced');

        // The pre-A2 page never sends discount_type → today's behaviour, totals untouched.
        $plainId = $this->holdCheck();
        $this->settle($plainId, ['client_uuid' => (string) Str::uuid(), 'payments' => $this->cash(200)])->assertOk()->assertJsonPath('grand_total', 200);
        $this->assertSame('none', $this->sale($plainId)->discount_type);
        $this->assertSame(3, DB::connection('tenant')->table('edge_sync_outbox')->count());
    }

    // ── 3. manager approval ──────────────────────────────────────────────────────────────────────────────────

    public function test_manager_approval_follows_the_branch_mode_and_binds_the_held_sale_and_amount(): void
    {
        $saleId = $this->holdCheck();
        $uuid = (string) Str::uuid();
        $body = ['client_uuid' => $uuid, 'discount_type' => 'fixed', 'discount_value' => 20, 'payments' => $this->cash(180)];

        // manager_required: no approval → 422 on manager_approval_id.
        $this->settle($saleId, $body)->assertStatus(422)->assertJsonValidationErrors('manager_approval_id');

        // Bound to a DIFFERENT amount → does not match.
        $wrongAmount = $this->approveForSettle($saleId, $uuid, 'fixed', 30, 30);
        $this->settle($saleId, $body + ['manager_approval_id' => $wrongAmount])->assertStatus(422)
            ->assertJsonPath('errors.manager_approval_id.0', 'Manager approval does not match this action.');
        $this->assertNull($this->consumedAt($wrongAmount), 'a refused settle never burns the approval');

        // Bound to a DIFFERENT sales_order_id (Direct Pay's 0) → does not match.
        $wrongSale = $this->approveForSettle(0, $uuid, 'fixed', 20, 20);
        $this->settle($saleId, $body + ['manager_approval_id' => $wrongSale])->assertStatus(422)
            ->assertJsonPath('errors.manager_approval_id.0', 'Manager approval does not match this action.');
        $this->assertSame('held', $this->sale($saleId)->status);

        // The correctly bound approval settles.
        $good = $this->approveForSettle($saleId, $uuid, 'fixed', 20, 20);
        $this->settle($saleId, $body + ['manager_approval_id' => $good])->assertOk()->assertJsonPath('grand_total', 180);

        // auto_approve branch: no approval needed.
        $this->autoApprove();
        $other = $this->holdCheck();
        $this->settle($other, ['client_uuid' => (string) Str::uuid(), 'discount_type' => 'fixed', 'discount_value' => 20, 'payments' => $this->cash(180)])
            ->assertOk()->assertJsonPath('grand_total', 180);
    }

    // ── 4. settle / complete consume ─────────────────────────────────────────────────────────────────────────

    public function test_the_approval_is_consumed_at_settle_and_by_direct_pay(): void
    {
        $saleId = $this->holdCheck();
        $uuid = (string) Str::uuid();
        $approvalId = $this->approveForSettle($saleId, $uuid, 'percent', 10, 20);
        $this->assertNull($this->consumedAt($approvalId), 'verify mints the approval; it is not consumed yet');

        $this->settle($saleId, ['client_uuid' => $uuid, 'discount_type' => 'percent', 'discount_value' => 10, 'manager_approval_id' => $approvalId, 'payments' => $this->cash(180)])
            ->assertOk()->assertJsonPath('grand_total', 180);
        $this->assertNotNull($this->consumedAt($approvalId), 'consumed inside the settle transaction');
        $this->assertSame($this->userId, (int) DB::connection('tenant')->table('manager_approvals')->where('id', $approvalId)->value('consumed_by_user_id'));

        // Direct Pay (/sales) consumes its approval too (sales_order_id 0, as Online POST /pos without a held sale).
        $dpUuid = (string) Str::uuid();
        $dpApproval = $this->approve(['sales_order_id' => 0, 'branch_id' => $this->branchId, 'client_uuid' => $dpUuid, 'discount_type' => 'fixed', 'discount_value' => 50, 'discount_amount' => 50]);
        $this->assertNull($this->consumedAt($dpApproval));
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => $dpUuid, 'discount_type' => 'fixed', 'discount_value' => 50,
            'manager_approval_id' => $dpApproval,
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2]],
            'payments' => $this->cash(150),
        ])->assertStatus(201)->assertJsonPath('grand_total', 150);
        $this->assertNotNull($this->consumedAt($dpApproval));

        // Direct Pay refuses a fixed discount bigger than the bill (never a silent clamp).
        $this->autoApprove();
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'discount_type' => 'fixed', 'discount_value' => 250,
            'lines' => [['product_id' => $this->karahi, 'quantity' => 2]],
            'payments' => $this->cash(1),
        ])->assertStatus(422)->assertJsonPath('errors.discount_value.0', 'The discount cannot be more than the bill subtotal.');
    }

    // ── 5. consumed approval cannot replay ──────────────────────────────────────────────────────────────────

    public function test_a_consumed_approval_cannot_be_reused_on_another_check(): void
    {
        $first = $this->holdCheck();
        $uuid = (string) Str::uuid();
        $approvalId = $this->approveForSettle($first, $uuid, 'fixed', 20, 20);
        $this->settle($first, ['client_uuid' => $uuid, 'discount_type' => 'fixed', 'discount_value' => 20, 'manager_approval_id' => $approvalId, 'payments' => $this->cash(180)])->assertOk();

        $second = $this->holdCheck();
        $this->settle($second, ['client_uuid' => (string) Str::uuid(), 'discount_type' => 'fixed', 'discount_value' => 20, 'manager_approval_id' => $approvalId, 'payments' => $this->cash(180)])
            ->assertStatus(422)->assertJsonPath('errors.manager_approval_id.0', 'This manager approval has already been used.');
        $this->assertSame('held', $this->sale($second)->status);
        $this->assertSame(1, DB::connection('tenant')->table('edge_sync_outbox')->count());
    }

    // ── 6. percent / fixed validation ────────────────────────────────────────────────────────────────────────

    public function test_discount_validation_at_settle(): void
    {
        $this->autoApprove();
        $saleId = $this->holdCheck();
        $try = fn (array $discount) => $this->settle($saleId, array_merge(['client_uuid' => (string) Str::uuid(), 'payments' => $this->cash(200)], $discount));

        $try(['discount_type' => 'percent', 'discount_value' => 150])->assertStatus(422)->assertJsonValidationErrors('discount_value');
        $try(['discount_type' => 'fixed', 'discount_value' => 250])->assertStatus(422)
            ->assertJsonPath('errors.discount_value.0', 'The discount cannot be more than the bill subtotal.');
        $try(['discount_type' => 'fixed', 'discount_value' => -5])->assertStatus(422)->assertJsonValidationErrors('discount_value');
        $try(['discount_type' => 'none', 'discount_value' => 10])->assertStatus(422)->assertJsonValidationErrors('discount_type');
        $try(['discount_type' => 'bogus', 'discount_value' => 10])->assertStatus(422)->assertJsonValidationErrors('discount_type');

        $row = $this->sale($saleId);
        $this->assertSame('held', $row->status, 'every refusal leaves the check held');
        $this->assertSame(200.0, (float) $row->grand_total);
        $this->assertSame(0, DB::connection('tenant')->table('sale_payments')->count());
        $this->assertSame(0, DB::connection('tenant')->table('edge_sync_outbox')->count());

        // A fixed discount of exactly the bill is allowed (reduces merchandise to zero).
        $this->settle($saleId, ['client_uuid' => (string) Str::uuid(), 'discount_type' => 'fixed', 'discount_value' => 200, 'payments' => $this->cash(0.01)])
            ->assertOk()->assertJsonPath('grand_total', 0);
    }

    // ── 7. remove discount ──────────────────────────────────────────────────────────────────────────────────

    public function test_discount_type_none_at_settle_removes_a_stored_discount(): void
    {
        $saleId = $this->holdCheck();
        $this->plantLegacyDiscount($saleId);
        $this->assertSame(180.0, (float) $this->sale($saleId)->grand_total);

        $this->settle($saleId, ['client_uuid' => (string) Str::uuid(), 'discount_type' => 'none', 'discount_value' => 0, 'payments' => $this->cash(200)])
            ->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('grand_total', 200);
        $row = $this->sale($saleId);
        $this->assertSame('none', $row->discount_type);
        $this->assertSame(0.0, (float) $row->discount_value);
        $this->assertSame(0.0, (float) $row->discount_amount);
        $this->assertSame(200.0, (float) $row->grand_total);
        $env = $this->envelope($row);
        $this->assertSame(0.0, (float) data_get($env, 'totals.discount_amount'));
        $this->assertSame(200.0, (float) data_get($env, 'totals.grand_total'));
    }

    // ── 8. retry / idempotency ──────────────────────────────────────────────────────────────────────────────

    public function test_settle_retry_replays_once_and_a_different_discount_conflicts(): void
    {
        $saleId = $this->holdCheck();
        $uuid = (string) Str::uuid();
        $approvalId = $this->approveForSettle($saleId, $uuid, 'fixed', 20, 20);
        $body = ['client_uuid' => $uuid, 'discount_type' => 'fixed', 'discount_value' => 20, 'manager_approval_id' => $approvalId, 'payments' => $this->cash(180)];

        $firstId = (int) $this->settle($saleId, $body)->assertOk()->assertJsonPath('grand_total', 180)->json('sale_id');
        $consumedAt = $this->consumedAt($approvalId);
        $this->assertNotNull($consumedAt);

        // Same client_uuid + same payload → the same sale, the approval is NOT consumed again, one outbox row.
        $replayId = (int) $this->settle($saleId, $body)->assertOk()->assertJsonPath('grand_total', 180)->json('sale_id');
        $this->assertSame($firstId, $replayId);
        $this->assertSame($consumedAt, $this->consumedAt($approvalId), 'the replay path returns before consuming');
        $this->assertSame(1, DB::connection('tenant')->table('edge_sync_outbox')->count());
        $this->assertSame(1, DB::connection('tenant')->table('sale_payments')->where('sales_order_id', $saleId)->count());

        // Same client_uuid + a DIFFERENT discount → 409 (never a silent replay).
        $this->settle($saleId, array_merge($body, ['discount_value' => 30, 'payments' => $this->cash(170)]))->assertStatus(409);
        $this->assertSame(180.0, (float) $this->sale($saleId)->grand_total);
        $this->assertSame(1, DB::connection('tenant')->table('edge_sync_outbox')->count());
    }
}
