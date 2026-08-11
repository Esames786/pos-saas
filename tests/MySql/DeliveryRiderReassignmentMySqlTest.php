<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\SalesOrderController;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MySql\Support\TenantFixtures;

class DeliveryRiderReassignmentMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private User $user;
    private int $ownChannelId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_order_rider_assignments', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'delivery_riders', 'delivery_channels', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['name' => 'Delivery Branch']);
        $this->user = User::on('tenant')->findOrFail($this->makeUser(['name' => 'Dispatch Manager']));
        $this->actingAs($this->user, 'tenant');

        $this->ownChannelId = $this->tenant()->table('delivery_channels')->insertGetId([
            'name' => 'Own Delivery',
            'type' => 'own',
            'commission_percent' => 0,
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_paid_delivery_rider_can_be_reassigned_without_touching_commercial_data(): void
    {
        $oldRiderId = $this->makeRider($this->branchId, 'Rider One');
        $newRiderId = $this->makeRider($this->branchId, 'Rider Two');
        $saleId = $this->makeSale($this->branchId, [
            'order_type' => 'delivery',
            'delivery_channel_id' => $this->ownChannelId,
            'delivery_rider_id' => $oldRiderId,
            'subtotal' => 1200,
            'discount_amount' => 50,
            'tax_amount' => 25,
            'delivery_charge_amount' => 100,
            'grand_total' => 1275,
            'paid_amount' => 1275,
            'change_amount' => 0,
            'inventory_posted' => 1,
            'kot_print_count' => 2,
            'receipt_print_count' => 1,
        ]);

        $before = (array) $this->tenant()->table('sales_orders')->find($saleId);

        $this->reassign($saleId, $newRiderId, 'Original rider unavailable');

        $after = (array) $this->tenant()->table('sales_orders')->find($saleId);
        $this->assertSame($newRiderId, (int) $after['delivery_rider_id']);

        unset($before['delivery_rider_id'], $before['updated_at'], $after['delivery_rider_id'], $after['updated_at']);
        $this->assertSame($before, $after, 'Reassignment must not change sale totals, status, stock, payment, or print state.');

        $this->assertDatabaseHas('sales_order_rider_assignments', [
            'sales_order_id' => $saleId,
            'branch_id' => $this->branchId,
            'from_delivery_rider_id' => $oldRiderId,
            'to_delivery_rider_id' => $newRiderId,
            'from_rider_name' => 'Rider One',
            'to_rider_name' => 'Rider Two',
            'changed_by_user_id' => $this->user->id,
            'changed_by_name' => 'Dispatch Manager',
            'reason' => 'Original rider unavailable',
        ], 'tenant');
    }

    public function test_cross_branch_inactive_and_external_channel_assignments_are_rejected(): void
    {
        $oldRiderId = $this->makeRider($this->branchId, 'Current Rider');
        $otherBranchId = $this->makeBranch(['name' => 'Other Branch']);
        $crossBranchRiderId = $this->makeRider($otherBranchId, 'Other Branch Rider');
        $inactiveRiderId = $this->makeRider($this->branchId, 'Inactive Rider', 'inactive');
        $saleId = $this->makeSale($this->branchId, [
            'order_type' => 'delivery',
            'delivery_channel_id' => $this->ownChannelId,
            'delivery_rider_id' => $oldRiderId,
        ]);

        $this->assertRejected($saleId, $crossBranchRiderId);
        $this->assertRejected($saleId, $inactiveRiderId);

        $externalChannelId = $this->tenant()->table('delivery_channels')->insertGetId([
            'name' => 'Marketplace',
            'type' => 'aggregator',
            'commission_percent' => 10,
            'is_active' => 1,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->tenant()->table('sales_orders')->where('id', $saleId)->update([
            'delivery_channel_id' => $externalChannelId,
        ]);
        $this->assertRejected($saleId, $this->makeRider($this->branchId, 'Replacement Rider'));

        $this->assertSame($oldRiderId, (int) $this->tenant()->table('sales_orders')->where('id', $saleId)->value('delivery_rider_id'));
        $this->assertSame(0, $this->tenant()->table('sales_order_rider_assignments')->count());
    }

    private function makeRider(?int $branchId, string $name, string $status = 'active'): int
    {
        return $this->tenant()->table('delivery_riders')->insertGetId([
            'branch_id' => $branchId,
            'name' => $name,
            'phone' => '0300-0000000',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function reassign(int $saleId, int $riderId, ?string $reason = null): void
    {
        $request = Request::create('/sales-orders/' . $saleId . '/rider', 'PATCH', [
            'delivery_rider_id' => $riderId,
            'reason' => $reason,
        ]);
        $request->setUserResolver(fn () => $this->user);

        app(SalesOrderController::class)->updateRider(
            $request,
            SalesOrder::on('tenant')->findOrFail($saleId),
        );
    }

    private function assertRejected(int $saleId, int $riderId): void
    {
        try {
            $this->reassign($saleId, $riderId);
            $this->fail('Expected rider reassignment to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }
}
