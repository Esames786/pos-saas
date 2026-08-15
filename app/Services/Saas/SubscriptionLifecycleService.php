<?php

namespace App\Services\Saas;

use App\Models\Master\Subscription;

class SubscriptionLifecycleService
{
    public function __construct(private readonly BillingNotifier $notifier) {}

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

        // Iterate (not a bulk update) so each newly past-due tenant gets a one-time notice.
        $toExpire = (clone $base)
            ->whereHas('tenant', fn ($q) => $q->where('is_demo', false))
            ->with('tenant')
            ->get();

        foreach ($toExpire as $subscription) {
            $subscription->update(['status' => 'past_due']);
            // Notify AFTER the state change is committed — a mail failure never rolls it back.
            $this->notifier->subscriptionPastDue($subscription->fresh());
        }

        return [
            'expired' => $toExpire->count(),
            'demo_skipped' => $demoSkipped,
        ];
    }
}
