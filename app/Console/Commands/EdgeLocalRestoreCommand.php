<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeRecoveryKeyProvisioner;
use App\Services\Edge\EdgeRestoreService;
use App\Support\EdgeApplianceLayout;
use App\Support\EdgeLocalDatabase;
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
    protected $signature = 'edge:local:restore {path : path to the .enc backup} {--branch= : branch id being recovered}
        {--pull-recovery-key : replacement machine: fetch this branch backup recovery material from the Cloud recovery authority first}
        {--cloud-url= : Cloud base URL for --pull-recovery-key (default EDGE_CLOUD_BASE_URL)}';

    protected $description = 'Restore the appliance local state from an encrypted backup (guarded, atomic).';

    public function handle(EdgeRestoreService $restore, EdgeBranchContext $context, EdgeRecoveryKeyProvisioner $provisioner): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:restore only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        // THE APPLIANCE DB PATH (P4): map the tenant connection to the Edge-local DB — a Branch Server has no Cloud tenant DB.
        EdgeLocalDatabase::useAsTenantConnection();

        $branch = $this->option('branch') !== null ? (int) $this->option('branch') : $context->boundBranchId();
        if (! $branch) {
            $this->error('A --branch is required (this box is not yet bound).');

            return self::FAILURE;
        }

        if ($this->option('pull-recovery-key')) {
            // P5B §3 — a dead appliance is never the only holder of its backup key: the paired replacement pulls the
            // branch material (current + retired keys) from the Cloud recovery authority into appliance.env first.
            $cloud = rtrim((string) ($this->option('cloud-url') ?: env('EDGE_CLOUD_BASE_URL', '')), '/');
            if ($cloud === '') {
                $this->error('--pull-recovery-key needs a Cloud base URL (--cloud-url or EDGE_CLOUD_BASE_URL).');
                return self::FAILURE;
            }
            try {
                $keys = $provisioner->pull($cloud, EdgeApplianceLayout::envFilePath());
            } catch (\Throwable $e) {
                $this->error('Recovery-key provisioning refused: ' . $e->getMessage());
                return self::FAILURE;
            }
            $this->line('Recovery material provisioned from the Cloud authority: current key id ' . $keys['key_id'] . ', retired keys ' . count($keys['retired_key_ids']));
        }
        try {
            $result = $restore->restore((string) $this->argument('path'), $branch);
        } catch (\Throwable $e) {
            $this->error('Restore refused: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restored branch ' . $branch . ' from backup taken ' . ($result['created_at'] ?? 'unknown'));
        if (! empty($result['device_rebound'])) {
            $this->line('  local binding re-pointed to THIS appliance\'s paired device identity (replacement machine; the dead device stays revoked at the Cloud)');
        }
        foreach ($result['restored'] as $table => $count) {
            $this->line(sprintf('  %-40s %d rows', $table, $count));
        }

        return self::SUCCESS;
    }
}
