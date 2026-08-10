<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * RECEIPT-PHASE-1 (reported from the counter 2026-08-11): printing the Bill / Preview before
 * payment made the CLOSING bill never print. The pre-payment proforma satisfied the auto-receipt
 * "ensure once" rule, so Review & Pay reused it instead of raising the customer's real bill.
 *
 * A proforma and a final bill are different documents. This locks that in, and also keeps the
 * original guarantee: an auto-receipt replay after payment must NEVER print a second bill.
 */
class ReceiptProformaVsFinalMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['print_jobs', 'sale_payments', 'sales_order_lines', 'sales_orders', 'printers', 'products', 'categories', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch();
        $this->productId = $this->makeProduct($this->makeCategory());
        $this->makePrinter(['code' => 'P1', 'print_role' => 'both', 'branch_id' => $this->branchId, 'is_default' => 1]);
    }

    private function heldSale(): SalesOrder
    {
        $id = $this->makeSale($this->branchId, ['status' => 'held', 'sale_no' => 'HS-1', 'grand_total' => 500]);
        $this->makeSaleLine($id, $this->productId, ['quantity' => 2, 'unit_price' => 250, 'line_total' => 500]);

        return SalesOrder::findOrFail($id);
    }

    public function test_preview_before_payment_does_not_suppress_the_final_bill(): void
    {
        $service = app(PrintJobService::class);
        $sale = $this->heldSale();

        // 1) cashier prints the proforma from Bill / Preview (sale not yet paid)
        $proforma = $service->queueReceipt($sale, ensureOnce: true);
        $this->assertStringContainsString('proforma', (string) $proforma->logical_key);

        // pressing it twice does not spam duplicates
        $this->assertSame($proforma->id, $service->queueReceipt($sale->fresh(), ensureOnce: true)->id);

        // 2) the sale is paid → the FINAL bill must be its own job
        $sale->update(['status' => 'paid', 'paid_amount' => 500, 'completed_at' => now()]);
        $final = $service->queueReceipt($sale->fresh(), ensureOnce: true);

        $this->assertNotSame($proforma->id, $final->id, 'the closing bill must print even after a preview');
        $this->assertStringContainsString('final', (string) $final->logical_key);
        $this->assertNotSame($proforma->logical_key, $final->logical_key, 'distinct identities, so neither dedupes the other');

        // 3) the original guarantee still holds: a replay after payment reuses the final bill
        $this->assertSame($final->id, $service->queueReceipt($sale->fresh(), ensureOnce: true)->id, 'no duplicate final bill on replay');
        $this->assertSame(2, PrintJob::where('document_type', 'receipt')->count(), 'exactly one proforma + one final');
    }

    public function test_a_legacy_receipt_raised_after_payment_still_blocks_a_duplicate(): void
    {
        $service = app(PrintJobService::class);
        $sale = $this->heldSale();
        $sale->update(['status' => 'paid', 'paid_amount' => 500, 'completed_at' => now()->subMinute()]);

        // a job created by the OLD code (legacy key) after payment
        $legacy = PrintJob::create([
            'job_no' => 'PJ-LEGACY', 'logical_key' => 'receipt:auto:sale-' . $sale->id, 'copy_no' => 1,
            'branch_id' => $this->branchId, 'document_type' => 'receipt', 'print_status' => 'printed',
            'reference_type' => 'sales_order', 'reference_id' => $sale->id, 'attempts' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($legacy->id, $service->queueReceipt($sale->fresh(), ensureOnce: true)->id,
            'an existing post-payment receipt is still honoured — no surprise reprint of old sales');
    }

    public function test_an_explicit_reprint_always_makes_a_new_job(): void
    {
        $service = app(PrintJobService::class);
        $sale = $this->heldSale();
        $sale->update(['status' => 'paid', 'paid_amount' => 500, 'completed_at' => now()]);

        $first = $service->queueReceipt($sale->fresh(), ensureOnce: true);
        $reprint = $service->queueReceipt($sale->fresh(), ensureOnce: false);

        $this->assertNotSame($first->id, $reprint->id, 'reprint is never deduped');
        $this->assertNull($reprint->logical_key);
    }
}
