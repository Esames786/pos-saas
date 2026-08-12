<?php

namespace Tests\MySql;

use App\Exceptions\BranchLocalEdgeException;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Services\Departments\DepartmentInventoryService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-SPLITBRAIN-STOCK-1 — the OFFICIAL-stock authority fence.
 *
 * There must never be two official stock authorities for one branch. `InventoryService`
 * (postIn/postOutFefo/transfer) is the sole runtime writer of stock_balances/stock_ledgers/
 * inventory_batches (proven by the direct-write census). This suite proves the fence at that
 * choke point:
 *
 *   - Cloud + a branch handed to its Branch Server (local_edge active/closing/suspended) → fail
 *     CLOSED with BranchLocalEdgeException::CODE_ACTIVE, before a single row is written.
 *   - transfer() fences BOTH endpoints — a move whose destination is a Local-Mode branch is still
 *     split-brain even if the source is normal.
 *   - Branch Server instance → fail CLOSED even for its OWN bound branch (stricter than the sale
 *     fence: official FEFO/costing is Cloud authority, applied later by sync ingestion).
 *   - Cloud + a normal branch (cloud/inactive/pending) → UNCHANGED: real posts still succeed. This
 *     is the zero-regression proof for all of production today.
 *   - The department custody sub-ledger (secondary authority) fails closed the same way.
 *
 * The branch-server direction for InventoryService is additionally covered by
 * EdgeBranchServerFencingMySqlTest; this suite owns the Cloud+Local-Mode direction.
 */
class EdgeSplitBrainStockMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'department_stock_ledgers', 'department_stock_balances', 'departments',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'branches',
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(), ['is_stock_tracked' => 1]);
    }

    protected function tearDown(): void
    {
        config(['app.role' => null, 'app.edge_branch_id' => null, 'app.edge_tenant_code' => null]);
        parent::tearDown();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function normalBranch(): int
    {
        return $this->makeBranch(); // sales_operating_mode defaults to 'cloud'
    }

    private function localModeBranch(string $status = 'active'): int
    {
        return $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => $status]);
    }

    private function seedBalance(int $branchId, float $qty = 25, float $cost = 7): void
    {
        $key = $branchId.'-'.$this->productId.'-0-0';
        DB::connection('tenant')->table('stock_balances')->insert([
            'balance_key' => $key, 'branch_id' => $branchId, 'product_id' => $this->productId,
            'quantity_on_hand' => $qty, 'average_cost' => $cost, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function stockRowCounts(): array
    {
        return [
            'stock_balances' => DB::connection('tenant')->table('stock_balances')->count(),
            'stock_ledgers' => DB::connection('tenant')->table('stock_ledgers')->count(),
            'inventory_batches' => DB::connection('tenant')->table('inventory_batches')->count(),
        ];
    }

    /** Assert the call throws the split-brain exception with the given code, and writes NO stock rows. */
    private function assertFencedWithCode(string $expectedCode, callable $call): void
    {
        $before = $this->stockRowCounts();
        try {
            $call();
            $this->fail('Official stock mutation must be fenced.');
        } catch (BranchLocalEdgeException $e) {
            $this->assertSame($expectedCode, $e->reasonCode, 'wrong fence reason code');
        }
        $this->assertSame($before, $this->stockRowCounts(), 'a fenced call must not write any stock row');
    }

    // ── Cloud + Local-Mode branch: every official entry fails closed ────────────

    public function test_cloud_refuses_official_in_out_transfer_for_a_local_mode_branch(): void
    {
        $branchId = $this->localModeBranch();
        $this->seedBalance($branchId);
        $branch = Branch::on('tenant')->find($branchId);
        $product = Product::on('tenant')->find($this->productId);
        $normal = Branch::on('tenant')->find($this->normalBranch());

        $key = $branchId.'-'.$this->productId.'-0-0';
        $qtyBefore = (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('quantity_on_hand');

        $this->assertFencedWithCode(BranchLocalEdgeException::CODE_ACTIVE,
            fn () => app(InventoryService::class)->postIn($branch, $product, null, 5, 10, 'purchase'));
        $this->assertFencedWithCode(BranchLocalEdgeException::CODE_ACTIVE,
            fn () => app(InventoryService::class)->postOutFefo($branch, $product, null, 5, 'sale'));
        $this->assertFencedWithCode(BranchLocalEdgeException::CODE_ACTIVE,
            fn () => app(InventoryService::class)->transfer($branch, $normal, $product, null, 5, 7, 'transfer', 1, 'T-1'));

        $this->assertSame($qtyBefore, (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('quantity_on_hand'),
            'the Local-Mode branch balance VALUE must be unchanged by any fenced call');
    }

    /** closing and suspended are also "handed to the server" — both must fence. */
    public function test_closing_and_suspended_statuses_also_fence(): void
    {
        foreach (['closing', 'suspended'] as $status) {
            $branch = Branch::on('tenant')->find($this->localModeBranch($status));
            $product = Product::on('tenant')->find($this->productId);
            $this->assertFencedWithCode(BranchLocalEdgeException::CODE_ACTIVE,
                fn () => app(InventoryService::class)->postIn($branch, $product, null, 3, 10, 'purchase'));
        }
    }

    // ── transfer fences BOTH endpoints ──────────────────────────────────────────

    public function test_transfer_is_refused_when_only_the_destination_is_local_mode(): void
    {
        $source = Branch::on('tenant')->find($this->normalBranch());
        $this->seedBalance($source->id);
        $dest = Branch::on('tenant')->find($this->localModeBranch());
        $product = Product::on('tenant')->find($this->productId);

        $key = $source->id.'-'.$this->productId.'-0-0';
        $qtyBefore = (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('quantity_on_hand');

        $this->assertFencedWithCode(BranchLocalEdgeException::CODE_ACTIVE,
            fn () => app(InventoryService::class)->transfer($source, $dest, $product, null, 5, 7, 'transfer', 1, 'T-2'));

        // The fence runs BEFORE the source OUT leg — the normal source keeps its full quantity.
        $this->assertSame($qtyBefore, (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('quantity_on_hand'),
            'a destination-fenced transfer must not decrement the source');
    }

    public function test_transfer_is_refused_when_only_the_source_is_local_mode(): void
    {
        $source = Branch::on('tenant')->find($this->localModeBranch());
        $dest = Branch::on('tenant')->find($this->normalBranch());
        $product = Product::on('tenant')->find($this->productId);

        $this->assertFencedWithCode(BranchLocalEdgeException::CODE_ACTIVE,
            fn () => app(InventoryService::class)->transfer($source, $dest, $product, null, 5, 7, 'transfer', 1, 'T-3'));
    }

    // ── Branch Server: official stock fails even for the BOUND branch (stricter than sale) ──

    public function test_branch_server_refuses_official_stock_even_for_its_own_bound_branch(): void
    {
        $branchId = $this->normalBranch(); // a plain cloud branch...
        $branch = Branch::on('tenant')->find($branchId);
        $product = Product::on('tenant')->find($this->productId);

        // Configure THIS instance as the Branch Server bound to exactly this branch. The official-stock
        // fence deliberately never inspects the binding (unlike the sale fence) — it fails closed on any
        // Branch Server. Setting the binding here proves that "the branch matches" does NOT open the gate.
        config([
            'app.role' => 'branch_server',
            'app.edge_branch_id' => $branchId,
            'app.edge_tenant_code' => 'test',
        ]);

        // Binding matches — a sale would be allowed — but official stock is NEVER posted on the server.
        $this->assertFencedWithCode(BranchLocalEdgeException::CODE_BRANCH_SERVER_OFFICIAL_STOCK,
            fn () => app(InventoryService::class)->postIn($branch, $product, null, 5, 10, 'purchase'));
        $this->assertFencedWithCode(BranchLocalEdgeException::CODE_BRANCH_SERVER_OFFICIAL_STOCK,
            fn () => app(InventoryService::class)->postOutFefo($branch, $product, null, 1, 'sale'));
    }

    // ── Department custody sub-ledger (secondary authority) fences the same way ──

    public function test_department_custody_sink_is_refused_for_a_local_mode_branch(): void
    {
        $branchId = $this->localModeBranch();
        $before = DB::connection('tenant')->table('department_stock_ledgers')->count();

        // The fence runs before the department belongs-to-branch lookup, so no department row is needed.
        try {
            app(DepartmentInventoryService::class)->postIn($branchId, 999, $this->productId, null, null, 5, 10, 'branch_issue_in');
            $this->fail('Department custody mutation must be fenced for a Local-Mode branch.');
        } catch (BranchLocalEdgeException $e) {
            $this->assertSame(BranchLocalEdgeException::CODE_ACTIVE, $e->reasonCode);
        }
        $this->assertSame($before, DB::connection('tenant')->table('department_stock_ledgers')->count(),
            'no department ledger row may be written by a fenced call');
    }

    // ── zero-regression: a NORMAL cloud branch still posts official stock exactly as before ──

    public function test_cloud_normal_branch_still_posts_official_stock_unchanged(): void
    {
        $branchId = $this->normalBranch();
        $branch = Branch::on('tenant')->find($branchId);
        $product = Product::on('tenant')->find($this->productId);

        // IN: creates a balance + a ledger row.
        $ledger = app(InventoryService::class)->postIn($branch, $product, null, 20, 5, 'purchase');
        $this->assertNotNull($ledger->id);
        $key = $branchId.'-'.$this->productId.'-0-0';
        $this->assertSame(20.0, (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('quantity_on_hand'));

        // OUT: decrements.
        app(InventoryService::class)->postOutFefo($branch, $product, null, 8, 'sale');
        $this->assertSame(12.0, (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('quantity_on_hand'),
            'normal-branch FEFO out still decrements (fence is transparent on cloud)');

        // TRANSFER to another normal branch still moves stock.
        $dest = Branch::on('tenant')->find($this->normalBranch());
        app(InventoryService::class)->transfer($branch, $dest, $product, null, 4, 5, 'transfer', 1, 'T-OK');
        $this->assertSame(8.0, (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('quantity_on_hand'),
            'normal-branch transfer still decrements the source');
        $destKey = $dest->id.'-'.$this->productId.'-0-0';
        $this->assertSame(4.0, (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $destKey)->value('quantity_on_hand'),
            'normal-branch transfer still credits the destination');
    }
}
