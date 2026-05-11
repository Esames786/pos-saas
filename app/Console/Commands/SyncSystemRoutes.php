<?php

namespace App\Console\Commands;

use App\Models\Master\RouteCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class SyncSystemRoutes extends Command
{
    protected $signature = 'system:routes-sync';
    protected $description = 'Sync named routes into master route catalog';

    public function handle(): int
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

        $this->info('Routes synced successfully.');

        return self::SUCCESS;
    }
}
