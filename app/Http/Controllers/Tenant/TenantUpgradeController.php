<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Master\Plan;
use App\Models\Master\SubscriptionChangeRequest;
use App\Services\Saas\SubscriptionChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class TenantUpgradeController extends Controller
{
    public function __construct(private readonly SubscriptionChangeRequestService $changeRequests) {}

    public function create()
    {
        $tenant = app('tenant');
        $tenant->loadMissing('subscription.plan');

        $currentPlan = $tenant->subscription?->plan;

        // Only higher-priced active plans are eligible upgrade targets.
        $plans = Plan::where('is_active', true)
            ->when($currentPlan, fn ($q) => $q->where('price', '>', (float) $currentPlan->price))
            ->orderBy('price')
            ->get();

        $openRequest = SubscriptionChangeRequest::where('tenant_id', $tenant->id)
            ->whereIn('status', ['pending', 'approved', 'invoiced'])
            ->latest()
            ->first();

        return view('tenant.billing.upgrade', [
            'tenant'      => $tenant,
            'currentPlan' => $currentPlan,
            'plans'       => $plans,
            'openRequest' => $openRequest,
        ]);
    }

    public function store(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'requested_plan_id' => ['required', 'integer', 'exists:plans,id'],
            'customer_notes'    => ['nullable', 'string', 'max:2000'],
        ]);

        $plan = Plan::findOrFail($data['requested_plan_id']);

        try {
            $changeRequest = $this->changeRequests->createUpgradeRequest(
                $tenant,
                $plan,
                Auth::guard('tenant')->id(),
                $data['customer_notes'] ?? null
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['requested_plan_id' => $e->getMessage()]);
        }

        return redirect(url('/billing/upgrade/' . $changeRequest->id))
            ->with('status', 'Upgrade request submitted. Our team will review it shortly.');
    }

    public function show(SubscriptionChangeRequest $requestModel)
    {
        $this->ensureTenantRequest($requestModel);

        $requestModel->load(['currentPlan', 'requestedPlan', 'invoice']);

        return view('tenant.billing.upgrade-request-show', [
            'changeRequest' => $requestModel,
        ]);
    }

    public function cancel(SubscriptionChangeRequest $requestModel)
    {
        $this->ensureTenantRequest($requestModel);

        try {
            $this->changeRequests->cancelRequest($requestModel);
        } catch (RuntimeException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect(url('/billing/upgrade/' . $requestModel->id))
            ->with('status', 'Upgrade request cancelled.');
    }

    private function ensureTenantRequest(SubscriptionChangeRequest $requestModel): void
    {
        abort_unless((int) $requestModel->tenant_id === (int) app('tenant')->id, 404);
    }
}
