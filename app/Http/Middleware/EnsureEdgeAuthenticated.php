<?php

namespace App\Http\Middleware;

use App\Models\Edge\EdgeLocalUserCredential;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBranchContext;
use App\Support\EdgeRuntime;
use App\Support\EdgeUserAuthz;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * EDGE-LOCAL-AUTH-1 / EDGE-LOCAL-POS-1 (slice 1.1) — guard for Branch Server routes that require a
 * CURRENT local session. Only exists on a branch_server; redirects an unauthenticated request to the
 * local login. Uses the same `tenant` session guard the rest of the app uses.
 *
 * SESSION FRESHNESS (slice 1.1): `auth('tenant')->check()` alone is not enough for operational
 * mutations — a cashier may have been disabled, made Edge-ineligible, removed from the bound branch,
 * had their local credential disabled, or belong to a superseded activation epoch while an old session
 * still exists. Every protected request therefore re-establishes a CURRENT principal: bound appliance,
 * fresh active + Edge-eligible + branch-authorized user, and an ACTIVE local credential matching the
 * bound branch + current activation_epoch. A stale session is logged out and fails closed. (This does
 * NOT re-verify the password — the credential VERIFIER runs only at login; this is row-state freshness.)
 */
class EnsureEdgeAuthenticated
{
    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new NotFoundHttpException();
        }
        if (! auth('tenant')->check()) {
            return $this->refuse($request);
        }

        // ── freshness: the session must map to a CURRENTLY valid local principal ──
        $meta = $this->context->tryCurrent();
        $user = User::on('tenant')->find((int) auth('tenant')->id()); // fresh row, never the cached session model
        $fresh = false;
        if ($meta !== null && $user !== null
            && EdgeUserAuthz::isActive($user)
            && EdgeUserAuthz::isEdgeLoginEligible($user)
            && EdgeUserAuthz::mayOperateBranch($user, (int) $meta->branch_id)) {
            $cred = EdgeLocalUserCredential::where('user_id', $user->id)->first();
            $fresh = $cred !== null
                && $cred->isActive()
                && (int) $cred->branch_id === (int) $meta->branch_id
                && (int) $cred->activation_epoch === (int) $meta->activation_epoch;
        }

        if (! $fresh) {
            // stale local session — invalidate it and fail closed.
            auth('tenant')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->refuse($request);
        }

        return $next($request);
    }

    private function refuse(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect('/edge/local/login');
    }
}
