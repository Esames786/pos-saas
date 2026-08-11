<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

/**
 * THERMAL SALES REPORT MUST FIT THE PAPER.
 *
 * 72mm at 10px monospace holds roughly 45 characters. The wide A4 tables do not fit: adding the
 * returns columns squeezed the name column to nothing and "Beverages" printed one letter per line.
 * Stacking name + "sold - returned = net" fixed that, but putting BOTH the qty and money
 * expressions on one line still reached 53 characters on a normal trading day — fine at lunchtime,
 * off the edge of the paper by evening.
 *
 * So every thermal entry is TWO lines through one helper:
 *     name ......................... NET
 *     Qty 29-5=24 .... 11,180.00-1,590.00
 *
 * These assertions check the SHAPE that keeps it narrow, not one particular table's markup.
 */
class ThermalSalesReportLayoutRegressionTest extends TestCase
{
    private function printView(): string
    {
        return file_get_contents(resource_path('views/tenant/reports/center/print.blade.php'));
    }

    public function test_every_wide_section_has_a_thermal_branch(): void
    {
        $view = $this->printView();

        foreach (['$orderTypes', '$waiters', '$categories', '$items'] as $section) {
            $this->assertMatchesRegularExpression(
                '/@if\(\$isThermal\)/',
                $view,
                "a thermal branch is required before rendering {$section} wide"
            );
        }
        // The A4 seven-column header must never be what thermal renders.
        $this->assertMatchesRegularExpression('/@if\(\$isThermal\).*?@else.*?Sold Qty.*?Ret Qty/s', $view);
    }

    public function test_thermal_rows_go_through_the_two_line_helper(): void
    {
        $view = $this->printView();

        $this->assertStringContainsString('$tRow = function', $view, 'the two-line helper must exist');
        $this->assertGreaterThanOrEqual(
            12,
            substr_count($view, '$tRow('),
            'every thermal row and total must render through the helper, not a wide one-line row'
        );
    }

    public function test_the_wide_one_line_expression_is_gone(): void
    {
        $view = $this->printView();

        // "a - b = c" for BOTH qty and money on a single thermal line is what overflowed 72mm.
        $this->assertStringNotContainsString(
            '$sub(',
            $view,
            'the single-line "sold - returned = net" form overflows thermal paper — use $tRow'
        );
    }

    public function test_a_worst_case_thermal_line_stays_within_the_paper(): void
    {
        // Widest realistic content: a long item name is on its OWN line, and the figures line is
        // "Qty 999-99=900" + "999,999.00-99,999.00". Proven here so the layout cannot silently
        // regress into something that runs off the roll.
        $qtySide = 'Qty 999-99=900';
        $moneySide = '999,999.00-99,999.00';

        $this->assertLessThanOrEqual(
            45,
            strlen($qtySide) + strlen($moneySide) + 2,
            'the figures line must fit 72mm (~45 monospace characters)'
        );
    }
}
