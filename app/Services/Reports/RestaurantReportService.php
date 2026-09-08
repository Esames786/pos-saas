<?php

namespace App\Services\Reports;

use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * LEGACY-REPORTS-POPULATION-1 — these three reports now count what every other report counts.
 *
 * They filtered `status = 'paid'` alone, so a returned or partially returned bill vanished from
 * them entirely: the table it was served on, the waiter who served it and its order type all lost
 * it, count and money together. A PARTIAL return is the worst case, because the money the shop
 * kept went with it.
 *
 * That is not a new discovery. `SalesReportService::baseSalesQuery` carries the note of the day it
 * was first found: at Khatri the same filter hid 8 orders and the delivery charges the shop
 * legitimately kept, so the counter was told to expect 22,500 when the drawer said 22,850. The
 * correction went into that one function and these three were missed.
 *
 * So: `SalesReportEngine::POPULATION`, and the refund subtracted rather than the whole bill
 * discarded — which is precisely what "a returned order keeps its original sale visible; the
 * refund is deducted separately" means.
 */
class RestaurantReportService
{
    /**
     * SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1: operational restaurant-sales aggregations filter by
     * BUSINESS date (matching SalesReportService), so an after-midnight sale lands on its shift's
     * business day. COALESCE keeps legacy pre-backfill rows on their sale_date.
     */
    private function businessDayExpr(string $prefix = ''): string
    {
        return "COALESCE({$prefix}business_date, DATE({$prefix}sale_date))";
    }

    /**
     * Posted refunds per ORDER, as a joinable subquery.
     *
     * One row per order, so joining it cannot fan the aggregate out — which a plain join to
     * `sales_returns` would, silently doubling an order that was refunded twice.
     */
    private function refundsPerOrder()
    {
        return DB::connection('tenant')->table('sales_returns')
            ->where('status', 'posted')
            ->selectRaw('sales_order_id, COALESCE(SUM(grand_total), 0) as refunded')
            ->groupBy('sales_order_id');
    }

    public function tables(array $filters)
    {
        return SalesOrder::query()
            ->join('restaurant_tables', 'sales_orders.restaurant_table_id', '=', 'restaurant_tables.id')
            ->leftJoin('restaurant_floors', 'restaurant_tables.restaurant_floor_id', '=', 'restaurant_floors.id')
            ->leftJoinSub($this->refundsPerOrder(), 'r', 'r.sales_order_id', '=', 'sales_orders.id')
            ->whereIn('sales_orders.status', SalesReportEngine::POPULATION)
            ->whereNotNull('sales_orders.restaurant_table_id')
            ->when(!empty($filters['branch_id']),   fn ($q) => $q->where('sales_orders.branch_id', $filters['branch_id']))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' >= ?', [$filters['date_from']]))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' <= ?', [$filters['date_to']]))
            ->selectRaw('
                restaurant_tables.id as table_id,
                restaurant_tables.table_no,
                COALESCE(restaurant_floors.name, \'—\') as floor_name,
                COUNT(*) as order_count,
                COALESCE(SUM(sales_orders.subtotal), 0)              as gross_sales,
                COALESCE(SUM(sales_orders.discount_amount), 0)       as total_discount,
                COALESCE(SUM(sales_orders.service_charge_amount), 0) as total_service_charge,
                COALESCE(SUM(sales_orders.tip_amount), 0)            as total_tips,
                COALESCE(SUM(r.refunded), 0)                         as returns_amount,
                COALESCE(SUM(sales_orders.grand_total), 0)
                    - COALESCE(SUM(r.refunded), 0)                   as net_sales
            ')
            ->groupBy('restaurant_tables.id', 'restaurant_tables.table_no', 'restaurant_floors.name')
            ->orderByDesc('net_sales')
            ->get()
            ->map(function ($row) {
                // Keep the row an object — the view reads $t->floor_name etc. (object syntax).
                $row->avg_bill = $row->order_count > 0 ? round($row->net_sales / $row->order_count, 2) : 0;

                return $row;
            });
    }

    public function waiters(array $filters)
    {
        return SalesOrder::query()
            ->join('restaurant_waiters', 'sales_orders.restaurant_waiter_id', '=', 'restaurant_waiters.id')
            ->leftJoinSub($this->refundsPerOrder(), 'r', 'r.sales_order_id', '=', 'sales_orders.id')
            ->whereIn('sales_orders.status', SalesReportEngine::POPULATION)
            ->whereNotNull('sales_orders.restaurant_waiter_id')
            ->when(!empty($filters['branch_id']),   fn ($q) => $q->where('sales_orders.branch_id', $filters['branch_id']))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' >= ?', [$filters['date_from']]))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' <= ?', [$filters['date_to']]))
            ->selectRaw('
                restaurant_waiters.id as waiter_id,
                restaurant_waiters.name as waiter_name,
                COUNT(*) as order_count,
                COALESCE(SUM(sales_orders.subtotal), 0)              as gross_sales,
                COALESCE(SUM(sales_orders.discount_amount), 0)       as total_discount,
                COALESCE(SUM(sales_orders.tip_amount), 0)            as total_tips,
                COALESCE(SUM(r.refunded), 0)                         as returns_amount,
                COALESCE(SUM(sales_orders.grand_total), 0)
                    - COALESCE(SUM(r.refunded), 0)                   as net_sales
            ')
            ->groupBy('restaurant_waiters.id', 'restaurant_waiters.name')
            ->orderByDesc('net_sales')
            ->get()
            ->map(function ($row) {
                // Keep the row an object — the view reads $w->waiter_name etc. (object syntax).
                $row->avg_order = $row->order_count > 0 ? round($row->net_sales / $row->order_count, 2) : 0;

                return $row;
            });
    }

    public function orderTypes(array $filters)
    {
        return SalesOrder::query()
            ->leftJoinSub($this->refundsPerOrder(), 'r', 'r.sales_order_id', '=', 'sales_orders.id')
            ->whereIn('sales_orders.status', SalesReportEngine::POPULATION)
            ->when(!empty($filters['branch_id']),   fn ($q) => $q->where('sales_orders.branch_id', $filters['branch_id']))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' >= ?', [$filters['date_from']]))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' <= ?', [$filters['date_to']]))
            // Columns are table-qualified now that a subquery is joined: an unprefixed name here
            // would be one added column away from being ambiguous.
            ->selectRaw('
                sales_orders.order_type,
                COUNT(*) as order_count,
                COALESCE(SUM(sales_orders.subtotal), 0)              as gross_sales,
                COALESCE(SUM(sales_orders.discount_amount), 0)       as total_discount,
                COALESCE(SUM(sales_orders.tax_amount), 0)            as total_tax,
                COALESCE(SUM(sales_orders.service_charge_amount), 0) as total_service_charge,
                COALESCE(SUM(sales_orders.tip_amount), 0)            as total_tips,
                COALESCE(SUM(r.refunded), 0)                         as returns_amount,
                COALESCE(SUM(sales_orders.grand_total), 0)
                    - COALESCE(SUM(r.refunded), 0)                   as net_sales
            ')
            ->groupBy('sales_orders.order_type')
            ->orderByDesc('order_count')
            ->get();
    }
}
