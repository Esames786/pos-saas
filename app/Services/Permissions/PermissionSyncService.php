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

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (!$name) {
                continue;
            }

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

    public function moduleKey(string $routeName): string
    {
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
