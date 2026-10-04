<?php

namespace Tests\MySql;

use App\Models\Tenant\ManagerApproval;
use App\Models\Tenant\User;
use App\Services\Sales\ManagerApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\MySql\Support\TenantFixtures;

/**
 * PHASE 3 SECURITY — the manager-approval eligibility contract on the CLOUD (Online manager-PIN path), the seven owner
 * cases as named tests (4 Oct 2026). The Online cashier UI and the `{ok, approval_id…}` / 422 `{ok:false, message}`
 * contract of ManagerApprovalController@verify are unchanged: the controller is a thin wrapper around verifyPin() →
 * the ONE shared creator (createApprovalForAuthenticatedManager) → consume(), which are exercised here directly.
 *
 * Cloud eligibility = an ACTIVE manager_pins row AND an active user (what the Edge bootstrap exports as
 * users[].may_approve_pos). New in Phase 3: self-approval and a deactivated approver are refused SERVER-SIDE in the
 * shared creator (Cloud and Edge alike); an approval can never be consumed by its own approver.
 */
class CloudManagerApprovalEligibilityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const SELF_APPROVAL = 'You cannot approve your own request. Ask another manager to approve.';

    private int $branchId;
    private int $cashierId;
    private int $managerId;
    private int $saleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanTenant(['manager_approvals', 'manager_pins', 'model_has_permissions', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch();
        $this->cashierId = $this->makeUser(['default_branch_id' => $this->branchId, 'name' => 'Cashier']);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'name' => 'Manager']);
        $this->saleId = $this->makeSale($this->branchId, ['status' => 'held']);
        DB::connection('tenant')->table('manager_pins')->insert([
            'user_id' => $this->managerId, 'pin_hash' => Hash::make('2468'), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function svc(): ManagerApprovalService
    {
        return app(ManagerApprovalService::class);
    }

    private function payload(): array
    {
        return ['sales_order_id' => $this->saleId, 'sales_order_line_id' => 7, 'quantity' => 1];
    }

    private function grant(int $userId, string $permission): void
    {
        $c = DB::connection('tenant');
        $permId = (int) ($c->table('permissions')->where('name', $permission)->where('guard_name', 'tenant')->value('id')
            ?: $c->table('permissions')->insertGetId(['name' => $permission, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]));
        $c->table('model_has_permissions')->insertOrIgnore(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $userId]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Owner case 1 — a valid manager (active PIN, active user) approves another cashier's request. */
    public function test_valid_manager_approves_another_cashier(): void
    {
        $approval = $this->svc()->verifyPin('2468', 'void_kot_item', $this->cashierId, $this->payload());
        $this->assertSame($this->managerId, (int) $approval->approved_by_user_id);
        $this->assertSame($this->cashierId, (int) $approval->requested_by_user_id);
        $this->assertNull($approval->consumed_at);
        $this->assertNotNull(DB::connection('tenant')->table('manager_pins')->where('user_id', $this->managerId)->value('last_used_at'));
    }

    /** Owner case 2 — a cashier holding tenant.pos.void-kot-item but no manager PIN has no approver identity at all. */
    public function test_cashier_with_void_permission_but_no_approver_eligibility_is_refused(): void
    {
        $this->grant($this->cashierId, 'tenant.pos.void-kot-item');
        $this->assertSame(1, DB::connection('tenant')->table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_id', $this->cashierId)->where('permissions.name', 'tenant.pos.void-kot-item')->count(), 'the cashier holds the void permission (direct grant, tenant guard)');
        $this->assertSame(0, DB::connection('tenant')->table('manager_pins')->where('user_id', $this->cashierId)->count(), 'no PIN = not an approver');

        // Online identifies the approver by the PIN alone: the cashier's own credentials are never a PIN.
        try {
            $this->svc()->verifyPin('secret', 'void_kot_item', $this->managerId, $this->payload());
            $this->fail('a user without an active manager PIN cannot approve');
        } catch (\RuntimeException $e) {
            $this->assertSame('Invalid manager PIN.', $e->getMessage());
        }
        // a PIN that exists but is DISABLED is not eligibility either
        DB::connection('tenant')->table('manager_pins')->insert(['user_id' => $this->cashierId, 'pin_hash' => Hash::make('1357'), 'is_active' => 0, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $this->svc()->verifyPin('1357', 'void_kot_item', $this->managerId, $this->payload());
            $this->fail('a disabled manager PIN must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame('Invalid manager PIN.', $e->getMessage());
        }
        $this->assertSame(0, ManagerApproval::on('tenant')->count());
    }

    /**
     * Owner case 3 — ONLINE-vs-EDGE DIFFERENCE, documented: Online requires NO action permission of the approver (any
     * active manager-PIN holder approves any action — the original Cloud semantics, unchanged by Phase 3), whereas the
     * Edge additionally requires the permission the action needs (EdgeLocalPosService::MANAGER_ACTION_PERMISSIONS).
     * Tightening Online is an owner decision; this test pins the current Online behaviour so a change is deliberate.
     */
    public function test_approver_lacking_action_permission_is_accepted_online_but_refused_on_edge_documented_difference(): void
    {
        $this->assertFalse(User::on('tenant')->find($this->managerId)->can('tenant.pos.void-kot-item'), 'the Online manager holds no void permission');
        $approval = $this->svc()->verifyPin('2468', 'void_kot_item', $this->cashierId, $this->payload());
        $this->assertSame($this->managerId, (int) $approval->approved_by_user_id, 'Online: PIN identity is sufficient (documented difference)');
    }

    /** Owner case 4 — the manager's own PIN cannot approve the manager's own request (server-side refusal). */
    public function test_self_approval_is_refused_server_side(): void
    {
        try {
            $this->svc()->verifyPin('2468', 'void_kot_item', $this->managerId, $this->payload());
            $this->fail('self-approval must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::SELF_APPROVAL, $e->getMessage());
        }
        try {
            $this->svc()->createApprovalForAuthenticatedManager(User::on('tenant')->find($this->managerId), 'manual_discount', $this->managerId, ['sales_order_id' => 0, 'branch_id' => $this->branchId]);
            $this->fail('the shared creator must refuse approver == requester');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::SELF_APPROVAL, $e->getMessage());
        }
        $this->assertSame(0, ManagerApproval::on('tenant')->count(), 'nothing minted');
        // the same PIN approves another cashier's request
        $this->assertSame($this->managerId, (int) $this->svc()->verifyPin('2468', 'void_kot_item', $this->cashierId, $this->payload())->approved_by_user_id);
    }

    /** Owner case 5 — a wrong PIN is refused. */
    public function test_wrong_credential_is_refused(): void
    {
        foreach (['0000', '', '24680'] as $wrong) {
            try {
                $this->svc()->verifyPin($wrong, 'void_kot_item', $this->cashierId, $this->payload());
                $this->fail('a wrong manager PIN must be refused');
            } catch (\RuntimeException $e) {
                $this->assertSame('Invalid manager PIN.', $e->getMessage());
            }
        }
        $this->assertSame(0, ManagerApproval::on('tenant')->count());
        $this->assertNull(DB::connection('tenant')->table('manager_pins')->where('user_id', $this->managerId)->value('last_used_at'));
    }

    /** Owner case 6 — a deactivated manager cannot approve, through the PIN path and through the shared creator. */
    public function test_deactivated_user_is_refused(): void
    {
        DB::connection('tenant')->table('users')->where('id', $this->managerId)->update(['status' => 'inactive']);
        try {
            $this->svc()->verifyPin('2468', 'void_kot_item', $this->cashierId, $this->payload());
            $this->fail('a deactivated manager must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame('Invalid manager PIN.', $e->getMessage());
        }
        try {
            $this->svc()->createApprovalForAuthenticatedManager(User::on('tenant')->find($this->managerId), 'void_kot_item', $this->cashierId, $this->payload());
            $this->fail('the shared creator must refuse an inactive approver');
        } catch (\RuntimeException $e) {
            $this->assertSame('This manager account is deactivated and cannot approve.', $e->getMessage());
        }
        $this->assertSame(0, ManagerApproval::on('tenant')->count());
        DB::connection('tenant')->table('users')->where('id', $this->managerId)->update(['status' => 'active']);
        $this->assertSame($this->managerId, (int) $this->svc()->verifyPin('2468', 'void_kot_item', $this->cashierId, $this->payload())->approved_by_user_id);
    }

    /** Owner case 7 — single-use consumption; a consumed approval is refused on replay, and never by its own approver. */
    public function test_consumed_approval_replay_is_refused(): void
    {
        $approval = $this->svc()->verifyPin('2468', 'void_kot_item', $this->cashierId, $this->payload());
        $consumed = $this->svc()->consume($approval, 'void_kot_item', $this->cashierId, $this->payload());
        $this->assertNotNull($consumed->consumed_at);
        $this->assertSame($this->cashierId, (int) $consumed->consumed_by_user_id);

        try {
            $this->svc()->consume($approval->fresh(), 'void_kot_item', $this->cashierId, $this->payload());
            $this->fail('a consumed approval must be refused on replay');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already been used', $e->getMessage());
        }

        // a fresh approval minted for the cashier can never be consumed by the manager who granted it
        $fresh = $this->svc()->verifyPin('2468', 'void_kot_item', $this->cashierId, $this->payload());
        ManagerApproval::where('id', $fresh->id)->update(['requested_by_user_id' => $this->managerId]); // tampered binding
        try {
            $this->svc()->consume($fresh->fresh(), 'void_kot_item', $this->managerId, $this->payload());
            $this->fail('an approver must not consume their own approval');
        } catch (\RuntimeException $e) {
            $this->assertSame('A manager approval cannot be used by the manager who granted it.', $e->getMessage());
        }
        $this->assertNull($fresh->fresh()->consumed_at);
    }
}
