<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Sales\SalesService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE-SYNC-ENGINE-1A §3 — design-verification spike (like ConfigRefreshFkSpikeTest).
 *
 * PURPOSE: pin, against the REAL SalesService on MySQL, the architectural verdict of the sync
 * preflight (docs/design/OFFLINE_SYNC_ENGINE_V1.md): Cloud sync ingestion must NOT reuse
 * SalesService::finalizePaidSale for an ingested Edge sale.
 *
 * The trap: an ingested envelope arrives ALREADY 'paid'. finalizePaidSale early-returns on
 * status==='paid' INSIDE its transaction — skipping official FEFO stock, COGS, and settlement —
 * while the code AFTER the transaction (GL posting, cash-bank movement, department custody) still
 * runs. A naive ingestion would therefore post finance from zero-COGS lines with no official stock
 * movement behind it. This spike proves the skip half executable-y (stock/COGS untouched for an
 * already-paid sale), so the dedicated EdgeInboundSaleIngestionService design rests on a pinned
 * fact, not an assumption. It does NOT implement ingestion.
 */
class EdgeSyncIngestPreflightSpikeTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    public function test_finalize_on_an_already_paid_sale_skips_official_stock_and_cogs(): void
    {
        $this->cleanTenant([
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'sale_payments', 'sales_order_lines', 'sales_orders',
            'products', 'categories', 'branches',
        ]);
        $conn = DB::connection('tenant');

        $branchId = $this->makeBranch();
        $catId = $this->makeCategory();
        // A stock-tracked product WITH available official stock — the strongest form of the proof:
        // stock exists and could be consumed, yet the early-return never touches it.
        $prodId = $this->makeProduct($catId, ['is_stock_tracked' => 1, 'inventory_consumption_method' => 'stock_item']);
        $batchId = $conn->table('inventory_batches')->insertGetId([
            'batch_key' => "spike-{$branchId}-{$prodId}", 'branch_id' => $branchId, 'product_id' => $prodId,
            'batch_no' => 'B-1', 'received_date' => now()->toDateString(), 'unit_cost' => 40,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $conn->table('stock_balances')->insert([
            'balance_key' => "spike-{$branchId}-{$prodId}-{$batchId}", 'branch_id' => $branchId,
            'product_id' => $prodId, 'inventory_batch_id' => $batchId,
            'quantity_on_hand' => 100, 'average_cost' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The shape an INGESTED Edge sale would have: already paid, inventory never posted, zero COGS.
        $saleId = $this->makeSale($branchId, ['status' => 'paid', 'grand_total' => 500, 'paid_amount' => 500]);
        $lineId = $this->makeSaleLine($saleId, $prodId, ['quantity' => 2, 'unit_price' => 250, 'unit_cost' => 0, 'cost_total' => 0]);

        $sale = SalesOrder::on('tenant')->findOrFail($saleId);
        $this->assertFalse((bool) $sale->inventory_posted, 'precondition: official inventory not posted');

        app(SalesService::class)->finalizePaidSale($sale);

        // The early-return skipped EVERYTHING inside the transaction:
        $this->assertSame(0, $conn->table('stock_ledgers')->where('product_id', $prodId)->count(),
            'official FEFO stock movement was SKIPPED for the already-paid sale');
        $this->assertSame(100.0, (float) $conn->table('stock_balances')->where('product_id', $prodId)->value('quantity_on_hand'),
            'official on-hand quantity untouched');
        $this->assertSame(0.0, (float) $conn->table('sales_order_lines')->where('id', $lineId)->value('cost_total'),
            'COGS still zero — finance posting after the transaction would book a zero-COGS sale');
        $this->assertFalse((bool) $sale->fresh()->inventory_posted,
            'inventory_posted stays false — the skip is silent, not recorded as done');
    }
}
