<?php

namespace App\Services\Saas;

use App\Models\Master\Subscription;
use App\Models\Master\SubscriptionInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CLOUD-BILLING-1B — the authoritative, idempotent transition from `trial` to `active`.
 *
 * A trial subscription becomes active exactly when BOTH are true:
 *   • the promised trial has ended (trial_ends_at <= now), and
 *   • its first subscription invoice is fully paid.
 *
 * This is deliberately NOT a Blade/runtime condition — it is one service method, driven daily by
 * saas:process-trial-transitions AND reused for late payments. Idempotent: it only ever acts on a
 * subscription still in `trial`, so a repeated run activates nothing twice, extends no period,
 * duplicates no invoice. Demo tenants are never transitioned (they self-renew nightly).
 */
class SubscriptionTrialTransitionService
{
    public function __construct(private readonly BillingNotifier $notifier) {}

    /**
     * Activate every trial whose promised window has closed and whose first invoice is paid.
     *
     * @return array{activated:int, waiting_unpaid:int, demo_skipped:int}
     */
    public function processDueTrialTransitions(?Carbon $now = null): array
    {
        $now ??= now();

        $due = Subscription::query()
            ->where('status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now)
            ->with('tenant')
            ->get();

        $activated = 0;
        $waitingUnpaid = 0;
        $demoSkipped = 0;

        foreach ($due as $subscription) {
            if ($subscription->tenant?->is_demo) {
                $demoSkipped++;

                continue;
            }

            $invoice = $this->firstInvoiceFor($subscription);

            if (! $invoice || $invoice->status !== 'paid') {
                $waitingUnpaid++;

                continue;
            }

            $this->activateFromInvoice($subscription, $invoice);
            // Notify AFTER the activation is committed — a mail failure never rolls it back.
            $this->notifier->subscriptionActivated($subscription->fresh());
            $activated++;
        }

        return [
            'activated' => $activated,
            'waiting_unpaid' => $waitingUnpaid,
            'demo_skipped' => $demoSkipped,
        ];
    }

    /**
     * Send a one-time "your trial is ending" reminder for each non-demo trial whose window closes
     * within $daysAhead days. Idempotent per subscription (the notifier claims once).
     *
     * @return int number of reminders sent this run
     */
    public function notifyTrialsEndingWithin(int $daysAhead = 3, ?Carbon $now = null): int
    {
        $now ??= now();

        $ending = Subscription::query()
            ->where('status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$now, $now->copy()->addDays($daysAhead)])
            ->with('tenant')
            ->get();

        $sent = 0;
        foreach ($ending as $subscription) {
            if ($subscription->tenant?->is_demo) {
                continue;
            }
            if ($this->notifier->trialEnding($subscription)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Deterministically flip a subscription to active on the given (paid) invoice's period.
     * Idempotent: only a trial subscription is moved; the paid period start (invoice.period_start =
     * trial end) is fixed, so a late payment cannot pull the period earlier.
     */
    public function activateFromInvoice(Subscription $subscription, SubscriptionInvoice $invoice): void
    {
        if ($subscription->status !== 'trial') {
            return;
        }

        DB::connection('master')->transaction(function () use ($subscription, $invoice) {
            $subscription->update([
                'status' => 'active',
                'plan_id' => $invoice->plan_id ?: $subscription->plan_id,
                'current_period_ends_at' => $invoice->period_end ?: $subscription->current_period_ends_at,
            ]);
        });
    }

    private function firstInvoiceFor(Subscription $subscription): ?SubscriptionInvoice
    {
        return SubscriptionInvoice::where('origin_key', 'signup:'.$subscription->id)->first();
    }
}
