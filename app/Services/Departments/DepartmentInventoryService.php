<?php

namespace App\Services\Departments;

use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentStockBalance;
use App\Models\Tenant\DepartmentStockLedger;
use App\Models\Tenant\DepartmentStockTransfer;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\StockBalance;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DEPT-2 — department custody sub-ledger engine.
 *
 * Mirrors InventoryService mechanics (transaction + lockForUpdate + WAC) but
 * writes ONLY department_stock_balances / department_stock_ledgers. It NEVER
 * calls InventoryService, never writes stock_balances/stock_ledgers, and
 * never creates journals — official branch stock and GL are untouched.
 *
 * Over-allocation guard: available_to_issue =
 *   official branch on-hand − total already allocated to departments
 * so custody handed out can never exceed what the branch officially holds.
 */
class DepartmentInventoryService
{
    public function resolveVariant(Product $product, ?ProductVariant $variant = null): ?ProductVariant
    {
        return $variant ?? $product->defaultVariant()->first();
    }

    /** Official branch stock on hand (reads stock_balances only). */
    public function officialBranchOnHand(int $branchId, int $productId, ?int $variantId, ?int $batchId = null): float
    {
        return (float) StockBalance::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->when($batchId !== null, fn ($q) => $q->where('inventory_batch_id', $batchId))
            ->sum('quantity_on_hand');
    }

    /** Total custody already allocated across ALL departments of the branch. */
    public function allocatedDepartmentOnHand(int $branchId, int $productId, ?int $variantId, ?int $batchId = null): float
    {
        return (float) DepartmentStockBalance::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->when($batchId !== null, fn ($q) => $q->where('inventory_batch_id', $batchId))
            ->sum('quantity_on_hand');
    }

    public function availableToIssue(int $branchId, int $productId, ?int $variantId, ?int $batchId = null): float
    {
        return $this->officialBranchOnHand($branchId, $productId, $variantId, $batchId)
            - $this->allocatedDepartmentOnHand($branchId, $productId, $variantId, $batchId);
    }

    public function departmentOnHand(int $departmentId, int $productId, ?int $variantId, ?int $batchId = null): float
    {
        return (float) DepartmentStockBalance::query()
            ->where('department_id', $departmentId)
            ->where('product_id', $productId)
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->when($batchId !== null, fn ($q) => $q->where('inventory_batch_id', $batchId))
            ->sum('quantity_on_hand');
    }

    /** Official branch WAC for a product/variant (cost fallback for lines). */
    public function officialAverageCost(int $branchId, int $productId, ?int $variantId): float
    {
        $balance = StockBalance::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->where('quantity_on_hand', '>', 0)
            ->orderByDesc('quantity_on_hand')
            ->first();

        return (float) ($balance?->average_cost ?? 0);
    }

    /**
     * Custody IN to a department (issue receipt / transfer destination).
     */
    public function postIn(
        int $branchId,
        int $departmentId,
        int $productId,
        ?int $variantId,
        ?int $batchId,
        float $quantity,
        float $unitCost,
        string $movementType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNo = null,
        ?string $notes = null,
        ?int $userId = null,
    ): DepartmentStockLedger {
        return $this->postMovement(
            $branchId, $departmentId, $productId, $variantId, $batchId,
            $movementType, 'in', $quantity, $unitCost,
            $referenceType, $referenceId, $referenceNo, $notes, $userId
        );
    }

    /**
     * Custody OUT of a department (return / transfer source).
     */
    public function postOut(
        int $branchId,
        int $departmentId,
        int $productId,
        ?int $variantId,
        ?int $batchId,
        float $quantity,
        ?float $unitCost,
        string $movementType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNo = null,
        ?string $notes = null,
        ?int $userId = null,
    ): DepartmentStockLedger {
        return $this->postMovement(
            $branchId, $departmentId, $productId, $variantId, $batchId,
            $movementType, 'out', $quantity, $unitCost,
            $referenceType, $referenceId, $referenceNo, $notes, $userId
        );
    }

    /**
     * Post a whole issue/return/transfer document atomically.
     * Duplicate-post safe: the header row is locked and re-checked inside
     * the transaction, so a posted document can never write ledgers twice.
     */
    public function postTransferDocument(DepartmentStockTransfer $transfer, ?int $userId = null): DepartmentStockTransfer
    {
        return DB::connection('tenant')->transaction(function () use ($transfer, $userId) {
            /** @var DepartmentStockTransfer $doc */
            $doc = DepartmentStockTransfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($doc->status === 'posted') {
                throw new RuntimeException("Document {$doc->transfer_no} is already posted.");
            }
            if ($doc->status === 'cancelled') {
                throw new RuntimeException("Document {$doc->transfer_no} is cancelled and cannot be posted.");
            }

            $doc->load('lines.product');

            if ($doc->lines->isEmpty()) {
                throw new RuntimeException('Cannot post a document without product lines.');
            }

            foreach ($doc->lines as $line) {
                $productName = $line->product?->name ?? ('#' . $line->product_id);
                $qty         = (float) $line->quantity;
                $variantId   = $line->product_variant_id;
                $batchId     = $line->inventory_batch_id;

                if ($qty <= 0) {
                    throw new RuntimeException("Quantity for {$productName} must be greater than zero.");
                }

                switch ($doc->transfer_type) {
                    case 'issue':
                        // Over-allocation guard against OFFICIAL branch stock.
                        $available = $this->availableToIssue($doc->branch_id, $line->product_id, $variantId, $batchId);
                        if ($qty > $available + 0.0005) {
                            throw new RuntimeException(
                                "Cannot issue {$qty} of {$productName}: only " . number_format($available, 3)
                                . ' available (official branch stock minus custody already allocated to departments).'
                            );
                        }
                        // NB: "0.0000" is a TRUTHY string — cast before the empty check,
                        // otherwise a zero line cost bypasses the branch-WAC fallback.
                        $cost = (float) $line->unit_cost;
                        if ($cost <= 0) {
                            $cost = $this->officialAverageCost($doc->branch_id, $line->product_id, $variantId);
                        }
                        $this->postIn(
                            $doc->branch_id, $doc->to_department_id, $line->product_id, $variantId, $batchId,
                            $qty, $cost, 'branch_issue_in',
                            'department_stock_transfer', $doc->id, $doc->transfer_no, $line->notes, $userId
                        );
                        break;

                    case 'return':
                        $this->postOut(
                            $doc->branch_id, $doc->from_department_id, $line->product_id, $variantId, $batchId,
                            $qty, $line->unit_cost !== null && (float) $line->unit_cost > 0 ? (float) $line->unit_cost : null,
                            'branch_return_out',
                            'department_stock_transfer', $doc->id, $doc->transfer_no, $line->notes, $userId
                        );
                        break;

                    case 'transfer':
                        $outLedger = $this->postOut(
                            $doc->branch_id, $doc->from_department_id, $line->product_id, $variantId, $batchId,
                            $qty, $line->unit_cost !== null && (float) $line->unit_cost > 0 ? (float) $line->unit_cost : null,
                            'department_transfer_out',
                            'department_stock_transfer', $doc->id, $doc->transfer_no, $line->notes, $userId
                        );
                        // Destination receives at the source's out cost.
                        $this->postIn(
                            $doc->branch_id, $doc->to_department_id, $line->product_id, $variantId, $batchId,
                            $qty, (float) $outLedger->unit_cost, 'department_transfer_in',
                            'department_stock_transfer', $doc->id, $doc->transfer_no, $line->notes, $userId
                        );
                        break;

                    default:
                        throw new RuntimeException("Unknown transfer type: {$doc->transfer_type}");
                }
            }

            $doc->update([
                'status'    => 'posted',
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $doc->fresh(['lines', 'fromDepartment', 'toDepartment', 'branch']);
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Atomic custody read-modify-write — the ONLY place department quantities
     * move. Same lockForUpdate + WAC mechanics as InventoryService, but on
     * the department sub-ledger tables only.
     */
    private function postMovement(
        int $branchId,
        int $departmentId,
        int $productId,
        ?int $variantId,
        ?int $batchId,
        string $movementType,
        string $direction,
        float $quantity,
        ?float $unitCost,
        ?string $referenceType,
        ?int $referenceId,
        ?string $referenceNo,
        ?string $notes,
        ?int $userId,
    ): DepartmentStockLedger {
        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }
        if (! in_array($movementType, DepartmentStockLedger::MOVEMENT_TYPES, true)) {
            throw new RuntimeException("Unknown department movement type: {$movementType}");
        }

        Department::query()
            ->where('id', $departmentId)
            ->where('branch_id', $branchId)
            ->firstOr(fn () => throw new RuntimeException('Department does not belong to the selected branch.'));

        return DB::connection('tenant')->transaction(function () use (
            $branchId, $departmentId, $productId, $variantId, $batchId,
            $movementType, $direction, $quantity, $unitCost,
            $referenceType, $referenceId, $referenceNo, $notes, $userId
        ) {
            $balanceKey = implode('-', [$branchId, $departmentId, $productId, $variantId ?: 0, $batchId ?: 0]);

            $balance = DepartmentStockBalance::where('balance_key', $balanceKey)->lockForUpdate()->first();

            if (! $balance) {
                $balance = DepartmentStockBalance::create([
                    'balance_key'        => $balanceKey,
                    'branch_id'          => $branchId,
                    'department_id'      => $departmentId,
                    'product_id'         => $productId,
                    'product_variant_id' => $variantId,
                    'inventory_batch_id' => $batchId,
                    'quantity_on_hand'   => 0,
                    'average_cost'       => 0,
                ]);
                $balance = DepartmentStockBalance::where('balance_key', $balanceKey)->lockForUpdate()->firstOrFail();
            }

            $currentQty  = (float) $balance->quantity_on_hand;
            $currentCost = (float) $balance->average_cost;

            if ($direction === 'out') {
                if ($currentQty + 0.0005 < $quantity) {
                    throw new RuntimeException(
                        'Insufficient department custody stock: has ' . number_format($currentQty, 3)
                        . ', needs ' . number_format($quantity, 3) . '.'
                    );
                }
                // Out at current department WAC unless an explicit cost was given.
                $effectiveCost = $unitCost !== null ? $unitCost : $currentCost;
                $newQty        = $currentQty - $quantity;
                $newAvgCost    = $currentCost;
            } else {
                $effectiveCost = (float) ($unitCost ?? 0);
                $newQty        = $currentQty + $quantity;
                $newAvgCost    = $newQty > 0
                    ? (($currentQty * $currentCost) + ($quantity * $effectiveCost)) / $newQty
                    : $effectiveCost;
            }

            $balance->update([
                'quantity_on_hand' => $newQty,
                'average_cost'     => $newAvgCost,
            ]);

            return DepartmentStockLedger::create([
                'branch_id'          => $branchId,
                'department_id'      => $departmentId,
                'product_id'         => $productId,
                'product_variant_id' => $variantId,
                'inventory_batch_id' => $batchId,
                'movement_type'      => $movementType,
                'direction'          => $direction,
                'quantity'           => $quantity,
                'unit_cost'          => $effectiveCost,
                'total_cost'         => $quantity * $effectiveCost,
                'balance_after'      => $newQty,
                'reference_type'     => $referenceType,
                'reference_id'       => $referenceId,
                'reference_no'       => $referenceNo,
                'notes'              => $notes,
                'created_by'         => $userId,
            ]);
        });
    }
}
