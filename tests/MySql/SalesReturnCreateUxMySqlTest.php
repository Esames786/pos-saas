<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Ajax\SaleLookupController;
use App\Http\Controllers\Tenant\SalesReturnController;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\User;
use App\Services\Sales\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * PHASE 2 (return UX) — the Sales-Return "Create" flow: the sale search is scoped to the operator's
 * terminals/order types (it was the last unscoped sales surface); the refund method defaults to how
 * the customer originally paid; and a single-item return no longer fails on the untouched zero-qty
 * lines the form posts alongside it.
 */
class SalesReturnCreateUxMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'sales_ledgers',
            'sales_return_lines', 'sales_returns', 'sale_payments', 'payment_methods',
            'sales_order_lines', 'sales_orders', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'accounts', 'terminal_user', 'terminals', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder)->run();
    }

    public function test_sale_search_is_scoped_to_the_operators_terminal(): void
    {
        $branchId = $this->makeBranch();
        $t1 = $this->makeTerminal($branchId, ['name' => 'Delivery']);
        $t2 = $this->makeTerminal($branchId, ['name' => 'Dine In']);
        $cashier = $this->makeUser();
        DB::connection('tenant')->table('terminal_user')->insert(['user_id' => $cashier, 'terminal_id' => $t1]);

        $mine = $this->makeSale($branchId, ['terminal_id' => $t1, 'order_type' => 'delivery', 'status' => 'paid']);
        $this->makeSale($branchId, ['terminal_id' => $t2, 'order_type' => 'dine_in', 'status' => 'paid']);

        $this->actingAs(User::on('tenant')->find($cashier), 'tenant');
        Auth::shouldUse('tenant');

        $results = app(SaleLookupController::class)(Request::create('/ajax/sales', 'GET'))->getData(true)['results'];
        $this->assertCount(1, $results, 'a terminal cashier only finds his own terminal\'s sales');
        $this->assertSame($mine, (int) $results[0]['id']);

        // An unbound owner finds both.
        $owner = $this->makeUser();
        $this->actingAs(User::on('tenant')->find($owner), 'tenant');
        Auth::shouldUse('tenant');
        $ownerResults = app(SaleLookupController::class)(Request::create('/ajax/sales', 'GET'))->getData(true)['results'];
        $this->assertCount(2, $ownerResults, 'an unbound owner finds every sale');
    }

    public function test_create_defaults_refund_method_to_the_original_payment(): void
    {
        $branchId = $this->makeBranch();
        $userId = $this->makeUser();
        $saleId = $this->makeSale($branchId, ['status' => 'paid', 'subtotal' => 100, 'grand_total' => 100, 'paid_amount' => 100]);

        $cardMethod = DB::connection('tenant')->table('payment_methods')->insertGetId([
            'code' => 'CARD', 'name' => 'Card', 'method_type' => 'card', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('sale_payments')->insert([
            'sales_order_id' => $saleId, 'payment_method_id' => $cardMethod, 'amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');

        $data = app(SalesReturnController::class)
            ->create(Request::create('/sales-returns/create', 'GET', ['sales_order_id' => $saleId]), app(SalesReturnService::class))
            ->getData();

        $this->assertSame('card', $data['defaultRefundMethod'], 'the refund method pre-selects the original pay method');
    }

    public function test_single_item_return_ignores_the_untouched_zero_lines(): void
    {
        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $userId = $this->makeUser();
        $saleId = $this->makeSale($branchId, [
            'created_by_user_id' => $userId, 'status' => 'paid',
            'subtotal' => 300, 'grand_total' => 300, 'paid_amount' => 300,
        ]);
        $lineIds = [];
        foreach (['A', 'B', 'C'] as $name) {
            $p = $this->makeProduct($categoryId, ['is_stock_tracked' => 0, 'inventory_consumption_method' => 'none']);
            $lineIds[] = $this->makeSaleLine($saleId, $p, [
                'line_kind' => 'standard', 'product_name' => "Item {$name}",
                'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
            ]);
        }

        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');

        // The form posts EVERY line — the two the cashier did not touch stay at 0. This used to be
        // rejected by min:0.001; now the zero lines are skipped and only Item A is returned.
        $request = Request::create('/sales-returns', 'POST', [
            'sales_order_id' => $saleId,
            'refund_method' => 'cash',
            'lines' => [
                ['sales_order_line_id' => $lineIds[0], 'quantity' => 1],
                ['sales_order_line_id' => $lineIds[1], 'quantity' => 0],
                ['sales_order_line_id' => $lineIds[2], 'quantity' => 0],
            ],
        ]);

        $response = app(SalesReturnController::class)->store($request, app(SalesReturnService::class));

        $this->assertStringContainsString('/sales-returns/', $response->getTargetUrl(), 'a single-item return posts successfully');
        $returns = SalesReturn::query()->get();
        $this->assertCount(1, $returns, 'one return created');
        $this->assertCount(1, $returns->first()->lines, 'only the one filled line was returned; the zero lines were skipped');
        $this->assertSame($lineIds[0], (int) $returns->first()->lines->first()->sales_order_line_id);
    }

    /**
     * On a partially-returned order the already-fully-returned line must still post a
     * quantity. A `disabled` input is never submitted, so lines[N][quantity] went missing
     * while its hidden sales_order_line_id was still posted, and the backend rejected the
     * whole return with "lines.N.quantity is required" — blocking every partial return's
     * remaining items. The line is `readonly` (posts its 0), never `disabled`.
     */
    public function test_a_fully_returned_line_stays_submittable_so_the_rest_can_be_returned(): void
    {
        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $userId = $this->makeUser();
        $saleId = $this->makeSale($branchId, [
            'created_by_user_id' => $userId, 'status' => 'partially_returned',
            'subtotal' => 520, 'grand_total' => 520, 'paid_amount' => 520,
        ]);
        $pA = $this->makeProduct($categoryId, ['is_stock_tracked' => 0, 'inventory_consumption_method' => 'none']);
        $pB = $this->makeProduct($categoryId, ['is_stock_tracked' => 0, 'inventory_consumption_method' => 'none']);
        // Line A is already fully returned; line B still has one returnable.
        $lineA = $this->makeSaleLine($saleId, $pA, ['product_name' => 'Raita', 'quantity' => 1, 'returned_quantity' => 1, 'unit_price' => 70, 'line_total' => 70]);
        $lineB = $this->makeSaleLine($saleId, $pB, ['product_name' => 'Beef', 'quantity' => 1, 'returned_quantity' => 0, 'unit_price' => 450, 'line_total' => 450]);

        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');

        // Outside the HTTP middleware, share the empty errors bag the layout expects.
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag);

        $html = app(SalesReturnController::class)
            ->create(Request::create('/sales-returns/create', 'GET', ['sales_order_id' => $saleId]), app(SalesReturnService::class))
            ->render();

        // No returnable-quantity input may be disabled (a disabled one is never submitted).
        $this->assertDoesNotMatchRegularExpression('/return-qty[^>]*\sdisabled/', $html,
            'a fully-returned line must not disable its quantity input — it would drop from the payload');
        // The fully-returned line is readonly, so it still posts its 0.
        $this->assertMatchesRegularExpression('/return-qty[^>]*\sreadonly/', $html,
            'the fully-returned line posts a readonly 0 so the remaining line can be returned');

        // End-to-end: the payload the fixed form now sends (fully-returned line at 0, the rest > 0) posts.
        $request = Request::create('/sales-returns', 'POST', [
            'sales_order_id' => $saleId,
            'refund_method' => 'cash',
            'lines' => [
                ['sales_order_line_id' => $lineA, 'quantity' => 0],
                ['sales_order_line_id' => $lineB, 'quantity' => 1],
            ],
        ]);
        $response = app(SalesReturnController::class)->store($request, app(SalesReturnService::class));

        $this->assertStringContainsString('/sales-returns/', $response->getTargetUrl(), 'the remaining item returns successfully');
        $this->assertSame($lineB, (int) SalesReturn::query()->latest('id')->first()->lines->first()->sales_order_line_id);
    }
}
