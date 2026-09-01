<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\InventoryBatch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\Shift;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\SalesReportService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * KASHIF-CATERING-CALENDAR-1 — is catering actually on this tenant's plan?
     *
     * Entitlement, never permission. The two disagree here by design: every
     * Owner holds every tenant.* permission, so only the plan can answer this.
     */
    private function cateringEnabled(): bool
    {
        try {
            $plan = app('tenant')->subscription?->loadMissing('plan.enabledModules')->plan;

            return (bool) $plan?->hasEnabledModuleKey('catering');
        } catch (\Throwable) {
            // No bound tenant (console, tests without tenancy) — show nothing.
            return false;
        }
    }

    /**
     * Older months, fetched only when the operator steps back past the default
     * three-month window. Keeps the first dashboard paint small on a kitchen
     * terminal with years of history behind it.
     */
    public function cateringCalendar(Request $request)
    {
        if (! $this->cateringEnabled()) {
            abort(404);
        }

        $anchor = $request->filled('month')
            ? \Carbon\CarbonImmutable::createFromFormat('Y-m', $request->string('month')->toString())->startOfMonth()
            : null;

        return view('tenant.partials.catering-calendar', [
            'cateringCalendar' => app(\App\Services\Catering\CateringCalendarService::class)
                ->window($anchor, $request->integer('branch_id') ?: null),
            'fragment' => true,
        ]);
    }

    public function __invoke(Request $request, SalesReportService $salesService, InventoryReportService $inventoryService)
    {
        $branches       = Branch::where('status', 'active')->orderBy('name')->get();
        $selectedBranch = $request->integer('branch_id') ?: null;

        // USER-DATA-SCOPE-1: the dashboard defaults to the operator's own terminals + order types.
        // A user assigned all terminals and all order types is unrestricted and sees everything.
        $scopeUser = auth('tenant')->user();
        $scope     = app(\App\Services\Security\UserDataScope::class);

        // Today's sales stats
        $today = $salesService->todayStats($selectedBranch, $scopeUser);

        // SHIFT-POS-INTEGRATION-CLOSURE-1: operational "today" uses the BUSINESS calendar date in
        // each branch's business timezone (via TenantClock) — NEVER Laravel's UTC today(), which can
        // be a day behind for e.g. Asia/Karachi just after midnight. A single branch uses its own
        // business date; an all-branch dashboard lets each branch contribute its own current day.
        $clock = app(\App\Support\TenantClock::class);
        $businessDay = 'COALESCE(sales_orders.business_date, DATE(sales_orders.sale_date))';
        $applyToday = function ($query) use ($clock, $selectedBranch, $businessDay) {
            if ($selectedBranch) {
                return $query->whereRaw("$businessDay = ?", [$clock->currentBusinessDate(\App\Models\Tenant\Branch::find($selectedBranch))]);
            }
            $map = $clock->currentBusinessDatesByBranch();

            return $query->where(function ($q) use ($map, $businessDay) {
                foreach ($map as $bid => $date) {
                    $q->orWhere(fn ($w) => $w->where('sales_orders.branch_id', $bid)->whereRaw("$businessDay = ?", [$date]));
                }
                if (empty($map)) {
                    $q->whereRaw('1 = 0');
                }
            });
        };

        // Cash vs card today (split from payment methods)
        $cashToday = $applyToday(\App\Models\Tenant\SalePayment::query()
            ->join('sales_orders', 'sale_payments.sales_order_id', '=', 'sales_orders.id')
            ->join('payment_methods', 'sale_payments.payment_method_id', '=', 'payment_methods.id')
            ->where('sales_orders.status', 'paid')
            ->when($selectedBranch, fn ($q) => $q->where('sales_orders.branch_id', $selectedBranch))
            ->tap(fn ($q) => $scope->applyToSales($q, $scopeUser, 'sales_orders')))
            ->where('payment_methods.method_type', 'cash')
            ->sum('sale_payments.amount');

        $cardToday = $applyToday(\App\Models\Tenant\SalePayment::query()
            ->join('sales_orders', 'sale_payments.sales_order_id', '=', 'sales_orders.id')
            ->join('payment_methods', 'sale_payments.payment_method_id', '=', 'payment_methods.id')
            ->where('sales_orders.status', 'paid')
            ->when($selectedBranch, fn ($q) => $q->where('sales_orders.branch_id', $selectedBranch))
            ->tap(fn ($q) => $scope->applyToSales($q, $scopeUser, 'sales_orders')))
            ->whereIn('payment_methods.method_type', ['card', 'bank_transfer'])
            ->sum('sale_payments.amount');

        // Open shifts (scoped to the operator's terminals, when he is terminal-restricted).
        $openShifts = Shift::where('status', 'open')
            ->when($selectedBranch, fn ($q) => $q->where('branch_id', $selectedBranch))
            ->when($scope->terminalIds($scopeUser), fn ($q, $t) => $q->whereIn('terminal_id', $t))
            ->count();

        // Failed print jobs (last 24h)
        $failedPrints = PrintJob::where('print_status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        // Low stock
        $lowStockCount = $inventoryService->lowStockCount();

        // Expiry alerts (next 30 days)
        $expiryCount = InventoryBatch::whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->count();

        // DASHBOARD-OPEN-BILLS-1 — the money still sitting on the tables.
        //
        // Every tile above counts PAID work only, so a busy service is invisible until the bills are
        // settled: on 31 Aug Kashif Food showed Rs 82,795 while another Rs 47,235 sat on 23 open
        // checks — 36% of the day, nowhere on the page.
        //
        // These figures are EXPECTED, never earned. A held bill still changes: items get added or
        // cancelled, a discount lands, the total is rounded. So they are shown as their own small
        // line under the tile and are NEVER added into the tile's own number — that number has to
        // keep matching the Report Center and the day's closing.
        //
        // Cash and Card get NO such line, deliberately. A held bill has no payment row at all (0 of
        // them today), because the customer has not chosen a method yet — splitting the open total
        // into cash and card would be an invention, not a forecast.
        //
        // Same business-date window and same user scope as the tiles, or the two numbers would not
        // be comparable.
        $openBills = $applyToday(SalesOrder::query()
            ->whereIn('status', ['held', 'draft'])
            ->when($selectedBranch, fn ($q) => $q->where('branch_id', $selectedBranch))
            ->tap(fn ($q) => $scope->applyToSales($q, $scopeUser)))
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(grand_total), 0) as amount,
                         COALESCE(SUM(discount_amount), 0) as discount, COALESCE(SUM(tax_amount), 0) as tax')
            ->first();

        // DASHBOARD-DETAILS-1: the two bottom cards read the whole branch — what sells, and how
        // each day of the week compared. Both are computed ONLY for a role that may see them.
        // The blade's @can alone would not be enough: the queries would still run, and "hidden"
        // would mean rendered-then-dropped rather than never fetched.
        $maySeeDetails = (bool) auth('tenant')->user()?->can('tenant.dashboard.details');

        // Top 5 products today
        $topProducts = collect();
        if ($maySeeDetails) {
            $topProducts = $applyToday(SalesOrderLine::query()
                ->join('sales_orders', 'sales_order_lines.sales_order_id', '=', 'sales_orders.id')
                ->where('sales_orders.status', 'paid')
                ->when($selectedBranch, fn ($q) => $q->where('sales_orders.branch_id', $selectedBranch))
                ->tap(fn ($q) => $scope->applyToSales($q, $scopeUser, 'sales_orders')))
                ->selectRaw('sales_order_lines.product_name, SUM(sales_order_lines.quantity) as qty_sold, SUM(sales_order_lines.line_total) as revenue')
                ->groupBy('sales_order_lines.product_name')
                ->orderByDesc('qty_sold')
                ->limit(5)
                ->get();
        }

        // Last 7 business days net sales (window anchored to the current business date, not UTC now)
        $todayBusinessDate = $clock->currentBusinessDate(
            $selectedBranch ? \App\Models\Tenant\Branch::find($selectedBranch) : null
        );
        $windowStart = \Illuminate\Support\Carbon::parse($todayBusinessDate)->subDays(6)->toDateString();

        // The row labels come from the SAME window as the data. They used to be built in the Blade
        // from `now()`, which is the UTC calendar date — so between midnight in Karachi and
        // midnight UTC the table asked for keys the query had never produced and printed "—" for
        // the day that was actually trading.
        $last7DayKeys = collect(range(6, 0))
            ->map(fn ($back) => \Illuminate\Support\Carbon::parse($todayBusinessDate)->subDays($back)->toDateString())
            ->all();
        // DASHBOARD-7DAY-POPULATION-1: ask the same authority the tile above asks, instead of
        // running a second query here. This card used to count `paid` only and subtract no
        // returns, so a returned bill disappeared from it while the tile still counted it —
        // "Orders Today 295" over a row reading 291 for the same day, and, on a day with a
        // PARTIAL return, real kept revenue missing from the history as well.
        $last7Days = collect();
        if ($maySeeDetails) {
            $last7Days = collect($salesService->dailyStats($windowStart, $todayBusinessDate, $selectedBranch, $scopeUser))
                ->map(fn (array $row) => (object) $row)
                ->sortKeys();
        }

        // KASHIF-CATERING-CALENDAR-1 — the booking diary, for catering tenants only.
        //
        // Gated on the plan's ENTITLEMENT, not on @can. deploy.sh grants the Owner
        // every tenant.* permission regardless of plan, so a permission check here
        // would put a catering widget on a restaurant's dashboard. Null means the
        // widget is not rendered at all, not merely hidden.
        $calendarService = $this->cateringEnabled()
            ? app(\App\Services\Catering\CateringCalendarService::class)
            : null;
        $cateringCalendar = $calendarService?->window(null, $selectedBranch);
        // KASHIF-CATERING-OPERATOR-UI-1: the owner KPI cards and the next-7-days
        // list, from the same presentation authority as the calendar itself.
        $cateringKpis = $calendarService?->kpis($selectedBranch);
        $cateringNextSeven = $calendarService ? $calendarService->nextDays(7, $selectedBranch) : [];

        // HIDE-AMOUNTS-1: the tiles follow the same branch flag as the closing screens. On "All
        // Branches" there is no single branch to ask about, so allowsAcross() fails CLOSED — one
        // restricted branch in view masks the tiles. Showing the money because the question was
        // ambiguous is the one answer that cannot be defended.
        $maySeeAmounts = app(\App\Support\AmountVisibility::class)->allowsAcross(
            auth('tenant')->user(),
            $selectedBranch
                ? \App\Models\Tenant\Branch::where('id', $selectedBranch)->get()
                : $branches
        );

        return view('tenant.dashboard', compact(
            'branches', 'selectedBranch', 'today',
            'cashToday', 'cardToday', 'openShifts', 'failedPrints',
            'lowStockCount', 'expiryCount', 'topProducts', 'last7Days', 'last7DayKeys',
            'todayBusinessDate', 'openBills',
            'cateringCalendar', 'cateringKpis', 'cateringNextSeven',
            'maySeeAmounts'
        ));
    }
}
