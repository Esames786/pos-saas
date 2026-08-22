<?php

namespace App\Services\Reports;

use App\Services\Concerns\ResolvesBranchIds;
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
        ];
    }

    /** Base sales_orders query with EVERY shared filter applied. */
    private function salesBase(array $f)
    {
        return DB::connection('tenant')->table('sales_orders as o')
            ->whereIn('o.status', self::POPULATION)
            ->whereRaw($this->businessDayExpr() . ' >= ?', [$f['date_from']])
            ->whereRaw($this->businessDayExpr() . ' <= ?', [$f['date_to']])
            ->when($f['branch_ids'], fn ($q) => $q->whereIn('o.branch_id', $f['branch_ids']))
            ->when($f['terminal_id'], fn ($q) => $q->where('o.terminal_id', $f['terminal_id']))
            ->when(! $f['terminal_id'] && $f['allowed_terminal_ids'], fn ($q) => $q->whereIn('o.terminal_id', $f['allowed_terminal_ids']))
            ->when($f['shift_id'], fn ($q) => $q->where('o.shift_id', $f['shift_id']))
            ->when($f['cashier_id'], fn ($q) => $q->where('o.created_by_user_id', $f['cashier_id']))
            ->when($f['waiter_id'], fn ($q) => $q->where('o.restaurant_waiter_id', $f['waiter_id']))
            ->when($f['order_type'], fn ($q) => $q->where('o.order_type', $f['order_type']))
            ->when(! $f['order_type'] && $f['allowed_order_types'], fn ($q) => $q->whereIn('o.order_type', $f['allowed_order_types']))
            ->when($f['payment_method_id'], fn ($q) => $q->whereExists(function ($s) use ($f) {
                $s->selectRaw('1')->from('sale_payments as sp')
                    ->whereColumn('sp.sales_order_id', 'o.id')
                    ->where('sp.payment_method_id', $f['payment_method_id']);
            }))
            ->when($f['category_id'], fn ($q) => $q->whereExists(function ($s) use ($f) {
                $s->selectRaw('1')->from('sales_order_lines as fl')
                    ->join('products as fp', 'fp.id', '=', 'fl.product_id')
                    ->whereColumn('fl.sales_order_id', 'o.id')
                    ->whereIn('fp.category_id', $this->categoryWithDescendants((int) $f['category_id']));
            }))
            ->when($f['product_id'], fn ($q) => $q->whereExists(function ($s) use ($f) {
                $s->selectRaw('1')->from('sales_order_lines as fl')
                    ->whereColumn('fl.sales_order_id', 'o.id')->where('fl.product_id', $f['product_id']);
            }));
    }

    /** Line-level base (joins products/categories) constrained to the same sale population. */
    private function linesBase(array $f)
    {
        return DB::connection('tenant')->table('sales_order_lines as l')
            ->joinSub($this->salesBase($f)->select('o.*'), 'o', 'o.id', '=', 'l.sales_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->when($f['category_id'], fn ($q) => $q->whereIn('p.category_id', $this->categoryWithDescendants((int) $f['category_id'])))
            ->when($f['product_id'], fn ($q) => $q->where('l.product_id', $f['product_id']));
    }

    /** Period returns (allocated by BUSINESS day — the order's day it reverses, calendar
     *  return_date only as a fallback for un-backfilled rows) constrained to the same branch scope. */
    private function returnsBase(array $f)
    {
        return DB::connection('tenant')->table('sales_returns as r')
            ->join('sales_orders as o', 'o.id', '=', 'r.sales_order_id')
            ->where('r.status', 'posted')
            ->whereRaw('COALESCE(r.business_date, DATE(r.return_date)) >= ?', [$f['date_from']])
            ->whereRaw('COALESCE(r.business_date, DATE(r.return_date)) <= ?', [$f['date_to']])
            ->when($f['branch_ids'], fn ($q) => $q->whereIn('r.branch_id', $f['branch_ids']))
            ->when($f['order_type'], fn ($q) => $q->where('o.order_type', $f['order_type']))
            ->when(! $f['order_type'] && $f['allowed_order_types'], fn ($q) => $q->whereIn('o.order_type', $f['allowed_order_types']))
            ->when($f['waiter_id'], fn ($q) => $q->where('o.restaurant_waiter_id', $f['waiter_id']))
            ->when($f['terminal_id'], fn ($q) => $q->where('o.terminal_id', $f['terminal_id']))
            ->when(! $f['terminal_id'] && $f['allowed_terminal_ids'], fn ($q) => $q->whereIn('o.terminal_id', $f['allowed_terminal_ids']))
            ->when($f['cashier_id'], fn ($q) => $q->where('o.created_by_user_id', $f['cashier_id']))
            ->when($f['shift_id'], fn ($q) => $q->where('o.shift_id', $f['shift_id']));
    }

    /** Period return lines with the same sale-side filters as returnsBase(). */
    private function returnLinesBase(array $f)
    {
        return $this->returnsBase($f)
            ->join('sales_return_lines as rl', 'rl.sales_return_id', '=', 'r.id')
            ->join('sales_order_lines as ol', 'ol.id', '=', 'rl.sales_order_line_id')
            ->leftJoin('products as p', 'p.id', '=', 'rl.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->when($f['category_id'], fn ($q) => $q->whereIn('p.category_id', $this->categoryWithDescendants((int) $f['category_id'])))
            ->when($f['product_id'], fn ($q) => $q->where('rl.product_id', $f['product_id']));
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

    // ── Q. Overview KPIs ─────────────────────────────────────────────────────────────────────────
    public function overview(array $f): array
    {
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
        $soldQty = (float) $this->linesBase($f)->sum('l.quantity');
        $returnedQty = (float) $this->returnLinesBase($f)->sum('rl.quantity');
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

    // ── R. Category → child → item rollup ───────────────────────────────────────────────────────
    public function byCategory(array $f): array
    {
        $rows = $this->linesBase($f)
            ->groupBy('p.category_id')
            ->selectRaw(
                'p.category_id,
                 MAX(c.name) as category_name, MAX(c.parent_id) as parent_id,
                 COUNT(DISTINCT o.id) as orders,
                 COALESCE(SUM(l.quantity),0) as sold_qty,
                 COALESCE(SUM(l.quantity * l.unit_price),0) as gross,
                 COALESCE(SUM(l.discount_amount),0) as discount,
                 COALESCE(SUM(l.tax_amount),0) as tax,
                 COALESCE(SUM(l.line_total),0) as net'
            )->get();
        $returnByCategory = $this->returnStatsBy($f, 'p.category_id');
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
        foreach ($tree as &$root) {
            foreach (['orders', 'sold_qty', 'returned_qty', 'gross', 'discount', 'tax', 'net', 'returns_amount'] as $key) {
                $root[$key] ??= 0;
            }
            $root['net_qty'] = $root['sold_qty'] - $root['returned_qty'];
            $root['net_value'] = $root['net'] - $root['returns_amount'];
            $root['children'] = array_values($root['children'] ?? []);
            foreach ($root['children'] as &$c) {
                foreach (['orders', 'sold_qty', 'returned_qty', 'gross', 'discount', 'tax', 'net', 'returns_amount'] as $key) {
                    $c[$key] ??= 0;
                }
                $c['net_qty'] = $c['sold_qty'] - $c['returned_qty'];
                $c['net_value'] = $c['net'] - $c['returns_amount'];
            }
        }

        return array_values($tree);
    }

    // ── S. Item report ───────────────────────────────────────────────────────────────────────────
    public function byItem(array $f, string $sort = 'net'): array
    {
        $rows = $this->linesBase($f)
            ->leftJoin('product_variants as v', 'v.id', '=', 'l.product_variant_id')
            ->groupBy('l.product_id', 'l.product_variant_id')
            ->selectRaw(
                'l.product_id, l.product_variant_id,
                 MAX(l.product_name) as item, MAX(v.name) as variant, MAX(c.name) as category,
                 COALESCE(SUM(l.quantity),0) as sold_qty,
                 COALESCE(SUM(l.quantity * l.unit_price),0) as gross,
                 COALESCE(SUM(l.discount_amount),0) as discount,
                 COALESCE(SUM(l.tax_amount),0) as tax,
                 COALESCE(SUM(l.line_total),0) as net'
            )->get()->keyBy(fn ($r) => $r->product_id . ':' . ($r->product_variant_id ?? ''));
        $returnRows = $this->returnLinesBase($f)
            ->leftJoin('product_variants as v', 'v.id', '=', 'rl.product_variant_id')
            ->groupBy('rl.product_id', 'rl.product_variant_id')
            ->selectRaw(
                'rl.product_id, rl.product_variant_id,
                 MAX(ol.product_name) as item, MAX(v.name) as variant, MAX(c.name) as category,
                 COALESCE(SUM(rl.quantity),0) as returned_qty,
                 COALESCE(SUM(rl.line_total),0) as returns_amount'
            )->get()->keyBy(fn ($r) => $r->product_id . ':' . ($r->product_variant_id ?? ''));

        $rows = $rows->keys()->merge($returnRows->keys())->unique()->map(function ($key) use ($rows, $returnRows) {
            $sold = $rows->get($key);
            $returned = $returnRows->get($key);
            $row = $sold ?: $returned;
            $row->sold_qty = (float) ($sold->sold_qty ?? 0);
            $row->returned_qty = (float) ($returned->returned_qty ?? 0);
            $row->net_qty = $row->sold_qty - $row->returned_qty;
            $row->gross = (float) ($sold->gross ?? 0);
            $row->discount = (float) ($sold->discount ?? 0);
            $row->tax = (float) ($sold->tax ?? 0);
            $row->net = (float) ($sold->net ?? 0);
            $row->returns_amount = (float) ($returned->returns_amount ?? 0);
            $row->net_value = $row->net - $row->returns_amount;

            return $row;
        });

        return $rows->sortByDesc(fn ($r) => match ($sort) {
            'qty' => (float) $r->sold_qty,
            'returns' => (float) $r->returned_qty,
            'alpha' => null,
            default => (float) $r->net_value,
        })->when($sort === 'alpha', fn ($c) => $c->sortBy('item'))->values()->all();
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
            ->selectRaw("$column as dim, COALESCE(SUM(l.quantity),0) as sold_qty")
            ->get()->keyBy('dim');
        $returns = $this->returnsBase($f)->groupBy(DB::raw($column))
            ->selectRaw("$column as dim, COALESCE(SUM(r.grand_total),0) as amount")
            ->get()->keyBy('dim');
        $returnQty = $this->returnLinesBase($f)->groupBy(DB::raw($column))
            ->selectRaw("$column as dim, COALESCE(SUM(rl.quantity),0) as quantity")
            ->get()->keyBy('dim');

        $rows = $rows->keyBy('dim');

        return $rows->keys()->merge($returns->keys())->unique()->map(function ($dim) use ($rows, $qty, $label, $returns, $returnQty) {
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
                'net_value' => $r['net_value'],
            ])->values()->all();
            $items[$typeLabel] = collect($this->byItem($typed))->map(fn ($r) => [
                'label' => $r->item . ($r->variant ? ' (' . $r->variant . ')' : ''),
                'sold_qty' => $r->sold_qty,
                'returned_qty' => $r->returned_qty,
                'net_qty' => $r->net_qty,
                'net' => $r->net,
                'returns_amount' => $r->returns_amount,
                'net_value' => $r->net_value,
            ])->values()->all();
            $waiters[$typeLabel] = $this->byWaiter($typed);
        }

        return ['categories' => $categories, 'items' => $items, 'waiters' => $waiters];
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

    /** Period return quantity and value grouped by an arbitrary return-line-side column. */
    private function returnStatsBy(array $f, string $column): array
    {
        $rows = $this->returnLinesBase($f)
            ->groupBy(DB::raw($column))
            // rl.line_total is the FINAL refunded line value (subtotal − discount + tax) since
            // 2026_08_11_000004 normalised the column and backfilled history. Adding tax again
            // here — as this did while line_total was ex-tax — double-counts it.
            ->selectRaw("$column as dim, COALESCE(SUM(rl.quantity),0) as quantity, COALESCE(SUM(rl.line_total),0) as amount")
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
        return $this->linesBase($f)
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
