<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrderLineCancellation;
use App\Services\Reports\SalesReportEngine;
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

    /**
     * REPORT-BUSINESS-DATE-1: a return is reported on the BUSINESS day of the order it reverses,
     * not the wall-clock date it was punched. A refund handed back at 07:14 on 20 Aug — while the
     * shift that opened 19 Aug was still open — must reduce 19 Aug, not 20 Aug. This is the exact
     * Khatri defect: three 08-19 returns (1,450) had leaked onto the 08-20 Report Center overview.
     */
    public function test_a_return_reports_on_its_orders_business_day_not_the_calendar_return_date(): void
    {
        $this->cleanTenant(['sales_return_lines', 'sales_returns', 'sales_orders', 'terminals', 'branches', 'users']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);

        // Belongs to business day 19 Aug, but rung at 07:13 on 20 Aug (after midnight, shift still open).
        $saleId = $this->makeSale($branchId, [
            'status' => 'paid', 'business_date' => '2026-08-19', 'sale_date' => '2026-08-20 07:13:00',
            'subtotal' => 1000, 'grand_total' => 1000, 'paid_amount' => 1000,
        ]);

        // The return is punched at 07:14 on 20 Aug but STAMPED with the order's business day (as the
        // service now does), so it books to 19 Aug.
        $this->tenant()->table('sales_returns')->insert([
            'return_no' => 'SR-BD-1', 'sales_order_id' => $saleId, 'branch_id' => $branchId,
            'return_date' => '2026-08-20 07:14:00', 'business_date' => '2026-08-19',
            'subtotal' => 450, 'grand_total' => 450, 'refund_method' => 'cash', 'refund_amount' => 450,
            'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Sale Report (SalesReportService::summary) — the return lands on 19 Aug…
        $svc = app(SalesReportService::class);
        $aug19 = $svc->summary(['date_from' => '2026-08-19', 'date_to' => '2026-08-19', 'branch_ids' => [$branchId]]);
        $this->assertSame(450.0, (float) $aug19['totals']->returns_amount, 'summary: return allocated to the order\'s business day');
        $this->assertSame(550.0, (float) $aug19['totals']->net_sales);

        // …and NOT on 20 Aug, the calendar day it was physically handed back.
        $aug20 = $svc->summary(['date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'branch_ids' => [$branchId]]);
        $this->assertSame(0.0, (float) $aug20['totals']->returns_amount, 'summary: after-midnight return no longer leaks onto the next calendar day');

        // Report Center overview (SalesReportEngine::overview) — the exact screen the client saw.
        $engine = app(SalesReportEngine::class);
        $o19 = $engine->overview($engine->normalizeFilters(['date_from' => '2026-08-19', 'date_to' => '2026-08-19']));
        $this->assertSame(450.0, (float) $o19['returns_amount'], 'overview: return on 19 Aug');
        $o20 = $engine->overview($engine->normalizeFilters(['date_from' => '2026-08-20', 'date_to' => '2026-08-20']));
        $this->assertSame(0.0, (float) $o20['returns_amount'], 'overview: nothing leaks to 20 Aug');
    }

    /**
     * A legacy return with NO business_date (pre-backfill) still falls back to its calendar
     * return_date via COALESCE — zero regression for un-backfilled rows.
     */
    public function test_a_legacy_return_without_business_date_falls_back_to_return_date(): void
    {
        $this->cleanTenant(['sales_return_lines', 'sales_returns', 'sales_orders', 'terminals', 'branches', 'users']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $saleId = $this->makeSale($branchId, ['status' => 'paid', 'business_date' => '2026-08-20', 'grand_total' => 500]);

        $this->tenant()->table('sales_returns')->insert([
            'return_no' => 'SR-LEG-1', 'sales_order_id' => $saleId, 'branch_id' => $branchId,
            'return_date' => '2026-08-20 12:00:00', 'business_date' => null, // legacy: never stamped
            'subtotal' => 200, 'grand_total' => 200, 'refund_method' => 'cash', 'refund_amount' => 200,
            'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = app(SalesReportService::class);
        $aug20 = $svc->summary(['date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'branch_ids' => [$branchId]]);
        $this->assertSame(200.0, (float) $aug20['totals']->returns_amount, 'a NULL business_date falls back to the calendar return_date');
    }

    /**
     * The same rule for item cancellations: a void punched after midnight on a still-open shift is
     * reported on the order's business day, not the calendar cancelled_at.
     */
    public function test_a_cancellation_reports_on_its_orders_business_day(): void
    {
        $this->cleanTenant(['sales_order_line_cancellations', 'void_reasons', 'sales_order_lines', 'sales_orders', 'products', 'categories', 'branches', 'users']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $productId = $this->makeProduct($this->makeCategory());

        $saleId = $this->makeSale($branchId, [
            'status' => 'paid', 'order_type' => 'dine_in',
            'business_date' => '2026-08-19', 'sale_date' => '2026-08-20 07:10:00',
        ]);
        $lineId = $this->makeSaleLine($saleId, $productId, ['product_name' => 'Biryani', 'quantity' => 2]);
        $reasonId = $this->tenant()->table('void_reasons')->insertGetId([
            'name' => 'Wrong order', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Void punched 07:12 on 20 Aug, stamped with the order's business day (19 Aug).
        SalesOrderLineCancellation::create([
            'sales_order_id' => $saleId, 'sales_order_line_id' => $lineId, 'void_reason_id' => $reasonId,
            'approval_mode' => 'none', 'product_name' => 'Biryani', 'quantity' => 2,
            'cancelled_at' => '2026-08-20 07:12:00', 'business_date' => '2026-08-19',
        ]);

        $engine = app(SalesReportEngine::class);
        $c19 = $engine->cancellations($engine->normalizeFilters(['date_from' => '2026-08-19', 'date_to' => '2026-08-19']));
        $this->assertSame(2.0, (float) $c19['total_qty'], 'the void lands on the order\'s business day');
        $c20 = $engine->cancellations($engine->normalizeFilters(['date_from' => '2026-08-20', 'date_to' => '2026-08-20']));
        $this->assertSame(0.0, (float) $c20['total_qty'], 'the after-midnight void no longer leaks onto the next calendar day');
    }
}
