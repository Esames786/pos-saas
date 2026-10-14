<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Requests\Central\StoreTenantDomainRequest;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDomain;

class TenantDomainController extends Controller
{
    public function store(StoreTenantDomainRequest $request, Tenant $tenant)
    {
        TenantDomain::create([
            'tenant_id'  => $tenant->id,
            'domain'     => $request->fullDomain(),
            'kind'       => $request->kind(),
            'is_primary' => false,
            'status'     => $tenant->status === 'active' ? 'active' : 'pending',
        ]);

        if ($request->kind() === TenantDomain::KIND_WEBSITE) {
            return back()->with('status', 'Website domain added. Client DNS: A record for @ and www → this server\'s IP (do not change nameservers or MX). SSL is issued once DNS points here.');
        }

        return back()->with('status', 'Domain added successfully.');
    }

    public function makePrimary(TenantDomain $domain)
    {
        // ONLINE-ORDERING-1 (Phase 5): primary domain se POS/login ke links bante hain — website domain par wo nahi khulte.
        if ($domain->isWebsite()) {
            return back()->withErrors(['domain' => 'A website domain cannot be the primary domain (it opens only the ordering website).']);
        }

        TenantDomain::where('tenant_id', $domain->tenant_id)->update(['is_primary' => false]);

        $domain->update(['is_primary' => true, 'status' => 'active']);

        return back()->with('status', 'Primary domain updated successfully.');
    }

    public function activate(TenantDomain $domain)
    {
        $domain->update(['status' => 'active']);

        return back()->with('status', 'Domain activated successfully.');
    }

    public function deactivate(TenantDomain $domain)
    {
        $domain->update(['status' => 'inactive']);

        return back()->with('status', 'Domain deactivated successfully.');
    }

    public function destroy(TenantDomain $domain)
    {
        if ($domain->is_primary) {
            return back()->withErrors(['domain' => 'Primary domain cannot be deleted.']);
        }

        $domain->delete();

        return back()->with('status', 'Domain deleted successfully.');
    }
}
