<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * KASHIF-CATERING-DOUBLE-SUBMIT-1 — one press, one record.
 *
 * Kashif's live data showed four bookings created inside two seconds — three of
 * them in the same second, same customer, same venue. That is one form
 * submitted repeatedly, and on the Record Advance screen the same thing posts
 * real money to the general ledger more than once.
 *
 * A disabled button is not enough. It does nothing for a refresh, a back button,
 * a double-tap on a slow connection, or a request replayed outside the browser.
 * So the guard is here, on the server.
 *
 * How it works: a form renders a one-time token. The first request to arrive
 * claims it atomically; any request carrying a token already claimed is turned
 * away without reaching the controller. Cache::add is a single atomic operation,
 * so two simultaneous requests cannot both win.
 *
 * Deliberately scoped: only requests that actually carry a token are checked, so
 * adding this middleware changes nothing anywhere until a form opts in. Keys are
 * tenant-prefixed — two tenants must never share a claim.
 */
class PreventDuplicateSubmission
{
    /** Long enough to cover a slow double-tap, short enough not to block a genuine retry. */
    private const TTL_SECONDS = 600;

    public function handle(Request $request, Closure $next)
    {
        $token = $request->input('_submit_token');

        // No token means the form has not opted in. Behave exactly as before.
        if (! $token || ! $request->isMethod('POST')) {
            return $next($request);
        }

        $key = $this->cacheKey((string) $token);

        // Atomic: add() returns false if the key already exists, so the second
        // request through this line loses, whichever order they arrive in.
        if (! Cache::add($key, true, self::TTL_SECONDS)) {
            return back()->with('status',
                'That was already submitted — nothing was created twice. '
                .'Refresh the page if you do not see the result.');
        }

        $response = $next($request);

        // A failed request should be retryable: if the controller rejected the
        // input, release the claim so a corrected resubmit is not blocked.
        if ($this->failed($response)) {
            Cache::forget($key);
        }

        return $response;
    }

    private function failed($response): bool
    {
        if (! method_exists($response, 'getSession') || ! $response->getSession()) {
            return false;
        }

        return $response->getSession()->has('errors');
    }

    /** Tenant-prefixed so a token claimed by one tenant never blocks another. */
    private function cacheKey(string $token): string
    {
        $tenant = 'global';

        try {
            $tenant = app()->bound('tenant') ? (string) app('tenant')->tenant_code : 'global';
        } catch (\Throwable) {
            // no bound tenant — fall through to the global namespace
        }

        return 'submit-token:'.$tenant.':'.sha1($token);
    }
}
