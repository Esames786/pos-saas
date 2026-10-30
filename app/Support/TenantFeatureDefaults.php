<?php

namespace App\Support;

/**
 * ROLLOUT-1 (25 Oct) — which Master switches a NEW tenant starts with. One policy for self-signup, Master create and
 * the public demos (provision and the nightly reset), so the three cannot drift apart.
 *
 * Screens follow the plan's modules. Business rules follow the primary business, and only where it is known — a
 * signup or a Master-created tenant gets them when its business is first set (TenantBusinessService::changeBusiness),
 * filling only switches it has never set. An existing tenant's switches are never touched by this class.
 */
final class TenantFeatureDefaults
{
    /**
     * Business policy for "whole numbers for counted items in purchasing and stock". A medical store and a mart buy and
     * count whole boxes, bottles and pieces. A restaurant, a caterer or a factory buys some counted items in parts (an
     * opened crate, half a dozen), so it stays off for them. It is never switched on because of the purchasing module.
     */
    private const WHOLE_COUNTED = [Business::PHARMACY, Business::MART];

    /** Packs (box · strip · tablet; carton · piece) where things are sold and stocked in packs. */
    private const PACKS = [Business::PHARMACY, Business::MART];

    /** A carton scanned at the normal counter — a mart's counter; a medical store has its own counter. */
    private const COUNTER_PACKS = [Business::MART];

    private const MEDICAL_COUNTER = [Business::PHARMACY];

    /** The switches that depend on the business — the only ones filled when a business is first set. */
    public const BUSINESS_SWITCHES = [TenantFeatures::WHOLE_COUNTED_STOCK, TenantFeatures::PACKS, TenantFeatures::COUNTER_PACKS, TenantFeatures::MEDICAL_COUNTER];

    /**
     * The switches a new tenant starts with ON (nothing listed starts off). Every switch's needs are on too.
     *
     * Never by default: void_purge (permanent delete) and report_delivery_v2 — each the owner's own choice. A refund
     * without a bill (return_no_bill) starts on only for a demo: a live medical store opts in, and switching on its
     * counter does not authorise refunds nobody can check.
     *
     * @param  list<string>  $modules  the plan's enabled module keys
     * @return array<string, true>
     */
    public static function for(?string $business, array $modules, bool $demo = false): array
    {
        $has = fn (string $m) => in_array($m, $modules, true);
        $on = [];

        if ($has('catalog')) {
            $on[TenantFeatures::PRODUCT_SCREEN_V2] = true;
        }
        if ($has('purchasing')) {
            $on[TenantFeatures::PURCHASING_V2] = true;
            $on[TenantFeatures::PURCHASE_WORKSPACE] = true;
        }

        if (Business::exists($business)) {
            if (in_array($business, self::WHOLE_COUNTED, true) && ($has('inventory') || $has('purchasing'))) {
                $on[TenantFeatures::WHOLE_COUNTED_STOCK] = true;
            }
            if (in_array($business, self::PACKS, true) && $has('inventory')) {
                $on[TenantFeatures::PACKS] = true;
            }
            if (in_array($business, self::COUNTER_PACKS, true) && $has('pos')) {
                $on[TenantFeatures::COUNTER_PACKS] = true;
            }
            if (in_array($business, self::MEDICAL_COUNTER, true) && $has('pos')) {
                $on[TenantFeatures::MEDICAL_COUNTER] = true;
            }
            if ($demo && $business === Business::PHARMACY) {
                $on[TenantFeatures::RETURN_NO_BILL] = true;
            }
        }

        return self::withNeedsMet($on);
    }

    /**
     * The defaults a tenant has never set, added to its switches. A switch already there — on, or explicitly off — is
     * kept as it is. Returns [the switches after, the keys added].
     *
     * @param  array<string, bool>  $current
     * @param  array<string, true>  $defaults
     * @return array{0: array<string, bool>, 1: list<string>}
     */
    public static function fill(array $current, array $defaults): array
    {
        $added = [];
        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $current)) {
                $current[$key] = $value;
                $added[] = $key;
            }
        }

        return [$current, $added];
    }

    /** Drops any switch whose needs are not on (until nothing changes), so a default can never break a dependency. */
    private static function withNeedsMet(array $on): array
    {
        $all = TenantFeatures::all();
        do {
            $dropped = false;
            foreach (array_keys($on) as $key) {
                foreach ($all[$key]['needs'] ?? [] as $need) {
                    if (empty($on[$need])) {
                        unset($on[$key]);
                        $dropped = true;
                        break;
                    }
                }
            }
        } while ($dropped);

        return $on;
    }
}
