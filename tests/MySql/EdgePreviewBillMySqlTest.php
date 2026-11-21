<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPosService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — ONLINE POS PARITY: Preview Bill uses the SAME server-side sale/totals truth and mutates
 * NOTHING — no sale, payment, stock movement, outbox, KOT, or receipt job.
 */
class EdgePreviewBillMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $userId;
    private int $terminalId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'sale_payments', 'sales_order_lines', 'sales_orders', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'PB' . Str::random(4), 'allowed_order_types' => json_encode(['quick_sale', 'takeaway'])]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 100]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        Auth::guard('tenant')->setUser(User::on('tenant')->find($this->userId));
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    public function test_preview_bill_returns_totals_and_mutates_nothing(): void
    {
        $counts = fn () => [
            'sales' => DB::table('sales_orders')->count(),
            'outbox' => DB::table('edge_sync_outbox')->count(),
            'kot' => DB::table('kot_batches')->count(),
            'prints' => DB::table('print_jobs')->count(),
            'movements' => DB::table('edge_operational_stock_movements')->count(),
        ];
        $before = $counts();

        $preview = app(EdgeLocalPosService::class)->previewBill([
            'order_type' => 'quick_sale',
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
        ], User::on('tenant')->find($this->userId), $this->terminalId);

        // Same totals truth as a real sale.
        $this->assertArrayHasKey('totals', $preview);
        $this->assertEqualsWithDelta(200.0, (float) $preview['totals']['grand_total'], 0.01, 'running bill = 2 x 100');

        // ZERO mutation.
        $this->assertSame($before, $counts(), 'Preview Bill created no sale / outbox / KOT / print / stock movement');
    }
}
