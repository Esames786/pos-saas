<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Edge\BranchOperatingModeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $query = Branch::query()->latest();

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $branches = $query->paginate(15)->withQueryString();

        $usage = app(\App\Services\Saas\TenantSubscriptionAccessService::class)
            ->checkLimit(app('tenant'), 'branches');

        return view('tenant.branches.index', compact('branches', 'usage'));
    }

    public function create()
    {
        return view('tenant.branches.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateBranch($request);

        $limit = app(\App\Services\Saas\TenantSubscriptionAccessService::class)
            ->checkLimit(app('tenant'), 'branches');

        if (!$limit['allowed']) {
            return back()->withInput()->withErrors(['limit' => $limit['message']]);
        }

        Branch::create($data);

        return redirect('/branches')->with('status', 'Branch created successfully.');
    }

    public function edit(Branch $branch)
    {
        return view('tenant.branches.edit', compact('branch'));
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $this->validateBranch($request, $branch);

        $branch->update($data);

        return redirect('/branches')->with('status', 'Branch updated successfully.');
    }

    /**
     * BRANCH-OPERATING-MODE-1: begin Local POS setup (→ pending). Cloud sales
     * stay available; activation happens later via Branch Server pairing/bootstrap
     * readiness (NOT reachable from this UI on purpose — no accidental lock).
     */
    public function requestLocalMode(Branch $branch, BranchOperatingModeService $mode)
    {
        if ($branch->local_edge_status !== 'inactive') {
            return back()->withErrors(['local_mode' => 'Local POS Mode setup is already in progress or active for this branch.']);
        }

        $mode->transition($branch, 'pending', auth('tenant')->id(), 'Owner requested Local POS setup');

        return back()->with('status', 'Local POS Mode setup requested. Cloud sales stay available until the Branch Server is paired and activated.');
    }

    /** Return a pending/suspended branch to Cloud Mode. Active branches must go through the (future) controlled close/reconciliation flow. */
    public function cancelLocalMode(Branch $branch, BranchOperatingModeService $mode)
    {
        if (! in_array($branch->local_edge_status, ['pending', 'suspended'], true)) {
            return back()->withErrors(['local_mode' => 'Only a pending or suspended branch can be returned to Cloud Mode here.']);
        }

        $mode->transition($branch, 'inactive', auth('tenant')->id(), 'Returned to Cloud Mode');

        return back()->with('status', 'Branch returned to Cloud Mode.');
    }

    public function destroy(Branch $branch)
    {
        if ($branch->terminals()->exists()) {
            return back()->withErrors(['branch' => 'Branch has terminals and cannot be deleted.']);
        }

        $branch->delete();

        return back()->with('status', 'Branch deleted successfully.');
    }

    private function validateBranch(Request $request, ?Branch $branch = null): array
    {
        $data = $request->validate([
            'code'                       => ['nullable', 'string', 'max:50', Rule::unique('branches', 'code')->ignore($branch?->id)],
            'name'                       => ['required', 'string', 'max:190'],
            'business_type'              => ['required', Rule::in(['store', 'restaurant', 'hybrid'])],
            'address'                    => ['nullable', 'string'],
            'phone'                      => ['nullable', 'string', 'max:50'],
            'email'                      => ['nullable', 'email', 'max:190'],
            'timezone'                   => ['required', 'string', 'max:100'],
            'tax_registration_no'        => ['nullable', 'string', 'max:100'],
            'is_tax_enabled'             => ['nullable', 'boolean'],
            'show_tax_number_on_invoice' => ['nullable', 'boolean'],
            'allow_negative_stock'       => ['nullable', 'boolean'],
            'receipt_footer'             => ['nullable', 'string'],
            'status'                     => ['required', Rule::in(['active', 'inactive'])],
        ]);

        // Unchecked checkbox is absent from the request; force an explicit value
        // so turning the setting OFF actually persists false.
        $data['allow_negative_stock'] = $request->boolean('allow_negative_stock');

        return $data;
    }
}
