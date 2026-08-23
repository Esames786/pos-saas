<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Reports\SalesReportCenterController;
use App\Models\Tenant\User;
use App\Services\Security\UserDataScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * REPORT-PRINT-SCOPE — the Sales Report Center's Print / Z / Export links must carry the same
 * "All terminals/branch" markers the on-screen view uses. Without them the report scope treats the
 * ABSENT terminal filter as "first visit → default to my ONE terminal", so an owner viewing all his
 * dine-in sales got a printed report of only his default (Delivery) terminal — looking empty.
 */
class ReportCenterPrintScopeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    public function test_explicit_all_keeps_every_terminal_but_an_absent_filter_defaults_to_one(): void
    {
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['terminal_user', 'terminals', 'branches', 'users']);

        $branchId = $this->makeBranch();
        $t1 = $this->makeTerminal($branchId, ['name' => 'Delivery']);
        $t2 = $this->makeTerminal($branchId, ['name' => 'Dine In']);
        $userId = $this->makeUser(['default_branch_id' => $branchId, 'default_terminal_id' => $t1]);
        DB::connection('tenant')->table('terminal_user')->insert([
            ['user_id' => $userId, 'terminal_id' => $t1],
            ['user_id' => $userId, 'terminal_id' => $t2],
        ]);
        $user = User::on('tenant')->find($userId);
        $scope = app(UserDataScope::class);

        // On-screen / fixed print URL: terminal filter PRESENT (blank "All") → no single-terminal
        // narrowing; the report spans every allowed terminal.
        $present = $scope->applyToReportFilters(['_terminal_filter_present' => true, 'terminal_id' => null], $user);
        $this->assertNull($present['terminal_id'], 'an explicit "All" must not pin the report to one terminal');
        $this->assertEqualsCanonicalizing([$t1, $t2], $present['allowed_terminal_ids']);

        // Old broken print URL: terminal filter ABSENT → scope defaults to the ONE default terminal,
        // which is exactly why the print link must preserve the marker.
        $absent = $scope->applyToReportFilters(['_terminal_filter_present' => false, 'terminal_id' => null], $user);
        $this->assertSame($t1, $absent['terminal_id'], 'an absent filter narrows to the default terminal — the bug the URL fix prevents');
    }

    public function test_print_and_export_links_preserve_markers_and_are_not_html_escaped(): void
    {
        $blade = file_get_contents(base_path('resources/views/tenant/reports/center/index.blade.php'));

        // $qs keeps branch_id / terminal_id even when blank (the filter-presence markers).
        $this->assertStringContainsString("['branch_id', 'terminal_id']", $blade,
            'the query-string helper must preserve the branch/terminal filter-presence markers');

        // The JS print/export URLs use raw {!! $qs() !!} so & is not encoded to &amp; and dropped.
        $this->assertGreaterThanOrEqual(2, substr_count($blade, '{!! $qs() !!}'),
            'the window.open print + export URLs must emit the query string raw (not HTML-escaped)');
        $this->assertStringNotContainsString('?{{ $qs() }}', $blade,
            'a {{ $qs() }} inside a JS URL would HTML-escape & to &amp; and lose every filter param');
    }

    /**
     * SEND-TO-NETWORK printer scope: a terminal-bound operator only sees THEIR terminal's attached
     * printer(s) — receipt first so it is the default option — never another counter's printer.
     */
    public function test_send_to_network_lists_only_the_bound_terminals_printers_receipt_first(): void
    {
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['terminal_printer_settings', 'printers', 'terminal_user', 'terminals', 'branches', 'users']);

        $branchId = $this->makeBranch();
        $delivery = $this->makeTerminal($branchId, ['name' => 'Delivery']);
        $this->makeTerminal($branchId, ['name' => 'Dine In']);

        $deliveryReceipt = $this->makePrinter(['name' => 'Delivery Receipt', 'print_role' => 'receipt']);
        $deliveryKot = $this->makePrinter(['name' => 'Delivery KOT']);
        $this->makePrinter(['name' => 'Dine Receipt']);   // another counter's printer — must NOT appear

        DB::connection('tenant')->table('terminal_printer_settings')->insert([
            'terminal_id' => $delivery, 'receipt_printer_id' => $deliveryReceipt, 'kot_printer_id' => $deliveryKot,
            'auto_print_receipt' => 0, 'auto_print_kot' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $opId = $this->makeUser(['default_branch_id' => $branchId, 'default_terminal_id' => $delivery]);
        DB::connection('tenant')->table('terminal_user')->insert(['user_id' => $opId, 'terminal_id' => $delivery]);
        $this->actingAs(User::on('tenant')->find($opId), 'tenant');
        Auth::shouldUse('tenant');

        $data = app(SalesReportCenterController::class)->index(Request::create('/reports/center', 'GET'))->getData();
        $ids = collect($data['networkPrinters'])->pluck('id')->all();

        $this->assertSame([$deliveryReceipt, $deliveryKot], $ids,
            'a terminal-bound operator sees only their terminal printers, receipt first (default) — never another counter\'s');
    }

    /** An unbound owner/manager (no default terminal) still sees every network printer to pick from. */
    public function test_send_to_network_lists_every_printer_for_an_unbound_owner(): void
    {
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['terminal_printer_settings', 'printers', 'terminal_user', 'terminals', 'branches', 'users']);

        $branchId = $this->makeBranch();
        $a = $this->makePrinter(['name' => 'AAA']);
        $b = $this->makePrinter(['name' => 'BBB']);

        $ownerId = $this->makeUser(['default_branch_id' => $branchId]);   // no default_terminal_id
        $this->actingAs(User::on('tenant')->find($ownerId), 'tenant');
        Auth::shouldUse('tenant');

        $data = app(SalesReportCenterController::class)->index(Request::create('/reports/center', 'GET'))->getData();
        $ids = collect($data['networkPrinters'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$a, $b], $ids, 'an unbound owner still sees every network printer');
    }
}
