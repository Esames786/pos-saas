<?php

namespace App\Services\Saas;

use App\Models\Master\Subscription;

class SubscriptionLifecycleService
{
    /**
     * Flip active subscriptions whose billing period has ended to past_due.
     * Trials are NOT touched (runtime access check already denies expired trials);
     * cancelled / past_due / active-with-null-period_end are left unchanged.
     */
    public function markExpiredSubscriptionsPastDue(): array
    {
        $expired = Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<', now())
            ->update([
                'status' => 'past_due',
            ]);

        return [
            'expired' => $expired,
        ];
    }
}
