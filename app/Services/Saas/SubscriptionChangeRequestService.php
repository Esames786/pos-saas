<?php

namespace App\Services\Saas;

use App\Models\Master\Plan;
use App\Models\Master\SubscriptionChangeRequest;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubscriptionChangeRequestService
{
    public function __construct(
        private SubscriptionBillingService $billing
    ) {
    }

    /**
     * Tenant-initiated plan upgrade request.
     */
    public function createUpgradeRequest(Tenant $tenant, Plan $requestedPlan, ?int $tenantUserId, ?string $notes = null): SubscriptionChangeRequest
    {
        $subscription = $tenant->subscription;
        $currentPlan  = $subscription?->plan;

        if (!$subscription) {
            throw new RuntimeException('No subscription is attached to this tenant.');
        }

        if (!$requestedPlan->is_active) {
            throw new RuntimeException('The requested plan is not available.');
        }

        if ((int) $requestedPlan->id === (int) $subscription->plan_id) {
            throw new RuntimeException('You are already on this plan.');
        }

        // Upgrade only: requested plan must cost more than the current plan.
        if ($currentPlan && (float) $requestedPlan->price <= (float) $currentPlan->price) {
            throw new RuntimeException('Only upgrades to a higher plan are supported at this time.');
        }

        // Block stacking duplicate open requests.
        $openExists = $tenant->changeRequests()
            ->whereIn('status', ['pending', 'approved', 'invoiced'])
            ->exists();

        if ($openExists) {
            throw new RuntimeException('You already have an upgrade request in progress.');
        }

        return DB::connection('master')->transaction(function () use ($tenant, $subscription, $currentPlan, $requestedPlan, $tenantUserId, $notes) {
            return SubscriptionChangeRequest::create([
                'tenant_id'            => $tenant->id,
                'subscription_id'      => $subscription->id,
                'current_plan_id'      => $currentPlan?->id,
                'requested_plan_id'    => $requestedPlan->id,
                'type'                 => 'upgrade',
                'status'               => 'pending',
                'requested_by_user_id' => $tenantUserId,
                'customer_notes'       => $notes,
            ]);
        });
    }

    /**
     * Central approval: creates an upgrade invoice for the requested plan and
     * links it to the request. Payment of that invoice will upgrade the plan
     * via the existing 14C billing pipeline.
     */
    public function approveRequest(SubscriptionChangeRequest $request, ?string $adminNotes = null): SubscriptionChangeRequest
    {
        if (!$request->isPending()) {
            throw new RuntimeException('Only pending requests can be approved.');
        }

        $tenant        = $request->tenant;
        $requestedPlan = $request->requestedPlan;

        if (!$tenant || !$requestedPlan) {
            throw new RuntimeException('Request is missing its tenant or requested plan.');
        }

        return DB::connection('master')->transaction(function () use ($request, $tenant, $requestedPlan, $adminNotes) {
            $price = round((float) $requestedPlan->price, 2);

            $invoice = $this->billing->createInvoice($tenant, [
                'plan_id'       => $requestedPlan->id,
                'invoice_type'  => 'upgrade',
                'status'        => 'issued',
                'currency_code' => $requestedPlan->currency_code ?: ($tenant->currency_code ?? 'PKR'),
                'subtotal'      => $price,
                'notes'         => 'Plan upgrade to ' . $requestedPlan->name
                    . ' (change request #' . $request->id . ').',
            ]);

            $request->update([
                'status'             => 'invoiced',
                'related_invoice_id' => $invoice->id,
                'admin_notes'        => $adminNotes ?: $request->admin_notes,
                'approved_by_user_id' => Auth::guard('central')->id(),
                'approved_at'        => now(),
            ]);

            return $request->fresh();
        });
    }

    public function rejectRequest(SubscriptionChangeRequest $request, ?string $adminNotes = null): SubscriptionChangeRequest
    {
        if (!$request->isPending()) {
            throw new RuntimeException('Only pending requests can be rejected.');
        }

        $request->update([
            'status'             => 'rejected',
            'admin_notes'        => $adminNotes ?: $request->admin_notes,
            'rejected_by_user_id' => Auth::guard('central')->id(),
            'rejected_at'        => now(),
        ]);

        return $request->fresh();
    }

    /**
     * Tenant cancels their own request while it is still pending.
     */
    public function cancelRequest(SubscriptionChangeRequest $request): SubscriptionChangeRequest
    {
        if (!$request->isPending()) {
            throw new RuntimeException('Only pending requests can be cancelled.');
        }

        $request->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return $request->fresh();
    }

    /**
     * Called from the billing pipeline when an invoice is fully paid.
     * Flips any related invoiced request to paid (subscription plan is
     * already upgraded by activateSubscriptionFromPaidInvoice).
     */
    public function markInvoiceRequestPaid(SubscriptionInvoice $invoice): void
    {
        SubscriptionChangeRequest::where('related_invoice_id', $invoice->id)
            ->where('status', 'invoiced')
            ->get()
            ->each(function (SubscriptionChangeRequest $request) {
                $request->update(['status' => 'paid']);
            });
    }
}
