<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRoutePermission
{
    public function handle(Request $request, Closure $next)
    {
        $routeName = optional($request->route())->getName();

        if (!$routeName) {
            return $next($request);
        }

        // Cached routes (route:cache) get a framework-assigned name for every UNNAMED route,
        // e.g. "generated::2Vj7CTuCvhWqDX6n" for the bare "/" redirect closure. That is not a real
        // permission — an unnamed route requires none (it passes above when routes are uncached), so
        // a cached one must behave identically. Without this, can("generated::…") is always false and
        // every unnamed route (the root "/") returns 403 as soon as routes are cached.
        if (str_starts_with($routeName, 'generated::')) {
            return $next($request);
        }

        $allowedPrefixes = [
            'central.login',
            'central.logout',
            'central.password',
            'central.locale',
            'tenant.login',
            'tenant.logout',
            'tenant.password',
            'tenant.locale',
            'tenant.api.print-agent',
            'tenant.api.pos',
            // QUICK-REPORT-SEND-1: the modal endpoints gate on the single synthetic permission
            // `tenant.pos.quick-report-send` in the controller, not on per-route names.
            'tenant.pos.quick-report',
            'tenant.api.catalog',
            'tenant.api.kitchen-display',
            // Read-only server clock the POS/report pages poll on every screen; behind auth:tenant,
            // needs no distinct permission (was 403ing for non-Owner roles that lack it).
            'tenant.api.server-time',
            'tenant.printing.documents',
            'tenant.printing.jobs',
            'tenant.printing.layouts.preview',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return $next($request);
            }
        }

        $guard = app()->bound('tenant') ? 'tenant' : 'central';
        $user = auth($guard)->user();

        if (!$user) {
            return redirect('/login');
        }

        if (!$user->can($routeName)) {
            abort(403, 'Permission denied.');
        }

        return $next($request);
    }
}
