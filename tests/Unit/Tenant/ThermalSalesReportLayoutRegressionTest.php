<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

/**
 * THERMAL SALES REPORT — COLUMNS THAT FIT THE PAPER.
 *
 * 72mm at 10px monospace holds roughly 45 characters, so the A4 seven-column table cannot fit:
 * adding the returns columns squeezed the name column to nothing and "Beverages" printed one
 * letter per line. An inline "sold - returned = net" form fitted but was hard to read.
 *
 * The layout is therefore FOUR narrow columns — label + Sold / Ret / Net — with the name on its
 * own full-width line and its figures beneath, under a header printed once per table:
 *
 *              Sold     Ret     Net
 *   Beverages
 *   Qty           3       2       1
 *   Amt      280.00  170.00  110.00
 *
 * These assertions pin the SHAPE that keeps it narrow and readable, not one table's markup.
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

        $this->assertGreaterThanOrEqual(
            8,
            substr_count($view, '@if($isThermal)'),
            'order types, categories, items, waiters and each by-order-type sub-table need a thermal branch'
        );
        // The A4 seven-column header must live behind @else, never be what thermal renders.
        $this->assertMatchesRegularExpression('/@if\(\$isThermal\).*?@else.*?Sold Qty.*?Ret Qty/s', $view);
    }

    public function test_thermal_uses_the_four_column_helpers(): void
    {
        $view = $this->printView();

        $this->assertStringContainsString('$tEntry = function', $view, 'qty+money entries need the column helper');
        $this->assertStringContainsString('$tOrders = function', $view, 'order-level entries need the column helper');
        $this->assertStringContainsString('$tHead = fn', $view, 'each thermal table needs a column header');

        $this->assertGreaterThanOrEqual(
            8,
            substr_count($view, '$tHead()'),
            'every thermal table must print the Sold/Ret/Net header once'
        );
        $this->assertGreaterThanOrEqual(
            12,
            substr_count($view, '$tEntry(') + substr_count($view, '$tOrders('),
            'every thermal row and total must render through a column helper'
        );
    }

    public function test_the_one_line_expression_forms_are_gone(): void
    {
        $view = $this->printView();

        // Both earlier attempts overflowed or were unreadable on 72mm.
        $this->assertStringNotContainsString('$sub(', $view, 'inline "a - b = c" overflowed the roll');
        $this->assertStringNotContainsString('$tRow(', $view, 'the two-line stacked form was replaced by columns');
    }

    public function test_a_worst_case_thermal_figures_line_stays_within_the_paper(): void
    {
        // Courier advances ~0.6em per character, so 72mm (272px) at 12px holds ~37 characters.
        // "Amt" plus three right-aligned amounts is the widest line the layout can produce.
        $usableChars = (int) floor((72 / 25.4 * 96) / (12 * 0.6));
        $widest = 'Amt' . str_repeat(' 999,999.00', 3);

        $this->assertLessThanOrEqual(
            $usableChars,
            strlen($widest),
            'the figures line must fit 72mm at the printed font size'
        );
    }

    public function test_thermal_is_printed_bold_and_large_enough_to_read(): void
    {
        $view = $this->printView();

        // The counter could not read 10px normal weight off a thermal head.
        $this->assertMatchesRegularExpression(
            "/font-size:\s*\{\{ \\\$paper === '58mm' \? '11px' : '12px' \}\}/",
            $view,
            'thermal body must print at 12px on 80mm (11px on the narrower 58mm roll)'
        );
        $this->assertMatchesRegularExpression('/font-weight:\s*700/', $view, 'thermal must print bold');
    }
}
