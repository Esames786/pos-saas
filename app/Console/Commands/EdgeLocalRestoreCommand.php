<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeRestoreService;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * OFFLINE EDGE PRODUCTIZATION — restore an encrypted local appliance backup (replacement-box recovery).
 *
 *   php artisan edge:local:restore {path} --branch=ID
 *
 * Branch Server only, CLI-allowlisted, guarded (integrity/format/schema/identity, atomic apply). The branch
 * is required and must match the backup's branch — you can never restore one branch's data onto another.
 */
class EdgeLocalRestoreCommand extends Command
{
    protected $signature = 'edge:local:restore {path : path to the .enc backup} {--branch= : branch id being recovered}';

    protected $description = 'Restore the appliance local state from an encrypted backup (guarded, atomic).';

    public function handle(EdgeRestoreService $restore, EdgeBranchContext $context): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:restore only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }

        $branch = $this->option('branch') !== null ? (int) $this->option('branch') : $context->boundBranchId();
        if (! $branch) {
            $this->error('A --branch is required (this box is not yet bound).');

            return self::FAILURE;
        }

        try {
            $result = $restore->restore((string) $this->argument('path'), $branch);
        } catch (\Throwable $e) {
            $this->error('Restore refused: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restored branch ' . $branch . ' from backup taken ' . ($result['created_at'] ?? 'unknown'));
        foreach ($result['restored'] as $table => $count) {
            $this->line(sprintf('  %-40s %d rows', $table, $count));
        }

        return self::SUCCESS;
    }
}
