<?php

namespace Tests\MySql;

use App\Models\Tenant\ManagerApproval;
use App\Services\Sales\ManagerApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * CLOUD manager-approval regression (EDGE-LOCAL-POS-1 correction): verifyPin was refactored onto the
 * shared createApprovalForAuthenticatedManager creator (which the Edge verifyManager path also uses),
 * so the ORIGINAL Cloud manager_pins semantics are pinned here: Hash::check PIN resolution, order-branch
 * authorization, approval identity, payload binding, 10-minute expiry and single-use consumption.
 */
class CloudManagerApprovalMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $cashierId;
    private int $managerId;
    private int $saleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanTenant(['manager_approvals', 'manager_pins', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch();
        $this->cashierId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $this->saleId = $this->makeSale($this->branchId, ['status' => 'held']);
        DB::connection('tenant')->table('manager_pins')->insert([
            'user_id' => $this->managerId, 'pin_hash' => Hash::make('password@'), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function svc(): ManagerApprovalService
    {
        return app(ManagerApprovalService::class);
    }

    public function test_cloud_pin_verification_and_single_use_consume_are_unchanged(): void
    {
        // wrong PIN refused (real Hash::check over active pins).
        try {
            $this->svc()->verifyPin('9999', 'void_kot_item', $this->cashierId, ['sales_order_id' => $this->saleId]);
            $this->fail('a wrong manager PIN must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid manager PIN', $e->getMessage());
        }

        // correct PIN mints the approval with full identity + payload binding.
        $payload = ['sales_order_id' => $this->saleId, 'sales_order_line_id' => 7, 'quantity' => 2];
        $approval = $this->svc()->verifyPin('password@', 'void_kot_item', $this->cashierId, $payload);
        $this->assertSame($this->managerId, (int) $approval->approved_by_user_id);
        $this->assertSame($this->cashierId, (int) $approval->requested_by_user_id);
        $this->assertSame('void_kot_item', $approval->action_type);
        $this->assertStringStartsWith('MA-', $approval->approval_no);
        $this->assertTrue(Str::isUlid($approval->approval_uuid), 'canonical approval identity');
        $this->assertNotNull(DB::connection('tenant')->table('manager_pins')->where('user_id', $this->managerId)->value('last_used_at'));

        // payload mismatch refused; matching payload consumes exactly once.
        try {
            $this->svc()->consume($approval, 'void_kot_item', $this->cashierId, ['sales_order_id' => $this->saleId, 'quantity' => 99]);
            $this->fail('a payload mismatch must refuse consumption');
        } catch (\RuntimeException $e) {
            $this->assertTrue(true);
        }
        $consumed = $this->svc()->consume($approval, 'void_kot_item', $this->cashierId, $payload);
        $this->assertNotNull($consumed->consumed_at);
        try {
            $this->svc()->consume($approval->fresh(), 'void_kot_item', $this->cashierId, $payload);
            $this->fail('an approval is single-use');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already been used', $e->getMessage());
        }
    }

    public function test_cloud_branch_authorization_and_expiry_are_unchanged(): void
    {
        // a manager without access to the order's branch is refused.
        $foreignSale = $this->makeSale($this->makeBranch(), ['status' => 'held']);
        try {
            $this->svc()->verifyPin('password@', 'void_kot_item', $this->cashierId, ['sales_order_id' => $foreignSale]);
            $this->fail('branch authorization must be enforced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not authorized for the order branch', $e->getMessage());
        }

        // 10-minute expiry.
        $approval = $this->svc()->verifyPin('password@', 'void_kot_item', $this->cashierId, ['sales_order_id' => $this->saleId]);
        ManagerApproval::where('id', $approval->id)->update(['approved_at' => now()->subMinutes(11)]);
        try {
            $this->svc()->consume($approval->fresh(), 'void_kot_item', $this->cashierId, ['sales_order_id' => $this->saleId]);
            $this->fail('an expired approval must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
    }
}
