<?php

namespace App\Http\Middleware;

use App\Models\Master\TenantDomain;
use App\Services\Tenancy\TenancyManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next)
    {
        // Each PHP-FPM worker may be reused across requests. A previous request
        // that activated a tenant will have set the default connection to 'tenant'.
        // Reset to master before looking up the tenant domain.
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));

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
