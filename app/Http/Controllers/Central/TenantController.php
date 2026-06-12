<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Requests\Central\ProvisionTenantRequest;
use App\Http\Requests\Central\StoreTenantRequest;
use App\Http\Requests\Central\UpdateTenantRequest;
use App\Models\Master\Plan;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDomain;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $query = Tenant::query()
            ->with(['domains', 'database', 'subscription.plan'])
            ->latest();

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('tenant_code', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('owner_email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $tenants = $query->paginate(15)->withQueryString();

        return view('central.tenants.index', compact('tenants'));
    }

    public function create()
    {
        return view('central.tenants.create', [
            'plans' => Plan::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreTenantRequest $request)
    {
        $trialDays = (int) ($request->trial_days ?: 60);

        $tenant = Tenant::create([
            'tenant_code'   => $request->tenant_code,
            'business_name' => $request->business_name,
            'owner_name'    => $request->owner_name,
            'owner_email'   => $request->owner_email,
            'currency_code' => $request->currency_code,
            'status'        => 'pending',
            'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
        ]);

        TenantDomain::create([
            'tenant_id'  => $tenant->id,
            'domain'     => $request->subdomain . '.' . config('tenancy.tenant_base_domain'),
            'is_primary' => true,
            'status'     => 'pending',
        ]);

        if ($request->filled('plan_id')) {
            Subscription::create([
                'tenant_id'    => $tenant->id,
                'plan_id'      => $request->plan_id,
                'status'       => 'trial',
                'trial_ends_at' => $tenant->trial_ends_at,
            ]);
        }

        return redirect('/tenants/' . $tenant->id)
            ->with('status', 'Tenant created. Provision the database to activate.');
    }

    public function show(Tenant $tenant)
    {
        $tenant->load(['domains', 'database', 'subscription.plan']);

        $recentInvoices = $tenant->invoices()
            ->with('plan')
            ->latest()
            ->take(5)
            ->get();

        return view('central.tenants.show', [
            'tenant'         => $tenant,
            'plans'          => Plan::where('is_active', true)->orderBy('name')->get(),
            'recentInvoices' => $recentInvoices,
        ]);
    }

    public function edit(Tenant $tenant)
    {
        $tenant->load(['subscription']);

        return view('central.tenants.edit', [
            'tenant' => $tenant,
            'plans'  => Plan::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $tenant->update([
            'tenant_code'   => $request->tenant_code,
            'business_name' => $request->business_name,
            'owner_name'    => $request->owner_name,
            'owner_email'   => $request->owner_email,
            'currency_code' => $request->currency_code,
            'trial_ends_at' => $request->trial_ends_at,
        ]);

        if ($request->filled('plan_id')) {
            Subscription::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan_id'      => $request->plan_id,
                    'status'       => $tenant->subscription?->status ?: 'trial',
                    'trial_ends_at' => $tenant->trial_ends_at,
                ]
            );
        }

        return redirect('/tenants/' . $tenant->id)
            ->with('status', 'Tenant updated successfully.');
    }

    public function provision(ProvisionTenantRequest $request, Tenant $tenant, TenantProvisioner $provisioner)
    {
        $provisioner->provisionTenant($tenant, $request->owner_password);

        return redirect('/tenants/' . $tenant->id)
            ->with('status', 'Tenant database provisioned and activated successfully.');
    }

    public function activate(Tenant $tenant)
    {
        if (!$tenant->database || $tenant->database->migration_status !== 'completed') {
            return back()->withErrors([
                'tenant' => 'Tenant database must be provisioned before activation.',
            ]);
        }

        $tenant->update([
            'status'       => 'active',
            'activated_at' => $tenant->activated_at ?: now(),
        ]);

        $tenant->domains()->where('is_primary', true)->update(['status' => 'active']);

        return back()->with('status', 'Tenant activated successfully.');
    }

    public function suspend(Tenant $tenant)
    {
        $tenant->update(['status' => 'suspended']);

        return back()->with('status', 'Tenant suspended successfully.');
    }

    public function cancel(Tenant $tenant)
    {
        $tenant->update(['status' => 'cancelled']);

        return back()->with('status', 'Tenant cancelled successfully.');
    }

    public function updateSubscription(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'exists:plans,id'],
            'status' => ['required', Rule::in(['trial', 'active', 'past_due', 'cancelled'])],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_ends_at' => ['nullable', 'date'],
        ]);

        Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $data['plan_id'] ?? null,
                'status' => $data['status'],
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                'current_period_ends_at' => $data['current_period_ends_at'] ?? null,
            ]
        );

        return redirect('/tenants/' . $tenant->id)
            ->with('status', 'Tenant subscription updated successfully.');
    }
}
