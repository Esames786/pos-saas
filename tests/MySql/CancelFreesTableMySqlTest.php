<?php

namespace Tests\MySql;

use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Sales\KotCancellationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * CANCEL-FREES-TABLE-1 — a cancelled order gives its table back.
 *
 * Cancelling only set the sale to `cancelled`. The table session stayed open and the table stayed
 * "Occupied" with a Rs 0 total, so nobody could be seated and nobody could tell why: Kashif Food's
 * Table 9 sat like that on 30 Aug until it was freed by hand.
 *
 * The case that decides the shape of the fix is SPLIT BILL — one session can carry several bills, and
 * cancelling one must not pull the table out from under the others.
 */
class CancelFreesTableMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $userId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_line_cancellations',
            'void_reasons', 'sales_order_lines', 'sales_orders', 'restaurant_table_sessions',
            'restaurant_tables', 'restaurant_floors', 'category_printer_mappings', 'printers',
            'products', 'categories', 'terminals', 'role_has_permissions', 'model_has_permissions', 'model_has_roles', 'permissions', 'roles', 'branches', 'users',
        ]);

        $this->userId = $this->makeUser(['employee_code' => 'CF' . Str::random(4)]);
        $user = User::on('tenant')->find($this->userId);
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');

        // cancelHeldOrder() refuses without this — it is the operator's right to void food already
        // sent to the kitchen.
        DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $user->givePermissionTo(
            \Spatie\Permission\Models\Permission::on('tenant')->firstOrCreate(
                ['name' => 'tenant.pos.void-kot-item', 'guard_name' => 'tenant']
            )
        );

        $this->branchId = $this->makeBranch(['held_kot_cancellation_approval_mode' => 'auto_approve', 'held_kot_line_cancellation_approval_mode' => 'auto_approve']);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'Food', 'slug' => 'food-' . Str::random(4)]));
    }

    /** @return array{0:int,1:int} table id, session id */
    private function occupiedTable(): array
    {
        $tableId = $this->makeTable($this->branchId);
        DB::connection('tenant')->table('restaurant_tables')->where('id', $tableId)->update(['status' => 'occupied']);
        $sessionId = DB::connection('tenant')->table('restaurant_table_sessions')->insertGetId([
            'session_no' => 'TS-' . Str::upper(Str::random(10)),
            'branch_id' => $this->branchId, 'restaurant_table_id' => $tableId, 'status' => 'open',
            'opened_by_user_id' => $this->userId, 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$tableId, $sessionId];
    }

    private function heldSale(?int $sessionId, ?int $tableId, float $total = 550): SalesOrder
    {
        $id = $this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => $sessionId ? 'dine_in' : 'takeaway',
            'restaurant_table_session_id' => $sessionId, 'restaurant_table_id' => $tableId,
            'grand_total' => $total,
        ]);
        $this->makeSaleLine($id, $this->productId, ['quantity' => 1, 'kot_sent_quantity' => 1]);

        return SalesOrder::on('tenant')->with('lines')->findOrFail($id);
    }

    private function cancel(SalesOrder $sale): void
    {
        $reason = DB::connection('tenant')->table('void_reasons')->insertGetId([
            'name' => 'Order cancelled by customer', 'reason_type' => 'void', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(KotCancellationService::class)->cancelHeldOrder($sale, $reason, null, $this->userId);
    }

    private function tableStatus(int $tableId): string
    {
        return (string) DB::connection('tenant')->table('restaurant_tables')->where('id', $tableId)->value('status');
    }

    /** The straightforward case: one bill on the table, cancelled, table comes back. */
    public function test_cancelling_the_only_bill_frees_the_table(): void
    {
        [$tableId, $sessionId] = $this->occupiedTable();
        $sale = $this->heldSale($sessionId, $tableId);

        $this->cancel($sale);

        $this->assertSame('available', $this->tableStatus($tableId), 'the table is free again.');
        $this->assertSame('cancelled', (string) RestaurantTableSession::on('tenant')->find($sessionId)->status);
        $this->assertNotNull(RestaurantTableSession::on('tenant')->find($sessionId)->closed_at);
    }

    /** SPLIT BILL: cancelling one bill must not take the table from the other. */
    public function test_a_split_bill_keeps_the_table_until_the_last_one_goes(): void
    {
        [$tableId, $sessionId] = $this->occupiedTable();
        $first = $this->heldSale($sessionId, $tableId, 550);
        $second = $this->heldSale($sessionId, $tableId, 1200);

        $this->cancel($first);

        $this->assertSame('occupied', $this->tableStatus($tableId),
            'the second bill is still running — the table is NOT free.');
        $this->assertSame('open', (string) RestaurantTableSession::on('tenant')->find($sessionId)->status);

        $this->cancel($second->refresh()->load('lines'));

        $this->assertSame('available', $this->tableStatus($tableId), 'now nothing is left, so the table is free.');
    }

    /** A PAID bill on the session counts as live — the table belongs to it until it is closed. */
    public function test_a_paid_bill_on_the_session_keeps_the_table(): void
    {
        [$tableId, $sessionId] = $this->occupiedTable();
        $paid = $this->heldSale($sessionId, $tableId, 900);
        DB::connection('tenant')->table('sales_orders')->where('id', $paid->id)->update(['status' => 'paid']);
        $held = $this->heldSale($sessionId, $tableId, 550);

        $this->cancel($held);

        $this->assertSame('occupied', $this->tableStatus($tableId),
            'a paid bill still belongs to this table — closing it is the waiter\'s job, not a cancel\'s.');
    }

    /** No session at all (takeaway / delivery / quick sale) — nothing to release, and no error. */
    public function test_a_takeaway_cancel_touches_no_table(): void
    {
        [$tableId, ] = $this->occupiedTable();
        $sale = $this->heldSale(null, null);

        $this->cancel($sale);

        $this->assertSame('occupied', $this->tableStatus($tableId), 'an unrelated table is untouched.');
        $this->assertSame('cancelled', (string) SalesOrder::on('tenant')->find($sale->id)->status);
    }

    /** Someone already closed the session — leave it alone rather than reopening or erroring. */
    public function test_an_already_closed_session_is_left_alone(): void
    {
        [$tableId, $sessionId] = $this->occupiedTable();
        $sale = $this->heldSale($sessionId, $tableId);
        DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)
            ->update(['status' => 'closed', 'closed_at' => now()]);
        DB::connection('tenant')->table('restaurant_tables')->where('id', $tableId)->update(['status' => 'available']);

        $this->cancel($sale);

        $this->assertSame('closed', (string) RestaurantTableSession::on('tenant')->find($sessionId)->status,
            'a session closed by someone else is not rewritten.');
        $this->assertSame('available', $this->tableStatus($tableId));
    }
}
