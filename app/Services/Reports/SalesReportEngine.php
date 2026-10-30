<?php

namespace App\Services\Reports;

use App\Services\Concerns\ResolvesBranchIds;
use App\Support\QtyMix;
use App\Support\TenantClock;
use Illuminate\Support\Facades\DB;

/**
 * SALES REPORT CENTER — the ONE shared filter/query engine (Khatri onboarding, sections O–W).
 *
 * LOCKED PRINCIPLES:
 *  - Sales reports answer "what did we sell?"; Cash & Bank answers "where did money come from/go?" —
 *    never merged into one grand total. Opening float / transfers are NEVER revenue.
 *  - Population = status IN (paid, partially_returned, returned): a returned order keeps its original
 *    sale visible; returns are ALWAYS separate columns (returned qty by lines.returned_quantity;
 *    return VALUE = sales_returns.grand_total, the money-movement authority, allocated by return_date
 *    within the period). Net Sales = SUM(grand_total) − period returns. Existing report screens keep
 *    their old formulas untouched — this engine is the single home for the NEW center.
 *  - ONE businessDayExpr home: COALESCE(business_date, DATE(sale_date)).
 *
 * Every report variant reads the SAME normalized filter array so all tabs/exports reconcile:
 *   date_from/date_to (business dates), branch_ids[], terminal_id, shift_id, cashier_id, waiter_id,
 *   order_type, category_id (self+descendants), product_id, payment_method_id.
 */
class SalesReportEngine
{
    use ResolvesBranchIds;

    public const POPULATION = ['paid', 'partially_returned', 'returned'];

    /**
     * QUICK-REPORT-WAITER-NULL-1 — the value that means "orders that carry NO waiter".
     *
     * A waiter is attached on dine-in only, so counter work (takeaway, delivery, quick sale) sits on
     * sales_orders.restaurant_waiter_id = NULL. SQL's IN (...) never matches NULL, so a plain
     * whereIn silently DROPPED that whole population: ticking every waiter on Khatri hid 46 of 82
     * orders in a day, and on Kashif Food — where no order carries a waiter at all — it emptied the
     * report outright. The sentinel makes that population askable instead of unaskable. A selection
     * WITHOUT it still means "only these waiters", so "just Ali's orders" keeps working.
     */
    public const WAITER_NONE = 'none';

    public function businessDayExpr(string $prefix = 'o.'): string
    {
        return "COALESCE({$prefix}business_date, DATE({$prefix}sale_date))";
    }

    /** Normalize raw request filters (fills default = current business day per TenantClock). */
    public function normalizeFilters(array $raw): array
    {
        $today = app(TenantClock::class)->currentBusinessDate();

        return [
            'date_from' => $raw['date_from'] ?? $today,
            'date_to' => $raw['date_to'] ?? $today,
            'branch_ids' => $this->resolveBranchIds($raw),
            'terminal_id' => $raw['terminal_id'] ?? null,
            'allowed_terminal_ids' => array_values(array_filter(array_map('intval', $raw['allowed_terminal_ids'] ?? []))),
            'shift_id' => $raw['shift_id'] ?? null,
            'cashier_id' => $raw['cashier_id'] ?? null,
            'waiter_id' => $raw['waiter_id'] ?? null,
            'order_type' => $raw['order_type'] ?? null,
            'allowed_order_types' => array_values(array_filter($raw['allowed_order_types'] ?? [])),
            'category_id' => $raw['category_id'] ?? null,
            'product_id' => $raw['product_id'] ?? null,
            'payment_method_id' => $raw['payment_method_id'] ?? null,
            // QUICK-REPORT-SEND-1: multi-value filters (Quick Report only). Additive — the Report
            // Center passes single-value filters and never these arrays, so an empty array makes every
            // ->when() below a no-op and its output is byte-identical. All AND-compose with each other.
            'category_ids' => array_values(array_filter(array_map('intval', $raw['category_ids'] ?? []))),
            'product_ids' => array_values(array_filter(array_map('intval', $raw['product_ids'] ?? []))),
            'waiter_ids' => array_values(array_filter($raw['waiter_ids'] ?? [], fn ($v) => $v !== '' && $v !== null)),
            'order_types' => array_values(array_filter($raw['order_types'] ?? [], fn ($v) => $v !== '' && $v !== null)),
            // QUICK-REPORT-OPEN-BILLS-1: khule bills (held + draft) bhi ginne hain ya nahi.
            // Default BAND — Report Center, nightly email, Z Report, legacy reports,
            // restaurant reports aur dashboard sab isay bhejte hi nahi, is liye un ka
            // jawab wohi rehta hai. SIRF POS ki Quick Report ise true bhejti hai.
            'include_open' => (bool) ($raw['include_open'] ?? false),
        ];
    }

    /**
     * QUICK-REPORT-WAITER-NULL-1 — apply the multi-waiter filter, NULL included on request.
     *
     * Wrapped in its own where() group so the OR can never leak out and widen the other filters
     * sitting beside it (branch, date, order type) — that is the classic bug this shape prevents.
     */
    private function applyWaiterIds($q, array $waiterIds, string $column = 'o.restaurant_waiter_id')
    {
        $ids = array_values(array_filter($waiterIds, fn ($v) => (string) $v !== self::WAITER_NONE));
        $wantsUnassigned = count($ids) !== count($waiterIds);

        return $q->where(function ($w) use ($ids, $wantsUnassigned, $column) {
            if ($ids) {
                $w->whereIn($column, $ids);
            }
            if ($wantsUnassigned) {
                $ids ? $w->orWhereNull($column) : $w->whereNull($column);
            }
        });
    }

    /**
     * QUICK-REPORT-WAITER-NULL-1 — the narrowing a reader CANNOT see on the printed page.
     *
     * Categories and items announce themselves: you see which rows printed. Waiter and order-type
     * filters leave no trace at all, so a half report looks exactly like a full one — which is why
     * one saved selection shrank a counter's Quick Report every day for a month before anyone
     * noticed. Naming them in the header is the guard against that silence. Returns [] when the
     * report is whole, so the Report Center's own prints are byte-identical.
     *
     * @return list<string>
     */
    public function describeNarrowing(array $f): array
    {
        $bits = [];

        if ($f['waiter_ids'] ?? []) {
            $ids = array_values(array_filter($f['waiter_ids'], fn ($v) => (string) $v !== self::WAITER_NONE));
            $names = $ids
                ? DB::connection('tenant')->table('restaurant_waiters')->whereIn('id', $ids)->orderBy('name')->pluck('name')->all()
                : [];
            if (count($ids) !== count($f['waiter_ids'])) {
                $names[] = 'Unassigned';
            }
            $bits[] = 'Waiters: ' . ($names ? implode(', ', $names) : '—');
        }

        if ($f['order_types'] ?? []) {
            $labels = \App\Models\Tenant\User::ORDER_TYPES;
            $bits[] = 'Order types: ' . implode(', ', array_map(
                fn ($v) => $labels[$v] ?? (string) $v,
                $f['order_types']
            ));
        }

        return $bits;
    }

    /** Union of each category id + all its descendants (for the multi-category filter). */
    public function categoriesWithDescendants(array $categoryIds): array
    {
        $all = [];
        foreach (array_unique(array_map('intval', $categoryIds)) as $id) {
            foreach ($this->categoryWithDescendants($id) as $descendant) {
                $all[$descendant] = true;
            }
        }

        return array_keys($all);
    }

    /** Base sales_orders query with EVERY shared filter applied. */
    private function salesBase(array $f)
    {
        // QUICK-REPORT-OPEN-BILLS-1: population ka faisla YAHIN — aur ek satar se poori
        // report badalti hai, kyunke `linesBase()` isi par joinSub karta hai. Is liye
        // Categories, Items aur Items-by-Category khud shaamil kar lete hain; Waiters,
        // Order Types aur Combos seedha isi se aate hain. Returns par asar nahi (khule
        // bill ka return hota hi nahi), Cash/Bank payments par chalta hai jo khule bill
        // par hoti hi nahi, aur Cancellations ki apni table hai — teeno khud sach rehte hain.
        //
        // `POPULATION` ki QEEMAT nahi badli: wo chaar aur jagah parhi jaati hai
        // (SalesReportService, RestaurantReportService, DashboardController).
        return DB::connection('tenant')->table('sales_orders as o')
            ->whereIn('o.status', $f['include_open']
                ? array_merge(self::POPULATION, ['held', 'draft'])
                : self::POPULATION)
            ->whereRaw($this->businessDayExpr() . ' >= ?', [$f['date_from']])
            ->whereRaw($this->businessDayExpr() . ' <= ?', [$f['date_to']])
            ->when($f['branch_ids'], fn ($q) => $q->whereIn('o.branch_id', $f['branch_ids']))
            ->when($f['terminal_id'], fn ($q) => $q->where('o.terminal_id', $f['terminal_id']))
            ->when(! $f['terminal_id'] && $f['allowed_terminal_ids'], fn ($q) => $q->whereIn('o.terminal_id', $f['allowed_terminal_ids']))
            ->when($f['shift_id'], fn ($q) => $q->where('o.shift_id', $f['shift_id']))
            ->when($f['cashier_id'], fn ($q) => $q->where('o.created_by_user_id', $f['cashier_id']))
            ->when($f['waiter_id'], fn ($q) => $q->where('o.restaurant_waiter_id', $f['waiter_id']))
            ->when($f['waiter_ids'], fn ($q) => $this->applyWaiterIds($q, $f['waiter_ids']))
            ->when($f['order_type'], fn ($q) => $q->where('o.order_type', $f['order_type']))
            ->when(! $f['order_type'] && $f['allowed_order_types'], fn ($q) => $q->whereIn('o.order_type', $f['allowed_order_types']))
            ->when($f['order_types'], fn ($q) => $q->whereIn('o.order_type', $f['order_types']))
            ->when($f['payment_method_id'], fn ($q) => $q->whereExists(function ($s) use ($f) {
                $s->selectRaw('1')->from('sale_payments as sp')
                    ->whereColumn('sp.sales_order_id', 'o.id')
                    ->where('sp.payment_method_id', $f['payment_method_id']);
            }))
            // DEAL-CATEGORY-1: the FILTER follows the same rule as the grouping. Left on the
            // product alone, a report filtered to "Platters" would find no deals while the
            // Categories section happily filed them there — a fresh contradiction, and a worse one
            // than the original because it would look deliberate.
            ->when($f['category_id'], fn ($q) => $q->whereExists(function ($s) use ($f) {
                $s->selectRaw('1')->from('sales_order_lines as fl')
                    ->leftJoin('products as fp', 'fp.id', '=', 'fl.product_id')
                    ->leftJoin('combos as fcb', 'fcb.id', '=', 'fl.combo_id')
                    ->whereColumn('fl.sales_order_id', 'o.id')
                    ->whereIn(DB::raw('COALESCE(fcb.category_id, fp.category_id)'),
                        $this->categoryWithDescendants((int) $f['category_id']));
            }))
            ->when($f['category_ids'], fn ($q) => $q->whereExists(function ($s) use ($f) {
                $s->selectRaw('1')->from('sales_order_lines as fl')
                    ->leftJoin('products as fp', 'fp.id', '=', 'fl.product_id')
                    ->leftJoin('combos as fcb', 'fcb.id', '=', 'fl.combo_id')
                    ->whereColumn('fl.sales_order_id', 'o.id')
                    ->whereIn(DB::raw('COALESCE(fcb.category_id, fp.category_id)'),
                        $this->categoriesWithDescendants($f['category_ids']));
            }))
            ->when($f['product_id'], fn ($q) => $q->whereExists(function ($s) use ($f) {
                $s->selectRaw('1')->from('sales_order_lines as fl')
                    ->whereColumn('fl.sales_order_id', 'o.id')->where('fl.product_id', $f['product_id']);
            }))
            ->when($f['product_ids'], fn ($q) => $q->whereExists(function ($s) use ($f) {
                $s->selectRaw('1')->from('sales_order_lines as fl')
                    ->whereColumn('fl.sales_order_id', 'o.id')->whereIn('fl.product_id', $f['product_ids']);
            }));
    }

    /** Line-level base (joins products/categories) constrained to the same sale population. */
    /**
     * REPORT-DEAL-COMPONENTS-1 — a deal's parts are not separate sales.
     *
     * Selling a deal writes a `combo_header` line carrying the WHOLE price, plus one `component`
     * line per linked item carrying 0.00. The components exist to tell the kitchen what to cook and
     * to draw the stock; they were never sold on their own.
     *
     * Counting them here made every quantity lie. On 30 Aug the report printed 1,620 units against
     * 1,322 actually sold; Bar-B-Que read 148 when 87 were sold and 61 went out inside deals. It also
     * produced rows like "Regular Drink 39 @ 0.00", which reads as 39 free drinks, and it DOUBLED a
     * deal whose header points at the same product as one of its own components (Khaas is product
     * 206 twice, so byItem's group-by merged 5 + 5 into 10).
     *
     * Excluding them cannot move a single rupee — their line_total is 0.00 — so every financial
     * figure in every section is byte-identical before and after. Only the quantities become true.
     *
     * The DETAILED section passes $includeComponents = true: it is the line-by-line record, and a
     * deal's parts belong in it.
     */
    private function linesBase(array $f, bool $includeComponents = false)
    {
        $cat = $this->lineCategoryExpr();

        return DB::connection('tenant')->table('sales_order_lines as l')
            ->joinSub($this->salesBase($f)->select('o.*'), 'o', 'o.id', '=', 'l.sales_order_id')
            ->unless($includeComponents, fn ($q) => $q->where(
                fn ($w) => $w->where('l.line_kind', '!=', 'component')->orWhereNull('l.line_kind')
            ))
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            // DEAL-CATEGORY-1: joined here, once, so every consumer of this builder files a deal
            // under the deal's own head without having to remember the rule.
            ->leftJoin('combos as cb', 'cb.id', '=', 'l.combo_id')
            ->leftJoin('categories as c', fn ($j) => $j->on(DB::raw('c.id'), '=', DB::raw($cat)))
            ->when($f['category_id'], fn ($q) => $q->whereIn(DB::raw($cat), $this->categoryWithDescendants((int) $f['category_id'])))
            ->when($f['category_ids'], fn ($q) => $q->whereIn(DB::raw($cat), $this->categoriesWithDescendants($f['category_ids'])))
            ->when($f['product_id'], fn ($q) => $q->where('l.product_id', $f['product_id']))
            ->when($f['product_ids'], fn ($q) => $q->whereIn('l.product_id', $f['product_ids']));
    }

    /**
     * DEAL-CATEGORY-1 — the ONE definition of "which category a sales line reports under".
     *
     * A deal has no product of its own: its `combo_header` line sits on whichever product it was
     * built from, so the money followed that product's category. Classic Platter 3 begins with
     * Arabic Rice, so 26,400 of platters was filed under Extras — beside the cheese slices.
     *
     * The combo already knows its own head (`combos.category_id`, populated since
     * POS-COMBO-CATEGORY-1 on 30 Aug and declared display-only at the time). This reads it.
     *
     * It lives in ONE place on purpose. The category was worked out in three separate spots —
     * the grouping, the category FILTER and the departments report — and three separate COALESCEs
     * would fix September and lose again the next time somebody writes a fourth. That is precisely
     * how this family of bugs keeps returning. Same reasoning as `businessDayExpr()`.
     *
     * Requires `combos as cb` and `products as p` to be in scope — both line builders join them.
     */
    private function lineCategoryExpr(): string
    {
        return 'COALESCE(cb.category_id, p.category_id)';
    }

    /** Period returns (allocated by BUSINESS day — the order's day it reverses, calendar
     *  return_date only as a fallback for un-backfilled rows) constrained to the same branch scope. */
    private function returnsBase(array $f)
    {
        // RETURN-NO-BILL-1: LEFT join — a return without a bill has no sale (o.* is NULL) and must still count.
        // A chosen order type / waiter means returns of THAT kind of sale (none without a bill); an operator's
        // order-type SCOPE still shows him his returns without a bill; the cashier is the one who posted it.
        return DB::connection('tenant')->table('sales_returns as r')
            ->leftJoin('sales_orders as o', 'o.id', '=', 'r.sales_order_id')
            ->where('r.status', 'posted')
            ->whereRaw('COALESCE(r.business_date, DATE(r.return_date)) >= ?', [$f['date_from']])
            ->whereRaw('COALESCE(r.business_date, DATE(r.return_date)) <= ?', [$f['date_to']])
            ->when($f['branch_ids'], fn ($q) => $q->whereIn('r.branch_id', $f['branch_ids']))
            ->when($f['order_type'], fn ($q) => $q->where('o.order_type', $f['order_type']))
            ->when(! $f['order_type'] && $f['allowed_order_types'], fn ($q) => $q->where(fn ($w) => $w->whereIn('o.order_type', $f['allowed_order_types'])->orWhereNull('r.sales_order_id')))
            ->when($f['order_types'], fn ($q) => $q->whereIn('o.order_type', $f['order_types']))
            ->when($f['waiter_id'], fn ($q) => $q->where('o.restaurant_waiter_id', $f['waiter_id']))
            ->when($f['waiter_ids'], fn ($q) => $this->applyWaiterIds($q, $f['waiter_ids']))
            // REFUND-SHIFT-1: a refund belongs to the counter / shift whose drawer PAID it (recorded on the return
            // from 20 Oct); older returns carry none and keep falling back to the sale's own, so no past figure moves.
            ->when($f['terminal_id'], fn ($q) => $q->whereRaw('COALESCE(r.terminal_id, o.terminal_id) = ?', [$f['terminal_id']]))
            ->when(! $f['terminal_id'] && $f['allowed_terminal_ids'], fn ($q) => $q->whereIn(DB::raw('COALESCE(r.terminal_id, o.terminal_id)'), $f['allowed_terminal_ids']))
            ->when($f['cashier_id'], fn ($q) => $q->whereRaw('COALESCE(o.created_by_user_id, r.created_by_user_id) = ?', [$f['cashier_id']]))
            ->when($f['shift_id'], fn ($q) => $q->whereRaw('COALESCE(r.shift_id, o.shift_id) = ?', [$f['shift_id']]));
    }

    /** Period return lines with the same sale-side filters as returnsBase(). */
    private function returnLinesBase(array $f)
    {
        $cat = $this->lineCategoryExpr();

        return $this->returnsBase($f)
            ->join('sales_return_lines as rl', 'rl.sales_return_id', '=', 'r.id')
            // RETURN-NO-BILL-1: LEFT — a line returned without a bill has no sale line (no deal, its own name).
            ->leftJoin('sales_order_lines as ol', 'ol.id', '=', 'rl.sales_order_line_id')
            ->leftJoin('products as p', 'p.id', '=', 'rl.product_id')
            // DEAL-CATEGORY-1: the combo comes off the ORIGINAL sale line, so a refunded deal
            // credits back to the same head it was sold under.
            ->leftJoin('combos as cb', 'cb.id', '=', 'ol.combo_id')
            ->leftJoin('categories as c', fn ($j) => $j->on(DB::raw('c.id'), '=', DB::raw($cat)))
            ->when($f['category_id'], fn ($q) => $q->whereIn(DB::raw($cat), $this->categoryWithDescendants((int) $f['category_id'])))
            ->when($f['category_ids'], fn ($q) => $q->whereIn(DB::raw($cat), $this->categoriesWithDescendants($f['category_ids'])))
            ->when($f['product_id'], fn ($q) => $q->where('rl.product_id', $f['product_id']))
            ->when($f['product_ids'], fn ($q) => $q->whereIn('rl.product_id', $f['product_ids']));
    }

    /** category id + all descendants (MySQL 8 recursive CTE — adjacency list authority). */
    public function categoryWithDescendants(int $categoryId): array
    {
        $rows = DB::connection('tenant')->select(
            'WITH RECURSIVE tree AS (
                SELECT id FROM categories WHERE id = ?
                UNION ALL
                SELECT c.id FROM categories c JOIN tree t ON c.parent_id = t.id
            ) SELECT id FROM tree',
            [$categoryId]
        );

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /** Root ancestor map for category rollups: [category_id => root_id]. */
    private function rootMap(): array
    {
        $rows = DB::connection('tenant')->select(
            'WITH RECURSIVE tree AS (
                SELECT id, id AS root_id FROM categories WHERE parent_id IS NULL
                UNION ALL
                SELECT c.id, t.root_id FROM categories c JOIN tree t ON c.parent_id = t.id
            ) SELECT id, root_id FROM tree'
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->id] = (int) $r->root_id;
        }

        return $map;
    }

    /**
     * A category or product filter narrows the report to specific LINES within a bill.
     * The order-level sections (overview, waiters, order types) must then measure only
     * those lines too — otherwise a bill that merely CONTAINS the category counts whole,
     * and the headline dwarfs the very category the operator filtered to. With no such
     * filter this is false and every total stays order-level, so an all-"All" report
     * reconciles exactly as before.
     */
    private function isLineNarrowed(array $f): bool
    {
        return ! empty($f['category_id']) || ! empty($f['product_id'])
            || ! empty($f['category_ids']) || ! empty($f['product_ids']);
    }

    // ── Q. Overview KPIs ─────────────────────────────────────────────────────────────────────────
    public function overview(array $f): array
    {
        if ($this->isLineNarrowed($f)) {
            return $this->overviewNarrowed($f);
        }

        $s = $this->salesBase($f)->selectRaw(
            'COUNT(*) as orders,
             COALESCE(SUM(o.subtotal),0) as gross_sales,
             COALESCE(SUM(o.discount_amount),0) as discount,
             COALESCE(SUM(o.tax_amount),0) as tax,
             COALESCE(SUM(o.service_charge_amount),0) as service_charge,
             COALESCE(SUM(o.delivery_charge_amount),0) as delivery_charge,
             COALESCE(SUM(o.tip_amount),0) as tips,
             COALESCE(SUM(o.grand_total),0) as grand_total'
        )->first();
        // PACK-QTY-REPORTS-1: quantities in the item's own unit. A line sold in a pack ("2 Strip") keeps its tablets in
        // base_quantity; every other line has it NULL and counts `quantity` exactly as before. Same rule below.
        $soldQty = (float) $this->linesBase($f)->sum(DB::raw('COALESCE(l.base_quantity, l.quantity)'));
        $returnedQty = (float) $this->returnLinesBase($f)->sum(DB::raw('COALESCE(rl.base_quantity, rl.quantity)'));
        $returns = (float) $this->returnsBase($f)->sum('r.grand_total');
        $refundsRecorded = (float) $this->returnsBase($f)->sum('r.refund_amount');
        $cashRefunds = (float) $this->returnsBase($f)->where('r.refund_method', 'cash')->sum('r.refund_amount');
        $payments = DB::connection('tenant')->table('sale_payments as sp')
            ->joinSub($this->salesBase($f)->select('o.*'), 'o', 'o.id', '=', 'sp.sales_order_id')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->groupBy('pm.method_type')
            ->selectRaw('pm.method_type, COALESCE(SUM(sp.amount),0) as amount')
            ->pluck('amount', 'method_type')->map(fn ($v) => (float) $v)->all();

        return [
            'orders' => (int) $s->orders,
            'sold_qty' => $soldQty,
            'returned_qty' => $returnedQty,
            'net_qty' => $soldQty - $returnedQty,
            // UNITS-PACKS F4: the same totals split by kind of unit (kg and pieces are never one number).
            ...$this->overviewMix($f),
            'gross_sales' => (float) $s->gross_sales,
            'discount' => (float) $s->discount,
            'tax' => (float) $s->tax,
            'service_charge' => (float) $s->service_charge,
            'delivery_charge' => (float) $s->delivery_charge,
            // Delivery income given back with a fully-returned order. The category/item bridge
            // must add NET delivery (charged − refunded), or it no longer reaches NET SALES.
            'delivery_refunded' => (float) $this->returnsBase($f)->sum('r.delivery_charge_amount'),
            'tips' => (float) $s->tips,
            'grand_total' => (float) $s->grand_total,
            'returns_amount' => $returns,
            'refunds_recorded' => $refundsRecorded,
            'cash_refunds' => $cashRefunds,
            // max(0, …) with two equal operands returns PHP's INT 0, so this key silently changed
            // type whenever refunds exactly matched returns. Every other figure here is a float.
            'returns_not_refunded' => max(0.0, $returns - $refundsRecorded),
            'net_sales' => (float) $s->grand_total - $returns,
            'payments' => $payments,
            'cash_collected' => (float) ($payments['cash'] ?? 0),
            'net_cash_from_sales' => (float) ($payments['cash'] ?? 0) - $cashRefunds,
        ];
    }

    /**
     * Overview restricted to the filtered lines — merchandise only. Order-level charges
     * (delivery, service, tips) and the order-level discount/tax belong to the whole bill,
     * not a category, so they are zero here; SUM(line_total) already carries any LINE
     * discount/tax, so Items Sold − Discount + Tax still reconciles to BILLED. A bill's
     * payment method cannot be split across its categories either, so the method breakdown
     * is dropped and the cash section mirrors the slice's own net.
     */
    private function overviewNarrowed(array $f): array
    {
        $m = $this->linesBase($f)->selectRaw(
            'COUNT(DISTINCT o.id) as orders,
             COALESCE(SUM(l.quantity * l.unit_price),0) as gross_sales,
             COALESCE(SUM(l.discount_amount),0) as discount,
             COALESCE(SUM(l.tax_amount),0) as tax,
             COALESCE(SUM(l.line_total),0) as net'
        )->first();
        $soldQty = (float) $this->linesBase($f)->sum(DB::raw('COALESCE(l.base_quantity, l.quantity)'));
        $returnedQty = (float) $this->returnLinesBase($f)->sum(DB::raw('COALESCE(rl.base_quantity, rl.quantity)'));
        $returns = (float) $this->returnLinesBase($f)->sum('rl.line_total');
        $billed = (float) $m->net;
        $netSales = $billed - $returns;

        return [
            'orders' => (int) $m->orders,
            'sold_qty' => $soldQty,
            'returned_qty' => $returnedQty,
            'net_qty' => $soldQty - $returnedQty,
            // UNITS-PACKS F4: the same totals split by kind of unit (kg and pieces are never one number).
            ...$this->overviewMix($f),
            'gross_sales' => (float) $m->gross_sales,
            'discount' => (float) $m->discount,
            'tax' => (float) $m->tax,
            'service_charge' => 0.0,
            'delivery_charge' => 0.0,
            'delivery_refunded' => 0.0,
            'tips' => 0.0,
            'grand_total' => $billed,
            'returns_amount' => $returns,
            'refunds_recorded' => $returns,
            'cash_refunds' => $returns,
            'returns_not_refunded' => 0.0,
            'net_sales' => $netSales,
            'payments' => [],
            'cash_collected' => $billed,
            'net_cash_from_sales' => $netSales,
        ];
    }

    // ── R. Category → child → item rollup ───────────────────────────────────────────────────────
    public function byCategory(array $f): array
    {
        $cat = $this->lineCategoryExpr();

        $rows = $this->linesBase($f)
            ->groupByRaw($cat)
            ->selectRaw(
                $cat . ' as category_id,
                 MAX(c.name) as category_name, MAX(c.parent_id) as parent_id,
                 COUNT(DISTINCT o.id) as orders,
                 COALESCE(SUM(COALESCE(l.base_quantity, l.quantity)),0) as sold_qty,
                 COALESCE(SUM(l.quantity * l.unit_price),0) as gross,
                 COALESCE(SUM(l.discount_amount),0) as discount,
                 COALESCE(SUM(l.tax_amount),0) as tax,
                 COALESCE(SUM(l.line_total),0) as net'
            )->get();
        $returnByCategory = $this->returnStatsBy($f, $cat);
        $rootMap = $this->rootMap();
        $names = DB::connection('tenant')->table('categories')->pluck('name', 'id');

        $tree = [];
        foreach ($rows as $row) {
            $catId = $row->category_id !== null ? (int) $row->category_id : 0;
            $rootId = $catId !== 0 ? ($rootMap[$catId] ?? $catId) : 0;
            $tree[$rootId]['id'] = $rootId;
            $tree[$rootId]['name'] = $rootId === 0 ? 'Uncategorised' : ($names[$rootId] ?? ('#' . $rootId));
            $child = &$tree[$rootId]['children'][$catId];
            $child['id'] = $catId;
            $child['name'] = $catId === 0 ? 'Uncategorised' : ($names[$catId] ?? ('#' . $catId));
            foreach (['orders', 'sold_qty', 'gross', 'discount', 'tax', 'net'] as $k) {
                $child[$k] = (float) $row->{$k};
                $tree[$rootId][$k] = ($tree[$rootId][$k] ?? 0) + (float) $row->{$k};
            }
            unset($child);
        }
        foreach ($returnByCategory as $catId => $return) {
            $rootId = $catId !== 0 ? ($rootMap[$catId] ?? $catId) : 0;
            $tree[$rootId]['id'] = $rootId;
            $tree[$rootId]['name'] = $rootId === 0 ? 'Uncategorised' : ($names[$rootId] ?? ('#' . $rootId));
            $child = &$tree[$rootId]['children'][$catId];
            $child['id'] = $catId;
            $child['name'] = $catId === 0 ? 'Uncategorised' : ($names[$catId] ?? ('#' . $catId));
            $child['returned_qty'] = (float) $return['quantity'];
            $child['returns_amount'] = (float) $return['amount'];
            $tree[$rootId]['returned_qty'] = ($tree[$rootId]['returned_qty'] ?? 0) + (float) $return['quantity'];
            $tree[$rootId]['returns_amount'] = ($tree[$rootId]['returns_amount'] ?? 0) + (float) $return['amount'];
            unset($child);
        }
        // A parent's "orders" must be a DISTINCT count over its sub-tree; summing the
        // children (above) double-counts an order that has lines in two of them (the
        // qty/gross/net sums are right — only a head-count double-counts). One pass maps
        // every leaf category to its root and counts distinct orders per root.
        $distinctOrdersByRoot = [];
        if ($rootMap !== []) {
            $cases = collect($rootMap)->map(fn ($root, $catId) => 'WHEN ' . (int) $catId . ' THEN ' . (int) $root)->implode(' ');
            // DEAL-CATEGORY-1: rolls up from the SAME category the rows were grouped by, or a
            // deal's order would be counted against the head its anchor product happens to sit in.
            $rootExpr = 'CASE ' . $cat . ' ' . $cases . ' ELSE 0 END';
            $distinctOrdersByRoot = $this->linesBase($f)
                ->selectRaw($rootExpr . ' as root_id, COUNT(DISTINCT o.id) as orders')
                ->groupBy(DB::raw($rootExpr))
                ->pluck('orders', 'root_id')->all();
        }

        // UNITS-PACKS F4: the same quantities split by kind of unit, so a head of kg and pieces is never one number.
        $mixes = $this->qtyMixes($f, $cat);

        foreach ($tree as $rootId => &$root) {
            foreach (['orders', 'sold_qty', 'returned_qty', 'gross', 'discount', 'tax', 'net', 'returns_amount'] as $key) {
                $root[$key] ??= 0;
            }
            if (array_key_exists((int) $rootId, $distinctOrdersByRoot)) {
                $root['orders'] = (int) $distinctOrdersByRoot[(int) $rootId];
            }
            $root['net_qty'] = $root['sold_qty'] - $root['returned_qty'];
            $root['net_value'] = $root['net'] - $root['returns_amount'];
            $root['children'] = array_values($root['children'] ?? []);
            $root['sold_mix'] = $root['returned_mix'] = [];
            foreach ($root['children'] as &$c) {
                foreach (['orders', 'sold_qty', 'returned_qty', 'gross', 'discount', 'tax', 'net', 'returns_amount'] as $key) {
                    $c[$key] ??= 0;
                }
                $c['net_qty'] = $c['sold_qty'] - $c['returned_qty'];
                $c['net_value'] = $c['net'] - $c['returns_amount'];
                $c['sold_mix'] = $mixes[(int) $c['id']]['sold'] ?? [];
                $c['returned_mix'] = $mixes[(int) $c['id']]['returned'] ?? [];
                $c['net_mix'] = QtyMix::merge($c['sold_mix'], $c['returned_mix'], -1.0);
                $root['sold_mix'] = QtyMix::merge($root['sold_mix'], $c['sold_mix']);
                $root['returned_mix'] = QtyMix::merge($root['returned_mix'], $c['returned_mix']);
            }
            unset($c);
            $root['net_mix'] = QtyMix::merge($root['sold_mix'], $root['returned_mix'], -1.0);
            // REPORT-CATEGORY-ORDER-1: within a head too, so a sub-category lands where the menu
            // puts it rather than where the grouping happened to leave it.
            usort($root['children'], fn ($x, $y) => $this->compareCategoryOrder((int) $x['id'], (int) $y['id']));
        }
        unset($root);

        $out = array_values($tree);
        usort($out, fn ($a, $b) => $this->compareCategoryOrder((int) $a['id'], (int) $b['id']));

        return $out;
    }

    // ── S. Item report ───────────────────────────────────────────────────────────────────────────
    /**
     * REPORT-DEAL-IDENTITY-1 — a deal reports under its OWN name.
     *
     * A deal has no product of its own: its `combo_header` line sits on an existing product, very
     * often one of its own components. Grouping by product alone therefore piled several different
     * things into one row and labelled the pile with MAX(product_name) — whichever name sorted
     * highest. On 31 Aug that row read "Singaporean Rice (Regular) (Midnight) — 196", when Midnight
     * had sold 30: the other 166 were ordinary Regular plus four more deals. Seven such rows that
     * day, the largest on the report among them.
     *
     * Grouping now carries the combo as well, so each deal is its own row under `combos.name`, and
     * the plain product keeps its own. NOTHING moves: grouping decides which rows exist, never the
     * sum — measured at 465,450.00 either way before this shipped.
     *
     * RETURNS follow the same split, which is the part that could have moved money. A return keyed
     * only by product would land on whichever row happened to match, or fall out entirely.
     * returnLinesBase already joins the original order line, so its combo is known: on 31 Aug
     * product 158's five returned units divide as 4 from ordinary sales and 1 from the Midnight
     * deal — the same 2,950 as before. A return whose original line is missing keeps combo NULL and
     * lands on the plain row rather than disappearing.
     */
    /**
     * DEAL-CATEGORY-1: `$excludeCombos` takes the deals out, because they now have a section of
     * their own (`byDeal`). Printed together they would count the same money twice.
     *
     * The flag defaults to FALSE so nothing that already calls this changes behaviour; only the
     * Items section asks for the split. And the two must stay linked in the UI — Items alone, with
     * deals removed and no Deals section beside it, is a total that does not reconcile.
     */
    public function byItem(array $f, string $sort = 'net', bool $excludeCombos = false): array
    {
        $key = fn ($r) => $r->product_id . ':' . ($r->product_variant_id ?? '') . ':' . ($r->combo_id ?? '');

        $rows = $this->linesBase($f)
            ->when($excludeCombos, fn ($q) => $q->whereNull('l.combo_id'))
            ->leftJoin('product_variants as v', 'v.id', '=', 'l.product_variant_id')
            // UNITS-PACKS F4: the stock unit the quantities are counted in (base quantity, so a Bag counts its kg).
            ->leftJoin('units as un', 'un.id', '=', 'p.unit_id')
            // `combos as cb` is joined by linesBase() since DEAL-CATEGORY-1 — joining it again
            // here would be a duplicate alias.
            ->groupBy('l.product_id', 'l.product_variant_id', 'l.combo_id')
            ->selectRaw(
                'l.product_id, l.product_variant_id, l.combo_id,
                 COALESCE(MAX(cb.name), MAX(l.product_name)) as item,
                 MAX(v.name) as variant, MAX(c.name) as category, MAX(un.name) as unit, MAX(p.unit_id) as unit_id,
                 -- ITEMS-BY-CATEGORY-1: the id travels with the name, so a row can be filed under
                 -- its ROOT head without a second query guessing the head from a string.
                 MAX(c.id) as category_id,
                 COALESCE(SUM(COALESCE(l.base_quantity, l.quantity)),0) as sold_qty,
                 COALESCE(SUM(l.quantity * l.unit_price),0) as gross,
                 COALESCE(SUM(l.discount_amount),0) as discount,
                 COALESCE(SUM(l.tax_amount),0) as tax,
                 COALESCE(SUM(l.line_total),0) as net'
            )->get()->keyBy($key);
        $returnRows = $this->returnLinesBase($f)
            ->leftJoin('product_variants as v', 'v.id', '=', 'rl.product_variant_id')
            ->leftJoin('units as un', 'un.id', '=', 'p.unit_id')
            // as above — returnLinesBase() already joins `combos as cb` off ol.combo_id.
            // The refund follows the sale: exclude a deal's refund from Items too, or a returned
            // deal would credit a section it was never counted in.
            ->when($excludeCombos, fn ($q) => $q->whereNull('ol.combo_id'))
            ->groupBy('rl.product_id', 'rl.product_variant_id', 'ol.combo_id')
            ->selectRaw(
                'rl.product_id, rl.product_variant_id, ol.combo_id,
                 COALESCE(MAX(cb.name), MAX(ol.product_name), MAX(p.name)) as item,
                 MAX(v.name) as variant, MAX(c.name) as category, MAX(un.name) as unit, MAX(p.unit_id) as unit_id,
                 MAX(c.id) as category_id,
                 COALESCE(SUM(COALESCE(rl.base_quantity, rl.quantity)),0) as returned_qty,
                 COALESCE(SUM(rl.line_total),0) as returns_amount'
            )->get()->keyBy($key);

        $rows = $rows->keys()->merge($returnRows->keys())->unique()->map(function ($key) use ($rows, $returnRows) {
            $sold = $rows->get($key);
            $returned = $returnRows->get($key);
            $row = $sold ?: $returned;
            // A deal is counted in deals, not in any one item's unit.
            $row->unit = $row->combo_id ? null : ($sold->unit ?? $returned->unit ?? null);
            $row->sold_qty = (float) ($sold->sold_qty ?? 0);
            $row->returned_qty = (float) ($returned->returned_qty ?? 0);
            $row->net_qty = $row->sold_qty - $row->returned_qty;
            $row->gross = (float) ($sold->gross ?? 0);
            $row->discount = (float) ($sold->discount ?? 0);
            $row->tax = (float) ($sold->tax ?? 0);
            $row->net = (float) ($sold->net ?? 0);
            $row->returns_amount = (float) ($returned->returns_amount ?? 0);
            $row->net_value = $row->net - $row->returns_amount;
            // UNITS-PACKS F4: one item is one kind of unit; its mix lets every total above it add kg and pieces apart.
            $uid = $row->combo_id ? null : ($sold->unit_id ?? $returned->unit_id ?? null);
            $row->unit_id = $uid !== null ? (int) $uid : null;
            $row->sold_mix = QtyMix::add([], $row->unit_id, $row->sold_qty);
            $row->returned_mix = QtyMix::add([], $row->unit_id, $row->returned_qty);
            $row->net_mix = QtyMix::merge($row->sold_mix, $row->returned_mix, -1.0);

            return $row;
        });

        return $rows->sortByDesc(fn ($r) => match ($sort) {
            'qty' => (float) $r->sold_qty,
            'returns' => (float) $r->returned_qty,
            'alpha' => null,
            default => (float) $r->net_value,
        })->when($sort === 'alpha', fn ($c) => $c->sortBy('item'))->values()->all();
    }

    /**
     * REPORT-CATEGORY-ORDER-1 — the menu's own order, for the report to print in.
     *
     * `categories.sort_order` is the number the owner sets on the Catalog screen, and the POS has
     * always laid its pills out by it. The reports never looked: CATEGORIES came back in whatever
     * order MySQL grouped the rows in, and ITEMS BY CATEGORY led with the biggest earner. So the
     * shop read its takings in an order it had never chosen, and the one field that exists to say
     * "print biryani first" did nothing outside the till.
     *
     * Name is the tiebreaker so two categories sharing a number still come out the same way twice.
     *
     * DELIBERATELY NOT CACHED in a static. The scheduled report command walks every tenant inside
     * one PHP process, so a map held in a static would be built from the first tenant's categories
     * and then silently reused for the rest — every shop after the first would print in a
     * stranger's order. It is one small query per section; a report asks for it twice.
     *
     * @return array<int, array{0:int, 1:string}>  id => [sort_order, name]
     */
    private function categoryOrder(): array
    {
        return DB::connection('tenant')->table('categories')
            ->get(['id', 'sort_order', 'name'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => [(int) $c->sort_order, (string) $c->name]])
            ->all();
    }

    /** Compare two category ids by the order the owner set on the Catalog screen. */
    private function compareCategoryOrder(int $a, int $b): int
    {
        $order = $this->categoryOrder();
        // Anything with no category of its own ("Uncategorised") sorts last, not first: a bucket
        // for the unfiled is not what a shop wants at the top of its report.
        $left  = $order[$a] ?? [PHP_INT_MAX, 'zzz'];
        $right = $order[$b] ?? [PHP_INT_MAX, 'zzz'];

        return [$left[0], $left[1]] <=> [$right[0], $right[1]];
    }

    /**
     * BRIDGE-DEALS-1 — the net value of the deals in a period, for the section bridges.
     *
     * A section that EXCLUDES deals (Items, and Items by Category) cannot close to NET SALES by
     * adding charges alone: the deals are missing from its total too. Before this the whole gap was
     * printed as "Plus Delivery & Other Charges", so at Kashif Food on 2 September a line that
     * should have read 4,369 read 95,859 — the other 91,490 was deal money wearing the wrong name.
     *
     * Deliberately a small aggregate of its own rather than a byDeal() call: the bridge needs one
     * number and byDeal builds a whole grouped report to get it.
     */
    public function dealsNet(array $f): float
    {
        $sold = (float) $this->linesBase($f)->whereNotNull('l.combo_id')->sum('l.line_total');
        $back = (float) $this->returnLinesBase($f)->whereNotNull('ol.combo_id')->sum('rl.line_total');

        return round($sold - $back, 2);
    }

    /**
     * ITEMS-BY-CATEGORY-1 — the Items section, filed under category heads.
     *
     * The client's old software prints its item report this way: a category head with its own
     * subtotal, then the items beneath it. The owner reads both papers side by side, so he asked
     * for the same shape here — but as a SECTION OF ITS OWN, beside Items rather than instead of
     * it, exactly as Deals was added on 2 September. The existing Items report is untouched.
     *
     * It is built ON TOP OF `byItem($f, 'net', excludeCombos: true)` and does no arithmetic of its
     * own beyond adding those same rows up. That is deliberate: two queries computing "the same"
     * item money is how two figures eventually disagree, and this report exists precisely so the
     * owner can reconcile. Deals stay out for the reason they are out of Items — they have their
     * own section, and counting them here would double the money.
     *
     * Heads are ROOTS. A tenant nests its categories (Khatri files Beef Khatri / Khatri Sadi /
     * Matka under Beef Khatri Biryani; Kashif files seven kinds of roll under Rolls), and the
     * Categories section already rolls those up to the root. Filing items under the leaf instead
     * would print head names that appear nowhere else on the report, with subtotals that reconcile
     * against nothing. So the root leads and the leaf appears as a sub-head beneath it — but only
     * where a root really has children with sales. A flat category prints its items directly,
     * without a ceremonial sub-head repeating the name above it.
     *
     * @return list<array{head:string, head_id:int, nested:bool, groups:list<array>}>
     */
    public function byCategoryItems(array $f): array
    {
        $rows = $this->byItem($f, 'net', true);

        if ($rows === []) {
            return [];
        }

        $rootMap = $this->rootMap();
        $names   = DB::connection('tenant')->table('categories')->pluck('name', 'id');
        $money   = ['sold_qty', 'returned_qty', 'net_qty', 'gross', 'discount', 'tax', 'net', 'returns_amount', 'net_value'];

        $heads = [];
        foreach ($rows as $row) {
            $leafId = $row->category_id !== null ? (int) $row->category_id : 0;
            $rootId = $leafId !== 0 ? ($rootMap[$leafId] ?? $leafId) : 0;

            $heads[$rootId]['head_id'] ??= $rootId;
            $heads[$rootId]['head']    ??= $rootId === 0 ? 'Uncategorised' : ($names[$rootId] ?? ('#' . $rootId));

            $group = &$heads[$rootId]['groups'][$leafId];
            $group['id']   ??= $leafId;
            $group['name'] ??= $leafId === 0 ? 'Uncategorised' : ($names[$leafId] ?? ('#' . $leafId));

            foreach ($money as $k) {
                $v                  = (float) ($row->{$k} ?? 0);
                $group[$k]          = ($group[$k] ?? 0) + $v;
                $heads[$rootId][$k] = ($heads[$rootId][$k] ?? 0) + $v;
            }
            foreach (['sold_mix', 'returned_mix', 'net_mix'] as $k) {
                $group[$k] = QtyMix::merge($group[$k] ?? [], $row->{$k} ?? []);
                $heads[$rootId][$k] = QtyMix::merge($heads[$rootId][$k] ?? [], $row->{$k} ?? []);
            }

            $group['items'][] = [
                'item'           => (string) $row->item,
                'variant'        => $row->variant !== null ? (string) $row->variant : null,
                'category'       => (string) ($row->category ?? ''),
                'sold_qty'       => (float) $row->sold_qty,
                'returned_qty'   => (float) $row->returned_qty,
                'net_qty'        => (float) $row->net_qty,
                'gross'          => (float) $row->gross,
                'discount'       => (float) $row->discount,
                'tax'            => (float) $row->tax,
                'net'            => (float) $row->net,
                'returns_amount' => (float) $row->returns_amount,
                'net_value'      => round((float) $row->net_value, 2),
                'unit'           => $row->unit ?? null,
                'sold_mix'       => $row->sold_mix ?? null,
                'returned_mix'   => $row->returned_mix ?? null,
                'net_mix'        => $row->net_mix ?? null,
            ];
            unset($group);
        }

        $out = [];
        foreach ($heads as $rootId => $head) {
            // REPORT-CATEGORY-ORDER-1: sub-heads follow the menu, items stay biggest-first. The
            // owner asked for exactly that — the CATEGORY order is his to arrange, the items
            // beneath it are better read by size.
            $groups = collect($head['groups'])
                ->map(function (array $g) {
                    $g['items'] = collect($g['items'])->sortByDesc('net_value')->values()->all();

                    return $g;
                })->values()->all();
            usort($groups, fn ($x, $y) => $this->compareCategoryOrder((int) $x['id'], (int) $y['id']));

            // A sub-head earns its line only when it says something the head does not: either the
            // root has more than one child with sales, or the single group is a child by another
            // name. One group named exactly like its head is just the head printed twice.
            $head['nested'] = count($groups) > 1 || (int) ($groups[0]['id'] ?? 0) !== (int) $rootId;
            $head['groups'] = $groups;
            foreach ($money as $k) {
                $head[$k] = round((float) ($head[$k] ?? 0), 2);
            }
            $out[] = $head;
        }

        // REPORT-CATEGORY-ORDER-1: the menu's order, the same one CATEGORIES prints in, so the two
        // sections can be read side by side without hunting.
        usort($out, fn (array $a, array $b) => $this->compareCategoryOrder((int) $a['head_id'], (int) $b['head_id']));

        return $out;
    }

    /**
     * DEAL-CATEGORY-1 — the Deals section: one row per deal, under its own head.
     *
     * This is the shape the client's old software has always printed — DEALS, MIDNIGHT DEAL 1,
     * CLASSIC PLATTER, CHULLU KEBAB, POCKET FRIENDLY, each with its own subtotal — and the shape
     * the POS has grouped deals in since POS-COMBO-CATEGORY-1.
     *
     * Only combo lines. Their counterpart, `byItem($f, …, excludeCombos: true)`, takes them out of
     * Items, so the two sections together are the whole of the trading and neither double-counts.
     *
     * @return array<int, array{head:string, deal:string, sold_qty:float, returned_qty:float,
     *                          net_qty:float, gross:float, discount:float, net:float,
     *                          returns_amount:float, net_value:float}>
     */
    public function byDeal(array $f): array
    {
        $rows = $this->linesBase($f)
            ->whereNotNull('l.combo_id')
            ->groupBy('l.combo_id')
            ->selectRaw(
                'l.combo_id,
                 MAX(cb.name) as deal,
                 MAX(c.name) as head,
                 COUNT(DISTINCT o.id) as orders,
                 COALESCE(SUM(COALESCE(l.base_quantity, l.quantity)),0) as sold_qty,
                 COALESCE(SUM(l.quantity * l.unit_price),0) as gross,
                 COALESCE(SUM(l.discount_amount),0) as discount,
                 COALESCE(SUM(l.line_total),0) as net'
            )->get()->keyBy('combo_id');

        // A refunded deal credits the same row it sold on — the combo comes off the ORIGINAL line.
        $returns = $this->returnLinesBase($f)
            ->whereNotNull('ol.combo_id')
            ->groupBy('ol.combo_id')
            // UNITS-PACKS F4: counted like the sales above (base quantity), so a returned pack takes off what it sold.
            ->selectRaw('ol.combo_id, COALESCE(SUM(COALESCE(rl.base_quantity, rl.quantity)),0) as qty, COALESCE(SUM(rl.line_total),0) as amount')
            ->get()->keyBy('combo_id');

        $out = [];
        foreach ($rows->keys()->merge($returns->keys())->unique() as $comboId) {
            $row = $rows[$comboId] ?? null;
            $ret = $returns[$comboId] ?? null;
            $soldQty = (float) ($row->sold_qty ?? 0);
            $retQty  = (float) ($ret->qty ?? 0);
            $net     = (float) ($row->net ?? 0);
            $retAmt  = (float) ($ret->amount ?? 0);

            $out[] = [
                'head'           => $row->head ?? 'Uncategorised',
                'deal'           => $row->deal ?? ('#' . $comboId),
                'orders'         => (int) ($row->orders ?? 0),
                'sold_qty'       => $soldQty,
                'returned_qty'   => $retQty,
                'net_qty'        => $soldQty - $retQty,
                'gross'          => (float) ($row->gross ?? 0),
                'discount'       => (float) ($row->discount ?? 0),
                'net'            => $net,
                'returns_amount' => $retAmt,
                'net_value'      => round($net - $retAmt, 2),
            ];
        }

        // Grouped by head, biggest head first, biggest deal first inside it — the way the old
        // software's item report reads.
        $byHead = collect($out)->groupBy('head')
            ->sortByDesc(fn ($g) => $g->sum('net_value'))
            ->map(fn ($g) => $g->sortByDesc('net_value')->values());

        return $byHead->flatten(1)->all();
    }

    // ── S3. Partner share (PARTNER-SHARE-1) ──────────────────────────────────────────────────────
    /**
     * What each partner kitchen and the restaurant owe each other for the period: every rated dish
     * sold × its fixed amount, split by who pays, and the net.
     *
     * The rules, each one a decision the owner signed off:
     * - The SAME sales the rest of this report counts (`linesBase`: population, business day, every
     *   filter). A deal's parts never count — `linesBase` drops `component` lines — so a deal is
     *   rated as the deal (its `combo_header`), and a dish inside it is not rated a second time.
     * - A product is rated only when sold on its own line (`combo_id` NULL).
     * - The rate is the version in force on the ORDER's business day, so changing a rate never
     *   rewrites a period already reported.
     * - Returns come off at the rate of the sale they reverse (the ORIGINAL order's day).
     * - A line sold for nothing (line_total 0 — given away) owes nothing, unless the partner is
     *   set to count free lines.
     *
     * @return array<int, array<string, mixed>> one block per active partner that has rates
     */
    public function partnerShare(array $f): array
    {
        $conn = DB::connection('tenant');
        $partners = $conn->table('share_partners')->where('is_active', true)->orderBy('name')->get()->keyBy('id');
        if ($partners->isEmpty()) {
            return [];
        }
        $rates = $conn->table('share_rates')->whereIn('share_partner_id', $partners->keys())
            ->orderBy('effective_from')->orderBy('id')->get();
        if ($rates->isEmpty()) {
            return [];
        }

        // [partner][item_key] => versions, oldest first
        $versions = [];
        foreach ($rates as $r) {
            $versions[(int) $r->share_partner_id][$r->item_key][] = $r;
        }
        $productIds = $rates->whereNotNull('product_id')->pluck('product_id')->unique()->values()->all();
        $comboIds = $rates->whereNotNull('combo_id')->pluck('combo_id')->unique()->values()->all();

        $rated = function ($q, string $line) use ($productIds, $comboIds) {
            $q->where(function ($w) use ($line, $productIds, $comboIds) {
                $w->whereIn("$line.combo_id", $comboIds ?: [-1])
                    ->orWhere(fn ($p) => $p->whereNull("$line.combo_id")->whereIn("$line.product_id", $productIds ?: [-1]));
            });
        };
        $keyExpr = fn (string $line) => "CASE WHEN $line.combo_id IS NOT NULL THEN CONCAT('c:', $line.combo_id) ELSE CONCAT('p:', $line.product_id) END";
        $day = $this->businessDayExpr('o.');

        $sold = $this->linesBase($f)
            ->tap(fn ($q) => $rated($q, 'l'))
            ->selectRaw($keyExpr('l') . " as item_key, $day as day, (l.line_total > 0) as paid_line, COALESCE(SUM(l.quantity),0) as qty")
            ->groupByRaw($keyExpr('l') . ", $day, (l.line_total > 0)")
            ->get();

        $returned = $this->returnLinesBase($f)
            ->where(fn ($w) => $w->where('ol.line_kind', '!=', 'component')->orWhereNull('ol.line_kind'))
            ->tap(fn ($q) => $rated($q, 'ol'))
            ->selectRaw($keyExpr('ol') . " as item_key, $day as day, (ol.line_total > 0) as paid_line, COALESCE(SUM(rl.quantity),0) as qty")
            ->groupByRaw($keyExpr('ol') . ", $day, (ol.line_total > 0)")
            ->get();

        $versionOn = function (array $list, string $day) {
            $hit = null;
            foreach ($list as $v) {
                if ((string) $v->effective_from <= $day) {
                    $hit = $v;
                }
            }

            return $hit;
        };

        // [partner][rate id] => ['rate' => version, 'sold' => qty, 'returned' => qty]
        $buckets = [];
        foreach (['sold' => $sold, 'returned' => $returned] as $kind => $rows) {
            foreach ($rows as $row) {
                foreach ($partners as $pid => $partner) {
                    $list = $versions[$pid][$row->item_key] ?? null;
                    if (! $list || (! $row->paid_line && ! $partner->count_free_lines)) {
                        continue;
                    }
                    $v = $versionOn($list, (string) $row->day);
                    if (! $v || (float) $v->amount <= 0) {
                        continue;   // sold before any rate, or the item was taken off the list
                    }
                    $buckets[$pid][$v->id] ??= ['rate' => $v, 'sold' => 0.0, 'returned' => 0.0];
                    $buckets[$pid][$v->id][$kind] += (float) $row->qty;
                }
            }
        }

        $productNames = $productIds ? $conn->table('products')->whereIn('id', $productIds)->pluck('name', 'id') : collect();
        $comboNames = $comboIds ? $conn->table('combos')->whereIn('id', $comboIds)->pluck('name', 'id') : collect();

        $out = [];
        foreach ($partners as $pid => $partner) {
            if (! isset($versions[$pid])) {
                continue;
            }
            $sides = ['partner' => [], 'us' => []];
            foreach ($buckets[$pid] ?? [] as $b) {
                $v = $b['rate'];
                $netQty = $b['sold'] - $b['returned'];
                $sides[$v->payer][] = [
                    'item' => $v->combo_id ? ($comboNames[$v->combo_id] ?? 'Deal #' . $v->combo_id) : ($productNames[$v->product_id] ?? 'Product #' . $v->product_id),
                    'is_deal' => (bool) $v->combo_id,
                    'rate' => (float) $v->amount,
                    'effective_from' => (string) $v->effective_from,
                    'sold_qty' => $b['sold'],
                    'returned_qty' => $b['returned'],
                    'net_qty' => $netQty,
                    'amount' => round($netQty * (float) $v->amount, 2),
                ];
            }
            foreach ($sides as &$rows) {
                usort($rows, fn ($a, $b) => [$b['amount'], $a['item']] <=> [$a['amount'], $b['item']]);
            }
            unset($rows);

            $partnerPays = round(array_sum(array_column($sides['partner'], 'amount')), 2);
            $wePay = round(array_sum(array_column($sides['us'], 'amount')), 2);
            $out[] = [
                'partner_id' => (int) $pid,
                'partner' => $partner->name,
                'count_free_lines' => (bool) $partner->count_free_lines,
                'partner_pays' => $sides['partner'],
                'we_pay' => $sides['us'],
                'partner_pays_total' => $partnerPays,
                'we_pay_total' => $wePay,
                // Positive: we owe the partner. Negative: the partner owes us.
                'net' => round($wePay - $partnerPays, 2),
            ];
        }

        return $out;
    }

    // ── T. Waiter report (explicit Unassigned bucket) ────────────────────────────────────────────
    public function byWaiter(array $f): array
    {
        return $this->dimensionReport($f, 'o.restaurant_waiter_id', function ($id) {
            static $names = null;
            $names ??= DB::connection('tenant')->table('restaurant_waiters')->pluck('name', 'id');

            return $id === null ? 'Unassigned' : ($names[$id] ?? ('#' . $id));
        });
    }

    // ── U. Order-type report ─────────────────────────────────────────────────────────────────────
    public function byOrderType(array $f): array
    {
        $labels = \App\Models\Tenant\User::ORDER_TYPES;

        return $this->dimensionReport($f, 'o.order_type', fn ($v) => $labels[$v] ?? (string) $v);
    }

    /** Shared per-sale-dimension aggregation (waiter / order type). */
    private function dimensionReport(array $f, string $column, callable $label): array
    {
        if ($this->isLineNarrowed($f)) {
            return $this->dimensionReportNarrowed($f, $column, $label);
        }

        $rows = $this->salesBase($f)->groupBy(DB::raw($column))
            ->selectRaw(
                "$column as dim,
                 COUNT(*) as orders,
                 COALESCE(SUM(o.subtotal),0) as gross,
                 COALESCE(SUM(o.discount_amount),0) as discount,
                 COALESCE(SUM(o.tax_amount),0) as tax,
                 COALESCE(SUM(o.service_charge_amount),0) as service_charge,
                 COALESCE(SUM(o.delivery_charge_amount),0) as delivery_charge,
                 COALESCE(SUM(o.grand_total),0) as grand_total"
            )->get();
        $qty = $this->linesBase($f)->groupBy(DB::raw($column))
            ->selectRaw("$column as dim, COALESCE(SUM(COALESCE(l.base_quantity, l.quantity)),0) as sold_qty")
            ->get()->keyBy('dim');
        $returns = $this->returnsBase($f)->groupBy(DB::raw($column))
            ->selectRaw("$column as dim, COALESCE(SUM(r.grand_total),0) as amount")
            ->get()->keyBy('dim');
        $returnQty = $this->returnLinesBase($f)->groupBy(DB::raw($column))
            ->selectRaw("$column as dim, COALESCE(SUM(COALESCE(rl.base_quantity, rl.quantity)),0) as quantity")
            ->get()->keyBy('dim');

        $rows = $rows->keyBy('dim');
        $mixes = $this->qtyMixes($f, $column);   // UNITS-PACKS F4

        return $rows->keys()->merge($returns->keys())->unique()->map(function ($dim) use ($rows, $qty, $label, $returns, $returnQty, $mixes) {
            $mx = $mixes[self::mixKey($dim)] ?? [];
            $r = $rows->get($dim);
            $q = $qty->get($dim);
            $ret = (float) ($returns->get($dim)?->amount ?? 0);
            $returnedQty = (float) ($returnQty->get($dim)?->quantity ?? 0);

            return [
                'label' => $label($dim === '' ? null : $dim),
                'orders' => (int) ($r->orders ?? 0),
                'sold_qty' => (float) ($q->sold_qty ?? 0),
                'returned_qty' => $returnedQty,
                'net_qty' => (float) ($q->sold_qty ?? 0) - $returnedQty,
                'sold_mix' => $mx['sold'] ?? [], 'returned_mix' => $mx['returned'] ?? [],
                'net_mix' => QtyMix::merge($mx['sold'] ?? [], $mx['returned'] ?? [], -1.0),
                'gross' => (float) ($r->gross ?? 0),
                'discount' => (float) ($r->discount ?? 0),
                'tax' => (float) ($r->tax ?? 0),
                'service_charge' => (float) ($r->service_charge ?? 0),
                'delivery_charge' => (float) ($r->delivery_charge ?? 0),
                'grand_total' => (float) ($r->grand_total ?? 0),
                'returns_amount' => $ret,
                'net_sales' => (float) ($r->grand_total ?? 0) - $ret,
            ];
        })->values()->all();
    }

    /**
     * Per-dimension totals restricted to the filtered lines. Billed and Net are the
     * category's own line value for that waiter / order type — not the whole bill — and
     * no order-level charge is attributed, so the dimensions sum to the same narrowed
     * NET SALES the categories and items sections show.
     */
    private function dimensionReportNarrowed(array $f, string $column, callable $label): array
    {
        $rows = $this->linesBase($f)->groupBy(DB::raw($column))
            ->selectRaw(
                "$column as dim,
                 COUNT(DISTINCT o.id) as orders,
                 COALESCE(SUM(COALESCE(l.base_quantity, l.quantity)),0) as sold_qty,
                 COALESCE(SUM(l.line_total),0) as net"
            )->get()->keyBy('dim');
        $returns = $this->returnLinesBase($f)->groupBy(DB::raw($column))
            ->selectRaw("$column as dim, COALESCE(SUM(rl.line_total),0) as amount, COALESCE(SUM(COALESCE(rl.base_quantity, rl.quantity)),0) as quantity")
            ->get()->keyBy('dim');

        $mixes = $this->qtyMixes($f, $column);   // UNITS-PACKS F4

        return $rows->keys()->merge($returns->keys())->unique()->map(function ($dim) use ($rows, $returns, $label, $mixes) {
            $mx = $mixes[self::mixKey($dim)] ?? [];
            $r = $rows->get($dim);
            $ret = $returns->get($dim);
            $net = (float) ($r->net ?? 0);
            $retAmt = (float) ($ret->amount ?? 0);
            $sold = (float) ($r->sold_qty ?? 0);
            $retQty = (float) ($ret->quantity ?? 0);

            return [
                'label' => $label($dim === '' || $dim === null ? null : $dim),
                'orders' => (int) ($r->orders ?? 0),
                'sold_qty' => $sold,
                'returned_qty' => $retQty,
                'net_qty' => $sold - $retQty,
                'sold_mix' => $mx['sold'] ?? [], 'returned_mix' => $mx['returned'] ?? [],
                'net_mix' => QtyMix::merge($mx['sold'] ?? [], $mx['returned'] ?? [], -1.0),
                'gross' => $net,
                'discount' => 0.0,
                'tax' => 0.0,
                'service_charge' => 0.0,
                'delivery_charge' => 0.0,
                'grand_total' => $net,
                'returns_amount' => $retAmt,
                'net_sales' => $net - $retAmt,
            ];
        })->values()->all();
    }

    // ── V. Order-type COMBINATION reports (Khatri request 2026-08-10): for every order type,
    //      its categories (root rollup), items and waiters — qty + line-net columns. ─────────────
    public function orderTypeCombos(array $f): array
    {
        $labels = \App\Models\Tenant\User::ORDER_TYPES;
        $ot = fn ($v) => $labels[$v] ?? (string) $v;

        $types = $this->salesBase($f)->distinct()->pluck('o.order_type')
            ->merge($this->returnsBase($f)->distinct()->pluck('o.order_type'))
            ->filter()->unique()->values();
        $categories = [];
        $items = [];
        $waiters = [];
        $totals = [];
        foreach ($types as $type) {
            $typed = array_merge($f, ['order_type' => $type, 'allowed_order_types' => []]);
            $typeLabel = $ot($type);
            $categories[$typeLabel] = collect($this->byCategory($typed))->map(fn ($r) => [
                'label' => $r['name'],
                'orders' => $r['orders'],
                'sold_qty' => $r['sold_qty'],
                'returned_qty' => $r['returned_qty'],
                'net_qty' => $r['net_qty'],
                'net' => $r['net'],
                'returns_amount' => $r['returns_amount'],
                'sold_mix' => $r['sold_mix'] ?? null, 'returned_mix' => $r['returned_mix'] ?? null, 'net_mix' => $r['net_mix'] ?? null,
                'net_value' => $r['net_value'],
            ])->values()->all();
            $items[$typeLabel] = collect($this->byItem($typed))->map(fn ($r) => [
                'label' => $r->item . ($r->variant ? ' (' . $r->variant . ')' : ''),
                'sold_qty' => $r->sold_qty,
                'returned_qty' => $r->returned_qty,
                'net_qty' => $r->net_qty,
                'net' => $r->net,
                'returns_amount' => $r->returns_amount,
                'sold_mix' => $r->sold_mix ?? null, 'returned_mix' => $r->returned_mix ?? null, 'net_mix' => $r->net_mix ?? null,
                'net_value' => $r->net_value,
            ])->values()->all();
            $waiters[$typeLabel] = $this->byWaiter($typed);

            // Per-type reconciliation bridge: the category/item nets are MERCHANDISE only, but this
            // order type's cash/NET SALES includes the order-level charges (delivery etc.). Carry the
            // net charge so the print can close "merchandise + charges = NET SALES", like the global
            // sections already do — otherwise a delivery total looks like it contradicts the cash.
            $merchNet = (float) collect($categories[$typeLabel])->sum('net_value');
            $grandTotal = (float) $this->salesBase($typed)->sum('o.grand_total');
            $netSales = round($grandTotal - (float) $this->returnsBase($typed)->sum('r.grand_total'), 2);
            $totals[$typeLabel] = [
                'merch_net' => round($merchNet, 2),
                'net_sales' => $netSales,
                'net_charges' => round($netSales - $merchNet, 2),
            ];
        }

        return ['categories' => $categories, 'items' => $items, 'waiters' => $waiters, 'totals' => $totals];
    }

    // ── W. Cancellations: items voided / decreased AFTER the KOT went to the kitchen. Anchored on
    //      the order's BUSINESS day (calendar cancelled_at only as a fallback for un-backfilled
    //      rows) — so a void punched after midnight reports on the day its order belongs to. ──
    public function cancellations(array $f): array
    {
        $labels = \App\Models\Tenant\User::ORDER_TYPES;
        $rows = DB::connection('tenant')->table('sales_order_line_cancellations as x')
            ->join('sales_orders as o', 'o.id', '=', 'x.sales_order_id')
            ->leftJoin('void_reasons as vr', 'vr.id', '=', 'x.void_reason_id')
            ->whereRaw('COALESCE(x.business_date, DATE(x.cancelled_at)) >= ?', [$f['date_from']])
            ->whereRaw('COALESCE(x.business_date, DATE(x.cancelled_at)) <= ?', [$f['date_to']])
            ->when($f['branch_ids'], fn ($q) => $q->whereIn('o.branch_id', $f['branch_ids']))
            ->when($f['terminal_id'], fn ($q) => $q->where('o.terminal_id', $f['terminal_id']))
            ->when(! $f['terminal_id'] && $f['allowed_terminal_ids'], fn ($q) => $q->whereIn('o.terminal_id', $f['allowed_terminal_ids']))
            ->when($f['waiter_id'], fn ($q) => $q->where('o.restaurant_waiter_id', $f['waiter_id']))
            ->when($f['order_type'], fn ($q) => $q->where('o.order_type', $f['order_type']))
            ->when(! $f['order_type'] && $f['allowed_order_types'], fn ($q) => $q->whereIn('o.order_type', $f['allowed_order_types']))
            ->groupBy('x.product_name', 'o.order_type', 'vr.name')
            ->selectRaw(
                'x.product_name, o.order_type, vr.name as reason,
                 COUNT(*) as events, COALESCE(SUM(x.quantity),0) as qty'
            )
            ->orderByDesc(DB::raw('SUM(x.quantity)'))
            ->get()
            ->map(fn ($r) => [
                'item' => (string) $r->product_name,
                'order_type' => $labels[$r->order_type] ?? (string) $r->order_type,
                'reason' => $r->reason ?: '—',
                'events' => (int) $r->events,
                'qty' => (float) $r->qty,
            ])->values()->all();

        return [
            'rows' => $rows,
            'total_events' => array_sum(array_column($rows, 'events')),
            'total_qty' => array_sum(array_column($rows, 'qty')),
        ];
    }

    /**
     * UNITS-PACKS F4 (mixed units): sold and returned quantities split by kind of unit (QtyMix), per $group — a SQL
     * expression both line bases understand (the category one, a waiter or order-type column) — or one total when
     * $group is null.
     *
     * @return array<int|string, array{sold?: array<string, float>, returned?: array<string, float>}>
     */
    private function qtyMixes(array $f, ?string $group = null): array
    {
        $out = [];
        foreach ([['sold', $this->linesBase($f), 'l'], ['returned', $this->returnLinesBase($f), 'rl']] as [$side, $q, $a]) {
            $q = $group === null ? $q->groupBy('p.unit_id') : $q->groupBy(DB::raw($group), 'p.unit_id');
            $rows = $q->selectRaw(($group ?? '0') . " as dim, p.unit_id as unit_id, COALESCE(SUM(COALESCE($a.base_quantity, $a.quantity)),0) as q")->get();
            foreach ($rows as $r) {
                $dim = self::mixKey($r->dim);
                $out[$dim][$side] = QtyMix::add($out[$dim][$side] ?? [], $r->unit_id !== null ? (int) $r->unit_id : null, (float) $r->q);
            }
        }

        return $out;
    }

    /** The key a dimension value has in qtyMixes(): null and "" are 0, a number is an int, a word (order type) itself. */
    private static function mixKey(mixed $dim): int|string
    {
        return $dim === null || $dim === '' ? 0 : (is_numeric($dim) ? (int) $dim : (string) $dim);
    }

    /** @return array{sold_mix: array, returned_mix: array, net_mix: array} */
    private function overviewMix(array $f): array
    {
        $m = $this->qtyMixes($f)[0] ?? [];

        return ['sold_mix' => $m['sold'] ?? [], 'returned_mix' => $m['returned'] ?? [],
            'net_mix' => QtyMix::merge($m['sold'] ?? [], $m['returned'] ?? [], -1.0)];
    }

    /** Period return quantity and value grouped by an arbitrary return-line-side column. */
    private function returnStatsBy(array $f, string $column): array
    {
        $rows = $this->returnLinesBase($f)
            ->groupBy(DB::raw($column))
            // rl.line_total is the FINAL refunded line value (subtotal − discount + tax) since
            // 2026_08_11_000004 normalised the column and backfilled history. Adding tax again
            // here — as this did while line_total was ex-tax — double-counts it.
            ->selectRaw("$column as dim, COALESCE(SUM(COALESCE(rl.base_quantity, rl.quantity)),0) as quantity, COALESCE(SUM(rl.line_total),0) as amount")
            ->get();
        $map = [];
        foreach ($rows as $r) {
            $map[$r->dim !== null ? (int) $r->dim : 0] = [
                'quantity' => (float) $r->quantity,
                'amount' => (float) $r->amount,
            ];
        }

        return $map;
    }

    // ── V. Detailed report (paginated; exports use the FULL filtered set via cursor) ─────────────
    public function detailedQuery(array $f)
    {
        // REPORT-DEAL-COMPONENTS-1: components stay HERE. This is the line-by-line record of what
        // was rung up, so a deal's parts belong in it — they are only kept out of the summary
        // sections, where counting them as sales made the quantities lie.
        return $this->linesBase($f, includeComponents: true)
            ->leftJoin('product_variants as v', 'v.id', '=', 'l.product_variant_id')
            ->leftJoin('restaurant_waiters as w', 'w.id', '=', 'o.restaurant_waiter_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.created_by_user_id')
            ->leftJoin('terminals as t', 't.id', '=', 'o.terminal_id')
            // sale_date is stored UTC. Carry the timezone the sale was RECORDED in — frozen shift
            // first, then its branch — so the printed row matches the receipt instead of showing
            // UTC. Rows can span branches, so this has to travel per row, not once per report.
            ->leftJoin('shifts as rsh', 'rsh.id', '=', 'o.shift_id')
            ->leftJoin('branches as rbr', 'rbr.id', '=', 'o.branch_id')
            ->orderBy('o.sale_date')->orderBy('o.id')->orderBy('l.id')
            ->select([
                DB::raw('COALESCE(rsh.timezone_name, rbr.timezone) as sale_timezone'),
                'o.sale_date', 'o.sale_no', 'o.order_type', 'o.status as sale_status',
                't.name as terminal', 'o.shift_id', 'u.name as cashier', 'w.name as waiter',
                'l.product_name as item', 'v.name as variant', 'c.name as category',
                'l.quantity', 'l.returned_quantity', 'l.unit_price',
                DB::raw('(l.quantity * l.unit_price) as gross'),
                'l.discount_amount', 'l.tax_amount', 'l.line_total',
                'o.delivery_charge_amount', 'o.grand_total',
            ]);
    }

    // ── W. Cash & Bank Movement — a DIFFERENT question, never merged with sales ─────────────────
    public function cashBank(array $f): array
    {
        // shift side (business-date scoped like sales).
        $shifts = DB::connection('tenant')->table('shifts as s')
            ->whereRaw("COALESCE(s.business_date, DATE(s.opened_at)) >= ?", [$f['date_from']])
            ->whereRaw("COALESCE(s.business_date, DATE(s.opened_at)) <= ?", [$f['date_to']])
            ->when($f['branch_ids'], fn ($q) => $q->whereIn('s.branch_id', $f['branch_ids']))
            ->when($f['terminal_id'], fn ($q) => $q->where('s.terminal_id', $f['terminal_id']))
            ->when(! $f['terminal_id'] && $f['allowed_terminal_ids'], fn ($q) => $q->whereIn('s.terminal_id', $f['allowed_terminal_ids']))
            ->selectRaw(
                'COUNT(*) as shifts,
                 COALESCE(SUM(s.opening_cash),0) as opening_cash,
                 COALESCE(SUM(s.expected_cash),0) as expected_cash,
                 COALESCE(SUM(s.counted_cash),0) as counted_cash,
                 COALESCE(SUM(s.cash_variance),0) as cash_variance,
                 COALESCE(SUM(s.total_refunds),0) as refunds'
            )->first();

        // real payment-method money (sale_payments — includes wallet/other the shift buckets miss).
        $methodMoney = DB::connection('tenant')->table('sale_payments as sp')
            ->joinSub($this->salesBase($f)->select('o.*'), 'o', 'o.id', '=', 'sp.sales_order_id')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->groupBy('pm.method_type')
            ->selectRaw('pm.method_type, COALESCE(SUM(sp.amount),0) as amount')
            ->pluck('amount', 'method_type')->map(fn ($v) => (float) $v)->all();

        // cash/bank ledger movements (transaction_date basis — NOTE: sale-dated, can differ from
        // business_date across midnight; surfaced in the UI footnote).
        $movements = DB::connection('tenant')->table('cash_bank_account_transactions as t')
            ->join('cash_bank_accounts as a', 'a.id', '=', 't.cash_bank_account_id')
            ->whereBetween('t.transaction_date', [$f['date_from'], $f['date_to']])
            ->when($f['branch_ids'], fn ($q) => $q->where(fn ($w) => $w->whereNull('a.branch_id')->orWhereIn('a.branch_id', $f['branch_ids'])))
            ->groupBy('t.transaction_type', 't.direction', 'a.account_type')
            ->selectRaw('t.transaction_type, t.direction, a.account_type, COALESCE(SUM(t.amount),0) as amount, COUNT(*) as rows_count')
            ->get()
            ->map(fn ($r) => [
                'type' => $r->transaction_type,
                'label' => self::MOVEMENT_LABELS[$r->transaction_type] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', $r->transaction_type)),
                'direction' => $r->direction,
                'account_type' => $r->account_type,
                'amount' => (float) $r->amount,
                'rows' => (int) $r->rows_count,
            ])->values()->all();

        $cashIn = collect($movements)->where('account_type', 'cash')->where('direction', 'in')->sum('amount');
        $cashOut = collect($movements)->where('account_type', 'cash')->where('direction', 'out')->sum('amount');
        $bankIn = collect($movements)->whereIn('account_type', ['bank', 'card', 'wallet'])->where('direction', 'in')->sum('amount');
        $bankOut = collect($movements)->whereIn('account_type', ['bank', 'card', 'wallet'])->where('direction', 'out')->sum('amount');

        return [
            'shifts' => (array) $shifts,
            'method_money' => $methodMoney,
            'movements' => $movements,
            'cash_in' => (float) $cashIn, 'cash_out' => (float) $cashOut,
            'net_cash_movement' => (float) $cashIn - (float) $cashOut,
            'bank_in' => (float) $bankIn, 'bank_out' => (float) $bankOut,
            'net_bank_movement' => (float) $bankIn - (float) $bankOut,
            // reconciliation view: opening + cash sales + other in − outflows = expected.
            'expected_cash_formula' => (float) $shifts->opening_cash + ($methodMoney['cash'] ?? 0) - (float) $shifts->refunds,
        ];
    }

    /** Business labels for movement types (incl. the dept-handover payout journey + stale-const types). */
    public const MOVEMENT_LABELS = [
        'sales_payment' => 'Sales receipts',
        'sales_return_refund' => 'Sales return refunds',
        'sales_payment_void_reversal' => 'Sales receipt reversals',
        'sales_return_void_reversal' => 'Return refund reversals',
        'opening_balance' => 'Opening balances',
        'opening_balance_void_reversal' => 'Opening balance reversals',
        'manual_adjustment' => 'Manual adjustments',
        'expense_payment' => 'Expenses paid',
        'expense_void_reversal' => 'Expense reversals',
        'supplier_payment' => 'Paid to suppliers',
        'supplier_payment_void_reversal' => 'Supplier payment reversals',
        'customer_payment' => 'Received from customers',
        'customer_payment_void_reversal' => 'Customer payment reversals',
        'manual_journal' => 'Manual journal movements',
        'manual_journal_reversal' => 'Manual journal reversals',
        'dept_handover_payout' => 'Paid to department owners (handover payout)',
        'dept_handover_payout_reversal' => 'Department payout reversals',
    ];
}
