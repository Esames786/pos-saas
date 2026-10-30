<?php

namespace Tests\MySql\Support;

use App\Models\Master\Module;
use Database\Seeders\MasterSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * COLD-MASTER-FIXTURES-1 (23 Oct): the master catalog a test reads — modules (MasterSeeder) and route_catalogs
 * (system:routes-sync) — made by the test itself when a brand-new master DB has not got it yet.
 *
 * Before this, three classes passed only when an earlier test had already filled master. On a fresh DB set they failed
 * (8 errors, 12 failures) at the batch base edf958d9 and after it alike. Both steps are idempotent; deploy.sh runs them
 * on every deploy.
 */
trait SeedsMasterCatalog
{
    /** @param list<string> $routes the routes whose module the test attaches to its plan */
    protected function ensureMasterCatalog(array $routes): void
    {
        $m = DB::connection('master');
        $ready = function () use ($m, $routes): bool {
            foreach ($routes as $route) {
                $row = $m->table('route_catalogs')->where('route_name', $route)->first();
                if (! $row || ($row->module_key && ! Module::forRouteModuleKey($row->module_key)->exists())) {
                    return false;
                }
            }

            return true;
        };
        if ($ready()) {
            return;
        }

        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('master');
        (new MasterSeeder)->run();
        Artisan::call('system:routes-sync');
        DB::setDefaultConnection($default);
    }
}
