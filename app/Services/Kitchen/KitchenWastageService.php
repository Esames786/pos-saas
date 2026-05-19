<?php

namespace App\Services\Kitchen;

use App\Models\Tenant\Branch;
use App\Models\Tenant\KitchenWastage;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Services\Inventory\InventoryService;

class KitchenWastageService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {
    }

    public function record(array $data, Branch $branch, Product $product, ?ProductVariant $variant, int $userId): KitchenWastage
    {
        $wastage = KitchenWastage::create([
            'wastage_no'          => 'KW-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'branch_id'           => $branch->id,
            'product_id'          => $product->id,
            'product_variant_id'  => $variant?->id,
            'quantity'            => (float) $data['quantity'],
            'unit_id'             => $data['unit_id'] ?? $product->unit_id,
            'reason'              => $data['reason'] ?? null,
            'wastage_date'        => $data['wastage_date'] ?? now()->toDateString(),
            'recorded_by_user_id' => $userId,
        ]);

        if ($product->is_stock_tracked) {
            $this->inventoryService->postOutFefo(
                branch: $branch,
                product: $product,
                variant: $variant,
                quantity: (float) $data['quantity'],
                movementType: 'wastage',
                referenceType: 'kitchen_wastage',
                referenceId: $wastage->id,
                referenceNo: $wastage->wastage_no,
                notes: $data['reason'] ?? 'Kitchen wastage',
                userId: $userId,
            );
        }

        return $wastage;
    }
}
