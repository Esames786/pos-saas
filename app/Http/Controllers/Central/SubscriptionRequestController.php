<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\SubscriptionChangeRequest;
use App\Models\Master\Tenant;
use App\Services\Saas\SubscriptionChangeRequestService;
use Illuminate\Http\Request;
use RuntimeException;

class SubscriptionRequestController extends Controller
{
    public function __construct(private readonly SubscriptionChangeRequestService $changeRequests) {}

    public function index(Request $request)
    {
        $query = SubscriptionChangeRequest::with(['tenant', 'currentPlan', 'requestedPlan', 'invoice'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', (int) $request->tenant_id);
        }

        return view('central.subscription-requests.index', [
            'requests' => $query->paginate(20)->withQueryString(),
            'tenants'  => Tenant::orderBy('business_name')->get(),
        ]);
    }

    public function show(SubscriptionChangeRequest $subscriptionRequest)
    {
        $subscriptionRequest->load([
            'tenant.subscription.plan',
            'currentPlan',
            'requestedPlan',
            'invoice.payments',
            'approvedBy',
            'rejectedBy',
        ]);

        return view('central.subscription-requests.show', [
            'changeRequest' => $subscriptionRequest,
        ]);
    }

    public function approve(Request $request, SubscriptionChangeRequest $subscriptionRequest)
    {
        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->changeRequests->approveRequest($subscriptionRequest, $data['admin_notes'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return back()->with('status', 'Request approved. An upgrade invoice has been created.');
    }

    public function reject(Request $request, SubscriptionChangeRequest $subscriptionRequest)
    {
        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->changeRequests->rejectRequest($subscriptionRequest, $data['admin_notes'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['reject' => $e->getMessage()]);
        }

        return back()->with('status', 'Request rejected.');
    }
}
