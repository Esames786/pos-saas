<?php

namespace App\Services\Reports;

use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Product;

class InventoryReportService
{
    /**
     * Stock valuation — qty × average_cost per branch/product.
     */
    public function valuation(array $filters)
    {
        return StockBalance::query()
            ->with(['product.category', 'variant', 'branch'])
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
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
            ->get()
            ->filter(function (Product $product) {
                $qty     = $product->stockBalances->sum('quantity_on_hand');
                $reorder = (float) ($product->defaultVariant?->reorder_level ?? 0);
                return $reorder > 0 && $qty <= $reorder;
            })
            ->count();
    }
}
