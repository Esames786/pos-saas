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
