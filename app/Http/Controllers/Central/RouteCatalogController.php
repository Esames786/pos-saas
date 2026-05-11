<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\RouteCatalog;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class RouteCatalogController extends Controller
{
    public function sync()
    {
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (!$name) {
                continue;
            }

            RouteCatalog::updateOrCreate(
                ['route_name' => $name],
                [
                    'uri' => $route->uri(),
                    'method' => implode('|', $route->methods()),
                    'module_key' => Str::before($name, '.'),
                    'action_key' => Str::after($name, '.'),
                    'synced_at' => now(),
                ]
            );
        }

        return back()->with('status', 'Routes synced successfully.');
    }

    public function publish()
    {
        $routes = RouteCatalog::where('is_published', true)->get();

        foreach ($routes as $route) {
            Permission::findOrCreate($route->route_name, 'central');
        }

        return back()->with('status', 'Published routes converted to permissions.');
    }
}
