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

// PACKAGING-DRYRUN-IMMUTABLE-1 (0.8.1, owner §5) — a Branch Server runtime never writes Laravel's bootstrap caches
// (packages.php, services.php, config/routes/events caches) into its OWN directory: that directory is a signed, immutable
// artifact (a staged version, or a package booted in place by a pre-install dry-run — which is exactly how the 0.8.0
// package grew two unlisted cache files that failed `edge:audit-package`). The caches live under the appliance DATA root
// (<data-root>/logs/runtime-cache/<sha12 of this runtime's path>) instead. This must run BEFORE the application is created
// (the package manifest path is resolved in the constructor) and must be a RELATIVE path: Laravel only treats values that
// start with "/" or "\" as absolute, which no Windows drive path does, so the scratch directory is expressed relative to the
// runtime directory (both live on the same drive on every appliance layout: Program Files + ProgramData, or the LAB tree).
// A Cloud host never sets BINGOO_EDGE_ENV_DIR: nothing changes there. Explicit APP_*_CACHE values always win.
// The ACTIVE runtime (the version the launcher's `current` pointer names) keeps its caches in its own bootstrap/cache as
// before: the installed copy is not the signed package directory, and the clean-machine include-probe proves the active
// runtime executes only files inside itself. Only a NON-active runtime is redirected.
$edgeEnvDirEarly = getenv('BINGOO_EDGE_ENV_DIR');
$edgeIsActiveRuntime = false;
if (is_string($edgeEnvDirEarly) && $edgeEnvDirEarly !== '') {
    $edgePointer = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'current';   // <runtime-root>/versions/<v>/{this dir} → <runtime-root>/current
    $edgeIsActiveRuntime = is_file($edgePointer) && trim((string) @file_get_contents($edgePointer)) === basename(dirname(__DIR__));
}
if (! $edgeIsActiveRuntime && is_string($edgeEnvDirEarly) && $edgeEnvDirEarly !== '' && is_dir($edgeEnvDirEarly)) {
    $edgeRuntimeDir = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));
    $edgeScratch = str_replace('\\', '/', dirname(rtrim($edgeEnvDirEarly, "/\\"))) . '/logs/runtime-cache/' . substr(sha1(strtolower($edgeRuntimeDir)), 0, 12);
    if (! is_dir($edgeScratch)) {
        @mkdir($edgeScratch, 0775, true);
    }
    $edgeScratchReal = is_dir($edgeScratch) ? str_replace('\\', '/', (string) realpath($edgeScratch)) : '';
    $edgeSameDrive = $edgeScratchReal !== '' && strtolower(substr($edgeScratchReal, 0, 2)) === strtolower(substr($edgeRuntimeDir, 0, 2));
    if ($edgeSameDrive && is_writable($edgeScratchReal)) {
        $from = explode('/', trim($edgeRuntimeDir, '/'));
        $to = explode('/', trim($edgeScratchReal, '/'));
        while ($from !== [] && $to !== [] && strtolower($from[0]) === strtolower($to[0])) {
            array_shift($from);
            array_shift($to);
        }
        $edgeRelative = implode('/', array_merge(array_fill(0, count($from), '..'), $to));
        foreach (['APP_PACKAGES_CACHE' => 'packages.php', 'APP_SERVICES_CACHE' => 'services.php', 'APP_CONFIG_CACHE' => 'config.php', 'APP_ROUTES_CACHE' => 'routes-v7.php', 'APP_EVENTS_CACHE' => 'events.php'] as $edgeKey => $edgeFile) {
            if (getenv($edgeKey) === false && ! isset($_SERVER[$edgeKey]) && ! isset($_ENV[$edgeKey])) {
                $edgeValue = $edgeRelative . '/' . $edgeFile;
                putenv($edgeKey . '=' . $edgeValue);
                $_ENV[$edgeKey] = $edgeValue;
                $_SERVER[$edgeKey] = $edgeValue;
            }
        }
    }
    unset($edgeRuntimeDir, $edgeScratch, $edgeScratchReal, $edgeSameDrive, $from, $to, $edgeRelative, $edgeKey, $edgeFile, $edgeValue);
}
unset($edgeEnvDirEarly, $edgeIsActiveRuntime, $edgePointer);

$app = Application::configure(basePath: dirname(__DIR__))
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

        // WEBSITE-I18N-GEO-1 P5: the public site's cookie banner writes and reads this answer in the
        // browser, so it stays plain text (it holds only "v1.a1" / "v1.a0").
        $middleware->encryptCookies(except: [\App\Support\CookieConsent::COOKIE]);

        $middleware->redirectGuestsTo('/login');
        // P4 — on a Branch Server the LAN listener is the loopback TLS gateway (nginx → 127.0.0.1 PHP backends):
        // trust ONLY loopback so X-Forwarded-Proto=https is honoured. A Cloud host never enables this.
        if (env('APP_ROLE') === 'branch_server') {
            $middleware->trustProxies(at: ['127.0.0.1', '::1'], headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO);
        }

        $middleware->alias([
            'central.only' => CentralOnly::class,
            'public.locale' => \App\Http\Middleware\SetPublicLocale::class,
            'public.suggest' => \App\Http\Middleware\SuggestPublicLanguage::class,
            'tenant.only' => TenantOnly::class,
            // ONLINE-ORDERING-1 (Phase 5): client ke apne domain par sirf ordering website.
            'website.host' => \App\Http\Middleware\WebsiteDomainOnly::class,
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

// P4 WINDOWS APPLIANCE — configuration storage OUTSIDE the versioned runtime. The appliance launcher (and the
// serve command for its web children) exports BINGOO_EDGE_ENV_DIR=<data-root>/config; the runtime then loads
// <data-root>/config/appliance.env instead of a .env inside the install dir, so updates never touch secrets and
// uninstall never deletes them silently. A Cloud host never sets the variable: nothing changes there.
$edgeEnvDir = getenv('BINGOO_EDGE_ENV_DIR');
if (is_string($edgeEnvDir) && $edgeEnvDir !== '' && is_dir($edgeEnvDir)) {
    $app->useEnvironmentPath(rtrim($edgeEnvDir, "/\\"));
    $app->loadEnvironmentFrom('appliance.env');
}

return $app;
