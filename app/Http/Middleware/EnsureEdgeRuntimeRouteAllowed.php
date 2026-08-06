<?php

namespace App\Http\Middleware;

use App\Support\EdgeRouteManifest;
use App\Support\EdgeRuntime;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * EDGE-RUNTIME-BOUNDARY-1 — the request-level route boundary for a Branch Server.
 *
 * On a CLOUD instance this is a pass-through no-op (zero behavior change for the SaaS). On a
 * branch_server instance it is DEFAULT DENY: only routes whose name is on the Edge allowlist
 * (EdgeRouteManifest) may run; everything else is refused with a controlled 404 — so accidentally
 * registering a future cloud-only route does NOT expose it on a branch appliance. This is the first
 * line of defence-in-depth; route registration is the design intent, this middleware enforces it.
 */
class EnsureEdgeRuntimeRouteAllowed
{
    public function handle(Request $request, Closure $next)
    {
        if (EdgeRuntime::isCloud()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (EdgeRouteManifest::isAllowed($routeName)) {
            return $next($request);
        }

        // Controlled refusal — 404 so the appliance does not advertise cloud-only surface.
        throw new NotFoundHttpException('This route is not available on a Bingoo Edge Branch Server.');
    }
}
