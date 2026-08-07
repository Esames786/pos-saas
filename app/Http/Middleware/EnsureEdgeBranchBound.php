<?php

namespace App\Http\Middleware;

use App\Exceptions\EdgeNotBoundException;
use App\Services\Edge\EdgeBranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section R) — guard any Edge endpoint that REQUIRES an imported branch
 * context. It must NOT be attached to /health (which must answer while uninitialised); use it only
 * for routes that operate on the bound branch.
 *
 * STRICT path: no binding -> controlled refusal; a request naming a different tenant/branch than the
 * bound one -> refusal. The bound branch is the sole source of truth — an arbitrary request branch_id
 * is never trusted. A genuine local DB failure is NOT masked as "not bound" (it propagates).
 */
class EnsureEdgeBranchBound
{
    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            $bound = $this->context->requireCurrent(); // EdgeNotBoundException if uninitialised; DB errors propagate
        } catch (EdgeNotBoundException $e) {
            throw new NotFoundHttpException('This Branch Server is not bound to a branch yet.');
        }

        // If the request names a branch/tenant, it must match the bound one (never trust request input).
        if ($request->has('branch_id') && (int) $request->input('branch_id') !== (int) $bound->branch_id) {
            abort(403, 'This Branch Server can only operate on its own branch.');
        }
        if ($request->has('tenant_id') && (int) $request->input('tenant_id') !== (int) $bound->tenant_id) {
            abort(403, 'This Branch Server is bound to a different tenant.');
        }

        return $next($request);
    }
}
