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
}
