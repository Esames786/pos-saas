<?php

namespace App\Services\Reports;

use App\Models\Tenant\InventoryBatch;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Concerns\ResolvesBranchIds;

class InventoryReportService
{
    use ResolvesBranchIds;

    /**
     * Stock valuation — qty × average_cost per branch/product.
     */
    public function valuation(array $filters)
    {
        $branchIds = $this->resolveBranchIds($filters);

        return StockBalance::query()
            ->with(['product.category', 'variant', 'branch'])
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->where('quantity_on_hand', '>', 0)
            ->orderBy('branch_id')
            ->orderByRaw('(SELECT name FROM products WHERE products.id = stock_balances.product_id)')
            ->get()
            ->map(fn ($row) => [
                'branch'        => $row->branch?->name ?? '—',
                'product'       => $row->product?->name ?? $row->product_id,
                'variant'       => $row->variant?->name ?? '—',
                'sku'           => $row->product?->sku ?? '',
                'category'      => $row->product?->category?->name ?? 'Uncategorised',
                'qty_on_hand'   => (float) $row->quantity_on_hand,
                'average_cost'  => (float) $row->average_cost,
                'total_value'   => round((float) $row->quantity_on_hand * (float) $row->average_cost, 2),
            ]);
    }

    /** Totals for valuation report. */
    public function valuationTotals(array $filters): array
    {
        $rows = $this->valuation($filters);
        return [
            'total_items'  => $rows->count(),
            'total_value'  => $rows->sum('total_value'),
        ];
    }

    /** Low stock count for dashboard card. */
    public function lowStockCount(): int
    {
        return Product::query()
            ->where('is_stock_tracked', true)
            ->whereHas('defaultVariant', fn ($q) => $q->where('reorder_level', '>', 0))
            ->with(['defaultVariant', 'stockBalances'])
            ->get()
            ->filter(function (Product $product) {
                $qty     = $product->stockBalances->sum('quantity_on_hand');
                $reorder = (float) ($product->defaultVariant?->reorder_level ?? 0);
                return $reorder > 0 && $qty <= $reorder;
            })
            ->count();
    }

    /** Stock movement ledger. */
    public function movements(array $filters)
    {
        return StockLedger::query()
            ->with(['branch', 'product', 'variant', 'createdBy'])
            ->when($this->resolveBranchIds($filters), fn ($q, $ids) => $q->whereIn('branch_id', $ids))
            ->when(!empty($filters['product_id']),     fn ($q) => $q->where('product_id', $filters['product_id']))
            ->when(!empty($filters['movement_type']),  fn ($q) => $q->where('movement_type', $filters['movement_type']))
            ->when(!empty($filters['date_from']),      fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),        fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();
    }

    /** Products below reorder level. */
    public function lowStock(array $filters)
    {
        $branchIds = $this->resolveBranchIds($filters);

        return Product::query()
            ->with(['defaultVariant', 'stockBalances.branch', 'category'])
            ->where('is_stock_tracked', true)
            ->when($branchIds, fn ($q) => $q->whereHas('stockBalances', fn ($s) => $s->whereIn('branch_id', $branchIds)))
            ->whereHas('defaultVariant', fn ($q) => $q->where('reorder_level', '>', 0))
            ->get()
            ->filter(function (Product $product) use ($branchIds) {
                $balances = $product->stockBalances;
                if ($branchIds) {
                    $balances = $balances->whereIn('branch_id', $branchIds);
                }
                $qty     = $balances->sum('quantity_on_hand');
                $reorder = (float) ($product->defaultVariant?->reorder_level ?? 0);
                return $reorder > 0 && $qty <= $reorder;
            });
    }

    /** Inventory batches expiring within N days. */
    public function expiry(array $filters)
    {
        $days = (int) ($filters['days'] ?? 30);

        return InventoryBatch::query()
            ->with(['branch', 'product', 'variant'])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days))
            ->when($this->resolveBranchIds($filters), fn ($q, $ids) => $q->whereIn('branch_id', $ids))
            ->orderBy('expiry_date')
            ->paginate(25)
            ->withQueryString();
    }
}
