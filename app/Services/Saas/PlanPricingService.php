<?php

namespace App\Services\Saas;

use App\Models\Master\Plan;
use App\Models\Master\PlanPrice;
use App\Support\PublicLocale;
use Illuminate\Http\Request;

/**
 * WEBSITE-I18N-GEO-1 P2 — the one place a plan's price is worked out, for every market.
 *
 * Pakistan buys BUNDLE plans: the plan's price, its branches included. Saudi Arabia, the UAE,
 * Qatar and the US buy PER BRANCH: one branch's price × branches, plus extra terminals, less a
 * multi-branch discount. Yearly is always ten months of the monthly total (BillingPeriodResolver's
 * rule). The pricing page, the plan builder, the checkout and — later — the signup and the invoice
 * all read quote(); nothing else does arithmetic on a price, and nothing takes an amount from a
 * browser.
 */
class PlanPricingService
{
    /** Every market: [code => ['currency', 'pricing', 'timezone', 'locale', 'vat', 'name']]. */
    public function markets(): array
    {
        return (array) config('saas.markets', []);
    }

    public function isMarket(?string $code): bool
    {
        return $code !== null && isset($this->markets()[$code]);
    }

    public function market(?string $code): array
    {
        $markets = $this->markets();

        return ($markets[$code] ?? null) ?? $markets[$this->defaultMarket()] ?? ['currency' => 'PKR', 'pricing' => 'bundle'];
    }

    /** Until the visitor's country is known (P4), English opens on Pakistan and Arabic on Saudi Arabia. */
    public function defaultMarket(?string $locale = null): string
    {
        $locale = $locale ?? PublicLocale::current();
        $byLocale = (array) config('saas.market_by_locale', []);

        return $byLocale[$locale] ?? (string) config('saas.default_market', 'pk');
    }

    /** The market a page shows: ?market= (the currency picker) → the remembered choice → the language's. */
    public function resolveMarket(Request $request): string
    {
        foreach ([$request->query('market'), $request->cookie('bingoo_market')] as $candidate) {
            $candidate = is_string($candidate) ? strtolower(trim($candidate)) : null;
            if ($this->isMarket($candidate)) {
                return $candidate;
            }
        }

        return $this->defaultMarket();
    }

    public function price(Plan $plan, string $currency): ?PlanPrice
    {
        $prices = $plan->relationLoaded('prices') ? $plan->prices : $plan->prices()->get();

        return $prices->first(fn (PlanPrice $p) => $p->is_active && $p->currency_code === $currency);
    }

    /** % off for this many branches — config('saas.branch_discounts'), e.g. [3 => 10, 6 => 15]. */
    public function discountPercent(int $branches): int
    {
        $pct = 0;
        foreach ((array) config('saas.branch_discounts', []) as $from => $percent) {
            if ($branches >= (int) $from) {
                $pct = max($pct, (int) $percent);
            }
        }

        return $pct;
    }

    public function maxBranches(): int
    {
        return (int) config('saas.max_self_service_branches', 10);
    }

    /**
     * Everything a page or a signup needs to show or store this choice, or null when the plan has no
     * price in this market (then: Contact Sales). Money is worked in cents.
     *
     * @return array{market:string, currency:string, pricing:string, billing:string, branches:int,
     *   extra_terminals:int, unit:float, extra_terminal_unit:float|null, branches_total:float,
     *   extra_terminals_total:float, subtotal:float, discount_percent:int, discount:float,
     *   monthly_total:float, yearly_total:float, total:float, yearly_saving:float, vat_percent:int|null,
     *   terminals:int|null, users:int|null, contact_sales:bool}|null
     */
    public function quote(Plan $plan, string $marketCode, int $branches = 1, int $extraTerminals = 0, string $billing = 'monthly'): ?array
    {
        $market = $this->market($marketCode);
        $marketCode = $this->isMarket($marketCode) ? $marketCode : $this->defaultMarket();
        $currency = (string) ($market['currency'] ?? 'PKR');
        $price = $this->price($plan, $currency);
        if (! $price) {
            return null;
        }
        $billing = app(BillingPeriodResolver::class)->normalize($billing);
        $perBranch = $price->pricing_model === 'per_branch';

        $feature = fn (string $key) => ($v = $plan->features->firstWhere('feature_key', $key)?->feature_value) === null || $v === '' ? null : (int) $v;
        $contactSales = $perBranch && $branches > $this->maxBranches();
        $branches = $perBranch ? max(1, min($branches, $this->maxBranches())) : (int) ($feature('branch_limit') ?? 1);
        $extraTerminals = $perBranch && $price->extra_terminal_monthly !== null ? max(0, min($extraTerminals, 99)) : 0;

        $unitC = (int) round((float) $price->monthly_price * 100);
        $extraUnitC = $price->extra_terminal_monthly !== null ? (int) round((float) $price->extra_terminal_monthly * 100) : null;
        $branchesC = $perBranch ? $unitC * $branches : $unitC;
        $extraC = $extraUnitC !== null ? $extraUnitC * $extraTerminals : 0;
        $subC = $branchesC + $extraC;
        $pct = $perBranch ? $this->discountPercent($branches) : 0;
        $discC = (int) round($subC * $pct / 100);
        $monthC = $subC - $discC;
        // A bundle's stored yearly price wins (Pakistan's ×10 today); otherwise ten months.
        $yearC = (! $perBranch && $price->yearly_price !== null)
            ? (int) round((float) $price->yearly_price * 100)
            : $monthC * BillingPeriodResolver::YEARLY_PRICE_MONTHS;

        $terminals = $perBranch
            ? ($feature('terminals_per_branch') ?? 1) * $branches + $extraTerminals
            : $feature('terminal_limit');
        $users = $perBranch
            ? ($feature('users_per_branch') !== null ? $feature('users_per_branch') * $branches : null)
            : $feature('user_limit');

        $money = fn (int $cents): float => $cents / 100.0;   // always a float, even for whole amounts

        return [
            'market'                => $marketCode,
            'currency'              => $currency,
            'pricing'               => $price->pricing_model,
            'billing'               => $billing,
            'branches'              => $branches,
            'extra_terminals'       => $extraTerminals,
            'unit'                  => $money($unitC),
            'extra_terminal_unit'   => $extraUnitC !== null ? $money($extraUnitC) : null,
            'branches_total'        => $money($branchesC),
            'extra_terminals_total' => $money($extraC),
            'subtotal'              => $money($subC),
            'discount_percent'      => $pct,
            'discount'              => $money($discC),
            'monthly_total'         => $money($monthC),
            'yearly_total'          => $money($yearC),
            'total'                 => $money($billing === BillingPeriodResolver::YEARLY ? $yearC : $monthC),
            'yearly_saving'         => $money(max(0, $monthC * 12 - $yearC)),
            'vat_percent'           => $market['vat'] ?? null,
            'terminals'             => $terminals,
            'users'                 => $users,
            'contact_sales'         => $contactSales,
        ];
    }
}
