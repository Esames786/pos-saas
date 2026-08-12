<?php

namespace Tests\Unit\Tenant;

use App\Support\TenantClock;
use Tests\TestCase;

/**
 * SALE TIME DISPLAY.
 *
 * Timestamps are stored in UTC (app.timezone=UTC). The printed receipt has always converted to the
 * timezone the sale was recorded in, but every SCREEN formatted the raw UTC value — so a Karachi
 * counter saw 08:25 for an order taken at 13:25, five hours adrift from the clock in the same page
 * header, and from the slip in the customer's hand.
 *
 * The conversion is display-only: nothing stored changes, so already-placed orders read correctly
 * the moment the screen is fixed.
 */
class SaleTimeDisplayRegressionTest extends TestCase
{
    private function fakeSale(?string $shiftTz, ?string $branchTz, string $utc = '2026-08-11 08:25:21'): object
    {
        return new class($shiftTz, $branchTz, $utc) {
            public $shift;
            public $branch;
            public $sale_date;
            public $created_at = null;

            public function __construct(?string $shiftTz, ?string $branchTz, string $utc)
            {
                $this->shift = $shiftTz === null ? null : (object) ['timezone_name' => $shiftTz];
                $this->branch = $branchTz === null ? null : (object) ['timezone' => $branchTz];
                $this->sale_date = $utc;
            }
        };
    }

    public function test_a_karachi_sale_reads_in_karachi_not_utc(): void
    {
        $clock = app(TenantClock::class);

        // The exact live case: stored 08:25:21 UTC, taken at 13:25 in the shop.
        $this->assertSame(
            '2026-08-11 13:25',
            $clock->formatSale($this->fakeSale(null, 'Asia/Karachi'), 'Y-m-d H:i'),
            'a sale stored in UTC must display in the branch timezone'
        );
    }

    public function test_the_frozen_shift_timezone_wins_over_the_branch(): void
    {
        $clock = app(TenantClock::class);

        // Re-pointing a branch's timezone must never rewrite what an OLD order appears to have
        // happened at — the shift's frozen timezone is the historical truth.
        $sale = $this->fakeSale('Asia/Karachi', 'Europe/London');
        $this->assertSame('Asia/Karachi', $clock->saleTimezone($sale));
        $this->assertSame('2026-08-11 13:25', $clock->formatSale($sale, 'Y-m-d H:i'));
    }

    public function test_a_sale_with_no_shift_or_branch_still_never_shows_utc(): void
    {
        $clock = app(TenantClock::class);

        $this->assertSame(TenantClock::DEFAULT_TIMEZONE, $clock->saleTimezone($this->fakeSale(null, null)));
        $this->assertSame('2026-08-11 13:25', $clock->formatSale($this->fakeSale(null, null), 'Y-m-d H:i'));
    }

    /** Every surface that shows a sale's time must convert; a raw ->format() there is the bug. */
    public function test_no_sale_time_surface_formats_the_raw_utc_value(): void
    {
        $surfaces = [
            resource_path('views/tenant/sales-orders/index.blade.php'),
            resource_path('views/tenant/sales-orders/show.blade.php'),
            resource_path('views/tenant/sales-returns/create.blade.php'),
            resource_path('views/tenant/sales-returns/index.blade.php'),
            resource_path('views/tenant/sales-returns/show.blade.php'),
            resource_path('views/tenant/pos/partials/table-bill-preview.blade.php'),
            resource_path('views/tenant/restaurant/table-sessions/bill-preview.blade.php'),
            app_path('Http/Controllers/Tenant/POSController.php'),
        ];

        foreach ($surfaces as $file) {
            $code = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                "/sale_date[^\n]*->format\(/",
                $code,
                basename($file) . ' formats sale_date directly — use TenantClock::formatSale so the'
                . ' screen agrees with the receipt instead of printing UTC.'
            );
        }

        foreach (['index.blade.php', 'show.blade.php'] as $view) {
            $code = file_get_contents(resource_path('views/tenant/sales-returns/' . $view));
            $this->assertStringContainsString('displayTimezone(null, $', $code);
            $this->assertDoesNotMatchRegularExpression('/return_date[^\n]*->format\(/', $code);
        }

        // Print Jobs missed the original sweep: it rendered raw UTC created_at/claimed_at, so the
        // morning's first order read 07:38 on a wall clock showing 12:38.
        $code = file_get_contents(resource_path('views/tenant/printing/jobs/index.blade.php'));
        $this->assertStringContainsString('displayTimezone(null, $', $code);
        $this->assertDoesNotMatchRegularExpression(
            '/(created_at|claimed_at)[^\n]*->format\(/',
            $code,
            'Print Jobs formats a raw UTC timestamp — route it through TenantClock like every other portal screen.'
        );
    }

    /** The Report Center detail rows are raw query rows, so the timezone must travel per row. */
    public function test_the_detailed_report_carries_the_timezone_of_each_sale(): void
    {
        $engine = file_get_contents(app_path('Services/Reports/SalesReportEngine.php'));
        $view = file_get_contents(resource_path('views/tenant/reports/center/index.blade.php'));
        $exporter = file_get_contents(app_path('Services/Reports/SalesReportExporter.php'));

        $this->assertStringContainsString(
            'COALESCE(rsh.timezone_name, rbr.timezone) as sale_timezone',
            $engine,
            'detailedQuery must carry each row\'s recording timezone — a report can span branches'
        );
        $this->assertStringContainsString('$r->sale_timezone', $view, 'the detail table must convert');
        $this->assertStringContainsString('$r->sale_timezone', $exporter, 'the CSV export must convert too');
    }

    /** Multi-branch lists must not render every row in the request/default branch timezone. */
    public function test_branch_owned_operational_rows_use_their_own_branch_timezone(): void
    {
        $surfaces = [
            resource_path('views/tenant/held-sales/index.blade.php') => '$sale->branch',
            resource_path('views/tenant/sales-ledger/index.blade.php') => '$entry->branch',
            resource_path('views/tenant/restaurant/sessions/show.blade.php') => '$restaurantTableSession->branch',
        ];

        foreach ($surfaces as $file => $branchExpression) {
            $code = file_get_contents($file);
            $this->assertStringContainsString(
                'displayTimezone(null, ' . $branchExpression . ')',
                $code,
                basename($file) . ' must use the branch belonging to the displayed row'
            );
        }

        $controller = file_get_contents(app_path('Http/Controllers/Tenant/RestaurantTableSessionController.php'));
        $this->assertMatchesRegularExpression(
            "/function show\\(.*?'branch'.*?'table\\.floor'/s",
            $controller,
            'the table-session detail must eager-load its branch for timezone display'
        );
    }
}
