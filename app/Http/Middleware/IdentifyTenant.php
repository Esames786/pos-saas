<?php

namespace App\Http\Middleware;

use App\Models\Master\TenantDomain;
use App\Services\Tenancy\TenancyManager;
use Closure;
use Illuminate\Http\Request;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next)
    {
        $host = $request->getHost();
        $centralDomain = config('tenancy.central_domain');

        if ($host === $centralDomain) {
            return $next($request);
        }

        $domain = TenantDomain::with(['tenant.database'])
            ->where('domain', $host)
            ->where('status', 'active')
            ->first();

        if (!$domain || !$domain->tenant || $domain->tenant->status !== 'active') {
            abort(404, 'Tenant not found or inactive.');
        }

        app(TenancyManager::class)->activate($domain->tenant);

        return $next($request);
    }
}
