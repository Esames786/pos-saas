<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

class ThermalSalesReportLayoutRegressionTest extends TestCase
{
    public function test_wide_report_sections_have_thermal_two_column_layouts(): void
    {
        $view = file_get_contents(resource_path('views/tenant/reports/center/print.blade.php'));

        $this->assertMatchesRegularExpression('/@if\(\$isThermal\)\s*<table>\s*@foreach\(\$orderTypes/', $view);
        $this->assertMatchesRegularExpression('/@if\(\$isThermal\)\s*<table>\s*@foreach\(\$waiters/', $view);
        $this->assertMatchesRegularExpression('/@if\(\$isThermal\)\s*<table>\s*@foreach\(\$rows as \$r\)/', $view);
        $this->assertMatchesRegularExpression('/@if\(\$isThermal\)\s*<table>\s*@forelse\(\$cancellations\[\'rows\'\]/', $view);
    }

    public function test_order_type_category_and_item_totals_switch_for_thermal_paper(): void
    {
        $view = file_get_contents(resource_path('views/tenant/reports/center/print.blade.php'));

        $count = preg_match_all(
            '/@if\(\$isThermal\)\s*<tr class="total"><td>TOTAL Qty/',
            $view
        );

        $this->assertSame(2, $count, 'Both order-type category and item totals need a two-column thermal row.');
    }
}
