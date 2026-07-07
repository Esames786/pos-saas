<?php

namespace App\Services\Departments;

use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentConsumptionException;
use App\Models\Tenant\DepartmentCountLine;
use App\Models\Tenant\DepartmentCountSession;
use App\Models\Tenant\DepartmentStockBalance;
use App\Models\Tenant\DepartmentStockLedger;
use App\Models\Tenant\Product;
use Illuminate\Support\Facades\DB;

/**
 * DEPT-5 — Department Command Center metrics. READ-ONLY: aggregates the
 * custody sub-ledger, exceptions, counts, and allocation risk from existing
 * department tables. Branch stock stays the official financial truth.
 */
class DepartmentDashboardService
{
    /**
     * @param array{date_from?:string,date_to?:string,branch_id?:mixed,department_id?:mixed} $filters
     */
    public function build(array $filters): array
    {
        $branchId = !empty($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        $deptId   = !empty($filters['department_id']) ? (int) $filters['department_id'] : null;
        $dateFrom = $filters['date_from'] ?? now()->subDays(7)->toDateString();
        $dateTo   = $filters['date_to'] ?? now()->toDateString();

        $scopeBalance = fn ($q) => $q
            ->when($branchId, fn ($x) => $x->where('branch_id', $branchId))
            ->when($deptId, fn ($x) => $x->where('department_id', $deptId));

        // ── A. Custody stock value ────────────────────────────────────────
        $balances = DepartmentStockBalance::query()->tap($scopeBalance)->get(['department_id', 'branch_id', 'product_id', 'quantity_on_hand', 'average_cost']);
        $stockValue     = (float) $balances->sum(fn ($b) => (float) $b->quantity_on_hand * (float) $b->average_cost);
        $stockedCount   = $balances->where('quantity_on_hand', '!=', 0)->count();
        $negativeRows   = $balances->where('quantity_on_hand', '<', 0)->count();

        // ── B. Open consumption exceptions ────────────────────────────────
        $openExceptions = DepartmentConsumptionException::query()
            ->where('status', 'open')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($deptId, fn ($q) => $q->where(fn ($w) => $w->where('department_id', $deptId)->orWhereNull('department_id')))
            ->get(['id', 'department_id', 'reason', 'created_at']);
        $oldestOpen = $openExceptions->min('created_at');

        // ── C. Count pipeline ─────────────────────────────────────────────
        $countScope = fn () => DepartmentCountSession::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId));
        $draftCounts     = (clone $countScope())->where('status', 'draft')->count();
        $submittedCounts = (clone $countScope())->where('status', 'submitted')->count();
        $approvedCounts  = (clone $countScope())->where('status', 'approved')->whereBetween('count_date', [$dateFrom, $dateTo])->count();
        $rejectedCounts  = (clone $countScope())->where('status', 'rejected')->whereBetween('count_date', [$dateFrom, $dateTo])->count();

        // ── D. Recent movements (period) ──────────────────────────────────
        $movementRows = DepartmentStockLedger::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->get(['movement_type', 'total_cost']);
        $movements = [
            'total'       => $movementRows->count(),
            'shadow'      => $movementRows->filter(fn ($m) => str_contains($m->movement_type, '_shadow_consumption_'))->count(),
            'issue_return_transfer' => $movementRows->whereIn('movement_type', ['branch_issue_in', 'branch_return_out', 'department_transfer_in', 'department_transfer_out'])->count(),
            'adjustments' => $movementRows->whereIn('movement_type', ['department_adjustment_in', 'department_adjustment_out'])->count(),
            'total_value' => (float) $movementRows->sum('total_cost'),
        ];

        // ── E. Allocation risk (allocated > official) ─────────────────────
        $official = DB::connection('tenant')->table('stock_balances')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('branch_id', 'product_id')
            ->select(['branch_id', 'product_id', DB::raw('SUM(quantity_on_hand) as qty')])
            ->get()->keyBy(fn ($r) => $r->branch_id . '-' . $r->product_id);
        $allocated = DB::connection('tenant')->table('department_stock_balances')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('branch_id', 'product_id')
            ->select(['branch_id', 'product_id', DB::raw('SUM(quantity_on_hand) as qty')])
            ->get()->keyBy(fn ($r) => $r->branch_id . '-' . $r->product_id);

        $overAllocated = [];
        foreach ($allocated as $key => $a) {
            $off = (float) ($official[$key]->qty ?? 0);
            if ((float) $a->qty > $off + 0.0005) {
                $overAllocated[] = [
                    'branch_id' => (int) $a->branch_id,
                    'product'   => Product::find($a->product_id)?->name ?? ('#' . $a->product_id),
                    'sku'       => Product::find($a->product_id)?->sku,
                    'official'  => $off,
                    'allocated' => (float) $a->qty,
                    'over'      => (float) $a->qty - $off,
                ];
            }
        }
        usort($overAllocated, fn ($x, $y) => $y['over'] <=> $x['over']);

        // ── F. Reconciliation variance (period, approved) ─────────────────
        $varianceLines = DepartmentCountLine::query()
            ->whereHas('session', fn ($q) => $q
                ->when($branchId, fn ($s) => $s->where('branch_id', $branchId))
                ->when($deptId, fn ($s) => $s->where('department_id', $deptId))
                ->where('status', 'approved')
                ->whereBetween('count_date', [$dateFrom, $dateTo]))
            ->with('session.department:id,name')
            ->get(['id', 'department_count_session_id', 'product_id', 'variance_qty', 'variance_value']);
        $variance = [
            'positive_value' => (float) $varianceLines->where('variance_qty', '>', 0)->sum('variance_value'),
            'negative_value' => (float) $varianceLines->where('variance_qty', '<', 0)->sum('variance_value'),
            'by_department'  => $varianceLines->groupBy(fn ($l) => $l->session?->department?->name ?? '—')
                ->map(fn ($g) => (float) $g->sum('variance_value'))->all(),
            'top_products'   => $varianceLines->groupBy('product_id')
                ->map(fn ($g, $pid) => ['product' => Product::find($pid)?->name ?? ('#' . $pid), 'value' => (float) $g->sum(fn ($l) => abs((float) $l->variance_value))])
                ->sortByDesc('value')->take(5)->values()->all(),
        ];

        // ── Section 1: per-department health table ────────────────────────
        $health = [];
        $departments = Department::with('branch:id,name')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($deptId, fn ($q) => $q->where('id', $deptId))
            ->where('status', 'active')
            ->orderBy('branch_id')->orderBy('sort_order')->get();

        $overAllocDeptIds = []; // over-allocation is branch/product level; flag depts in affected branches holding that product
        foreach ($departments as $dept) {
            $deptBalances = $balances->where('department_id', $dept->id);
            $deptNegative = $deptBalances->where('quantity_on_hand', '<', 0)->count();
            $deptOpenExc  = $openExceptions->where('department_id', $dept->id)->count();
            $deptPending  = DepartmentCountSession::where('department_id', $dept->id)->whereIn('status', ['draft', 'submitted'])->count();
            $lastCount    = DepartmentCountSession::where('department_id', $dept->id)->where('status', 'approved')->max('count_date');

            $status = 'healthy';
            if ($deptOpenExc > 0 || $deptPending > 0) {
                $status = 'attention';
            }
            if ($deptNegative > 0) {
                $status = 'critical';
            }

            $health[] = [
                'id'              => $dept->id,
                'department'      => $dept->name,
                'branch'          => $dept->branch?->name,
                'stock_value'     => (float) $deptBalances->sum(fn ($b) => (float) $b->quantity_on_hand * (float) $b->average_cost),
                'stocked'         => $deptBalances->where('quantity_on_hand', '!=', 0)->count(),
                'open_exceptions' => $deptOpenExc,
                'pending_counts'  => $deptPending,
                'last_count'      => $lastCount,
                'status'          => $status,
            ];
        }

        // ── Section lists ─────────────────────────────────────────────────
        $topExceptions = DepartmentConsumptionException::query()
            ->with(['department:id,name', 'product:id,sku,name'])
            ->where('status', 'open')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->orderByDesc('id')->limit(10)->get();

        $awaitingApproval = DepartmentCountSession::query()
            ->with(['department:id,name', 'submittedBy:id,name', 'lines'])
            ->where('status', 'submitted')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->orderBy('submitted_at')->limit(10)->get();

        $recentMovements = DepartmentStockLedger::query()
            ->with(['department:id,name', 'product:id,sku,name'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->orderByDesc('id')->limit(12)->get();

        return [
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'branch_id' => $branchId, 'department_id' => $deptId],
            'cards' => [
                'stock_value'        => $stockValue,
                'stocked_products'   => $stockedCount,
                'open_exceptions'    => $openExceptions->count(),
                'awaiting_approval'  => $submittedCounts,
                'negative_rows'      => $negativeRows,
                'allocation_risk'    => count($overAllocated),
            ],
            'exceptions_breakdown' => [
                'insufficient' => $openExceptions->where('reason', 'insufficient_department_stock')->count(),
                'no_mapping'   => $openExceptions->where('reason', 'no_department_mapping')->count(),
                'oldest_days'  => $oldestOpen ? (int) \Carbon\Carbon::parse($oldestOpen)->diffInDays(now()) : null,
            ],
            'counts'           => ['draft' => $draftCounts, 'submitted' => $submittedCounts, 'approved' => $approvedCounts, 'rejected' => $rejectedCounts],
            'movements'        => $movements,
            'over_allocated'   => array_slice($overAllocated, 0, 10),
            'variance'         => $variance,
            'health'           => $health,
            'top_exceptions'   => $topExceptions,
            'awaiting'         => $awaitingApproval,
            'recent_movements' => $recentMovements,
        ];
    }
}
