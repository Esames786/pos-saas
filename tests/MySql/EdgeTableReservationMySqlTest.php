<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeTableReservation;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeRestoreService;
use App\Services\Edge\EdgeTableReservationService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — ONLINE POS PARITY: table reservations offline.
 *
 * Proves the same operator behavior as the online POS: reserve / view / cancel; a reservation cannot be
 * placed on an open table; only one active reservation per table (concurrency winner); and — the load-bearing
 * behavior — opening a reserved table carries its customer onto the session and the first held sale. Plus the
 * offline-correct property: the reservation is captured by the encrypted backup and survives restore.
 */
class EdgeTableReservationMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $userId;
    private int $terminalId;
    private int $tableId;
    private int $productId;
    private int $customerId;
    private string $customerUuid;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_local_table_reservations', 'edge_operational_stock_movements', 'edge_operational_stock_balances',
            'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_backups',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'restaurant_table_sessions', 'restaurant_tables',
            'restaurant_floors', 'restaurant_waiters', 'shifts', 'products', 'categories', 'customers', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CASH' . Str::random(4), 'allowed_order_types' => json_encode(['dine_in', 'quick_sale', 'takeaway'])]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 100]);
        $floor = DB::table('restaurant_floors')->insertGetId(['branch_id' => $this->branchId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $this->tableId = DB::table('restaurant_tables')->insertGetId(['branch_id' => $this->branchId, 'restaurant_floor_id' => $floor, 'table_no' => 'T1', 'name' => 'T1', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $this->customerUuid = (string) Str::ulid();
        $this->customerId = DB::table('customers')->insertGetId(['name' => 'Alice', 'phone' => '0300', 'customer_uuid' => $this->customerUuid, 'created_at' => now(), 'updated_at' => now()]);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function svc(): EdgeTableReservationService
    {
        return app(EdgeTableReservationService::class);
    }

    private function user(): User
    {
        return User::on('tenant')->find($this->userId);
    }

    private function openShift(): void
    {
        app(ShiftService::class)->open(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId), \App\Models\Tenant\Terminal::on('tenant')->find($this->terminalId), $this->userId, 0.0);
    }

    public function test_reserve_view_and_cancel(): void
    {
        $r = $this->svc()->reserve($this->tableId, ['customer_id' => $this->customerId, 'reserved_for' => now()->addHour(), 'note' => 'window seat'], $this->user());
        $this->assertSame(EdgeTableReservation::STATUS_ACTIVE, $r->status);
        $this->assertSame($this->customerUuid, $r->customer_uuid, 'the reservation snapshots the customer uuid');
        $this->assertSame('Alice', $r->customer_name);

        $this->assertNotNull($this->svc()->activeFor($this->tableId));

        $this->svc()->cancel($this->tableId, $this->user());
        $this->assertNull($this->svc()->activeFor($this->tableId), 'a cancelled reservation is no longer active');
        $this->assertSame(EdgeTableReservation::STATUS_CANCELLED, EdgeTableReservation::find($r->id)->status);
    }

    public function test_only_one_active_reservation_per_table(): void
    {
        $this->svc()->reserve($this->tableId, ['customer_name' => 'A'], $this->user());
        $this->expectExceptionMessage('already has an active reservation');
        $this->svc()->reserve($this->tableId, ['customer_name' => 'B'], $this->user());
    }

    public function test_cannot_reserve_an_open_table(): void
    {
        $this->openShift();
        app(EdgeLocalPosService::class)->openTableSession($this->tableId, ['guest_count' => 2], $this->user(), $this->terminalId);

        $this->expectExceptionMessage('currently open');
        $this->svc()->reserve($this->tableId, ['customer_name' => 'A'], $this->user());
    }

    public function test_opening_a_reserved_table_carries_the_customer_onto_the_held_sale(): void
    {
        $this->openShift();
        $this->svc()->reserve($this->tableId, ['customer_id' => $this->customerId], $this->user());

        // Open the reserved table -> the reservation is seated onto the session.
        $session = app(EdgeLocalPosService::class)->openTableSession($this->tableId, ['guest_count' => 2], $this->user(), $this->terminalId);
        $this->assertSame(EdgeTableReservation::STATUS_SEATED, EdgeTableReservation::where('restaurant_table_id', $this->tableId)->value('status'));
        $this->assertSame($session->id, (int) EdgeTableReservation::where('restaurant_table_id', $this->tableId)->value('restaurant_table_session_id'));

        // A held sale on that session (with NO explicit customer) inherits the reservation's customer.
        $sale = app(EdgeLocalPosService::class)->holdOrReviseSale([
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $session->id,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ], $this->user(), $this->terminalId);

        $fresh = SalesOrder::on('tenant')->find($sale->id);
        $this->assertSame($this->customerId, (int) $fresh->customer_id, 'the reserved customer carried onto the order');
        $this->assertSame('Alice', $fresh->customer_name);
    }

    public function test_a_reservation_survives_backup_and_restore(): void
    {
        config([
            'edge.backup.path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-resv-' . Str::lower(Str::random(6)),
            'edge.backup.recovery_key' => base64_encode(random_bytes(32)), 'edge.backup.recovery_key_id' => 'k1', 'edge.backup.retired_keys' => [],
        ]);
        $r = $this->svc()->reserve($this->tableId, ['customer_id' => $this->customerId, 'note' => 'keep me'], $this->user());
        $backup = app(EdgeBackupService::class)->backup();

        DB::table('edge_local_table_reservations')->delete();
        $this->assertSame(0, EdgeTableReservation::count());

        app(EdgeRestoreService::class)->restore($backup->path, $this->branchId);
        $restored = EdgeTableReservation::where('reservation_uuid', $r->reservation_uuid)->first();
        $this->assertNotNull($restored, 'the reservation survived backup + restore');
        $this->assertSame('keep me', $restored->note);
        $this->assertSame($this->customerUuid, $restored->customer_uuid);
    }
}
