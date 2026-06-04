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
    public function __invoke(Request $request, SalesReportService $salesService, InventoryReportService $inventoryService)
    {
        $branches       = Branch::where('status', 'active')->orderBy('name')->get();
        $selectedBranch = $request->integer('branch_id') ?: null;

        // Today's sales stats
        $today = $salesService->todayStats($selectedBranch);

        // Cash vs card today (split from payment methods)
        $cashToday = \App\Models\Tenant\SalePayment::query()
            ->join('sales_orders', 'sale_payments.sales_order_id', '=', 'sales_orders.id')
            ->join('payment_methods', 'sale_payments.payment_method_id', '=', 'payment_methods.id')
            ->where('sales_orders.status', 'paid')
            ->whereDate('sales_orders.sale_date', today())
            ->when($selectedBranch, fn ($q) => $q->where('sales_orders.branch_id', $selectedBranch))
            ->where('payment_methods.method_type', 'cash')
            ->sum('sale_payments.amount');

        $cardToday = \App\Models\Tenant\SalePayment::query()
            ->join('sales_orders', 'sale_payments.sales_order_id', '=', 'sales_orders.id')
            ->join('payment_methods', 'sale_payments.payment_method_id', '=', 'payment_methods.id')
            ->where('sales_orders.status', 'paid')
            ->whereDate('sales_orders.sale_date', today())
            ->when($selectedBranch, fn ($q) => $q->where('sales_orders.branch_id', $selectedBranch))
            ->whereIn('payment_methods.method_type', ['card', 'bank_transfer'])
            ->sum('sale_payments.amount');

        // Open shifts
        $openShifts = Shift::where('status', 'open')
            ->when($selectedBranch, fn ($q) => $q->where('branch_id', $selectedBranch))
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

        // Top 5 products today
        $topProducts = SalesOrderLine::query()
            ->join('sales_orders', 'sales_order_lines.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_orders.status', 'paid')
            ->whereDate('sales_orders.sale_date', today())
            ->when($selectedBranch, fn ($q) => $q->where('sales_orders.branch_id', $selectedBranch))
            ->selectRaw('sales_order_lines.product_name, SUM(sales_order_lines.quantity) as qty_sold, SUM(sales_order_lines.line_total) as revenue')
            ->groupBy('sales_order_lines.product_name')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get();

        // Last 7 days net sales (for sparkline/chart)
        $last7Days = SalesOrder::query()
            ->where('status', 'paid')
            ->when($selectedBranch, fn ($q) => $q->where('branch_id', $selectedBranch))
            ->whereDate('sale_date', '>=', now()->subDays(6))
            ->selectRaw('DATE(sale_date) as day, COALESCE(SUM(grand_total), 0) as net_sales, COUNT(*) as orders')
            ->groupByRaw('DATE(sale_date)')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        return view('tenant.dashboard', compact(
            'branches', 'selectedBranch', 'today',
            'cashToday', 'cardToday', 'openShifts', 'failedPrints',
            'lowStockCount', 'expiryCount', 'topProducts', 'last7Days'
        ));
    }
}
