<?php

namespace App\Services\Sales;

class SalesTotalsService
{
    public function __construct(
        private readonly PromotionService $promotionService,
        private readonly ServiceChargeService $serviceChargeService,
    ) {}

    /**
     * Calculate all sale totals server-side.
     *
     * @param  array  $resolvedLines  Each element must have quantity, unit_price, discount_amount, tax_amount
     * @return array{
     *   subtotal: float,
     *   manual_discount_amount: float,
     *   promotion_id: int|null,
     *   promo_code: string|null,
     *   promotion_discount_amount: float,
     *   discount_amount: float,
     *   tax_amount: float,
     *   service_charge_amount: float,
     *   service_charge_taxable: bool,
     *   tip_amount: float,
     *   grand_total: float,
     * }
     */
    public function calculate(
        array $resolvedLines,
        string $discountType,
        float $discountValue,
        int $branchId,
        string $orderType,
        ?string $promoCode = null,
        float $tipAmount = 0,
    ): array {
        // 1. Aggregate line totals
        $subtotal     = 0;
        $lineDiscount = 0;
        $tax          = 0;

        foreach ($resolvedLines as $line) {
            $qty       = (float) ($line['quantity'] ?? 0);
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $discount  = (float) ($line['discount_amount'] ?? 0);
            $lineTax   = (float) ($line['tax_amount'] ?? 0);

            $subtotal     += $qty * $unitPrice;
            $lineDiscount += $discount;
            $tax          += $lineTax;
        }

        // 2. Manual order-level discount
        $orderDiscount = 0;
        if ($discountType === 'fixed') {
            $orderDiscount = (float) $discountValue;
        } elseif ($discountType === 'percent') {
            $orderDiscount = ($subtotal * (float) $discountValue) / 100;
        }
        $manualDiscountAmount = $lineDiscount + $orderDiscount;

        // 3. Promotion (order-level only for now)
        $promotionId            = null;
        $promotionDiscountAmount = 0;
        $appliedPromoCode       = null;

        if ($promoCode) {
            $promotion = $this->promotionService->findApplicablePromotion(
                $branchId, $orderType, $subtotal - $manualDiscountAmount, $promoCode
            );
            if ($promotion) {
                $promotionId             = $promotion->id;
                $appliedPromoCode        = $promotion->code;
                $promotionDiscountAmount = $this->promotionService->calculateDiscount(
                    $promotion, $subtotal - $manualDiscountAmount
                );
            }
        }

        $discountAmount = $manualDiscountAmount + $promotionDiscountAmount;

        // 4. Service charge (on subtotal after discount)
        $scResult             = $this->serviceChargeService->calculate($branchId, $orderType);
        $serviceChargeSetting = $scResult['setting'] ?? null;
        $serviceChargeAmount  = 0;

        if ($serviceChargeSetting) {
            $chargeBase = max($subtotal - $discountAmount, 0);
            if ($serviceChargeSetting->charge_type === 'fixed') {
                $serviceChargeAmount = (float) $serviceChargeSetting->charge_value;
            } else {
                $serviceChargeAmount = round($chargeBase * (float) $serviceChargeSetting->charge_value / 100, 2);
            }
        }

        // 5. Tip — caller enforces 0 for held sales
        $tipAmount = max((float) $tipAmount, 0);

        // 6. Grand total — never negative
        $grandTotal = max(
            $subtotal - $discountAmount + $tax + $serviceChargeAmount + $tipAmount,
            0
        );

        return [
            'subtotal'                  => round($subtotal, 2),
            'manual_discount_amount'    => round($manualDiscountAmount, 2),
            'promotion_id'              => $promotionId,
            'promo_code'                => $appliedPromoCode,
            'promotion_discount_amount' => round($promotionDiscountAmount, 2),
            'discount_amount'           => round($discountAmount, 2),
            'tax_amount'                => round($tax, 2),
            'service_charge_amount'     => round($serviceChargeAmount, 2),
            'service_charge_taxable'    => (bool) ($scResult['is_taxable'] ?? false),
            'tip_amount'                => round($tipAmount, 2),
            'grand_total'               => round($grandTotal, 2),
        ];
    }
}
