<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeBackupService;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * OFFLINE EDGE PRODUCTIZATION — create an encrypted local appliance backup.
 *
 *   php artisan edge:local:backup [--json]
 *
 * Branch Server only, CLI-allowlisted. A read-only consistent snapshot — it never blocks a sale.
 */
class EdgeLocalBackupCommand extends Command
{
    protected $signature = 'edge:local:backup {--json : emit the backup manifest as JSON}';

    protected $description = 'Create an encrypted, integrity-verified backup of the appliance local state.';

    public function handle(EdgeBackupService $backups): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:backup only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }

        // Boot ordering: tolerate MariaDB not being ready yet (bounded wait; the task restarts otherwise).
        if (! \App\Services\Edge\EdgeWorkerBootstrap::awaitDatabase((int) env('EDGE_WORKER_DB_WAIT_TRIES', 30))) {
            $this->error('local database not ready — deferring backup.');

            return self::FAILURE;
        }

        $row = $backups->backup();
        if ($this->option('json')) {
            $this->line((string) json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Backup written: ' . $row->path);
        $this->line('  checksum ' . substr($row->checksum, 0, 16) . '…  size ' . $row->size_bytes . ' bytes');

        return self::SUCCESS;
    }
}
