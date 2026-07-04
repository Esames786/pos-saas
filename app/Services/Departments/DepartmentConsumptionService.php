<?php

namespace App\Services\Departments;

use App\Models\Tenant\DepartmentConsumptionException;
use App\Models\Tenant\DepartmentStockLedger;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\StockLedger;
use Illuminate\Support\Facades\Log;

/**
 * DEPT-3A — SHADOW department consumption.
 *
 * After a paid sale writes its OFFICIAL stock-ledger out movements (sale /
 * recipe_consumption / modifier_consumption), this mirrors each movement into
 * the mapped department's custody sub-ledger. Custody only:
 *   - never touches stock_balances / stock_ledgers / journals / COGS
 *   - NEVER blocks or fails the POS sale — every business problem
 *     (no mapping, short custody) becomes a department_consumption_exceptions
 *     row for the exception report instead.
 * Idempotent per official ledger row via reference_type='stock_ledger' +
 * reference_id on department_stock_ledgers.
 */
class DepartmentConsumptionService
{
    /** Official out-movements mirrored in DEPT-3A (wastage integration deferred). */
    public const PROCESSED_MOVEMENT_TYPES = [
        'sale',
        'recipe_consumption',
        'modifier_consumption',
    ];

    public function __construct(private readonly DepartmentInventoryService $inventory) {}

    /**
     * Mirror every official out-movement of a finalized sale. Called AFTER the
     * sale transaction + journal posting; must never throw business errors.
     */
    public function processSaleOrder(SalesOrder $sale): void
    {
        $ledgers = StockLedger::query()
            ->with('product:id,sku,name,category_id')
            ->where('reference_type', 'sales_order')
            ->where('reference_id', $sale->id)
            ->where('direction', 'out')
            ->whereIn('movement_type', self::PROCESSED_MOVEMENT_TYPES)
            ->orderBy('id')
            ->get();

        foreach ($ledgers as $ledger) {
            try {
                $this->processStockLedger($ledger);
            } catch (\Throwable $e) {
                // True coding/data bug — report + record, never bubble to POS.
                report($e);
                $this->recordException($ledger, null, 'invalid_stock_ledger', $e->getMessage());
            }
        }
    }

    /**
     * Mirror ONE official stock-ledger out movement into department custody.
     */
    public function processStockLedger(StockLedger $ledger): void
    {
        if ($ledger->direction !== 'out'
            || ! in_array($ledger->movement_type, self::PROCESSED_MOVEMENT_TYPES, true)) {
            return;
        }

        if ($this->alreadyProcessed($ledger)) {
            return; // idempotent skip — never double-deduct
        }

        $product = $ledger->product;
        if (! $product) {
            $this->recordException($ledger, null, 'invalid_stock_ledger', 'Product missing on stock ledger.');
            return;
        }

        // Resolve the responsible department (same rules as all dept reports).
        $department = DepartmentMappingService::forBranch((int) $ledger->branch_id)
            ->resolve((int) $ledger->product_id, $product->category_id);

        if (! $department) {
            $this->recordException($ledger, null, 'no_department_mapping',
                "No department claims {$product->name} at this branch — map its category or add an include override.");
            return;
        }

        $quantity = (float) $ledger->quantity;
        $onHand   = $this->inventory->departmentOnHand($department->id, (int) $ledger->product_id, $ledger->product_variant_id);

        if ($onHand + 0.0005 < $quantity) {
            $this->recordException($ledger, $department->id, 'insufficient_department_stock',
                "{$department->name} holds " . number_format($onHand, 3) . " but the sale consumed "
                . number_format($quantity, 3) . " of {$product->name} — issue custody stock to the department.");
            return;
        }

        $this->inventory->postOut(
            branchId: (int) $ledger->branch_id,
            departmentId: (int) $department->id,
            productId: (int) $ledger->product_id,
            variantId: $ledger->product_variant_id,
            batchId: null,
            quantity: $quantity,
            // official ledger cost when present; null → department WAC
            unitCost: (float) $ledger->unit_cost > 0 ? (float) $ledger->unit_cost : null,
            movementType: DepartmentStockLedger::SHADOW_TYPE_MAP[$ledger->movement_type],
            referenceType: 'stock_ledger',
            referenceId: $ledger->id,
            referenceNo: $ledger->reference_no,
            notes: 'Shadow of official ' . $ledger->movement_type . ' #' . $ledger->id,
            userId: $ledger->created_by_user_id,
        );

        // A previously-recorded exception for this ledger is now fixed.
        DepartmentConsumptionException::query()
            ->where('stock_ledger_id', $ledger->id)
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    public function alreadyProcessed(StockLedger $ledger): bool
    {
        return DepartmentStockLedger::query()
            ->where('reference_type', 'stock_ledger')
            ->where('reference_id', $ledger->id)
            ->exists();
    }

    /**
     * Record (or refresh) an exception row — updateOrCreate on the explicit
     * key so retries never pile up duplicates.
     */
    public function recordException(StockLedger $ledger, ?int $departmentId, string $reason, ?string $message = null): DepartmentConsumptionException
    {
        Log::info("DEPT-3A shadow consumption exception [{$reason}] for stock_ledger #{$ledger->id}: {$message}");

        return DepartmentConsumptionException::updateOrCreate(
            ['exception_key' => $ledger->id . '-' . $reason],
            [
                'stock_ledger_id'    => $ledger->id,
                'branch_id'          => $ledger->branch_id,
                'department_id'      => $departmentId,
                'product_id'         => $ledger->product_id,
                'product_variant_id' => $ledger->product_variant_id,
                'movement_type'      => $ledger->movement_type,
                'quantity'           => $ledger->quantity,
                'reason'             => $reason,
                'status'             => 'open',
                'reference_type'     => $ledger->reference_type,
                'reference_id'       => $ledger->reference_id,
                'reference_no'       => $ledger->reference_no,
                'message'            => $message,
                'payload'            => [
                    'unit_cost'  => (string) $ledger->unit_cost,
                    'total_cost' => (string) $ledger->total_cost,
                ],
            ]
        );
    }
}
