<?php

namespace App\Services\Sales;

use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;

/**
 * EDGE-LOCAL-POS-1 — shared, server-side price + tax resolution (config-governed, no request trust).
 *
 * Extracted verbatim from `SalesOrderController::resolveSellingPrice` / `resolveTaxAmount` so the Cloud POS
 * and the offline Branch Server resolve prices/tax from the SAME rules (branch price → variant → catalog;
 * tax from product taxability), preventing price tampering and Cloud/Edge drift. Pure reads on the `tenant`
 * connection (available offline). No side effects.
 */
class SalePricingService
{
    /**
     * Resolve the selling price. An explicitly submitted price (incl. 0 for free/complimentary items, and
     * combo header/component bundle prices) is honoured; otherwise the catalog price is used:
     * branch-specific price → variant selling_price → product default_selling_price.
     */
    public function resolveSellingPrice(Product $product, ?ProductVariant $variant, int $branchId, ?float $submittedPrice): float
    {
        if ($submittedPrice !== null) {
            return $submittedPrice; // includes 0 for free items (BUG-015)
        }

        $branchPrice = $product->branchPrices()
            ->where('branch_id', $branchId)
            ->where(function ($q) use ($variant) {
                if ($variant) {
                    $q->where('product_variant_id', $variant->id)->orWhereNull('product_variant_id');
                } else {
                    $q->whereNull('product_variant_id');
                }
            })
            ->where('is_available', true)
            ->orderByRaw('product_variant_id IS NULL')
            ->first();

        if ($branchPrice) {
            return (float) $branchPrice->selling_price;
        }

        if ($variant && (float) ($variant->selling_price ?? 0) > 0) {
            return (float) $variant->selling_price;
        }

        return (float) ($product->default_selling_price ?? 0);
    }

    /** Resolve the line tax amount. Honours an explicitly submitted positive tax; else computes from the product. */
    public function resolveTaxAmount(Product $product, float $quantity, float $unitPrice, float $lineDiscount, ?float $submittedTax): float
    {
        if ($submittedTax !== null && $submittedTax > 0) {
            return $submittedTax;
        }

        if (! (bool) ($product->is_taxable ?? false) || (float) ($product->tax_rate_percent ?? 0) <= 0) {
            return 0;
        }

        $taxableAmount = max(($quantity * $unitPrice) - $lineDiscount, 0);

        return round(($taxableAmount * (float) $product->tax_rate_percent) / 100, 2);
    }
}
