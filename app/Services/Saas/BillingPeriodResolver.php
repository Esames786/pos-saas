<?php

namespace App\Services\Saas;

use App\Models\Master\Plan;
use Carbon\CarbonInterface;

/**
 * CLOUD-BILLING-1B / -2 — the single authority for billing-period money + calendar math.
 *
 * Canonical contract:
 *   • monthly invoice  = plan monthly_price, entitlement = 1 CALENDAR month
 *   • yearly invoice   = monthly_price × 10, entitlement = 12 CALENDAR months
 *
 * Periods are CALENDAR periods, never "30/300/365 days" or "10 months". Month-end is handled by
 * addMonthsNoOverflow (Jan 31 + 1 month → Feb 28/29, never spilling into March), which also gives
 * correct leap-year and year-boundary behaviour.
 */
class BillingPeriodResolver
{
    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    /** Yearly is priced at ten months, not twelve — the canonical two-months-free discount. */
    public const YEARLY_PRICE_MONTHS = 10;

    public function normalize(?string $billingPeriod): string
    {
        return $billingPeriod === self::YEARLY ? self::YEARLY : self::MONTHLY;
    }

    public function isYearly(?string $billingPeriod): bool
    {
        return $this->normalize($billingPeriod) === self::YEARLY;
    }

    /**
     * The end of a paid entitlement period that starts at $start.
     * Monthly → +1 calendar month; yearly → +12 calendar months. No-overflow keeps month-ends sane.
     */
    public function periodEnd(CarbonInterface $start, ?string $billingPeriod): CarbonInterface
    {
        return $this->isYearly($billingPeriod)
            ? $start->copy()->addMonthsNoOverflow(12)
            : $start->copy()->addMonthsNoOverflow(1);
    }

    /**
     * The invoice amount for a plan in the given billing period, or null when the plan carries no
     * monthly price (custom / quote-only plans — those never get an auto-generated invoice).
     */
    public function invoiceAmount(Plan $plan, ?string $billingPeriod): ?float
    {
        $monthly = $plan->monthly_price;

        if ($monthly === null) {
            return null;
        }

        $monthly = (float) $monthly;

        return $this->isYearly($billingPeriod)
            ? round($monthly * self::YEARLY_PRICE_MONTHS, 2)
            : round($monthly, 2);
    }
}
