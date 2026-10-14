<?php

namespace App\Support;

/**
 * ONLINE-ORDERING-1 — kya is tenant ke PLAN me "Online Ordering" hai?
 *
 * Permission kaafi nahi: deploy Owner ko har tenant.* permission de deta hai, plan kuch bhi ho.
 * Isi liye product/deal form ka "Website price" sirf plan dekh kar dikhta hai. Tenant hi bound
 * na ho (sirf test harness) to jawab "nahi".
 */
final class OnlineOrdering
{
    public const MODULE_KEY = 'online_ordering';

    public static function enabledForTenant(): bool
    {
        if (! app()->bound('tenant')) {
            return false;
        }

        $plan = app('tenant')->subscription?->loadMissing('plan.enabledModules')->plan;

        return $plan !== null && $plan->enabledModules->contains('key', self::MODULE_KEY);
    }
}
