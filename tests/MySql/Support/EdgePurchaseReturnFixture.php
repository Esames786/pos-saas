<?php

namespace Tests\MySql\Support;

use App\Models\Tenant\Branch;
use App\Services\Edge\EdgePurchaseReturnCacheService;
use App\Services\Edge\EdgePurchaseReturnProjectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OFFLINE EDGE — F3 test seed: the Cloud's purchasing truth on the CURRENT tenant connection — chart, a supplier with a payable,
 * a stock-tracked purchasable product with official stock, a POSTED goods receipt (10 received at 300) linked to a bill — and
 * the projection → appliance step for single-database tests.
 */
trait EdgePurchaseReturnFixture
{
    protected int $prSupplierId;
    protected int $prProductId;
    protected int $prUnitId;
    protected int $prGrnId;
    protected int $prGrnLineId;
    protected int $prBillId;

    protected const PR_RECEIVED = 10.0;
    protected const PR_UNIT_COST = 300.0;
    protected const PR_STOCK = 100.0;        // official branch stock on hand (received earlier + this receipt)
    protected const PR_OPENING = 50000.0;

    protected const PR_TABLES = [
        'edge_inbound_purchase_return_ingestions', 'purchase_return_lines', 'purchase_returns', 'goods_receipt_lines', 'goods_receipts',
        'purchase_bill_lines', 'purchase_bills', 'supplier_ledgers', 'supplier_payments', 'suppliers',
        'stock_ledgers', 'stock_balances', 'inventory_batches', 'journal_lines', 'journal_entries', 'accounts',
    ];

    protected const PR_EDGE_TABLES = [
        'edge_local_purchase_return_lines', 'edge_local_purchase_return_events', 'edge_purchase_return_applied_events', 'edge_purchase_return_grn_lines', 'edge_purchase_return_grns',
        'edge_local_supplier_finance_effects', 'edge_local_supplier_finance_events', 'edge_supplier_finance_applied_events', 'edge_supplier_finance_suppliers',
    ];

    /** Seeds on the current tenant connection; the product/unit/category must already exist (pass their ids). */
    protected function seedCloudPurchaseReturnTruth(int $branchId, int $productId, int $unitId): void
    {
        $conn = DB::connection('tenant');
        if ($conn->table('accounts')->count() === 0) {
            (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        }
        $now = now();
        $this->prProductId = $productId;
        $this->prUnitId = $unitId;
        $this->prSupplierId = $conn->table('suppliers')->insertGetId(['code' => 'SUP-PR', 'name' => 'Return Test Supplier', 'opening_balance' => self::PR_OPENING, 'current_balance' => self::PR_OPENING, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $conn->table('supplier_ledgers')->insert(['supplier_id' => $this->prSupplierId, 'entry_type' => 'opening_balance', 'direction' => 'debit', 'amount' => self::PR_OPENING, 'balance_after' => self::PR_OPENING, 'reference_type' => 'opening_balance', 'reference_id' => $this->prSupplierId, 'reference_no' => 'OB-SUP-PR', 'created_at' => $now->copy()->subDays(2), 'updated_at' => $now->copy()->subDays(2)]);
        $this->prGrnId = $conn->table('goods_receipts')->insertGetId(['grn_no' => 'GRN-' . Str::upper(Str::random(5)), 'branch_id' => $branchId, 'supplier_id' => $this->prSupplierId, 'receipt_date' => $now->copy()->subDays(3)->toDateString(), 'status' => 'posted', 'posted_at' => $now->copy()->subDays(3), 'created_at' => $now->copy()->subDays(3), 'updated_at' => $now->copy()->subDays(3)]);
        $this->prGrnLineId = $conn->table('goods_receipt_lines')->insertGetId(['goods_receipt_id' => $this->prGrnId, 'product_id' => $productId, 'batch_no' => 'B-PR-1', 'quantity_received' => self::PR_RECEIVED, 'unit_cost' => self::PR_UNIT_COST, 'created_at' => $now, 'updated_at' => $now]);
        $this->prBillId = $conn->table('purchase_bills')->insertGetId(['bill_no' => 'PB-PR-1', 'supplier_id' => $this->prSupplierId, 'branch_id' => $branchId, 'goods_receipt_id' => $this->prGrnId, 'bill_date' => $now->copy()->subDays(3)->toDateString(), 'status' => 'posted', 'subtotal' => 3000, 'grand_total' => 3000, 'amount_paid' => 0, 'balance_due' => 3000, 'created_at' => $now, 'updated_at' => $now]);
        // Official stock the branch holds (a FEFO batch the return will draw from).
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$branchId}-{$productId}-pr", 'branch_id' => $branchId, 'product_id' => $productId, 'batch_no' => 'B-PR-1', 'received_date' => $now->copy()->subDays(3)->toDateString(), 'unit_cost' => self::PR_UNIT_COST, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$branchId}-{$productId}-0-{$batchId}", 'branch_id' => $branchId, 'product_id' => $productId, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => self::PR_STOCK, 'average_cost' => self::PR_UNIT_COST, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** Single-database tests: project from the same DB and apply it as the appliance's FRESH purchase-return cache. */
    protected function projectPurchaseReturnsToAppliance(int $branchId): array
    {
        $package = app(EdgePurchaseReturnProjectionService::class)->package(Branch::on('tenant')->findOrFail($branchId));
        app(EdgePurchaseReturnCacheService::class)->apply($package);
        DB::connection('tenant')->table('edge_local_meta')->update(['standby_purchase_return_watermark_seen' => $package['watermark'], 'standby_purchase_return_as_of_seen' => now()]);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);

        return $package;
    }
}
