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
        // EDGE-RUNTIME-BOUNDARY-1 / EDGE-LOCAL-RUNTIME-1: a Branch Server is a single-purpose
        // appliance, not a multi-tenant host. It never resolves a tenant from the request host. It
        // does, however, RESOLVE the `tenant` connection to its own local Edge database so
        // tenant-scoped models speak to the local MariaDB (the immutable tenant/branch binding itself
        // lives in edge_local_meta). Binding only reconfigures the connection (no socket opened), so
        // /edge/local/health still answers while the appliance is uninitialised. The route boundary
        // then restricts what may run.
        if (\App\Support\EdgeRuntime::isBranchServer()) {
            \App\Support\EdgeLocalDatabase::useAsTenantConnection();

            return $next($request);
        }

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
