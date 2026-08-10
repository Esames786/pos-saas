<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Security\UserDataScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * USER-DATA-SCOPE-1 (Khatri delivery counter) — an operator bound to a terminal and restricted to
 * delivery orders may only ever see HIS sales. Enforced in the query and on single-record access,
 * so a hand-edited filter or a guessed id cannot widen the view. Owners stay unscoped.
 */
class UserDataScopeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $deliveryTerminalId;
    private int $otherTerminalId;
    private User $deliveryUser;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['sales_order_lines', 'sales_orders', 'terminal_user', 'branch_user', 'terminals', 'branches', 'users']);

        $this->branchId = $this->makeBranch();
        $this->deliveryTerminalId = $this->makeTerminal($this->branchId, ['code' => 'T-DEL', 'name' => 'Delivery']);
        $this->otherTerminalId = $this->makeTerminal($this->branchId, ['code' => 'T-DIN', 'name' => 'Dine In']);

        $this->deliveryUser = User::on('tenant')->findOrFail($this->makeUser([
            'email' => 'delivery@example.test', 'employee_code' => 'DL' . Str::random(4),
            'default_branch_id' => $this->branchId, 'allowed_order_types' => json_encode(['delivery']),
        ]));
        $this->deliveryUser->terminals()->sync([$this->deliveryTerminalId]);

        $this->owner = User::on('tenant')->findOrFail($this->makeUser([
            'email' => 'owner@example.test', 'employee_code' => 'OW' . Str::random(4),
            'default_branch_id' => $this->branchId,
        ]));

        // four sales across both terminals and both order types
        $this->makeSale($this->branchId, ['sale_no' => 'S-MINE', 'terminal_id' => $this->deliveryTerminalId, 'order_type' => 'delivery']);
        $this->makeSale($this->branchId, ['sale_no' => 'S-MY-TERMINAL-DINEIN', 'terminal_id' => $this->deliveryTerminalId, 'order_type' => 'dine_in']);
        $this->makeSale($this->branchId, ['sale_no' => 'S-OTHER-DELIVERY', 'terminal_id' => $this->otherTerminalId, 'order_type' => 'delivery']);
        $this->makeSale($this->branchId, ['sale_no' => 'S-OTHER-DINEIN', 'terminal_id' => $this->otherTerminalId, 'order_type' => 'dine_in']);
    }

    public function test_scoped_user_lists_only_his_terminal_and_order_type(): void
    {
        $scope = app(UserDataScope::class);
        $this->assertTrue($scope->isScoped($this->deliveryUser));
        $this->assertFalse($scope->isScoped($this->owner), 'an unbound, unrestricted user is never scoped');

        $query = SalesOrder::on('tenant')->newQuery();
        $scope->applyToSales($query, $this->deliveryUser);
        $this->assertSame(['S-MINE'], $query->pluck('sale_no')->all(), 'only his terminal AND his order type');

        // the owner's list is untouched — all four sales.
        $ownerQuery = SalesOrder::on('tenant')->newQuery();
        $scope->applyToSales($ownerQuery, $this->owner);
        $this->assertSame(4, $ownerQuery->count());
    }

    public function test_terminal_assignment_also_limits_the_pos_branch_list(): void
    {
        $otherBranchId = $this->makeBranch(['name' => 'Other Branch']);
        $scope = app(UserDataScope::class);

        $this->assertSame(
            [$this->branchId],
            $scope->branchesForPos($this->deliveryUser)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertTrue($scope->branchesForPos($this->owner)->contains('id', $otherBranchId));
    }

    public function test_out_of_scope_sale_ids_are_denied_individually(): void
    {
        $scope = app(UserDataScope::class);
        $mine = SalesOrder::on('tenant')->where('sale_no', 'S-MINE')->firstOrFail();
        $otherTerminal = SalesOrder::on('tenant')->where('sale_no', 'S-OTHER-DELIVERY')->firstOrFail();
        $otherType = SalesOrder::on('tenant')->where('sale_no', 'S-MY-TERMINAL-DINEIN')->firstOrFail();

        $this->assertFalse($scope->deniesSale($this->deliveryUser, $mine));
        $this->assertTrue($scope->deniesSale($this->deliveryUser, $otherTerminal), 'another terminal is refused');
        $this->assertTrue($scope->deniesSale($this->deliveryUser, $otherType), 'another order type is refused');
        $this->assertFalse($scope->deniesSale($this->owner, $otherTerminal), 'the owner sees everything');
    }

    public function test_report_filters_are_forced_into_scope_even_when_the_request_asks_for_more(): void
    {
        $scope = app(UserDataScope::class);

        // a hand-edited URL asking for the OTHER terminal and dine-in
        $forced = $scope->applyToReportFilters(
            ['terminal_id' => $this->otherTerminalId, 'order_type' => 'dine_in'],
            $this->deliveryUser
        );
        $this->assertNull($forced['terminal_id']);
        $this->assertNull($forced['order_type']);
        $this->assertSame([$this->deliveryTerminalId], $forced['allowed_terminal_ids']);
        $this->assertSame(['delivery'], $forced['allowed_order_types']);

        // "All" (no filter) is likewise narrowed to his own scope
        $narrowed = $scope->applyToReportFilters(['terminal_id' => null, 'order_type' => null], $this->deliveryUser);
        $this->assertNull($narrowed['terminal_id']);
        $this->assertNull($narrowed['order_type']);
        $this->assertSame([$this->deliveryTerminalId], $narrowed['allowed_terminal_ids']);
        $this->assertSame(['delivery'], $narrowed['allowed_order_types']);

        // the owner keeps whatever he asked for (including "All")
        $ownerFilters = $scope->applyToReportFilters(['terminal_id' => null, 'order_type' => null], $this->owner);
        $this->assertNull($ownerFilters['terminal_id']);
        $this->assertNull($ownerFilters['order_type']);
    }
}
