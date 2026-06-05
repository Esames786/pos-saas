<?php

namespace App\Services\Reports;

use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Facades\DB;

class RestaurantReportService
{
    public function tables(array $filters)
    {
        return SalesOrder::query()
            ->join('restaurant_tables', 'sales_orders.restaurant_table_id', '=', 'restaurant_tables.id')
            ->leftJoin('restaurant_floors', 'restaurant_tables.restaurant_floor_id', '=', 'restaurant_floors.id')
            ->where('sales_orders.status', 'paid')
            ->whereNotNull('sales_orders.restaurant_table_id')
            ->when(!empty($filters['branch_id']),   fn ($q) => $q->where('sales_orders.branch_id', $filters['branch_id']))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereDate('sales_orders.sale_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereDate('sales_orders.sale_date', '<=', $filters['date_to']))
            ->selectRaw('
                restaurant_tables.id as table_id,
                restaurant_tables.table_no,
                COALESCE(restaurant_floors.name, \'—\') as floor_name,
                COUNT(*) as order_count,
                COALESCE(SUM(sales_orders.subtotal), 0)              as gross_sales,
                COALESCE(SUM(sales_orders.discount_amount), 0)       as total_discount,
                COALESCE(SUM(sales_orders.service_charge_amount), 0) as total_service_charge,
                COALESCE(SUM(sales_orders.tip_amount), 0)            as total_tips,
                COALESCE(SUM(sales_orders.grand_total), 0)           as net_sales
            ')
            ->groupBy('restaurant_tables.id', 'restaurant_tables.table_no', 'restaurant_floors.name')
            ->orderByDesc('net_sales')
            ->get()
            ->map(fn ($row) => array_merge((array) $row, [
                'avg_bill' => $row->order_count > 0 ? round($row->net_sales / $row->order_count, 2) : 0,
            ]));
    }

    public function waiters(array $filters)
    {
        return SalesOrder::query()
            ->join('restaurant_waiters', 'sales_orders.restaurant_waiter_id', '=', 'restaurant_waiters.id')
            ->where('sales_orders.status', 'paid')
            ->whereNotNull('sales_orders.restaurant_waiter_id')
            ->when(!empty($filters['branch_id']),   fn ($q) => $q->where('sales_orders.branch_id', $filters['branch_id']))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereDate('sales_orders.sale_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereDate('sales_orders.sale_date', '<=', $filters['date_to']))
            ->selectRaw('
                restaurant_waiters.id as waiter_id,
                restaurant_waiters.name as waiter_name,
                COUNT(*) as order_count,
                COALESCE(SUM(sales_orders.subtotal), 0)              as gross_sales,
                COALESCE(SUM(sales_orders.discount_amount), 0)       as total_discount,
                COALESCE(SUM(sales_orders.tip_amount), 0)            as total_tips,
                COALESCE(SUM(sales_orders.grand_total), 0)           as net_sales
            ')
            ->groupBy('restaurant_waiters.id', 'restaurant_waiters.name')
            ->orderByDesc('net_sales')
            ->get()
            ->map(fn ($row) => array_merge((array) $row, [
                'avg_order' => $row->order_count > 0 ? round($row->net_sales / $row->order_count, 2) : 0,
            ]));
    }

    public function orderTypes(array $filters)
    {
        return SalesOrder::query()
            ->where('status', 'paid')
            ->when(!empty($filters['branch_id']),   fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereDate('sale_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereDate('sale_date', '<=', $filters['date_to']))
            ->selectRaw('
                order_type,
                COUNT(*) as order_count,
                COALESCE(SUM(subtotal), 0)              as gross_sales,
                COALESCE(SUM(discount_amount), 0)       as total_discount,
                COALESCE(SUM(tax_amount), 0)            as total_tax,
                COALESCE(SUM(service_charge_amount), 0) as total_service_charge,
                COALESCE(SUM(tip_amount), 0)            as total_tips,
                COALESCE(SUM(grand_total), 0)           as net_sales
            ')
            ->groupBy('order_type')
            ->orderByDesc('order_count')
            ->get();
    }
}
