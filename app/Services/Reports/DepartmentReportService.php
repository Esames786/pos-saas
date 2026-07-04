<?php

namespace App\Services\Reports;

use App\Models\Tenant\Product;
use App\Models\Tenant\StockLedger;
use App\Services\Departments\DepartmentMappingService;
use Illuminate\Support\Facades\DB;

/**
 * DEPARTMENT-FOUNDATION-1 — department-wise sales + expected consumption,
 * computed from EXISTING data (sales_order_lines, stock_ledgers) through the
 * central DepartmentMappingService. Read-only: no stock, no GL, no posting.
 */
class DepartmentReportService
{
    /** Ledger movement types that represent stock leaving for operations. */
    public const CONSUMPTION_MOVEMENT_TYPES = [
        'sale',
        'recipe_consumption',
        'modifier_consumption',
        'wastage',
    ];

    /** @var array<int, DepartmentMappingService> resolver cache per branch */
    private array $resolvers = [];

    private function resolver(int $branchId): DepartmentMappingService
    {
        return $this->resolvers[$branchId] ??= DepartmentMappingService::forBranch($branchId);
    }

    /**
     * Department-wise sales built from paid sales_order_lines.
     *
     * @return array{rows: array, unassigned: array, warnings: array, totals: array}
     */
    public function sales(array $filters): array
    {
        $grouped = DB::connection('tenant')->table('sales_order_lines as l')
            ->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->where('o.status', 'paid')
            ->when(!empty($filters['date_from']),  fn ($q) => $q->whereDate('o.sale_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),    fn ($q) => $q->whereDate('o.sale_date', '<=', $filters['date_to']))
            ->when(!empty($filters['branch_id']),  fn ($q) => $q->where('o.branch_id', $filters['branch_id']))
            ->when(!empty($filters['order_type']), fn ($q) => $q->where('o.order_type', $filters['order_type']))
            ->groupBy('o.branch_id', 'l.product_id')
            ->select([
                'o.branch_id',
                'l.product_id',
                DB::raw('MAX(p.name) as product_name'),
                DB::raw('MAX(p.sku) as product_sku'),
                DB::raw('MAX(p.category_id) as category_id'),
                DB::raw('COUNT(DISTINCT o.id) as orders_count'),
                DB::raw('SUM(l.quantity) as qty_sold'),
                DB::raw('SUM(l.discount_amount) as discount_total'),
                DB::raw('SUM(l.line_total) as net_total'),
                DB::raw('SUM(l.cost_total) as cogs_total'),
            ])
            ->get();

        $rows       = [];
        $unassigned = [];
        $warnings   = [];

        foreach ($grouped as $g) {
            $resolver = $this->resolver((int) $g->branch_id);
            $dept     = $resolver->resolve((int) $g->product_id, $g->category_id !== null ? (int) $g->category_id : null);

            if ($dept && $resolver->isMultiMatch((int) $g->product_id, $g->category_id !== null ? (int) $g->category_id : null)) {
                $warnings[$g->product_id] = [
                    'product'    => $g->product_name,
                    'sku'        => $g->product_sku,
                    'department' => $dept->name,
                ];
            }

            if (! $dept) {
                $key = $g->branch_id . '-' . $g->product_id;
                $unassigned[$key] = [
                    'branch_id' => (int) $g->branch_id,
                    'product'   => $g->product_name ?? ('#' . $g->product_id),
                    'sku'       => $g->product_sku,
                    'qty'       => (float) $g->qty_sold,
                    'net'       => (float) $g->net_total,
                ];
            }

            if (!empty($filters['department_id'])) {
                if (! $dept || (int) $dept->id !== (int) $filters['department_id']) {
                    continue;
                }
            }

            $rowKey = $g->branch_id . '-' . ($dept?->id ?? 0);
            $rows[$rowKey] ??= [
                'department'      => $dept?->name ?? 'Unassigned',
                'department_id'   => $dept?->id,
                'branch_id'       => (int) $g->branch_id,
                'orders'          => 0,
                'qty'             => 0.0,
                'gross'           => 0.0,
                'discount'        => 0.0,
                'net'             => 0.0,
                'cogs'            => 0.0,
            ];

            // orders_count per product overlaps across products of one order —
            // treated as an activity indicator, summed per department.
            $rows[$rowKey]['orders']   += (int) $g->orders_count;
            $rows[$rowKey]['qty']      += (float) $g->qty_sold;
            $rows[$rowKey]['discount'] += (float) $g->discount_total;
            $rows[$rowKey]['net']      += (float) $g->net_total;
            $rows[$rowKey]['gross']    += (float) $g->net_total + (float) $g->discount_total;
            $rows[$rowKey]['cogs']     += (float) $g->cogs_total;
        }

        foreach ($rows as &$row) {
            $row['gross_profit'] = $row['net'] - $row['cogs'];
        }
        unset($row);

        usort($rows, fn ($a, $b) => [$a['branch_id'], $a['department'] === 'Unassigned', $a['department']]
            <=> [$b['branch_id'], $b['department'] === 'Unassigned', $b['department']]);

        $totals = [
            'qty'      => array_sum(array_column($rows, 'qty')),
            'gross'    => array_sum(array_column($rows, 'gross')),
            'discount' => array_sum(array_column($rows, 'discount')),
            'net'      => array_sum(array_column($rows, 'net')),
            'cogs'     => array_sum(array_column($rows, 'cogs')),
        ];
        $totals['gross_profit'] = $totals['net'] - $totals['cogs'];

        return [
            'rows'       => array_values($rows),
            'unassigned' => array_values($unassigned),
            'warnings'   => array_values($warnings),
            'totals'     => $totals,
        ];
    }

    /**
     * Department expected consumption from existing stock_ledgers out-movements.
     *
     * @return array{summary: array, top: array, lines: \Illuminate\Support\Collection, unassigned_count: int, line_limit: int}
     */
    public function consumption(array $filters): array
    {
        $movementTypes = !empty($filters['movement_type'])
            ? [$filters['movement_type']]
            : self::CONSUMPTION_MOVEMENT_TYPES;

        // ── Summary: grouped by branch + product + movement type ──────────
        $grouped = DB::connection('tenant')->table('stock_ledgers as sl')
            ->leftJoin('products as p', 'p.id', '=', 'sl.product_id')
            ->where('sl.direction', 'out')
            ->whereIn('sl.movement_type', $movementTypes)
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('sl.created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->whereDate('sl.created_at', '<=', $filters['date_to']))
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('sl.branch_id', $filters['branch_id']))
            ->groupBy('sl.branch_id', 'sl.product_id', 'sl.movement_type')
            ->select([
                'sl.branch_id',
                'sl.product_id',
                'sl.movement_type',
                DB::raw('MAX(p.name) as product_name'),
                DB::raw('MAX(p.sku) as product_sku'),
                DB::raw('MAX(p.category_id) as category_id'),
                DB::raw('SUM(sl.quantity) as qty'),
                DB::raw('SUM(sl.total_cost) as cost'),
            ])
            ->get();

        $summary         = [];
        $topProducts     = [];
        $unassignedCount = 0;

        foreach ($grouped as $g) {
            $resolver = $this->resolver((int) $g->branch_id);
            $dept     = $resolver->resolve((int) $g->product_id, $g->category_id !== null ? (int) $g->category_id : null);

            if (! $dept) {
                $unassignedCount++;
            }

            if (!empty($filters['department_id'])) {
                if (! $dept || (int) $dept->id !== (int) $filters['department_id']) {
                    continue;
                }
            }

            $deptKey = $g->branch_id . '-' . ($dept?->id ?? 0);
            $summary[$deptKey] ??= [
                'department'    => $dept?->name ?? 'Unassigned',
                'department_id' => $dept?->id,
                'branch_id'     => (int) $g->branch_id,
                'qty'           => 0.0,
                'cost'          => 0.0,
            ];
            $summary[$deptKey]['qty']  += (float) $g->qty;
            $summary[$deptKey]['cost'] += (float) $g->cost;

            $prodKey = $g->product_id;
            $topProducts[$prodKey] ??= [
                'product'    => $g->product_name ?? ('#' . $g->product_id),
                'sku'        => $g->product_sku,
                'department' => $dept?->name ?? 'Unassigned',
                'qty'        => 0.0,
                'cost'       => 0.0,
            ];
            $topProducts[$prodKey]['qty']  += (float) $g->qty;
            $topProducts[$prodKey]['cost'] += (float) $g->cost;
        }

        usort($summary, fn ($a, $b) => $b['cost'] <=> $a['cost']);
        usort($topProducts, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        // ── Detail lines (latest N raw movements, resolved per line) ──────
        $lineLimit = 200;

        $rawLines = StockLedger::query()
            ->with(['product:id,sku,name,category_id', 'branch:id,name'])
            ->where('direction', 'out')
            ->whereIn('movement_type', $movementTypes)
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->orderByDesc('id')
            ->limit($lineLimit * 3) // headroom so a department filter still fills the table
            ->get();

        $lines = $rawLines->map(function (StockLedger $ledger) {
            $resolver = $this->resolver((int) $ledger->branch_id);
            $dept     = $resolver->resolve((int) $ledger->product_id, $ledger->product?->category_id);
            $ledger->setAttribute('department_name', $dept?->name ?? 'Unassigned');
            $ledger->setAttribute('department_id', $dept?->id);

            return $ledger;
        });

        if (!empty($filters['department_id'])) {
            $lines = $lines->filter(fn ($l) => (int) ($l->department_id ?? 0) === (int) $filters['department_id']);
        }

        $lines = $lines->take($lineLimit)->values();

        return [
            'summary'          => array_values($summary),
            'top'              => array_slice(array_values($topProducts), 0, 10),
            'lines'            => $lines,
            'unassigned_count' => $unassignedCount,
            'line_limit'       => $lineLimit,
        ];
    }

    /**
     * DEPT-2 — Department Stock Available (custody balances).
     *
     * @return array{rows: \Illuminate\Support\Collection, dept_totals: array, product_totals: array, total_value: float}
     */
    public function stock(array $filters): array
    {
        $rows = \App\Models\Tenant\DepartmentStockBalance::query()
            ->with(['branch:id,name', 'department:id,name,code', 'product:id,sku,name,unit_id', 'product.unit:id,code', 'variant:id,name'])
            ->when(!empty($filters['branch_id']),     fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['department_id']), fn ($q) => $q->where('department_id', $filters['department_id']))
            ->when(!empty($filters['nonzero']),       fn ($q) => $q->where('quantity_on_hand', '!=', 0))
            ->orderBy('branch_id')->orderBy('department_id')
            ->get();

        $deptTotals = [];
        $prodTotals = [];
        $totalValue = 0.0;

        foreach ($rows as $row) {
            $value = (float) $row->quantity_on_hand * (float) $row->average_cost;
            $totalValue += $value;

            $dk = $row->branch_id . '-' . $row->department_id;
            $deptTotals[$dk] ??= ['branch' => $row->branch?->name, 'department' => $row->department?->name, 'qty' => 0.0, 'value' => 0.0];
            $deptTotals[$dk]['qty']   += (float) $row->quantity_on_hand;
            $deptTotals[$dk]['value'] += $value;

            $pk = $row->product_id;
            $prodTotals[$pk] ??= ['product' => $row->product?->name, 'sku' => $row->product?->sku, 'qty' => 0.0, 'value' => 0.0];
            $prodTotals[$pk]['qty']   += (float) $row->quantity_on_hand;
            $prodTotals[$pk]['value'] += $value;
        }

        usort($prodTotals, fn ($a, $b) => $b['value'] <=> $a['value']);

        return [
            'rows'           => $rows,
            'dept_totals'    => array_values($deptTotals),
            'product_totals' => array_slice(array_values($prodTotals), 0, 10),
            'total_value'    => $totalValue,
        ];
    }

    /**
     * DEPT-2 — Department Movement report (custody ledger, paginated).
     */
    public function movements(array $filters)
    {
        return \App\Models\Tenant\DepartmentStockLedger::query()
            ->with(['branch:id,name', 'department:id,name,code', 'product:id,sku,name', 'variant:id,name'])
            ->when(!empty($filters['date_from']),     fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),       fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->when(!empty($filters['branch_id']),     fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['department_id']), fn ($q) => $q->where('department_id', $filters['department_id']))
            ->when(!empty($filters['movement_type']), fn ($q) => $q->where('movement_type', $filters['movement_type']))
            ->when(!empty($filters['product']),       fn ($q) => $q->whereHas('product', fn ($p) => $p
                ->where('name', 'like', '%' . $filters['product'] . '%')
                ->orWhere('sku', 'like', '%' . $filters['product'] . '%')))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();
    }

    /**
     * DEPT-2 — Branch vs Department Allocation:
     * unallocated = official branch stock − total department custody.
     */
    public function allocation(array $filters): array
    {
        $official = DB::connection('tenant')->table('stock_balances as sb')
            ->leftJoin('products as p', 'p.id', '=', 'sb.product_id')
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('sb.branch_id', $filters['branch_id']))
            ->groupBy('sb.branch_id', 'sb.product_id')
            ->select([
                'sb.branch_id', 'sb.product_id',
                DB::raw('MAX(p.name) as product_name'), DB::raw('MAX(p.sku) as product_sku'),
                DB::raw('SUM(sb.quantity_on_hand) as official_qty'),
            ])->get()->keyBy(fn ($r) => $r->branch_id . '-' . $r->product_id);

        $allocated = DB::connection('tenant')->table('department_stock_balances as dsb')
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('dsb.branch_id', $filters['branch_id']))
            ->groupBy('dsb.branch_id', 'dsb.product_id')
            ->select(['dsb.branch_id', 'dsb.product_id', DB::raw('SUM(dsb.quantity_on_hand) as allocated_qty')])
            ->get()->keyBy(fn ($r) => $r->branch_id . '-' . $r->product_id);

        $rows = [];
        foreach ($official as $key => $o) {
            $alloc = (float) ($allocated[$key]->allocated_qty ?? 0);
            if (empty($filters['only_allocated']) || $alloc != 0.0) {
                $rows[] = [
                    'branch_id'   => (int) $o->branch_id,
                    'product'     => $o->product_name,
                    'sku'         => $o->product_sku,
                    'official'    => (float) $o->official_qty,
                    'allocated'   => $alloc,
                    'unallocated' => (float) $o->official_qty - $alloc,
                ];
            }
        }
        // Departments holding custody of a product the branch no longer officially
        // stocks (edge case) still must show up.
        foreach ($allocated as $key => $a) {
            if (! isset($official[$key])) {
                $p = Product::find($a->product_id);
                $rows[] = [
                    'branch_id'   => (int) $a->branch_id,
                    'product'     => $p?->name ?? ('#' . $a->product_id),
                    'sku'         => $p?->sku,
                    'official'    => 0.0,
                    'allocated'   => (float) $a->allocated_qty,
                    'unallocated' => -(float) $a->allocated_qty,
                ];
            }
        }

        usort($rows, fn ($x, $y) => [$x['branch_id'], -$x['allocated']] <=> [$y['branch_id'], -$y['allocated']]);

        return ['rows' => $rows];
    }

    /**
     * DEPT-2 — small stock summary for the department show page.
     */
    public function stockSummary(\App\Models\Tenant\Department $department): array
    {
        $balances = \App\Models\Tenant\DepartmentStockBalance::query()
            ->where('department_id', $department->id)
            ->where('quantity_on_hand', '!=', 0)
            ->get(['quantity_on_hand', 'average_cost']);

        return [
            'stock_value'      => (float) $balances->sum(fn ($b) => (float) $b->quantity_on_hand * (float) $b->average_cost),
            'product_count'    => $balances->count(),
            'recent_movements' => \App\Models\Tenant\DepartmentStockLedger::where('department_id', $department->id)
                ->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * Setup-coverage preview for one department (show page).
     */
    public function setupPreview(\App\Models\Tenant\Department $department): array
    {
        $categoryIds  = $department->mappedCategoryIdsIncludingChildren();
        $includeCount = $department->includeOverrides()->count();
        $excludeCount = $department->excludeOverrides()->count();

        $mappedProducts = Product::query()
            ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $categoryIds), fn ($q) => $q->whereRaw('1=0'))
            ->count();

        $from = now()->subDays(30)->toDateString();

        $sales = $this->sales([
            'date_from'     => $from,
            'date_to'       => now()->toDateString(),
            'branch_id'     => $department->branch_id,
            'department_id' => $department->id,
        ]);

        $allSales = $this->sales([
            'date_from' => $from,
            'date_to'   => now()->toDateString(),
            'branch_id' => $department->branch_id,
        ]);

        $consumption = $this->consumption([
            'date_from'     => $from,
            'date_to'       => now()->toDateString(),
            'branch_id'     => $department->branch_id,
            'department_id' => $department->id,
        ]);

        return [
            'mapped_category_count'    => count($categoryIds),
            'mapped_product_estimate'  => $mappedProducts + $includeCount,
            'include_override_count'   => $includeCount,
            'exclude_override_count'   => $excludeCount,
            'period_days'              => 30,
            'sales_net_30d'            => $sales['totals']['net'] ?? 0,
            'sales_qty_30d'            => $sales['totals']['qty'] ?? 0,
            'unassigned_sold_products' => count($allSales['unassigned'] ?? []),
            'consumption_lines_30d'    => $consumption['lines']->count(),
            'consumption_cost_30d'     => array_sum(array_column($consumption['summary'], 'cost')),
        ];
    }
}
