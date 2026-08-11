<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

/**
 * THE DASHBOARD MUST TELL THE SAME STORY AS THE REPORT CENTRE.
 *
 * todayStats() counted only status = 'paid' and never subtracted returns. A returned order
 * therefore vanished from the order count entirely while its money stayed inside "Net Sales" —
 * two errors that partly cancelled, so the tile looked plausible while being wrong twice. On a
 * live trading day it read 7 orders / 10,570 against the report's 11 orders / 10,820.
 *
 * The owner reads that tile before opening the report, so a figure that is nearly right is worse
 * than one that is obviously wrong.
 */
class DashboardMatchesReportCentreRegressionTest extends TestCase
{
    private function service(): string
    {
        return file_get_contents(app_path('Services/Reports/SalesReportService.php'));
    }

    public function test_today_stats_uses_the_same_population_as_the_engine(): void
    {
        $code = $this->service();

        // A returned order keeps its original sale visible — same rule as SalesReportEngine.
        $this->assertMatchesRegularExpression(
            "/whereIn\('status', \['paid', 'partially_returned', 'returned'\]\)/",
            $code,
            'the dashboard must not drop returned orders from the day'
        );
    }

    public function test_today_stats_subtracts_posted_returns(): void
    {
        $code = $this->service();

        $this->assertStringContainsString('$net = round($billed - $returns, 2);', $code, 'net must be billed minus returns');
        $this->assertStringContainsString("'returns_amount'", $code, 'the deduction must be exposed so the tile can show it');
        $this->assertStringContainsString("'billed'", $code, 'billed must be exposed so the tile can show its working');
    }

    public function test_returns_are_allocated_by_return_date(): void
    {
        $code = $this->service();

        // A refund handed back today reduces today, whichever day the original sale happened —
        // and a return carries no business_date, so the sales helper cannot be reused.
        $this->assertStringContainsString("whereDate('return_date'", $code);
    }

    public function test_the_tile_shows_its_working_when_there_were_returns(): void
    {
        $view = file_get_contents(resource_path('views/tenant/dashboard.blade.php'));

        $this->assertStringContainsString("\$today['returns_amount']", $view);
        $this->assertStringContainsString("\$today['billed']", $view);
    }

    public function test_the_shift_report_separates_billed_refunds_and_net(): void
    {
        $view = file_get_contents(resource_path('views/tenant/reports/daily-closings.blade.php'));

        $this->assertStringContainsString('Net Sales', $view, 'a closing report without a net figure reads as the day\'s takings');
        $this->assertStringContainsString('total_sales - $c->total_refunds', $view, 'each row must show its own net');
        $this->assertStringContainsString('total_sales - $t->total_refunds', $view, 'the summary must show the net too');
    }
}
