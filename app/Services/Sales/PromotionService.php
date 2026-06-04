<?php

namespace App\Services\Sales;

use App\Models\Tenant\Promotion;
use Carbon\Carbon;

class PromotionService
{
    public function findApplicablePromotion(int $branchId, string $orderType, float $subtotal, ?string $promoCode = null): ?Promotion
    {
        $query = Promotion::where('status', 'active')
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });

        if ($promoCode) {
            $query->where('code', $promoCode)->where('requires_code', true);
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

        return $query->orderByDesc('priority')->first();
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

    public function incrementUsage(Promotion $promotion): void
    {
        $promotion->increment('used_count');
    }
}
