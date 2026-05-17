<?php

namespace App\Console\Commands;

use App\Services\Permissions\PermissionSyncService;
use Illuminate\Console\Command;

class SyncSystemRoutes extends Command
{
    protected $signature = 'system:routes-sync';

    protected $description = 'Sync named routes into master route catalog';

    public function handle(PermissionSyncService $syncService): int
    {
        $count = $syncService->syncRouteCatalog();

        $this->info("Routes synced successfully. Total: {$count}");

        return self::SUCCESS;
    }
}
