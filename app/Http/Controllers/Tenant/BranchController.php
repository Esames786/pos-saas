<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
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
