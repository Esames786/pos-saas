<?php

namespace Tests\MySql;

use App\Services\Reports\SalesReportService;
use Tests\MySql\Support\TenantFixtures;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 (L): the sales report groups/filters by BUSINESS date, so a sale
 * rung after midnight is reported on the business day of its shift, not the wall-clock date.
 */
class SalesReportBusinessDateTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    public function test_after_midnight_sale_reports_on_its_business_day(): void
    {
        $this->cleanTenant(['sales_orders', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);

        // Belongs to business day 5 Aug, but was actually rung at 02:00 on 6 Aug (after midnight).
        $this->makeSale($branchId, [
            'status' => 'paid', 'business_date' => '2026-08-05',
            'sale_date' => '2026-08-06 02:00:00', 'grand_total' => 100,
        ]);
        // A genuine 6 Aug sale.
        $this->makeSale($branchId, [
            'status' => 'paid', 'business_date' => '2026-08-06',
            'sale_date' => '2026-08-06 12:00:00', 'grand_total' => 50,
        ]);

        $svc = app(SalesReportService::class);

        $aug5 = $svc->summary(['date_from' => '2026-08-05', 'date_to' => '2026-08-05', 'branch_ids' => [$branchId]]);
        $this->assertEquals(1, $aug5['totals']->order_count, 'The after-midnight sale is counted on 5 Aug.');
        $this->assertEquals(100, (float) $aug5['totals']->net_sales);

        $aug6 = $svc->summary(['date_from' => '2026-08-06', 'date_to' => '2026-08-06', 'branch_ids' => [$branchId]]);
        $this->assertEquals(1, $aug6['totals']->order_count, 'Only the genuine 6 Aug sale lands on 6 Aug.');
        $this->assertEquals(50, (float) $aug6['totals']->net_sales);

        // Daily breakdown keys off the business day, too.
        $days = $aug5['daily']->pluck('sale_day')->all();
        $this->assertEquals(['2026-08-05'], $days);
    }

    public function test_filtered_summary_only_subtracts_returns_from_matching_sales(): void
    {
        $this->cleanTenant(['sales_returns', 'sales_orders', 'terminals', 'branches', 'users']);
        $branchId = $this->makeBranch();
        $terminalA = $this->makeTerminal($branchId);
        $terminalB = $this->makeTerminal($branchId);
        $cashierA = $this->makeUser();
        $cashierB = $this->makeUser();

        $saleA = $this->makeSale($branchId, [
            'terminal_id' => $terminalA, 'created_by_user_id' => $cashierA,
            'order_type' => 'delivery', 'business_date' => '2026-08-11',
            'sale_date' => '2026-08-11 10:00:00', 'status' => 'returned',
            'grand_total' => 100,
        ]);
        $saleB = $this->makeSale($branchId, [
            'terminal_id' => $terminalB, 'created_by_user_id' => $cashierB,
            'order_type' => 'takeaway', 'business_date' => '2026-08-11',
            'sale_date' => '2026-08-11 11:00:00', 'status' => 'returned',
            'grand_total' => 200,
        ]);

        foreach ([[$saleA, 100], [$saleB, 200]] as [$saleId, $amount]) {
            $this->tenant()->table('sales_returns')->insert([
                'return_no' => 'SR-' . $saleId,
                'sales_order_id' => $saleId,
                'branch_id' => $branchId,
                'return_date' => '2026-08-11 12:00:00',
                'subtotal' => $amount,
                'grand_total' => $amount,
                'refund_method' => 'cash',
                'refund_amount' => $amount,
                'status' => 'posted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $result = app(SalesReportService::class)->summary([
            'date_from' => '2026-08-11', 'date_to' => '2026-08-11',
            'branch_ids' => [$branchId], 'terminal_id' => $terminalA,
            'order_type' => 'delivery', 'cashier_id' => $cashierA,
        ]);

        $this->assertSame(100.0, (float) $result['totals']->billed);
        $this->assertSame(100.0, (float) $result['totals']->returns_amount);
        $this->assertSame(0.0, (float) $result['totals']->net_sales);
    }
}
