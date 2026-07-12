<?php

namespace App\Services\Reports;

use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\Supplier;
use App\Services\Concerns\ResolvesBranchIds;
use Illuminate\Support\Facades\DB;

class PurchaseReportService
{
    use ResolvesBranchIds;

    public function summary(array $filters)
    {
        $branchIds = $this->resolveBranchIds($filters);

        return PurchaseBill::query()
            ->with(['supplier', 'branch'])
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when(!empty($filters['supplier_id']), fn ($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(!empty($filters['status']),      fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereDate('bill_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereDate('bill_date', '<=', $filters['date_to']))
            ->orderByDesc('bill_date')
            ->paginate(20)
            ->withQueryString();
    }

    public function summaryTotals(array $filters): array
    {
        $branchIds = $this->resolveBranchIds($filters);

        return PurchaseBill::query()
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when(!empty($filters['supplier_id']), fn ($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(!empty($filters['status']),      fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereDate('bill_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereDate('bill_date', '<=', $filters['date_to']))
            ->selectRaw('
                COUNT(*) as bill_count,
                COALESCE(SUM(subtotal), 0)        as total_subtotal,
                COALESCE(SUM(tax_total), 0)       as total_tax,
                COALESCE(SUM(grand_total), 0)     as total_grand,
                COALESCE(SUM(amount_paid), 0)     as total_paid,
                COALESCE(SUM(balance_due), 0)     as total_balance
            ')
            ->first()
            ->toArray();
    }

    public function suppliers(array $filters)
    {
        return PurchaseBill::query()
            ->join('suppliers', 'purchase_bills.supplier_id', '=', 'suppliers.id')
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('bill_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->whereDate('bill_date', '<=', $filters['date_to']))
            ->selectRaw('
                suppliers.id,
                suppliers.name as supplier_name,
                suppliers.phone,
                COUNT(*) as bill_count,
                COALESCE(SUM(purchase_bills.grand_total), 0) as total_purchases,
                COALESCE(SUM(purchase_bills.amount_paid), 0) as total_paid,
                MAX(purchase_bills.bill_date) as last_purchase_date
            ')
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.phone')
            ->orderByDesc('total_purchases')
            ->get();
    }

    /** Use current_balance as source of truth — never recalculate from ledger. */
    public function payables()
    {
        return Supplier::query()
            ->where('status', 'active')
            ->where('current_balance', '>', 0)
            ->withCount('purchaseBills')
            ->selectRaw('*, current_balance as outstanding')
            ->orderByDesc('current_balance')
            ->get();
    }

    /**
     * PURCHASE-RETURNS-1 — return lines report + summary.
     *
     * @return array{rows: \Illuminate\Contracts\Pagination\LengthAwarePaginator, summary: array}
     */
    public function returns(array $filters): array
    {
        $base = \App\Models\Tenant\PurchaseReturnLine::query()
            ->whereHas('purchaseReturn', function ($q) use ($filters) {
                $q->when(!empty($filters['date_from']),   fn ($s) => $s->whereDate('return_date', '>=', $filters['date_from']))
                  ->when(!empty($filters['date_to']),     fn ($s) => $s->whereDate('return_date', '<=', $filters['date_to']))
                  ->when(!empty($filters['branch_id']),   fn ($s) => $s->where('branch_id', $filters['branch_id']))
                  ->when(!empty($filters['supplier_id']), fn ($s) => $s->where('supplier_id', $filters['supplier_id']))
                  ->when(!empty($filters['status']),      fn ($s) => $s->where('status', $filters['status']));
            })
            ->when(!empty($filters['reason']), fn ($q) => $q->where(function ($w) use ($filters) {
                $w->where('reason_code', $filters['reason'])
                  ->orWhereHas('purchaseReturn', fn ($s) => $s->where('reason_code', $filters['reason']));
            }))
            ->when(!empty($filters['product']), fn ($q) => $q->whereHas('product', fn ($p) => $p
                ->where('name', 'like', '%' . $filters['product'] . '%')
                ->orWhere('sku', 'like', '%' . $filters['product'] . '%')));

        $rows = (clone $base)
            ->with(['purchaseReturn.supplier:id,name', 'purchaseReturn.branch:id,name', 'product:id,sku,name'])
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $all = (clone $base)->with(['purchaseReturn.supplier:id,name'])->get(['id', 'purchase_return_id', 'quantity', 'line_total', 'reason_code']);

        return [
            'rows'    => $rows,
            'summary' => [
                'total_qty'   => (float) $all->sum('quantity'),
                'total_value' => (float) $all->sum('line_total'),
                'by_supplier' => $all->groupBy(fn ($l) => $l->purchaseReturn?->supplier?->name ?? '—')
                    ->map(fn ($g) => (float) $g->sum('line_total'))->sortDesc()->all(),
                'by_reason'   => $all->groupBy(fn ($l) => $l->reason_code ?: ($l->purchaseReturn?->reason_code ?: 'unspecified'))
                    ->map(fn ($g) => (float) $g->sum('line_total'))->sortDesc()->all(),
            ],
        ];
    }
}
