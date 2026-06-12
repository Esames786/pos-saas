<?php

namespace App\Http\Middleware;

use App\Models\Master\Tenant;
use App\Services\Saas\TenantSubscriptionAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantSubscriptionAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // Tenant is bound in the container by TenancyManager::activate()
        // (via IdentifyTenant middleware), NOT on $request->attributes.
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if (!$tenant instanceof Tenant) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        $access = app(TenantSubscriptionAccessService::class)->check($tenant, $routeName);

        if (!$access['allowed']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message'    => $access['message'],
                    'reason'     => $access['reason'],
                    'module_key' => $access['module_key'],
                ], 403);
            }

            return response()
                ->view('tenant.errors.module-disabled', [
                    'message'   => $access['message'],
                    'reason'    => $access['reason'],
                    'moduleKey' => $access['module_key'],
                    'module'    => $access['module'],
                ], 403);
        }

        return $next($request);
    }
}
