<?php

namespace App\Services\Permissions;

use App\Models\Master\RouteCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class PermissionSyncService
{
    public function syncRouteCatalog(): int
    {
        $count = 0;
        $routeNames = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            // Laravel assigns temporary generated::* names to unnamed routes
            // while building the route cache. They are not stable permissions.
            if (!$name || str_starts_with($name, 'generated::')) {
                continue;
            }

            $routeNames[] = $name;

            RouteCatalog::updateOrCreate(
                ['route_name' => $name],
                [
                    'uri'        => $route->uri(),
                    'method'     => implode('|', $route->methods()),
                    'module_key' => $this->moduleKey($name),
                    'action_key' => $this->actionKey($name),
                    'synced_at'  => now(),
                ]
            );

            $count++;
        }

        // Keep the catalog aligned with the current application. Otherwise
        // deleted routes remain publishable as stale permissions forever.
        if ($routeNames !== []) {
            $staleRouteNames = RouteCatalog::query()
                ->pluck('route_name')
                ->diff($routeNames)
                ->values();

            if ($staleRouteNames->isNotEmpty()) {
                RouteCatalog::query()
                    ->whereIn('route_name', $staleRouteNames)
                    ->delete();
            }
        }

        return $count;
    }

    public function syncCentralPermissions(): int
    {
        $routes = RouteCatalog::query()
            ->where('is_published', true)
            ->where('route_name', 'like', 'central.%')
            ->get();

        $count = 0;

        foreach ($routes as $route) {
            Permission::findOrCreate($route->route_name, 'central');
            $count++;
        }

        return $count;
    }

    public function syncTenantPermissions(): int
    {
        $routes = RouteCatalog::query()
            ->where('is_published', true)
            ->where('route_name', 'like', 'tenant.%')
            ->get();

        $count = 0;

        foreach ($routes as $route) {
            Permission::findOrCreate($route->route_name, 'tenant');
            $count++;
        }

        return $count;
    }

    public function tenantPermissionGroups(): Collection
    {
        return Permission::query()
            ->where('guard_name', 'tenant')
            ->orderBy('name')
            ->get()
            ->groupBy(function (Permission $permission) {
                return $this->moduleKey($permission->name);
            });
    }

    /**
     * Exact route-name → module_key overrides. These win over the default
     * 2-segment derivation so a single route can belong to a different module
     * than its name prefix implies.
     */
    private const MODULE_KEY_OVERRIDES = [
        // Receivables aging is a finance/accounting report, not a generic
        // reports-module report — gate it behind the finance module (FIN-6A).
        'tenant.reports.sales.receivables' => 'tenant.finance',
    ];

    public function moduleKey(string $routeName): string
    {
        if (isset(self::MODULE_KEY_OVERRIDES[$routeName])) {
            return self::MODULE_KEY_OVERRIDES[$routeName];
        }

        $parts = explode('.', $routeName);

        return count($parts) >= 2
            ? $parts[0] . '.' . $parts[1]
            : Str::before($routeName, '.');
    }

    public function actionKey(string $routeName): string
    {
        $parts = explode('.', $routeName);

        return count($parts) >= 3
            ? implode('.', array_slice($parts, 2))
            : Str::after($routeName, '.');
    }
}
