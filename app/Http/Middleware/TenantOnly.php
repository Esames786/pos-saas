<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TenantOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (!app()->bound('tenant')) {
            abort(404);
        }

        return $next($request);
    }
}
