<?php

namespace App\Services\Saas;

use App\Models\Master\Subscription;

class SubscriptionLifecycleService
{
    /**
     * Flip active subscriptions whose billing period has ended to past_due.
     * Trials are NOT touched (runtime access check already denies expired trials);
     * cancelled / past_due / active-with-null-period_end are left unchanged.
     * Demo tenants (is_demo = true) are NEVER expired — they self-renew via the
     * nightly demo reset and must stay open for public /demos visitors (15D-8).
     */
    public function markExpiredSubscriptionsPastDue(): array
    {
        $base = Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<', now());

        // Count demo subscriptions that match the expiry window but are intentionally skipped.
        $demoSkipped = (clone $base)
            ->whereHas('tenant', fn ($q) => $q->where('is_demo', true))
            ->count();

        $expired = $base
            ->whereHas('tenant', fn ($q) => $q->where('is_demo', false))
            ->update([
                'status' => 'past_due',
            ]);

        return [
            'expired'      => $expired,
            'demo_skipped' => $demoSkipped,
        ];
    }
}
