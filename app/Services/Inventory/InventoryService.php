<?php

namespace App\Services\Inventory;

use App\Models\Tenant\Branch;
use App\Models\Tenant\InventoryBatch;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService
{
    public function postIn(
        Branch $branch,
        Product $product,
        ?ProductVariant $variant,
        float $quantity,
        float $unitCost,
        string $movementType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNo = null,
        ?string $batchNo = null,
        ?string $expiryDate = null,
        ?string $notes = null,
        ?int $userId = null,
    ): StockLedger {
        $this->ensureStockTracked($product);

        $batch = $this->findOrCreateBatch(
            branch: $branch,
            product: $product,
            variant: $variant,
            batchNo: $batchNo,
            expiryDate: $expiryDate,
            unitCost: $unitCost
        );

        return $this->postMovement(
            branch: $branch,
            product: $product,
            variant: $variant,
            batch: $batch,
            movementType: $movementType,
            direction: 'in',
            quantity: $quantity,
            unitCost: $unitCost,
            referenceType: $referenceType,
            referenceId: $referenceId,
            referenceNo: $referenceNo,
            notes: $notes,
            userId: $userId,
        );
    }

    public function postOutFefo(
        Branch $branch,
        Product $product,
        ?ProductVariant $variant,
        float $quantity,
        string $movementType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNo = null,
        ?string $notes = null,
        ?int $userId = null,
        bool $allowNegative = false,
    ): array {
        $this->ensureStockTracked($product);

        // BUG-006 FIX: always use the tenant connection so inventory writes stay
        // inside the same transaction as the caller (SalesService, etc.).
        return DB::connection('tenant')->transaction(function () use (
            $branch, $product, $variant, $quantity, $movementType,
            $referenceType, $referenceId, $referenceNo, $notes, $userId, $allowNegative
        ) {
            $remaining = $quantity;
            $ledgers = [];
            $lastConsumedCost = null;

            $balances = StockBalance::query()
                ->with('batch')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variant?->id)
                ->where('quantity_on_hand', '>', 0)
                ->get()
                ->sortBy(function (StockBalance $balance) {
                    return $balance->batch?->expiry_date?->format('Y-m-d') ?? '9999-12-31';
                });

            foreach ($balances as $balance) {
                if ($remaining <= 0) {
                    break;
                }

                $consumeQty = min((float) $balance->quantity_on_hand, $remaining);

                $ledgers[] = $this->postMovement(
                    branch: $branch,
                    product: $product,
                    variant: $variant,
                    batch: $balance->batch,
                    movementType: $movementType,
                    direction: 'out',
                    quantity: $consumeQty,
                    unitCost: (float) $balance->average_cost,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    referenceNo: $referenceNo,
                    notes: $notes,
                    userId: $userId,
                );

                if ((float) $balance->average_cost > 0) {
                    $lastConsumedCost = (float) $balance->average_cost;
                }

                $remaining -= $consumeQty;
            }

            if ($remaining > 0.0001) {
                if (! $allowNegative) {
                    throw new RuntimeException('Insufficient stock for ' . $product->name);
                }

                // NEGATIVE-STOCK-SETTING-1B: branch explicitly allows backorder.
                // Post ONE batch-less leg for the shortfall so the balance goes
                // negative while the ledger stays complete. Cost comes from the
                // fallback chain — never silently zero when a cost is known.
                $negativeCost = $this->resolveNegativeStockCost(
                    $branch->id, $product, $variant, $lastConsumedCost
                );

                $negativeNotes = $negativeCost > 0
                    ? trim(($notes ? $notes . ' | ' : '') . 'negative-stock leg')
                    : trim(($notes ? $notes . ' | ' : '') . 'negative-stock leg, no known cost');

                $ledgers[] = $this->postMovement(
                    branch: $branch,
                    product: $product,
                    variant: $variant,
                    batch: null,
                    movementType: $movementType,
                    direction: 'out',
                    quantity: $remaining,
                    unitCost: $negativeCost,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    referenceNo: $referenceNo,
                    notes: $negativeNotes,
                    userId: $userId,
                    allowNegative: true,
                );
            }

            return $ledgers;
        });
    }

    public function transfer(
        Branch $fromBranch,
        Branch $toBranch,
        Product $product,
        ?ProductVariant $variant,
        float $quantity,
        float $unitCost,
        string $referenceType,
        int $referenceId,
        string $referenceNo,
        ?string $notes = null,
        ?int $userId = null,
    ): void {
        if ($fromBranch->id === $toBranch->id) {
            throw new RuntimeException('Transfer branches must be different.');
        }

        // BUG-006 FIX: tenant connection keeps transfer inside the same tx scope.
        DB::connection('tenant')->transaction(function () use (
            $fromBranch, $toBranch, $product, $variant, $quantity, $unitCost,
            $referenceType, $referenceId, $referenceNo, $notes, $userId
        ) {
            $outLedgers = $this->postOutFefo(
                branch: $fromBranch,
                product: $product,
                variant: $variant,
                quantity: $quantity,
                movementType: 'transfer_out',
                referenceType: $referenceType,
                referenceId: $referenceId,
                referenceNo: $referenceNo,
                notes: $notes,
                userId: $userId,
            );

            foreach ($outLedgers as $outLedger) {
                $sourceBatch = $outLedger->batch;
                $destBatch = null;

                if ($sourceBatch) {
                    $destBatch = $this->findOrCreateBatch(
                        branch: $toBranch,
                        product: $product,
                        variant: $variant,
                        batchNo: $sourceBatch->batch_no,
                        expiryDate: $sourceBatch->expiry_date?->format('Y-m-d'),
                        unitCost: (float) $outLedger->unit_cost
                    );
                }

                $this->postMovement(
                    branch: $toBranch,
                    product: $product,
                    variant: $variant,
                    batch: $destBatch,
                    movementType: 'transfer_in',
                    direction: 'in',
                    quantity: (float) $outLedger->quantity,
                    unitCost: (float) ($outLedger->unit_cost ?: $unitCost),
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    referenceNo: $referenceNo,
                    notes: $notes,
                    userId: $userId,
                );
            }
        });
    }

    public function findOrCreateBatch(
        Branch $branch,
        Product $product,
        ?ProductVariant $variant,
        ?string $batchNo,
        ?string $expiryDate,
        float $unitCost = 0
    ): ?InventoryBatch {
        $batchRequired = $product->requires_batch || $product->has_expiry || filled($batchNo) || filled($expiryDate);

        if (!$batchRequired) {
            return null;
        }

        $batchNo = $batchNo ?: 'BATCH-' . now()->format('YmdHis') . '-' . random_int(100, 999);
        $batchKey = $this->batchKey($branch->id, $product->id, $variant?->id, $batchNo);

        return InventoryBatch::firstOrCreate(
            ['batch_key' => $batchKey],
            [
                'branch_id'          => $branch->id,
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'batch_no'           => $batchNo,
                'expiry_date'        => $expiryDate ?: null,
                'received_date'      => now()->toDateString(),
                'unit_cost'          => $unitCost,
                'status'             => 'active',
            ]
        );
    }

    public function resolveVariant(Product $product, ?int $variantId): ?ProductVariant
    {
        if ($variantId) {
            return ProductVariant::where('product_id', $product->id)
                ->where('id', $variantId)
                ->firstOrFail();
        }

        return $product->defaultVariant()->first();
    }

    private function postMovement(
        Branch $branch,
        Product $product,
        ?ProductVariant $variant,
        ?InventoryBatch $batch,
        string $movementType,
        string $direction,
        float $quantity,
        float $unitCost,
        ?string $referenceType,
        ?int $referenceId,
        ?string $referenceNo,
        ?string $notes,
        ?int $userId,
        bool $allowNegative = false,
    ): StockLedger {
        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        // BUG-006 FIX: tenant connection. BUG-007 FIX: lockForUpdate on StockBalance.
        return DB::connection('tenant')->transaction(function () use (
            $branch, $product, $variant, $batch, $movementType, $direction,
            $quantity, $unitCost, $referenceType, $referenceId, $referenceNo, $notes, $userId, $allowNegative
        ) {
            $balanceKey = $this->balanceKey($branch->id, $product->id, $variant?->id, $batch?->id);

            // BUG-007 FIX: acquire a row-level lock before read-modify-write so
            // concurrent sales of the same product cannot both pass the stock check.
            $balance = StockBalance::where('balance_key', $balanceKey)->lockForUpdate()->first();

            if (! $balance) {
                $balance = StockBalance::create([
                    'balance_key'         => $balanceKey,
                    'branch_id'           => $branch->id,
                    'product_id'          => $product->id,
                    'product_variant_id'  => $variant?->id,
                    'inventory_batch_id'  => $batch?->id,
                    'quantity_on_hand'    => 0,
                    'average_cost'        => 0,
                ]);
                // Re-acquire lock on the newly created row.
                $balance = StockBalance::where('balance_key', $balanceKey)->lockForUpdate()->firstOrFail();
            }

            $currentQty  = (float) $balance->quantity_on_hand;
            $currentCost = (float) $balance->average_cost;

            if ($direction === 'out' && $currentQty < $quantity && ! $allowNegative) {
                throw new RuntimeException('Insufficient stock for ' . $product->name);
            }

            if ($direction === 'in') {
                $newQty = $currentQty + $quantity;
                $newAverageCost = $newQty > 0
                    ? (($currentQty * $currentCost) + ($quantity * $unitCost)) / $newQty
                    : $unitCost;
            } else {
                $newQty = $currentQty - $quantity;
                $newAverageCost = $currentCost;
            }

            $balance->update([
                'quantity_on_hand' => $newQty,
                'average_cost'     => $newAverageCost,
            ]);

            return StockLedger::create([
                'branch_id'            => $branch->id,
                'product_id'           => $product->id,
                'product_variant_id'   => $variant?->id,
                'inventory_batch_id'   => $batch?->id,
                'movement_type'        => $movementType,
                'direction'            => $direction,
                'quantity'             => $quantity,
                'unit_cost'            => $unitCost,
                'total_cost'           => $quantity * $unitCost,
                'balance_after'        => $newQty,
                'reference_type'       => $referenceType,
                'reference_id'         => $referenceId,
                'reference_no'         => $referenceNo,
                'notes'                => $notes,
                'created_by_user_id'   => $userId,
            ]);
        });
    }

    /**
     * NEGATIVE-STOCK-SETTING-1B: best-known unit cost for a backorder (negative)
     * leg so COGS is never silently zero when any cost is known.
     * Fallback order (design doc §6):
     *   1. last positive balance cost consumed in the same postOutFefo call
     *   2. batch-less balance average_cost
     *   3. max average_cost across this branch/product/variant's balances
     *   4. variant purchase_price
     *   5. product default_purchase_price
     *   6. 0 — flagged via ledger notes by the caller
     */
    private function resolveNegativeStockCost(
        int $branchId,
        Product $product,
        ?ProductVariant $variant,
        ?float $lastConsumedCost = null
    ): float {
        if ($lastConsumedCost !== null && $lastConsumedCost > 0) {
            return $lastConsumedCost;
        }

        $batchlessKey = $this->balanceKey($branchId, $product->id, $variant?->id, null);
        $batchlessCost = (float) (StockBalance::where('balance_key', $batchlessKey)->value('average_cost') ?? 0);
        if ($batchlessCost > 0) {
            return $batchlessCost;
        }

        $maxCost = (float) (StockBalance::where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->max('average_cost') ?? 0);
        if ($maxCost > 0) {
            return $maxCost;
        }

        if ($variant && (float) $variant->purchase_price > 0) {
            return (float) $variant->purchase_price;
        }

        if ((float) $product->default_purchase_price > 0) {
            return (float) $product->default_purchase_price;
        }

        return 0.0;
    }

    private function ensureStockTracked(Product $product): void
    {
        if (!$product->is_stock_tracked) {
            throw new RuntimeException($product->name . ' is not stock tracked.');
        }
    }

    private function batchKey(int $branchId, int $productId, ?int $variantId, string $batchNo): string
    {
        return implode('-', [$branchId, $productId, $variantId ?: 0, strtolower(trim($batchNo))]);
    }

    private function balanceKey(int $branchId, int $productId, ?int $variantId, ?int $batchId): string
    {
        return implode('-', [$branchId, $productId, $variantId ?: 0, $batchId ?: 0]);
    }
}
