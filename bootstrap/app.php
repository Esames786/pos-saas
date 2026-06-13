<?php

use App\Http\Middleware\CentralOnly;
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
        ]);

        // IdentifyTenant must run before Authenticate (auth:*) so the tenant
        // DB connection is configured before the session guard tries to load
        // the authenticated user. Without this, auth fires first (priority 6)
        // while IdentifyTenant has no priority and sorts after it.
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            IdentifyTenant::class,
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
        ]);

        $middleware->redirectGuestsTo('/login');

        $middleware->alias([
            'central.only' => CentralOnly::class,
            'tenant.only' => TenantOnly::class,
            'route.permission' => EnsureRoutePermission::class,
            'tenant.subscription.access' => EnsureTenantSubscriptionAccess::class,
            'prevent.demo.mutation' => PreventDemoMutation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
