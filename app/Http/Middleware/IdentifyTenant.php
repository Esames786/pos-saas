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

        // ONLINE-ORDERING-1 (Phase 5): client ka website domain bina-www darj hota hai; www wala usi par.
        if (! $domain && str_starts_with($host, 'www.')) {
            $apex = substr($host, 4);
            if (TenantDomain::where('domain', $apex)->where('kind', TenantDomain::KIND_WEBSITE)->where('status', 'active')->exists()) {
                return redirect()->away($request->getScheme().'://'.substr($request->getHttpHost(), 4).$request->getRequestUri(), 301);
            }
        }

        if (!$domain || !$domain->tenant || $domain->tenant->status !== 'active') {
            abort(404, 'Tenant not found or inactive.');
        }

        app(TenancyManager::class)->activate($domain->tenant);

        if ($domain->isWebsite()) {
            // Sirf website ke raaste is host par khulte hain (`website.host`). Aur cookie isi host ki: ye
            // bingoopos.com ka hissa nahi, `SESSION_DOMAIN` wala cookie browser rad kar deta — phir checkout
            // ka CSRF kabhi na chalta.
            $request->attributes->set('tenant_domain_kind', TenantDomain::KIND_WEBSITE);
            config(['session.domain' => null]);

            // UAT jaisa website address jo khud *.bingoopos.com hai (kashiffoodweb.bingoopos.com): tenant ke
            // raaste bhi us host se milte hain, aur login wale raaste routing se pehle login par bhej dete. Yahan,
            // routing se pehle, sirf website: khali address → menu; /order…, /sw.js aur /robots.txt ke siwa sab 404.
            if (str_ends_with($host, '.'.config('tenancy.tenant_base_domain'))) {
                $path = '/'.ltrim($request->path(), '/');
                if ($path === '/') {
                    return redirect('/order');
                }
                if (! preg_match('#^/(order(/.*)?|sw\.js|robots\.txt)$#', $path)) {
                    abort(404);
                }
            }
        }

        return $next($request);
    }
}
