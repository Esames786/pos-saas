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
        $businessDayNoPrefix = 'COALESCE(business_date, DATE(sale_date))';
        $windowStart = \Illuminate\Support\Carbon::parse(
            $clock->currentBusinessDate($selectedBranch ? \App\Models\Tenant\Branch::find($selectedBranch) : null)
        )->subDays(6)->toDateString();
        $last7Days = collect();
        if ($maySeeDetails) {
            $last7Days = SalesOrder::query()
                ->where('status', 'paid')
                ->when($selectedBranch, fn ($q) => $q->where('branch_id', $selectedBranch))
                ->tap(fn ($q) => $scope->applyToSales($q, $scopeUser))
                ->whereRaw("$businessDayNoPrefix >= ?", [$windowStart])
                ->selectRaw("$businessDayNoPrefix as day, COALESCE(SUM(grand_total), 0) as net_sales, COUNT(*) as orders")
                ->groupByRaw($businessDayNoPrefix)
                ->orderBy('day')
                ->get()
                ->keyBy('day');
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

        return view('tenant.dashboard', compact(
            'branches', 'selectedBranch', 'today',
            'cashToday', 'cardToday', 'openShifts', 'failedPrints',
            'lowStockCount', 'expiryCount', 'topProducts', 'last7Days',
            'cateringCalendar', 'cateringKpis', 'cateringNextSeven'
        ));
    }
}
