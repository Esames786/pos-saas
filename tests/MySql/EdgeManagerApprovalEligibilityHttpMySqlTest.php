<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeAuthAudit;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * PHASE 3 SECURITY — the dedicated manager-approval ELIGIBILITY contract on the Branch Server, over REAL HTTP on a
 * branch_server-booted app (owner requirement, 4 Oct 2026; closes W-E Finding 1 / step-7 report item 1).
 *
 * Contract under test (EdgeLocalAuthService::verifyManager + the shared ManagerApprovalService creator/consume):
 *   1. the approver must carry the Cloud-authoritative bootstrap flag users.may_approve_pos (active manager PIN AND
 *      active user on the Cloud) — tenant.pos.void-kot-item is NOT an approver marker any more;
 *   2. the approver must ALSO hold the permission the approved ACTION needs (MANAGER_ACTION_PERMISSIONS);
 *   3. approver ≠ requesting cashier — self-approval is refused server-side (422 + business message), not just hidden;
 *   4. the epoch-fenced Edge credential rules are unchanged (wrong credential, deactivated / disabled / stale-epoch
 *      approver all fail closed);
 *   5. an approval stays one-time consumable; replay of a consumed approval is refused.
 *
 * The seven owner cases are one named test each. The master DB is unreachable throughout the approval flows (pure
 * local authority), exactly as the appliance runs.
 */
class EdgeManagerApprovalEligibilityHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const SELF_APPROVAL = 'You cannot approve your own request. Ask another manager to approve.';
    private const NOT_APPROVER = 'This user is not an approving manager (no active manager PIN on the Cloud).';
    private const NO_ACTION_PERMISSION = 'This user is not authorized to approve that action.';

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $managerId;
    private int $karahi;
    private int $cashMethodId;
    private string $managerCode;
    private string $cashierCode;

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
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'ELC' . Str::random(4)]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'ELM' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->karahi = $this->makeProduct($this->makeCategory(['name' => 'Karahi']), ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->karahi, 'product_variant_id' => null, 'quantity' => 100]]);
        // Both hold the full `Cashier (Counter)` template (incl. tenant.pos.void-kot-item + tenant.pos.store); ONLY the
        // manager carries the Phase 3 eligibility flag.
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->managerId, $this->branchId, 1, 'MgrPass1');
        $this->markPosApprover($this->managerId);
        $this->managerCode = (string) User::on('tenant')->find($this->managerId)->employee_code;
        $this->cashierCode = (string) User::on('tenant')->find($this->userId)->employee_code;
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);

        // Pure local authority: the Cloud master is unreachable for every approval below.
        config(['database.connections.master.database' => 'nonexistent_master_approver_eligibility']);
        DB::purge('master');
    }

    protected function tearDown(): void
    {
        config(['database.connections.master.database' => (string) env('DB_DATABASE', 'pos_test_master')]);
        DB::purge('master');
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────

    private function discountPayload(int $saleId, string $clientUuid): array
    {
        return ['sales_order_id' => $saleId, 'branch_id' => $this->branchId, 'client_uuid' => $clientUuid, 'discount_type' => 'fixed', 'discount_value' => 20, 'discount_amount' => 20];
    }

    private function verify(string $code, string $credential, string $action = 'manual_discount', ?array $payload = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/edge/local/pos/manager-approvals/verify', [
            'manager_employee_code' => $code, 'manager_credential' => $credential, 'action_type' => $action,
            'payload' => $payload ?? ['sales_order_id' => 0, 'branch_id' => $this->branchId, 'discount_type' => 'fixed', 'discount_value' => 20, 'discount_amount' => 20],
        ]);
    }

    /** A takeaway held check: 2 × Karahi (100) = 200. */
    private function holdCheck(): int
    {
        return (int) $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'takeaway', 'lines' => [['product_id' => $this->karahi, 'quantity' => 2]]])
            ->assertStatus(201)->json('sale_id');
    }

    private function approvals(): int
    {
        return DB::connection('tenant')->table('manager_approvals')->count();
    }

    private function lastFailDetail(): ?string
    {
        return EdgeAuthAudit::query()->where('event', EdgeAuthAudit::E_MGR_FAIL)->orderByDesc('id')->value('detail');
    }

    // ── the seven owner cases ────────────────────────────────────────────────────────────────────────────────

    /** Owner case 1 — a valid (eligible, permitted, different) manager approves another cashier's request. */
    public function test_valid_manager_approves_another_cashier(): void
    {
        $r = $this->verify($this->managerCode, 'MgrPass1');
        $r->assertStatus(200)->assertJsonPath('ok', true);
        foreach (['approval_id', 'approval_no', 'approval_uuid'] as $k) {
            $this->assertNotEmpty($r->json($k), $k);
        }
        $row = DB::connection('tenant')->table('manager_approvals')->find($r->json('approval_id'));
        $this->assertSame($this->managerId, (int) $row->approved_by_user_id, 'the approval identity is the manager');
        $this->assertSame($this->userId, (int) $row->requested_by_user_id, 'bound to the requesting cashier');
        $this->assertNull($row->consumed_at);
        $this->assertSame($this->userId, (int) auth('tenant')->id(), 'manager re-auth never replaces the cashier session');
        $this->assertTrue(EdgeAuthAudit::where('event', EdgeAuthAudit::E_MGR_OK)->where('user_id', $this->managerId)->exists());
    }

    /** Owner case 2 — holding tenant.pos.void-kot-item (the template) without the eligibility flag is NOT an approver. */
    public function test_cashier_with_void_permission_but_no_approver_eligibility_is_refused(): void
    {
        $peerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'ELP' . Str::random(4)]);
        $this->seedEdgeCredential($peerId, $this->branchId, 1, 'PeerPass1');
        $peer = User::on('tenant')->find($peerId);
        $this->assertTrue($peer->can('tenant.pos.void-kot-item'), 'the peer cashier DOES hold the former marker');
        $this->assertTrue($peer->can('tenant.pos.store'), 'and the action permission');
        $this->assertSame(0, (int) $peer->may_approve_pos);

        $this->verify($peer->employee_code, 'PeerPass1')->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('message', self::NOT_APPROVER);
        $this->verify($peer->employee_code, 'PeerPass1', 'void_kot_item', ['sales_order_id' => 0, 'branch_id' => $this->branchId])->assertStatus(422)->assertJsonPath('message', self::NOT_APPROVER);
        $this->assertSame('not_approver', $this->lastFailDetail());
        $this->assertSame(0, $this->approvals(), 'nothing minted');

        // the flag is the ONLY thing that differs: flip it and the same user approves; revoke it again and they cannot.
        $this->markPosApprover($peerId);
        $this->verify($peer->employee_code, 'PeerPass1')->assertStatus(200);
        $this->markPosApprover($peerId, false);
        $this->verify($peer->employee_code, 'PeerPass1')->assertStatus(422)->assertJsonPath('message', self::NOT_APPROVER);
    }

    /** Owner case 3 — an eligible approver who lacks the permission the ACTION needs is refused (per action). */
    public function test_approver_lacking_action_permission_is_refused(): void
    {
        $this->revokeEdgePermission($this->managerId, 'tenant.pos.store');
        $this->verify($this->managerCode, 'MgrPass1', 'manual_discount')->assertStatus(422)->assertJsonPath('message', self::NO_ACTION_PERMISSION);
        $this->assertSame('missing_permission', $this->lastFailDetail());

        $this->revokeEdgePermission($this->managerId, 'tenant.pos.void-kot-item');
        $this->verify($this->managerCode, 'MgrPass1', 'void_kot_item', ['sales_order_id' => 0, 'branch_id' => $this->branchId])->assertStatus(422)->assertJsonPath('message', self::NO_ACTION_PERMISSION);
        $this->revokeEdgePermission($this->managerId, 'tenant.held-sales.cancel');
        $this->verify($this->managerCode, 'MgrPass1', 'cancel_held_order', ['sales_order_id' => 0, 'branch_id' => $this->branchId])->assertStatus(422)->assertJsonPath('message', self::NO_ACTION_PERMISSION);
        $this->revokeEdgePermission($this->managerId, 'tenant.sales-returns.store');
        $this->verify($this->managerCode, 'MgrPass1', 'sales_return', ['sales_order_id' => 0, 'branch_id' => $this->branchId, 'refund_method' => 'cash', 'refund_amount' => 10])->assertStatus(422)->assertJsonPath('message', self::NO_ACTION_PERMISSION);
        $this->assertSame(0, $this->approvals());

        // re-granting the one action permission restores exactly that action (the flag alone was never enough).
        $this->grantEdgePermission($this->managerId, 'tenant.pos.store');
        $this->verify($this->managerCode, 'MgrPass1', 'manual_discount')->assertStatus(200);
        $this->verify($this->managerCode, 'MgrPass1', 'void_kot_item', ['sales_order_id' => 0, 'branch_id' => $this->branchId])->assertStatus(422);
    }

    /** Owner case 4 — self-approval is refused SERVER-SIDE (the cashier's own code + correct credential). */
    public function test_self_approval_is_refused_server_side(): void
    {
        // even an eligible, fully permitted cashier cannot approve their OWN request
        $this->markPosApprover($this->userId);
        $this->verify($this->cashierCode, 'CashierPass1')->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('message', self::SELF_APPROVAL);
        $this->assertSame('self_approval', $this->lastFailDetail());
        $this->assertSame(0, $this->approvals(), 'no approval row for a self-approval attempt');
        $this->assertSame(0, (int) DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->userId)->value('failed_attempts'), 'refused before any credential processing');

        // the shared creator refuses it too (defence in depth, Cloud and Edge alike)
        try {
            app(\App\Services\Sales\ManagerApprovalService::class)->createApprovalForAuthenticatedManager(User::on('tenant')->find($this->userId), 'manual_discount', $this->userId, ['sales_order_id' => 0, 'branch_id' => $this->branchId]);
            $this->fail('the shared creator must refuse approver == requester');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::SELF_APPROVAL, $e->getMessage());
        }
        // another manager approving the same request is fine
        $this->verify($this->managerCode, 'MgrPass1')->assertStatus(200);
    }

    /** Owner case 5 — a wrong Edge credential is refused with the generic message and counts towards lockout. */
    public function test_wrong_credential_is_refused(): void
    {
        $this->verify($this->managerCode, 'WrongPass9')->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('message', 'Invalid credentials.');
        $this->assertSame('bad_credential', $this->lastFailDetail());
        $this->assertSame(1, (int) DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->managerId)->value('failed_attempts'));
        $this->verify('NOBODY' . Str::random(3), 'MgrPass1')->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
        $this->assertSame(0, $this->approvals());
        $this->verify($this->managerCode, 'MgrPass1')->assertStatus(200);
    }

    /** Owner case 6 — a deactivated user, a disabled credential and a stale activation epoch all fail closed. */
    public function test_deactivated_user_is_refused(): void
    {
        $c = DB::connection('tenant');
        // deactivated on the Cloud → tombstoned by refresh: status inactive (the flag may still be set — eligibility requires active)
        $c->table('users')->where('id', $this->managerId)->update(['status' => 'inactive']);
        $this->verify($this->managerCode, 'MgrPass1')->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
        $this->assertSame('user_ineligible_or_missing', $this->lastFailDetail());
        $this->assertFalse(\App\Support\EdgeUserAuthz::mayApprovePos(User::on('tenant')->find($this->managerId)));
        $c->table('users')->where('id', $this->managerId)->update(['status' => 'active']);

        // disabled Edge credential (expired approver)
        $c->table('edge_local_user_credentials')->where('user_id', $this->managerId)->update(['status' => 'disabled']);
        $this->verify($this->managerCode, 'MgrPass1')->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
        $this->assertSame('no_credential', $this->lastFailDetail());
        $c->table('edge_local_user_credentials')->where('user_id', $this->managerId)->update(['status' => 'active']);

        // epoch fence intact: a credential of a superseded appliance generation never approves
        $c->table('edge_local_user_credentials')->where('user_id', $this->managerId)->update(['activation_epoch' => 0]);
        $this->verify($this->managerCode, 'MgrPass1')->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
        $this->assertSame('stale_epoch', $this->lastFailDetail());
        $c->table('edge_local_user_credentials')->where('user_id', $this->managerId)->update(['activation_epoch' => 1]);

        $this->assertSame(0, $this->approvals());
        $this->verify($this->managerCode, 'MgrPass1')->assertStatus(200);
    }

    /** Owner case 7 — an approval is consumed exactly once; replaying the consumed approval is refused. */
    public function test_consumed_approval_replay_is_refused(): void
    {
        $saleId = $this->holdCheck();
        $uuid = (string) Str::uuid();
        $approvalId = (int) $this->verify($this->managerCode, 'MgrPass1', 'manual_discount', $this->discountPayload($saleId, $uuid))->assertStatus(200)->json('approval_id');
        $body = ['client_uuid' => $uuid, 'discount_type' => 'fixed', 'discount_value' => 20, 'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 180, 'tendered_amount' => 180]], 'manager_approval_id' => $approvalId];

        $this->postJson("/edge/local/pos/held-sales/{$saleId}/settle", $body)->assertOk()->assertJsonPath('grand_total', 180);
        $row = DB::connection('tenant')->table('manager_approvals')->find($approvalId);
        $this->assertNotNull($row->consumed_at, 'consumed at settle');
        $this->assertSame($this->userId, (int) $row->consumed_by_user_id);

        // replay against a NEW check with a new client_uuid (not the idempotent retry of the same sale) → refused.
        $second = $this->holdCheck();
        $this->postJson("/edge/local/pos/held-sales/{$second}/settle", array_merge($body, ['client_uuid' => (string) Str::uuid()]))->assertStatus(422)
            ->assertJsonValidationErrors('manager_approval_id');
        $this->assertSame('held', DB::connection('tenant')->table('sales_orders')->where('id', $second)->value('status'));
        try {
            app(\App\Services\Sales\ManagerApprovalService::class)->consume(\App\Models\Tenant\ManagerApproval::on('tenant')->find($approvalId), 'manual_discount', $this->userId, $this->discountPayload($saleId, $uuid));
            $this->fail('a consumed approval must never be consumed again');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already been used', $e->getMessage());
        }
    }
}
