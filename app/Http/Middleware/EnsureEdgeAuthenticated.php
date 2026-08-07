<?php

namespace App\Http\Middleware;

use App\Support\EdgeRuntime;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * EDGE-LOCAL-AUTH-1 (Section 9) — guard for Branch Server routes that require a local session. Only
 * exists on a branch_server; redirects an unauthenticated request to the local login. Uses the same
 * `tenant` session guard the rest of the app uses.
 */
class EnsureEdgeAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new NotFoundHttpException();
        }
        if (! auth('tenant')->check()) {
            return redirect('/edge/local/login');
        }

        return $next($request);
    }
}
