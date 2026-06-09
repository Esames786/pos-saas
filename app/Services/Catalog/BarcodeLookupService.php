<?php

namespace App\Services\Catalog;

use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBarcode;
use App\Models\Tenant\ProductBranchPrice;
use App\Models\Tenant\ProductVariant;

class BarcodeLookupService
{
    public function lookup(string $code, int $branchId): array
    {
        $code = trim($code);

        if ($code === '') {
            return $this->notFound('Barcode is required.');
        }

        if ($result = $this->lookupProductBarcode($code, $branchId)) {
            return $result;
        }

        if ($result = $this->lookupVariantBarcode($code, $branchId)) {
            return $result;
        }

        if ($result = $this->lookupVariantSku($code, $branchId)) {
            return $result;
        }

        if ($result = $this->lookupProductSku($code, $branchId)) {
            return $result;
        }

        return $this->notFound('Barcode not found.');
    }

    private function lookupProductBarcode(string $code, int $branchId): ?array
    {
        $barcode = ProductBarcode::query()
            ->where('barcode', $code)
            ->first();

        if (!$barcode) {
            return null;
        }

        $product = $this->activeProduct((int) $barcode->product_id);

        if (!$product) {
            return null;
        }

        $variant = $barcode->product_variant_id
            ? $this->activeVariant((int) $barcode->product_variant_id, (int) $product->id)
            : $this->defaultVariant($product);

        return $this->formatResult($product, $variant, $branchId, 'product_barcodes');
    }

    private function lookupVariantBarcode(string $code, int $branchId): ?array
    {
        $variant = ProductVariant::query()
            ->where('barcode', $code)
            ->where('is_active', true)
            ->first();

        if (!$variant) {
            return null;
        }

        $product = $this->activeProduct((int) $variant->product_id);

        if (!$product) {
            return null;
        }

        return $this->formatResult($product, $variant, $branchId, 'variant_barcode');
    }

    private function lookupVariantSku(string $code, int $branchId): ?array
    {
        $variant = ProductVariant::query()
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($code)])
            ->where('is_active', true)
            ->first();

        if (!$variant) {
            return null;
        }

        $product = $this->activeProduct((int) $variant->product_id);

        if (!$product) {
            return null;
        }

        return $this->formatResult($product, $variant, $branchId, 'variant_sku');
    }

    private function lookupProductSku(string $code, int $branchId): ?array
    {
        $product = Product::query()
            ->with('unit')
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($code)])
            ->where('status', 'active')
            ->first();

        if (!$product) {
            return null;
        }

        return $this->formatResult($product, $this->defaultVariant($product), $branchId, 'product_sku');
    }

    private function activeProduct(int $productId): ?Product
    {
        return Product::query()
            ->with('unit')
            ->where('status', 'active')
            ->find($productId);
    }

    private function activeVariant(int $variantId, int $productId): ?ProductVariant
    {
        return ProductVariant::query()
            ->where('id', $variantId)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->first();
    }

    private function defaultVariant(Product $product): ?ProductVariant
    {
        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($variant) {
            return $variant;
        }

        return ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    private function formatResult(Product $product, ?ProductVariant $variant, int $branchId, string $source): array
    {
        $unitType    = $product->unit?->unit_type ?? 'quantity';
        $allowDecimal = in_array($unitType, ['weight', 'volume', 'length'], true);

        return [
            'found'                   => true,
            'source'                  => $source,
            'product_id'              => (int) $product->id,
            'variant_id'              => $variant?->id ? (int) $variant->id : null,
            'name'                    => $product->name,
            'variant_name'            => $variant?->name,
            'sku'                     => $variant?->sku ?: $product->sku,
            'product_sku'             => $product->sku,
            'unit_code'               => $product->unit?->code,
            'unit_type'               => $unitType,
            'selling_price'           => round($this->resolveSellingPrice($product, $variant, $branchId), 2),
            'purchase_price'          => round($this->resolvePurchasePrice($product, $variant), 2),
            'allow_decimal'           => $allowDecimal,
            'requires_quantity_modal' => $allowDecimal,
            'requires_batch'          => (bool) ($product->requires_batch ?? false),
            'has_expiry'              => (bool) ($product->has_expiry ?? false),
            'is_stock_tracked'        => (bool) ($product->is_stock_tracked ?? false),
            'is_sellable'             => (bool) ($product->is_sellable ?? false),
            'is_purchasable'          => (bool) ($product->is_purchasable ?? false),
        ];
    }

    private function resolveSellingPrice(Product $product, ?ProductVariant $variant, int $branchId): float
    {
        $branchPrice = ProductBranchPrice::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->where('is_available', true)
            ->where(function ($query) use ($variant) {
                if ($variant) {
                    $query->where('product_variant_id', $variant->id)
                        ->orWhereNull('product_variant_id');
                } else {
                    $query->whereNull('product_variant_id');
                }
            })
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

    private function resolvePurchasePrice(Product $product, ?ProductVariant $variant): float
    {
        if ($variant && (float) ($variant->purchase_price ?? 0) > 0) {
            return (float) $variant->purchase_price;
        }

        return (float) ($product->default_purchase_price ?? 0);
    }

    private function notFound(string $message): array
    {
        return [
            'found'   => false,
            'message' => $message,
        ];
    }
}
