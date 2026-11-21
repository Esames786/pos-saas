<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\POSController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalReturnService;
use App\Services\Edge\EdgeReturnableSaleCacheService;
use App\Services\Edge\EdgeReturnableSaleProjectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * PHASE 4 — GAP C (owner decision, 18 Oct 2026): "Standby Recent Orders must follow the Online cashier list rule."
 *
 * ONE database plays both parts, in order: first it is the CLOUD (the sales Online holds — a dine-in sale on a table, a
 * quick sale without one, a cancelled sale, a held check) and Online's `POSController::recentSales` answers; then the
 * Cloud projection (`EdgeReturnableSaleProjectionService::package`) is taken, the Cloud rows are removed, and the SAME
 * database is the APPLIANCE: the package is applied through `EdgeReturnableSaleCacheService::apply` (the standby's
 * mirror) and the real Branch Server route `GET /edge/local/pos/recent-sales` answers. The two JSON answers are compared
 * key by key (only the row id — a local id — differs). The owner's regression list, one test each:
 *
 *   dine-in sale with table → mirrored with the table label · quick/takeaway sale without table → no label · cancelled
 *   sale → listed exactly as Online lists it and NOT returnable · refresh/update of the mirror (no duplicate, no stale
 *   status, table move, partial return) · the shared POS Recent Orders rendering on Edge equals the Online rendering for
 *   the same data (HTTP-level JSON twin + the embedded view) · the return flow refuses a non-returnable mirrored sale.
 */
class EdgeRecentOrdersMirrorHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    /** The Online keys the shared view's Recent Orders row consumes (App\Support\Pos\RecentOrders::present). */
    private const ONLINE_KEYS = ['sale_no', 'order_type', 'status', 'payment_status', 'customer', 'delivery_channel', 'delivery_channel_type',
        'delivery_rider', 'waiter', 'vehicle_number', 'table', 'total', 'time', 'ago', 'printing'];

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $tableId;
    private int $waiterId;
    private int $productId;
    private int $cashMethodId;

    private int $dineInId;
    private int $quickId;
    private int $cancelledId;
    private int $heldId;

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
            'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances',
            'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'print_jobs', 'manager_approvals', 'model_has_permissions', 'permissions',
            'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters', 'customers',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'GC' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter 1']);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'G6', 'status' => 'available']);
        $this->waiterId = $this->makeWaiter($this->branchId, ['name' => 'Waiter Ali']);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'Karahi']), ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');

        // ── the Cloud's sales, oldest → newest (Online orders the list by id, newest first) ──
        $this->dineInId = $this->cloudSale(['order_type' => 'dine_in', 'restaurant_table_id' => $this->tableId, 'restaurant_waiter_id' => $this->waiterId,
            'customer_name' => 'Mr Zafar', 'customer_phone' => '0300-7777777', 'sale_date' => now()->subHours(3), 'completed_at' => now()->subHours(3)], 1250.5, 2);
        $this->quickId = $this->cloudSale(['order_type' => 'quick_sale', 'vehicle_number' => 'LEA-123', 'sale_date' => now()->subHours(2), 'completed_at' => now()->subHours(2)], 300.0, 3);
        $this->cancelledId = $this->cloudSale(['order_type' => 'dine_in', 'restaurant_table_id' => $this->tableId, 'status' => 'cancelled', 'sale_date' => now()->subHour(), 'completed_at' => null], 500.0, 5);
        $this->heldId = $this->cloudSale(['order_type' => 'takeaway', 'status' => 'held', 'sale_date' => now()->subMinutes(30), 'completed_at' => null], 100.0, 1);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    /** A sale as the Cloud tables hold it: one line of $qty Chicken Karahi, one cash tender of the grand total. */
    private function cloudSale(array $attrs, float $grandTotal, float $qty): int
    {
        $conn = DB::connection('tenant');
        $id = $this->makeSale($this->branchId, array_merge([
            'sale_no' => 'HS-' . Str::upper(Str::random(8)), 'terminal_id' => $this->terminalId, 'status' => 'paid', 'payment_status' => 'paid',
            'subtotal' => $grandTotal, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'service_charge_amount' => 0,
            'delivery_charge_amount' => 0, 'tip_amount' => 0, 'grand_total' => $grandTotal, 'paid_amount' => $grandTotal, 'change_amount' => 0,
            'inventory_posted' => 1, 'completed_at' => now(), 'created_by_user_id' => $this->userId,
        ], $attrs));
        $conn->table('sales_order_lines')->insert([
            'sales_order_id' => $id, 'line_kind' => 'standard', 'product_id' => $this->productId, 'product_name' => 'Chicken Karahi', 'quantity' => $qty,
            'returned_quantity' => 0, 'unit_price' => round($grandTotal / $qty, 2), 'unit_cost' => 40, 'cost_total' => 40 * $qty, 'discount_amount' => 0, 'tax_amount' => 0,
            'line_total' => $grandTotal, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payment = new \App\Models\Tenant\SalePayment(['sales_order_id' => $id, 'payment_method_id' => $this->cashMethodId, 'amount' => $grandTotal, 'tendered_amount' => $grandTotal, 'change_amount' => 0]);
        $payment->payment_uuid = (string) Str::ulid();
        $payment->save();

        return $id;
    }

    /** Online's answer — the real controller action (`tenant.api.pos.recent-sales`), on the Cloud's rows. */
    private function onlineList(?string $orderType = null): array
    {
        $params = ['branch_id' => $this->branchId] + ($orderType !== null ? ['order_type' => $orderType] : []);
        $response = app(POSController::class)->recentSales(Request::create('/api/pos/recent-sales', 'GET', $params));

        return $response->getData(true)['sales'];
    }

    /** The appliance's answer — the real Branch Server route the shared view calls. */
    private function edgeList(?string $orderType = null): array
    {
        return $this->getJson('/edge/local/pos/recent-sales' . ($orderType !== null ? '?order_type=' . $orderType : ''))->assertOk()->json('sales');
    }

    private function package(): array
    {
        return app(EdgeReturnableSaleProjectionService::class)->package(Branch::on('tenant')->find($this->branchId));
    }

    /** The database stops being the Cloud: its sales rows go; only the bootstrapped config (tables, waiters …) remains. */
    private function removeCloudSales(): void
    {
        $conn = DB::connection('tenant');
        $ids = $conn->table('sales_orders')->pluck('id')->all();
        $conn->table('sale_payments')->whereIn('sales_order_id', $ids)->delete();
        $conn->table('sales_order_lines')->whereIn('sales_order_id', $ids)->delete();
        $conn->table('sales_orders')->whereIn('id', $ids)->delete();
    }

    /** Apply a package as the standby worker does, and let the Cloud's advertised watermark equal it (a FRESH cache). */
    private function applyAsStandby(array $package): array
    {
        $stats = app(EdgeReturnableSaleCacheService::class)->apply($package);
        DB::connection('tenant')->table('edge_local_meta')->update(['standby_returnable_watermark_seen' => $package['watermark']]);

        return $stats;
    }

    /** Online rows → mirrored: take the projection, drop the Cloud rows, apply. Returns [onlineRows, package]. */
    private function mirror(): array
    {
        $online = $this->onlineList();
        $package = $this->package();
        $this->removeCloudSales();
        $this->applyAsStandby($package);
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();

        return [$online, $package];
    }

    private function localIdOf(int $cloudId): int
    {
        return (int) DB::connection('tenant')->table('edge_returnable_sales')->where('cloud_sales_order_id', $cloudId)->value('local_sales_order_id');
    }

    private function onlineKeys(array $row): array
    {
        return array_intersect_key($row, array_flip(self::ONLINE_KEYS));
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_dine_in_sale_with_table_is_mirrored_with_the_online_table_label(): void
    {
        [$online, $package] = $this->mirror();

        $shipped = collect($package['sales'])->firstWhere('cloud_sales_order_id', $this->dineInId);
        $this->assertSame($this->tableId, $shipped['restaurant_table_id'], 'the projection carries restaurant_table_id');
        $this->assertSame($this->waiterId, $shipped['restaurant_waiter_id']);
        $this->assertSame('paid', $shipped['payment_status']);
        $this->assertTrue($shipped['returnable']);

        $local = DB::connection('tenant')->table('sales_orders')->where('id', $this->localIdOf($this->dineInId))->first();
        $this->assertSame(EdgeReturnableSaleCacheService::SHADOW_STATUS, $local->status, 'storage: a shadow row');
        $this->assertSame($this->tableId, (int) $local->restaurant_table_id, 'the mirrored row points at the bootstrapped table row');

        $onlineRow = collect($online)->firstWhere('sale_no', $shipped['sale_no']);
        $edgeRow = collect($this->edgeList())->firstWhere('sale_no', $shipped['sale_no']);
        $this->assertSame('G6', $onlineRow['table']);
        $this->assertSame('G6', $edgeRow['table'], 'the SAME label Online renders (restaurantTable.table_no through the bootstrapped row)');
        $this->assertSame('Waiter Ali', $edgeRow['waiter']);
        $this->assertSame('Mr Zafar', $edgeRow['customer']);
        $this->assertSame('dine_in', $edgeRow['order_type']);
        $this->assertSame('paid', $edgeRow['status'], 'presentation: the CLOUD status, never the shadow marker');
        $this->assertSame('1,250.50', $edgeRow['total'], "Online's number_format(…, 2)");
        $this->assertSame('online', $edgeRow['origin']);
        $this->assertSame($this->onlineKeys($onlineRow), $this->onlineKeys($edgeRow));
    }

    public function test_quick_sale_without_table_is_mirrored_with_no_label(): void
    {
        [$online, $package] = $this->mirror();

        $shipped = collect($package['sales'])->firstWhere('cloud_sales_order_id', $this->quickId);
        $this->assertNull($shipped['restaurant_table_id']);
        $onlineRow = collect($online)->firstWhere('sale_no', $shipped['sale_no']);
        $edgeRow = collect($this->edgeList())->firstWhere('sale_no', $shipped['sale_no']);
        $this->assertNull($onlineRow['table']);
        $this->assertNull($edgeRow['table'], 'no table → no label, exactly as Online');
        $this->assertNull($edgeRow['waiter']);
        $this->assertSame('LEA-123', $edgeRow['vehicle_number']);
        $this->assertSame('Walk-in', $edgeRow['customer']);
        $this->assertSame('quick_sale', $edgeRow['order_type']);
        $this->assertSame('300.00', $edgeRow['total']);
        $this->assertSame($this->onlineKeys($onlineRow), $this->onlineKeys($edgeRow));
    }

    public function test_cancelled_sale_is_listed_exactly_as_online_and_is_not_returnable(): void
    {
        [$online, $package] = $this->mirror();

        $shipped = collect($package['sales'])->firstWhere('cloud_sales_order_id', $this->cancelledId);
        $this->assertNotNull($shipped, 'the projection carries the cancelled sale (Online lists it)');
        $this->assertSame('cancelled', $shipped['status']);
        $this->assertFalse($shipped['returnable']);
        $this->assertNull(collect($package['sales'])->firstWhere('cloud_sales_order_id', $this->heldId), 'a held check is never projected (Online never lists it)');

        // Online lists the cancelled sale FIRST (newest id) with its table; the Edge list reads the same.
        $this->assertSame($shipped['sale_no'], $online[0]['sale_no']);
        $this->assertSame('cancelled', $online[0]['status']);
        $this->assertSame('G6', $online[0]['table']);
        $edge = $this->edgeList();
        $this->assertSame($shipped['sale_no'], $edge[0]['sale_no'], 'the cancelled sale is listed where Online lists it');
        $this->assertSame($this->onlineKeys($online[0]), $this->onlineKeys($edge[0]));

        // … but it is NOT returnable: the mirror's Cloud status guards the return flow (search, open, page, post).
        $localId = $this->localIdOf($this->cancelledId);
        $this->assertSame('cancelled', DB::connection('tenant')->table('edge_returnable_sales')->where('cloud_sales_order_id', $this->cancelledId)->value('cloud_status'));
        $found = collect($this->getJson('/edge/local/pos/returns/search?q=')->assertOk()->json('sales'))->pluck('id')->all();
        $this->assertNotContains($localId, $found, 'the return search never offers a cancelled mirrored sale');
        $this->assertContains($this->localIdOf($this->dineInId), $found);
        $this->assertContains($this->localIdOf($this->quickId), $found);
        $this->getJson("/edge/local/pos/returns/sales/{$localId}")->assertStatus(422);
        $this->assertNull($this->get("/edge/local/pos/sales-returns/create?sales_order_id={$localId}")->assertOk()->viewData('salesOrder'), 'the shared return page opens without the sale');
        $this->assertNotNull($this->get('/edge/local/pos/sales-returns/create?sales_order_id=' . $this->localIdOf($this->dineInId))->assertOk()->viewData('salesOrder'));
    }

    public function test_refresh_updates_the_mirrored_row_without_duplicates(): void
    {
        [, $package] = $this->mirror();
        $count = fn () => [
            'shadows' => DB::connection('tenant')->table('sales_orders')->where('status', EdgeReturnableSaleCacheService::SHADOW_STATUS)->count(),
            'maps' => DB::connection('tenant')->table('edge_returnable_sales')->count(),
            'listed' => count($this->edgeList()),
        ];
        $this->assertSame(['shadows' => 3, 'maps' => 3, 'listed' => 3], $count());

        // The same package again → idempotent.
        $this->applyAsStandby($package);
        $this->assertSame(['shadows' => 3, 'maps' => 3, 'listed' => 3], $count(), 'a re-applied package never duplicates a row');

        // The Cloud moves on: the dine-in sale is CANCELLED after it was mirrored; the quick sale is moved onto table G6
        // and partially returned (1 of 3). The next package carries the new facts; the mirrored rows FOLLOW them.
        $next = $package;
        $next['watermark'] = 'rw:next';
        $next['as_of'] = now()->toIso8601String();
        foreach ($next['sales'] as &$sale) {
            if ($sale['cloud_sales_order_id'] === $this->dineInId) {
                $sale['status'] = 'cancelled';
                $sale['returnable'] = false;
                $sale['updated_at'] = now()->toIso8601String();
            }
            if ($sale['cloud_sales_order_id'] === $this->quickId) {
                $sale['status'] = 'partially_returned';
                $sale['restaurant_table_id'] = $this->tableId;
                $sale['lines'][0]['returned_quantity'] = 1;
                $sale['updated_at'] = now()->toIso8601String();
            }
        }
        unset($sale);
        $stats = $this->applyAsStandby($next);
        $this->assertSame(3, $stats['sales']);
        $this->assertSame(['shadows' => 3, 'maps' => 3, 'listed' => 3], $count(), 'an update never duplicates a row');

        $edge = collect($this->edgeList())->keyBy('sale_no');
        $dineIn = $edge->get(collect($package['sales'])->firstWhere('cloud_sales_order_id', $this->dineInId)['sale_no']);
        $quick = $edge->get(collect($package['sales'])->firstWhere('cloud_sales_order_id', $this->quickId)['sale_no']);
        $this->assertSame('cancelled', $dineIn['status'], 'no stale status: the list shows the Cloud cancellation');
        $this->assertSame('G6', $dineIn['table']);
        $this->assertSame('partially_returned', $quick['status']);
        $this->assertSame('G6', $quick['table'], 'the table move is reflected');

        // Returnability follows too: the cancelled one is gone from the return flow, the partially returned one stays.
        $found = collect($this->getJson('/edge/local/pos/returns/search?q=')->assertOk()->json('sales'))->pluck('id')->all();
        $this->assertNotContains($this->localIdOf($this->dineInId), $found, 'cancelled after mirroring → not returnable on the next package');
        $this->assertContains($this->localIdOf($this->quickId), $found);
        $view = $this->getJson('/edge/local/pos/returns/sales/' . $this->localIdOf($this->quickId))->assertOk()->json();
        $this->assertTrue($view['fresh']);
        $this->assertSame(2.0, (float) $view['lines'][0]['returnable'], 'sold 3 − Cloud returned 1');
        $this->getJson('/edge/local/pos/returns/sales/' . $this->localIdOf($this->dineInId))->assertStatus(422);
    }

    public function test_shared_pos_recent_orders_on_edge_equals_the_online_rendering_for_the_same_data(): void
    {
        $online = $this->onlineList();
        $onlineDineIn = $this->onlineList('dine_in');
        $package = $this->package();
        $this->removeCloudSales();

        // Mirror in TWO packages so the LOCAL ids are NOT in Cloud order: the oldest Cloud sale (dine-in) arrives last and
        // gets the highest local id — the list must still be in Online's order (the Cloud's id), newest first.
        $first = $package;
        $first['sales'] = array_values(array_filter($package['sales'], fn ($s) => $s['cloud_sales_order_id'] !== $this->dineInId));
        $this->applyAsStandby($first);
        $this->applyAsStandby($package);
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->assertGreaterThan($this->localIdOf($this->cancelledId), $this->localIdOf($this->dineInId), 'precondition: local id order ≠ Cloud id order');

        // HTTP-level JSON twin: same rows, same order, same values on every key the shared view consumes.
        $edge = $this->edgeList();
        $this->assertCount(3, $online);
        $this->assertSame(array_column($online, 'sale_no'), array_column($edge, 'sale_no'), "Online's order (the Cloud's id, newest first), not local-id order");
        foreach ($online as $i => $row) {
            $this->assertSame($this->onlineKeys($row), $this->onlineKeys($edge[$i]), "row {$i} ({$row['sale_no']}) renders the same on both runtimes");
        }
        $this->assertNotContains(DB::connection('tenant')->table('sales_orders')->where('status', 'held')->value('sale_no'), array_column($edge, 'sale_no'));

        // The optional type filter narrows identically.
        $edgeDineIn = $this->edgeList('dine_in');
        $this->assertSame(array_column($onlineDineIn, 'sale_no'), array_column($edgeDineIn, 'sale_no'));
        $this->assertCount(2, $edgeDineIn);

        // The RENDER: the shared view (one file on both runtimes) draws the row from exactly these keys.
        $html = $this->get('/edge/local/pos')->assertOk()->assertViewIs('tenant.pos.index')->getContent();
        $this->assertStringContainsString('id="completedOrdersModal"', $html);
        foreach (["s.table", "s.waiter", "s.vehicle_number", "s.customer || 'Walk-in'", "s.total", "s.time || s.ago"] as $consumed) {
            $this->assertStringContainsString($consumed, $html, "the shared view consumes {$consumed}");
        }
    }

    public function test_the_return_flow_refuses_a_non_returnable_mirrored_sale(): void
    {
        $this->mirror();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500])->assertStatus(201);
        $cancelledLocal = $this->localIdOf($this->cancelledId);
        $paidLocal = $this->localIdOf($this->dineInId);
        $lineOf = fn (int $saleId) => (int) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->value('id');

        // HTTP: the post is refused with a business message; nothing is written.
        $this->postJson('/edge/local/pos/returns', ['sales_order_id' => $cancelledLocal, 'refund_method' => 'cash', 'reason' => 'test',
            'lines' => [['sales_order_line_id' => $lineOf($cancelledLocal), 'quantity' => 1]]])->assertStatus(422);
        $this->assertSame(0, DB::connection('tenant')->table('sales_returns')->where('edge_origin', 'local')->count());

        // Service: the same guard, explicitly.
        $user = User::on('tenant')->find($this->userId);
        try {
            app(EdgeLocalReturnService::class)->processReturn($cancelledLocal, [['sales_order_line_id' => $lineOf($cancelledLocal), 'quantity' => 1]], 'test', 'cash', 100.0, $user, $this->terminalId);
            $this->fail('a cancelled mirrored sale must not be returnable');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sale', $e->errors());
        }

        // Control: the PAID mirrored sale on the same cache IS returnable (fresh cache, cash refund).
        $view = $this->getJson("/edge/local/pos/returns/sales/{$paidLocal}")->assertOk()->json();
        $this->assertTrue($view['can_return'], json_encode($view));
        $this->assertSame('online', $view['sale']['origin']);
    }
}
