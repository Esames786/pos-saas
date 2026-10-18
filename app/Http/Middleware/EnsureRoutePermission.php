<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRoutePermission
{
    /**
     * Route name → the permission that ACTUALLY gates it, for routes that belong to a workflow another route's
     * permission already owns (EDGE PHASE 4 F2). Every other route is gated by its own name.
     *
     * `tenant.pos.customers.addresses.store` (the shared POS quick-add modal's "Save address") never had a permission
     * of its own that a tenant could grant — the provisioner and the migrations create none, so a cashier got
     * 403 "Permission denied." on the ONLINE modal while the Edge twin (EdgeLocalCustomerController::PERMISSION) was
     * already gated by quick-store. Adding an address to a customer's book is part of the one "Add Customer"
     * workflow, so it is gated by the SAME permission as quick-store on both runtimes — no new permission, no grant on
     * any tenant: a quick-store holder gains add-address. A tenant that DID publish the route name through the route
     * catalog and granted it explicitly keeps working: the route's own name still passes (any-of), it is just no
     * longer required.
     *
     * tests/Feature/Edge/EdgePermissionCatalogTest asserts each alias target is a catalogued POS permission and each
     * alias source is a real routes/tenant.php name; tests/Feature/Pos/OnlineAddAddressPermissionAliasTest and
     * tests/MySql/EdgeCustomerOnlineAddAddressPermissionHttpMySqlTest prove the gate.
     *
     * @var array<string, string>
     */
    public const ROUTE_PERMISSION_ALIASES = [
        'tenant.pos.customers.addresses.store' => 'tenant.pos.customers.quick-store',
    ];

    /**
     * @return list<string> the permission names any ONE of which lets `$routeName` through (the alias first, then
     *                      the route's own name; a route without an alias is exactly its own name).
     */
    public static function permissionsFor(string $routeName): array
    {
        return array_values(array_unique([self::ROUTE_PERMISSION_ALIASES[$routeName] ?? $routeName, $routeName]));
    }

    public function handle(Request $request, Closure $next)
    {
        $routeName = optional($request->route())->getName();

        if (!$routeName) {
            return $next($request);
        }

        // Cached routes (route:cache) get a framework-assigned name for every UNNAMED route,
        // e.g. "generated::2Vj7CTuCvhWqDX6n" for the bare "/" redirect closure. That is not a real
        // permission — an unnamed route requires none (it passes above when routes are uncached), so
        // a cached one must behave identically. Without this, can("generated::…") is always false and
        // every unnamed route (the root "/") returns 403 as soon as routes are cached.
        if (str_starts_with($routeName, 'generated::')) {
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
            'tenant.api.pos',
            // QUICK-REPORT-SEND-1: the modal endpoints gate on the single synthetic permission
            // `tenant.pos.quick-report-send` in the controller, not on per-route names.
            'tenant.pos.quick-report',
            // TABLE-RESERVATION-1: reserve/unreserve/details gate in-controller on the existing
            // table-open permission (any dine-in operator), not on new per-route names.
            'tenant.restaurant.tables.reserve',
            'tenant.restaurant.tables.unreserve',
            'tenant.restaurant.tables.reservation',
            'tenant.api.catalog',
            'tenant.api.kitchen-display',
            // Read-only server clock the POS/report pages poll on every screen; behind auth:tenant,
            // needs no distinct permission (was 403ing for non-Owner roles that lack it).
            'tenant.api.server-time',
            'tenant.printing.documents',
            'tenant.printing.jobs',
            'tenant.printing.layouts.preview',
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

        foreach (self::permissionsFor($routeName) as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        abort(403, 'Permission denied.');
    }
}
