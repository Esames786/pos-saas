<?php

namespace App\Services\Reports;

use App\Models\Tenant\SalePayment;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\SalesReturn;
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

        // Daily breakdown — by business day.
        $day = $this->businessDayExpr();
        $daily = (clone $query)
            ->selectRaw("$day as sale_day,
                COUNT(*) as order_count,
                COALESCE(SUM(subtotal), 0)              as gross_sales,
                COALESCE(SUM(discount_amount), 0)       as total_discount,
                COALESCE(SUM(tax_amount), 0)            as total_tax,
                COALESCE(SUM(service_charge_amount), 0) as total_service_charge,
                COALESCE(SUM(tip_amount), 0)            as total_tips,
                COALESCE(SUM(grand_total), 0)           as net_sales"
            )
            ->groupByRaw($day)
            ->orderBy('sale_day')
            ->get();

        // Returns, allocated by RETURN date on the same business day as the engine does. Without
        // this, "Net Sales" was simply everything billed — money handed back never came off.
        $returnsByDay = $this->returnsByBusinessDay($filters);
        $returnsTotal = array_sum($returnsByDay);

        $totals->billed         = (float) $totals->net_sales;
        $totals->returns_amount = $returnsTotal;
        $totals->net_sales      = round((float) $totals->net_sales - $returnsTotal, 2);

        foreach ($daily as $row) {
            $dayReturns          = $returnsByDay[(string) $row->sale_day] ?? 0.0;
            $row->billed         = (float) $row->net_sales;
            $row->returns_amount = $dayReturns;
            $row->net_sales      = round((float) $row->net_sales - $dayReturns, 2);
        }

        return [
            'totals'        => $totals,
            'promo_discount' => (float) $promoDiscount,
            'daily'         => $daily,
        ];
    }

    /** Posted returns for the filtered period, keyed by the business day they were refunded on. */
    private function returnsByBusinessDay(array $filters): array
    {
        $branchIds = $this->resolveBranchIds($filters);

        return \App\Models\Tenant\SalesReturn::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_returns.sales_order_id')
            ->where('sales_returns.status', 'posted')
            ->when($branchIds, fn ($q) => $q->whereIn('sales_returns.branch_id', $branchIds))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereRaw('COALESCE(sales_returns.business_date, DATE(sales_returns.return_date)) >= ?', [$filters['date_from']]))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->whereRaw('COALESCE(sales_returns.business_date, DATE(sales_returns.return_date)) <= ?', [$filters['date_to']]))
            ->when(!empty($filters['terminal_id']), fn ($q) => $q->where('sales_orders.terminal_id', $filters['terminal_id']))
            ->when(!empty($filters['order_type']), fn ($q) => $q->where('sales_orders.order_type', $filters['order_type']))
            ->when(!empty($filters['cashier_id']), fn ($q) => $q->where('sales_orders.created_by_user_id', $filters['cashier_id']))
            ->selectRaw('COALESCE(sales_returns.business_date, DATE(sales_returns.return_date)) as day, COALESCE(SUM(sales_returns.grand_total), 0) as amount')
            ->groupByRaw('COALESCE(sales_returns.business_date, DATE(sales_returns.return_date))')
            ->pluck('amount', 'day')
            ->map(fn ($v) => (float) $v)
            ->all();
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
            ->when(!empty($filters['date_from']),    fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' >= ?', [$filters['date_from']]))
            ->when(!empty($filters['date_to']),      fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' <= ?', [$filters['date_to']]))
            ->when(!empty($filters['terminal_id']),  fn ($q) => $q->where('sales_orders.terminal_id', $filters['terminal_id']))
            ->when(!empty($filters['order_type']),   fn ($q) => $q->where('sales_orders.order_type', $filters['order_type']))
            // BUG-061 FIX: group by product_id + variant_id (stable identifiers), not
            // product_name string — prevents two products with the same name merging.
            ->selectRaw('
                sales_order_lines.product_id,
                sales_order_lines.product_variant_id,
                MAX(sales_order_lines.product_name) as product_name,
                MAX(COALESCE(sales_order_lines.variant_name, \'\')) as variant_name,
                MAX(COALESCE(categories.name, \'Uncategorised\')) as category_name,
                COALESCE(SUM(sales_order_lines.quantity), 0)                              as qty_sold,
                COALESCE(SUM(sales_order_lines.quantity * sales_order_lines.unit_price), 0) as gross_amount,
                COALESCE(SUM(sales_order_lines.discount_amount), 0)                      as total_discount,
                COALESCE(SUM(sales_order_lines.tax_amount), 0)                           as total_tax,
                COALESCE(SUM(sales_order_lines.line_total), 0)                           as net_amount,
                COALESCE(SUM(sales_order_lines.cost_total), 0)                           as total_cost,
                COALESCE(SUM(sales_order_lines.line_total) - SUM(sales_order_lines.cost_total), 0) as profit
            ')
            ->groupBy('sales_order_lines.product_id', 'sales_order_lines.product_variant_id')
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
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' >= ?', [$filters['date_from']]))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' <= ?', [$filters['date_to']]))
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

    /**
     * Dashboard quick stats for today — MUST agree with the Sales Report Center.
     *
     * This used to count only status = 'paid' and never subtract returns, so a returned order
     * disappeared from the order count entirely while its money stayed in "Net Sales". The two
     * errors partly cancelled, which made the tile look plausible while being wrong twice: on a
     * live day it read 7 orders / 10,570 against the report's 11 orders / 10,820.
     *
     * Population and the returns deduction now match SalesReportEngine exactly: a returned order
     * keeps its original sale visible, and returns come off as a separate figure.
     */
    public function todayStats(int $branchId = null, ?\App\Models\Tenant\User $scopeUser = null): array
    {
        // USER-DATA-SCOPE-1: a terminal/order-type restricted operator's dashboard shows only his own
        // numbers (branch + terminal + order_type). Unrestricted users see everything (no-op).
        $scope = app(\App\Services\Security\UserDataScope::class);

        $inner = SalesOrder::query()
            ->whereIn('status', ['paid', 'partially_returned', 'returned'])
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v));
        $scope->applyToSales($inner, $scopeUser);

        $q = $this->currentBusinessDay($inner, $branchId);

        $count    = (clone $q)->count();
        $billed   = (float) (clone $q)->sum('grand_total');
        $gross    = (float) (clone $q)->sum('subtotal');
        $discount = (float) (clone $q)->sum('discount_amount');
        $tax      = (float) (clone $q)->sum('tax_amount');
        $sc       = (float) (clone $q)->sum('service_charge_amount');
        $tips     = (float) (clone $q)->sum('tip_amount');

        // Returns are allocated by BUSINESS day — the order's day the return reverses — exactly as
        // the engine does, so a refund punched after midnight on a still-open shift stays on that
        // shift's day. Calendar return_date is only the fallback for un-backfilled legacy rows.
        $clock = app(\App\Support\TenantClock::class);
        $returnQuery = SalesReturn::query()
            ->where('status', 'posted')
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v));
        // A return's order type / terminal live on its originating sales order, so scope through it.
        if ($scope->isScoped($scopeUser)) {
            $returnQuery->whereHas('order', fn ($so) => $scope->applyToSales($so, $scopeUser));
        }

        if ($branchId) {
            $returnQuery->whereRaw('COALESCE(business_date, DATE(return_date)) = ?', [$clock->currentBusinessDate(\App\Models\Tenant\Branch::find($branchId))]);
        } else {
            $map = $clock->currentBusinessDatesByBranch();
            $returnQuery->where(function ($q) use ($map) {
                foreach ($map as $bid => $date) {
                    $q->orWhere(fn ($w) => $w->where('branch_id', $bid)->whereRaw('COALESCE(business_date, DATE(return_date)) = ?', [$date]));
                }
                if (empty($map)) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        $returns = (float) $returnQuery->sum('grand_total');

        $net = round($billed - $returns, 2);

        return [
            'order_count'           => $count,
            'gross_sales'           => $gross,
            'total_discount'        => $discount,
            'total_tax'             => $tax,
            'total_service_charge'  => $sc,
            'total_tips'            => $tips,
            'billed'                => $billed,
            'returns_amount'        => $returns,
            'net_sales'             => $net,
            'avg_order_value'       => $count > 0 ? round($net / $count, 2) : 0,
        ];
    }

    /**
     * DELIVERY-CHANNELS-1: paid delivery sales grouped by fulfilment channel.
     * Commission is computed at the channel's CURRENT commission_percent -
     * v1 has no per-order commission snapshot (documented caveat).
     */
    public function byChannel(array $filters)
    {
        return $this->baseDeliveryQuery($filters)
            ->leftJoin('delivery_channels', 'sales_orders.delivery_channel_id', '=', 'delivery_channels.id')
            ->selectRaw("
                sales_orders.delivery_channel_id,
                COALESCE(MAX(delivery_channels.name), '(No channel)')       as channel_name,
                MAX(COALESCE(delivery_channels.type, ''))                    as channel_type,
                MAX(COALESCE(delivery_channels.commission_percent, 0))       as commission_percent,
                COUNT(*)                                                     as order_count,
                COALESCE(SUM(sales_orders.grand_total), 0)                   as gross_amount,
                ROUND(COALESCE(SUM(sales_orders.grand_total), 0)
                    * MAX(COALESCE(delivery_channels.commission_percent, 0)) / 100, 2) as commission_amount
            ")
            ->groupBy('sales_orders.delivery_channel_id')
            ->orderByDesc('gross_amount')
            ->get();
    }

    /**
     * DELIVERY-CHANNELS-1: paid delivery sales grouped by rider (totals)
     * plus a rider by day breakdown for the same filter window.
     */
    public function byRider(array $filters): array
    {
        $riders = $this->baseDeliveryQuery($filters)
            ->leftJoin('delivery_riders', 'sales_orders.delivery_rider_id', '=', 'delivery_riders.id')
            ->leftJoin('branches', 'delivery_riders.branch_id', '=', 'branches.id')
            ->selectRaw("
                sales_orders.delivery_rider_id,
                COALESCE(MAX(delivery_riders.name), '(No rider)')  as rider_name,
                MAX(COALESCE(delivery_riders.phone, ''))            as rider_phone,
                MAX(COALESCE(branches.name, 'All Branches'))        as rider_branch,
                COUNT(*)                                            as delivery_count,
                COALESCE(SUM(sales_orders.grand_total), 0)          as total_amount
            ")
            ->groupBy('sales_orders.delivery_rider_id')
            ->orderByDesc('delivery_count')
            ->get();

        $daily = $this->baseDeliveryQuery($filters)
            ->leftJoin('delivery_riders', 'sales_orders.delivery_rider_id', '=', 'delivery_riders.id')
            ->selectRaw("
                {$this->businessDayExpr('sales_orders.')}          as sale_day,
                sales_orders.delivery_rider_id,
                COALESCE(MAX(delivery_riders.name), '(No rider)')  as rider_name,
                COUNT(*)                                            as delivery_count,
                COALESCE(SUM(sales_orders.grand_total), 0)          as total_amount
            ")
            ->groupByRaw($this->businessDayExpr('sales_orders.') . ', sales_orders.delivery_rider_id')
            ->orderByDesc('sale_day')
            ->orderByDesc('delivery_count')
            ->get();

        return ['riders' => $riders, 'daily' => $daily];
    }

    // ── Private helpers ──────────────────────────────────────────────────

    /** Paid DELIVERY sales with the standard report filters applied. */
    private function baseDeliveryQuery(array $filters)
    {
        $branchIds = $this->resolveBranchIds($filters);

        return SalesOrder::query()
            ->where('sales_orders.status', 'paid')
            ->where('sales_orders.order_type', 'delivery')
            ->when($branchIds, fn ($q) => $q->whereIn('sales_orders.branch_id', $branchIds))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' >= ?', [$filters['date_from']]))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->whereRaw($this->businessDayExpr('sales_orders.') . ' <= ?', [$filters['date_to']]))
            ->when(!empty($filters['delivery_channel_id']), fn ($q) => $q->where('sales_orders.delivery_channel_id', $filters['delivery_channel_id']))
            ->when(!empty($filters['delivery_rider_id']),   fn ($q) => $q->where('sales_orders.delivery_rider_id', $filters['delivery_rider_id']));
    }

    /**
     * The reporting population — MUST match SalesReportEngine.
     *
     * This filtered to status = 'paid' alone, so a returned order vanished from every figure it
     * fed. At Khatri that hid 8 orders and, with them, the delivery charges the shop legitimately
     * KEPT on three fully-returned orders: the counter was told to expect 22,500 when the drawer,
     * the ledger and the shift all said 22,850. A returned order keeps its original sale visible;
     * the refund is deducted separately.
     */
    private function baseSalesQuery(array $filters)
    {
        $branchIds = $this->resolveBranchIds($filters);
        $day = $this->businessDayExpr();

        return SalesOrder::query()
            ->whereIn('status', ['paid', 'partially_returned', 'returned'])
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when(!empty($filters['date_from']),    fn ($q) => $q->whereRaw("$day >= ?", [$filters['date_from']]))
            ->when(!empty($filters['date_to']),      fn ($q) => $q->whereRaw("$day <= ?", [$filters['date_to']]))
            ->when(!empty($filters['terminal_id']),  fn ($q) => $q->where('terminal_id', $filters['terminal_id']))
            ->when(!empty($filters['order_type']),   fn ($q) => $q->where('order_type', $filters['order_type']))
            ->when(!empty($filters['cashier_id']),   fn ($q) => $q->where('created_by_user_id', $filters['cashier_id']));
    }

    /**
     * SHIFT-TIMEZONE-BUSINESS-DATE-1 (L): the operational day for reporting is the shift's frozen
     * business_date — so a sale rung after midnight books to the business day it belongs to, not
     * the wall-clock date. COALESCE keeps legacy rows (pre-backfill) grouped by their sale_date.
     */
    /**
     * DASHBOARD-7DAY-POPULATION-1 — the same day, counted the same way, over a RANGE of days.
     *
     * The dashboard's "Last 7 Days" card used to run its own query inside the controller:
     * `status = paid` only, and no return subtraction. So a bill that was returned vanished from
     * it, while the tile above it — and every report in the system — still counted that bill and
     * merely netted the refund off. On 1 September that showed "Orders Today 295" beside a row
     * saying 291 for the same day; on the two days before, which carried PARTIAL returns, it also
     * left 1,400 and 2,490 of genuinely kept revenue out of the history.
     *
     * The population and the arithmetic here are deliberately the ones `todayStats()` uses, which
     * are the ones `SalesReportEngine` uses:
     *   population = paid + partially_returned + returned   (a return does not un-sell a bill)
     *   net_sales  = SUM(grand_total) − posted returns of that business day
     *
     * Returns are allocated by the return's BUSINESS day, exactly as `todayStats()` does, so a
     * refund punched after midnight on a still-open shift stays on that shift's day.
     *
     * @return array<string, array{orders:int, billed:float, returns_amount:float, net_sales:float}>
     *         keyed by business date (Y-m-d)
     */
    public function dailyStats(
        string $from,
        string $to,
        ?int $branchId = null,
        ?\App\Models\Tenant\User $scopeUser = null
    ): array {
        $scope = app(\App\Services\Security\UserDataScope::class);
        $day   = $this->businessDayExpr();

        $sales = SalesOrder::query()
            ->whereIn('status', SalesReportEngine::POPULATION)
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v))
            ->whereRaw("$day >= ?", [$from])
            ->whereRaw("$day <= ?", [$to]);
        $scope->applyToSales($sales, $scopeUser);

        $rows = $sales
            ->selectRaw("$day as day, COUNT(*) as orders, COALESCE(SUM(grand_total), 0) as billed")
            ->groupByRaw($day)
            ->get();

        $returnDay   = 'COALESCE(business_date, DATE(return_date))';
        $returnQuery = SalesReturn::query()
            ->where('status', 'posted')
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v))
            ->whereRaw("$returnDay >= ?", [$from])
            ->whereRaw("$returnDay <= ?", [$to]);

        // A return's order type / terminal live on its originating sales order, so scope through it.
        if ($scope->isScoped($scopeUser)) {
            $returnQuery->whereHas('order', fn ($so) => $scope->applyToSales($so, $scopeUser));
        }

        $returns = $returnQuery
            ->selectRaw("$returnDay as day, COALESCE(SUM(grand_total), 0) as amount")
            ->groupByRaw($returnDay)
            ->pluck('amount', 'day');

        $out = [];
        foreach ($rows as $row) {
            $date     = (string) $row->day;
            $billed   = (float) $row->billed;
            $returned = (float) ($returns[$date] ?? 0);

            $out[$date] = [
                'orders'         => (int) $row->orders,
                'billed'         => $billed,
                'returns_amount' => $returned,
                'net_sales'      => round($billed - $returned, 2),
            ];
        }

        return $out;
    }

    private function businessDayExpr(string $prefix = ''): string
    {
        return "COALESCE({$prefix}business_date, DATE({$prefix}sale_date))";
    }

    /**
     * SHIFT-POS-INTEGRATION-CLOSURE-1: constrain a sales_orders query to the CURRENT business day.
     * "Today" is the branch business-timezone calendar date (via TenantClock), never Laravel's UTC
     * today(). A single branch uses its own business date; an all-branch aggregate lets each branch
     * contribute its OWN current business day (branches in different timezones may differ).
     */
    private function currentBusinessDay($query, ?int $branchId, string $prefix = '')
    {
        $clock = app(\App\Support\TenantClock::class);
        $day = $this->businessDayExpr($prefix);

        if ($branchId) {
            return $query->whereRaw("$day = ?", [$clock->currentBusinessDate(\App\Models\Tenant\Branch::find($branchId))]);
        }

        $map = $clock->currentBusinessDatesByBranch();

        return $query->where(function ($q) use ($map, $day, $prefix) {
            foreach ($map as $bid => $date) {
                $q->orWhere(fn ($w) => $w->where("{$prefix}branch_id", $bid)->whereRaw("$day = ?", [$date]));
            }
            if (empty($map)) {
                $q->whereRaw('1 = 0');
            }
        });
    }
}
