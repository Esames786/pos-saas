<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Services\Printing\EscPosPayloadService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;
use Tests\Support\AssertsThermalSlipEnd;

/**
 * SLIP-END-LINES-1 (17 Nov 2026). The owner's photo of a Kashif Food bill showed blank paper under it.
 * His instruction: "KOT, reminder, preview sub pe fix karo — lekin printer cutter chalna chahye".
 *
 * Every slip must end with its last printed line, then the cut, with no blank lines between. The cut must
 * still be there. This file covers the documents the POS prints through the real builder: the paid bill,
 * the bill preview (a bill before payment), and the report. The KOT, reminder and catering slips are pinned
 * in their own tests with the same assertion.
 */
class ThermalSlipEndMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use AssertsThermalSlipEnd;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['print_jobs', 'receipt_layout_settings', 'sale_payments', 'sales_order_lines', 'sales_orders', 'products', 'categories', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
    }

    private function bill(int $saleId): string
    {
        return app(EscPosPayloadService::class)->build(PrintJob::findOrFail($this->makePrintJob(null, [
            'document_type' => 'receipt', 'print_status' => 'queued', 'printed_at' => null,
            'reference_type' => 'sales_order', 'reference_id' => $saleId, 'branch_id' => $this->branchId,
        ])));
    }

    private function sale(string $status, float $paid): int
    {
        $productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'none', 'is_stock_tracked' => 0]);
        $saleId = $this->makeSale($this->branchId, [
            'status' => $status, 'subtotal' => 1000, 'grand_total' => 1000, 'paid_amount' => $paid,
        ]);
        $this->makeSaleLine($saleId, $productId, ['quantity' => 2, 'unit_price' => 500, 'line_total' => 1000]);

        return $saleId;
    }

    public function test_the_bill_and_the_bill_preview_end_with_their_last_line_then_the_cut(): void
    {
        $paid = $this->sale('paid', 1000);
        $preview = $this->sale('held', 0);

        // Default layout: branding ON, so "Bingoopos.com" is the last line.
        $this->assertStringContainsString('Bingoopos.com', $this->bill($paid));
        $this->assertSlipEndsWithOneLineThenTheCut($this->bill($paid), 'paid bill, default layout');
        $this->assertSlipEndsWithOneLineThenTheCut($this->bill($preview), 'bill preview (before payment)');

        // The owner's shape: branding OFF, the footer (delivery phone numbers) is the last line.
        DB::connection('tenant')->table('receipt_layout_settings')->insert([
            'branch_id' => $this->branchId, 'document_type' => 'receipt', 'paper_size' => '80mm',
            'show_bingoo_branding' => 0, 'footer_text' => 'Delivery: 0300 0000000', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bill = $this->bill($paid);
        $this->assertStringContainsString("Delivery: 0300 0000000\n\x1D\x56\x42\x00", $bill, 'footer line, then straight to the cut');
        $this->assertSlipEndsWithOneLineThenTheCut($bill, 'paid bill, footer last');
        $this->assertSlipEndsWithOneLineThenTheCut($this->bill($preview), 'bill preview, footer last');
    }

    public function test_the_report_ends_with_its_last_rule_then_the_cut(): void
    {
        $meta = ['business_name' => 'Test Kitchen', 'label' => 'Z', 'date_from' => '2026-11-17', 'date_to' => '2026-11-17', 'generated' => '17-Nov-2026 23:50', 'paper' => '80mm'];
        $cat = ['id' => 1, 'name' => 'Burgers', 'sold_qty' => 2, 'returned_qty' => 0, 'net_qty' => 2, 'net' => 1000, 'returns_amount' => 0, 'net_value' => 1000, 'children' => []];

        $this->assertSlipEndsWithOneLineThenTheCut(
            app(EscPosPayloadService::class)->buildReport(['meta' => $meta, 'sections' => ['categories'], 'categories' => [$cat]]),
            'report (Quick Report / Report Center sent to the printer)'
        );
    }
}
