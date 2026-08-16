<?php

use App\Http\Middleware\CentralOnly;
use App\Http\Middleware\EnsureEdgeRuntimeRouteAllowed;
use App\Http\Middleware\EnsureRoutePermission;
use App\Http\Middleware\EnsureTenantSubscriptionAccess;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\PreventDemoMutation;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TenantOnly;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SetLocale::class,
            IdentifyTenant::class,
            // EDGE-RUNTIME-BOUNDARY-1: default-DENY route boundary for branch_server runtime.
            // No-op on cloud. Runs after tenant identification so the matched route is available.
            EnsureEdgeRuntimeRouteAllowed::class,
        ]);

        // IdentifyTenant runs FIRST — before the session ever starts, not merely before auth.
        //
        // It needs nothing but the request host, and everything session-related may need IT: the
        // session guard loads the authenticated user from the TENANT users table, and StartSession
        // saves the session (recording that user) even when an inner middleware threw — the
        // routing pipeline converts inner exceptions to responses, so the save still runs.
        //
        // With IdentifyTenant sorted after StartSession, any exception thrown between the two
        // (live case: an expired login page POSTing with a stale CSRF token → 419 from
        // ValidateCsrfToken) reached the session save with the tenant connection never
        // configured — "SQLSTATE[3D000] No database selected" 500s on /login, five times across
        // 2026-08-10/11. Guarded by TenantMiddlewarePriorityRegressionTest.
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            IdentifyTenant::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            '*/api/print-agent/*',
            '*/api/print-agent/heartbeat',
            // BRANCH-DEVICE-PAIRING-1: central Edge pairing/device API is called by the
            // installer/device (no browser session, no CSRF token).
            'api/edge/*',
        ]);

        $middleware->redirectGuestsTo('/login');

        $middleware->alias([
            'central.only' => CentralOnly::class,
            'tenant.only' => TenantOnly::class,
            'route.permission' => EnsureRoutePermission::class,
            'tenant.subscription.access' => EnsureTenantSubscriptionAccess::class,
            'prevent.demo.mutation' => PreventDemoMutation::class,
            // KASHIF-CATERING-DOUBLE-SUBMIT-1: inert unless a form sends a
            // _submit_token, so applying it to a route group changes nothing
            // until that group's forms opt in.
            'prevent.duplicate.submit' => \App\Http\Middleware\PreventDuplicateSubmission::class,
            'edge.device.auth' => \App\Http\Middleware\AuthenticateEdgeDevice::class,
            'edge.runtime.boundary' => EnsureEdgeRuntimeRouteAllowed::class,
            'edge.auth' => \App\Http\Middleware\EnsureEdgeAuthenticated::class,
            // EDGE-LOCAL-POS-1: branch-bound request guard for the local POS surface.
            'edge.branch' => \App\Http\Middleware\EnsureEdgeBranchBound::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
