<?php

namespace App\Services\Sales;

use App\Models\Tenant\Promotion;
use Carbon\Carbon;

class PromotionService
{
    public function findApplicablePromotion(int $branchId, string $orderType, float $subtotal, ?string $promoCode = null, array $lines = []): ?Promotion
    {
        $query = Promotion::with('targets')
            ->where('status', 'active')
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });

        if ($promoCode) {
            // Code submitted: find promos that match this code (requires_code or not).
            $query->where('code', $promoCode);
        } else {
            // BUG-013 FIX: no code submitted — only apply promos that do NOT require a code.
            // This prevents requires_code=true promos from auto-applying without the user entering a code.
            $query->where('requires_code', false);
        }

        $now = Carbon::now();
        $query->where(function ($q) use ($now) {
            $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
        });
        $query->where(function ($q) use ($now) {
            $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
        });

        $query->where('min_order_amount', '<=', $subtotal);

        $query->where(function ($q) use ($orderType) {
            $q->whereNull('order_types')->orWhereJsonContains('order_types', $orderType);
        });

        $query->where(function ($q) {
            $q->whereNull('usage_limit')->orWhereRaw('used_count < usage_limit');
        });

        return $query->orderByDesc('priority')
            ->get()
            ->first(fn (Promotion $promotion) => $this->qualifyingSubtotal($promotion, $lines, $subtotal) > 0);
    }

    public function calculateDiscount(Promotion $promotion, float $subtotal): float
    {
        if ($promotion->discount_type === 'fixed') {
            $discount = (float) $promotion->discount_value;
        } else {
            $discount = ($subtotal * (float) $promotion->discount_value) / 100;
            if ($promotion->max_discount_amount) {
                $discount = min($discount, (float) $promotion->max_discount_amount);
            }
        }

        return min($discount, $subtotal);
    }

    public function calculateDiscountForLines(Promotion $promotion, array $lines, float $subtotal): float
    {
        return $this->calculateDiscount($promotion, $this->qualifyingSubtotal($promotion, $lines, $subtotal));
    }

    public function qualifyingSubtotal(Promotion $promotion, array $lines, float $subtotal): float
    {
        if ($promotion->promotion_type === 'order') {
            return max($subtotal, 0);
        }

        $targets = $promotion->relationLoaded('targets') ? $promotion->targets : $promotion->targets()->get();
        if ($targets->isEmpty()) {
            return 0;
        }

        $targetIds = $targets
            ->where('target_type', $promotion->promotion_type)
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($targetIds)) {
            return 0;
        }

        return collect($lines)->sum(function (array $line) use ($promotion, $targetIds) {
            $lineSubtotal = max(
                ((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0))
                - (float) ($line['discount_amount'] ?? 0),
                0
            );

            if ($promotion->promotion_type === 'product') {
                return in_array((int) ($line['product_id'] ?? 0), $targetIds, true) ? $lineSubtotal : 0;
            }

            if ($promotion->promotion_type === 'category') {
                return in_array((int) ($line['category_id'] ?? 0), $targetIds, true) ? $lineSubtotal : 0;
            }

            return 0;
        });
    }

    public function incrementUsage(Promotion $promotion): void
    {
        $promotion->increment('used_count');
    }
}
