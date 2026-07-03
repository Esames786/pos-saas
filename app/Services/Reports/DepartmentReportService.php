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
