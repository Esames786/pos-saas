<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\SalesReturnController;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * The Sales Returns list now scopes to the operator's terminal and honours a date filter — it was
 * the one sales screen that showed every terminal's returns to a terminal-scoped cashier.
 */
class SalesReturnListScopeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_returns', 'sales_orders', 'terminal_user', 'terminals', 'branches', 'users',
        ]);
    }

    private function makeReturn(int $branchId, int $orderId, int $userId): void
    {
        DB::connection('tenant')->table('sales_returns')->insert([
            'return_no' => 'SR-' . uniqid(), 'sales_order_id' => $orderId, 'branch_id' => $branchId,
            'return_date' => now(), 'subtotal' => 100, 'discount_amount' => 0, 'tax_amount' => 0,
            'grand_total' => 100, 'refund_method' => 'cash', 'refund_amount' => 100,
            'status' => 'posted', 'created_by_user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_terminal_cashier_sees_only_their_terminals_returns(): void
    {
        $branchId = $this->makeBranch();
        $t1 = $this->makeTerminal($branchId, ['name' => 'Delivery']);
        $t2 = $this->makeTerminal($branchId, ['name' => 'Dine In']);
        $cashier = $this->makeUser();
        DB::connection('tenant')->table('terminal_user')->insert(['user_id' => $cashier, 'terminal_id' => $t1]);

        $o1 = $this->makeSale($branchId, ['terminal_id' => $t1, 'order_type' => 'delivery']);
        $o2 = $this->makeSale($branchId, ['terminal_id' => $t2, 'order_type' => 'dine_in']);
        $this->makeReturn($branchId, $o1, $cashier);   // cashier's terminal
        $this->makeReturn($branchId, $o2, $cashier);   // other terminal

        $this->actingAs(User::on('tenant')->find($cashier), 'tenant');
        Auth::shouldUse('tenant');

        $view = app(SalesReturnController::class)->index(Request::create('/sales-returns', 'GET'));
        $returns = $view->getData()['returns'];

        $this->assertSame(1, $returns->total(), 'A terminal cashier must see only their own terminal\'s returns.');
        $this->assertSame($o1, (int) $returns->first()->sales_order_id);
    }

    public function test_an_unbound_owner_sees_all_returns(): void
    {
        $branchId = $this->makeBranch();
        $t1 = $this->makeTerminal($branchId, ['name' => 'Delivery']);
        $t2 = $this->makeTerminal($branchId, ['name' => 'Dine In']);
        $owner = $this->makeUser();   // no terminal binding

        $this->makeReturn($branchId, $this->makeSale($branchId, ['terminal_id' => $t1]), $owner);
        $this->makeReturn($branchId, $this->makeSale($branchId, ['terminal_id' => $t2]), $owner);

        $this->actingAs(User::on('tenant')->find($owner), 'tenant');
        Auth::shouldUse('tenant');

        $view = app(SalesReturnController::class)->index(Request::create('/sales-returns', 'GET'));
        $this->assertSame(2, $view->getData()['returns']->total(), 'An unbound owner sees every terminal\'s returns.');
    }

    public function test_today_range_excludes_an_older_return(): void
    {
        $branchId = $this->makeBranch();
        $owner = $this->makeUser();
        $order = $this->makeSale($branchId);

        // one return today, one dated well in the past
        $this->makeReturn($branchId, $order, $owner);
        DB::connection('tenant')->table('sales_returns')->insert([
            'return_no' => 'SR-OLD', 'sales_order_id' => $order, 'branch_id' => $branchId,
            'return_date' => now()->subDays(10), 'subtotal' => 50, 'discount_amount' => 0, 'tax_amount' => 0,
            'grand_total' => 50, 'refund_method' => 'cash', 'refund_amount' => 50, 'status' => 'posted',
            'created_by_user_id' => $owner, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::on('tenant')->find($owner), 'tenant');
        Auth::shouldUse('tenant');

        $view = app(SalesReturnController::class)->index(Request::create('/sales-returns', 'GET', ['range' => 'today']));
        $this->assertSame(1, $view->getData()['returns']->total(), 'Today must exclude the 10-day-old return.');
    }
}
