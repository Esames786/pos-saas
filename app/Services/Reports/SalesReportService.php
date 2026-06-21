<?php

namespace App\Services\Reports;

use App\Models\Tenant\SalePayment;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Services\Concerns\ResolvesBranchIds;
use Illuminate\Support\Facades\DB;

class SalesReportService
{
    use ResolvesBranchIds;

    /**
     * Summary totals for a given filter set.
     * Returns both overall aggregates and a per-day breakdown.
     */
    public function summary(array $filters): array
    {
        $query = $this->baseSalesQuery($filters);

        $totals = (clone $query)->selectRaw('
            COUNT(*) as order_count,
            COALESCE(SUM(subtotal), 0)              as gross_sales,
            COALESCE(SUM(discount_amount), 0)       as total_discount,
            COALESCE(SUM(tax_amount), 0)            as total_tax,
            COALESCE(SUM(service_charge_amount), 0) as total_service_charge,
            COALESCE(SUM(tip_amount), 0)            as total_tips,
            COALESCE(SUM(grand_total), 0)           as net_sales
        ')->first();

        // Promo-specific discount (sales that had a promotion applied)
        $promoDiscount = (clone $query)
            ->whereNotNull('promotion_id')
            ->sum('discount_amount');

        // Daily breakdown
        $daily = (clone $query)
            ->selectRaw('DATE(sale_date) as sale_day,
                COUNT(*) as order_count,
                COALESCE(SUM(subtotal), 0)              as gross_sales,
                COALESCE(SUM(discount_amount), 0)       as total_discount,
                COALESCE(SUM(tax_amount), 0)            as total_tax,
                COALESCE(SUM(service_charge_amount), 0) as total_service_charge,
                COALESCE(SUM(tip_amount), 0)            as total_tips,
                COALESCE(SUM(grand_total), 0)           as net_sales'
            )
            ->groupByRaw('DATE(sale_date)')
            ->orderBy('sale_day')
            ->get();

        return [
            'totals'        => $totals,
            'promo_discount' => (float) $promoDiscount,
            'daily'         => $daily,
        ];
    }

    /**
     * Items sold — grouped by product/variant.
     */
    public function items(array $filters)
    {
        $branchIds = $this->resolveBranchIds($filters);

        return SalesOrderLine::query()
            ->join('sales_orders', 'sales_order_lines.sales_order_id', '=', 'sales_orders.id')
            ->leftJoin('products', 'sales_order_lines.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('sales_orders.status', 'paid')
            ->when($branchIds, fn ($q) => $q->whereIn('sales_orders.branch_id', $branchIds))
            ->when(!empty($filters['date_from']),    fn ($q) => $q->whereDate('sales_orders.sale_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),      fn ($q) => $q->whereDate('sales_orders.sale_date', '<=', $filters['date_to']))
            ->when(!empty($filters['terminal_id']),  fn ($q) => $q->where('sales_orders.terminal_id', $filters['terminal_id']))
            ->when(!empty($filters['order_type']),   fn ($q) => $q->where('sales_orders.order_type', $filters['order_type']))
            ->selectRaw('
                sales_order_lines.product_name,
                COALESCE(sales_order_lines.variant_name, \'\') as variant_name,
                COALESCE(categories.name, \'Uncategorised\') as category_name,
                COALESCE(SUM(sales_order_lines.quantity), 0)                              as qty_sold,
                COALESCE(SUM(sales_order_lines.quantity * sales_order_lines.unit_price), 0) as gross_amount,
                COALESCE(SUM(sales_order_lines.discount_amount), 0)                      as total_discount,
                COALESCE(SUM(sales_order_lines.tax_amount), 0)                           as total_tax,
                COALESCE(SUM(sales_order_lines.line_total), 0)                           as net_amount,
                COALESCE(SUM(sales_order_lines.cost_total), 0)                           as total_cost,
                COALESCE(SUM(sales_order_lines.line_total) - SUM(sales_order_lines.cost_total), 0) as profit
            ')
            ->groupBy('sales_order_lines.product_name', 'sales_order_lines.variant_name', 'categories.name')
            ->orderByDesc('qty_sold')
            ->get();
    }

    /**
     * Payment method breakdown — grouped by method.
     */
    public function payments(array $filters)
    {
        $branchIds = $this->resolveBranchIds($filters);

        return SalePayment::query()
            ->join('sales_orders',    'sale_payments.sales_order_id',    '=', 'sales_orders.id')
            ->join('payment_methods', 'sale_payments.payment_method_id', '=', 'payment_methods.id')
            ->where('sales_orders.status', 'paid')
            ->when($branchIds, fn ($q) => $q->whereIn('sales_orders.branch_id', $branchIds))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereDate('sales_orders.sale_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereDate('sales_orders.sale_date', '<=', $filters['date_to']))
            ->when(!empty($filters['terminal_id']), fn ($q) => $q->where('sales_orders.terminal_id', $filters['terminal_id']))
            ->selectRaw('
                payment_methods.id as method_id,
                payment_methods.name as method_name,
                payment_methods.method_type,
                COUNT(*) as transaction_count,
                COALESCE(SUM(sale_payments.amount), 0) as total_amount
            ')
            ->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.method_type')
            ->orderByDesc('total_amount')
            ->get();
    }

    /** Dashboard quick stats for today. */
    public function todayStats(int $branchId = null): array
    {
        $q = SalesOrder::query()
            ->where('status', 'paid')
            ->whereDate('sale_date', today())
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v));

        $count    = (clone $q)->count();
        $net      = (clone $q)->sum('grand_total');
        $gross    = (clone $q)->sum('subtotal');
        $discount = (clone $q)->sum('discount_amount');
        $tax      = (clone $q)->sum('tax_amount');
        $sc       = (clone $q)->sum('service_charge_amount');
        $tips     = (clone $q)->sum('tip_amount');

        return [
            'order_count'           => $count,
            'gross_sales'           => (float) $gross,
            'total_discount'        => (float) $discount,
            'total_tax'             => (float) $tax,
            'total_service_charge'  => (float) $sc,
            'total_tips'            => (float) $tips,
            'net_sales'             => (float) $net,
            'avg_order_value'       => $count > 0 ? round($net / $count, 2) : 0,
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function baseSalesQuery(array $filters)
    {
        $branchIds = $this->resolveBranchIds($filters);

        return SalesOrder::query()
            ->where('status', 'paid')
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when(!empty($filters['date_from']),    fn ($q) => $q->whereDate('sale_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),      fn ($q) => $q->whereDate('sale_date', '<=', $filters['date_to']))
            ->when(!empty($filters['terminal_id']),  fn ($q) => $q->where('terminal_id', $filters['terminal_id']))
            ->when(!empty($filters['order_type']),   fn ($q) => $q->where('order_type', $filters['order_type']))
            ->when(!empty($filters['cashier_id']),   fn ($q) => $q->where('created_by_user_id', $filters['cashier_id']));
    }
}
