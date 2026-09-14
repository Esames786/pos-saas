<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeRecoveryKeyProvisioner;
use App\Support\EdgeApplianceLayout;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * P5B §3 — provision this appliance's backup recovery material from the Cloud recovery authority.
 *
 *   php artisan edge:local:recovery-key [--cloud-url=https://…] [--for-key-id=brk_…]
 *
 * Runs after pairing (installer step 7) and during a replacement-machine restore (edge:local:restore
 * --pull-recovery-key / Restore-EdgeAppliance.ps1 -PullConfig). Writes EDGE_BACKUP_RECOVERY_KEY / _ID /
 * EDGE_BACKUP_RETIRED_KEYS into appliance.env; prints key ids only.
 */
class EdgeLocalRecoveryKeyCommand extends Command
{
    protected $signature = 'edge:local:recovery-key
        {--cloud-url= : Cloud base URL (default EDGE_CLOUD_BASE_URL)}
        {--for-key-id= : Assert the Cloud still holds this key id for this branch (the key that sealed a backup being recovered)}
        {--env-file= : appliance.env path (default: the appliance layout)}
        {--json : Emit key ids as JSON}';

    protected $description = 'Provision the branch backup recovery material from the Cloud recovery authority into appliance.env (key ids printed, never material).';

    public function handle(EdgeRecoveryKeyProvisioner $provisioner): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:recovery-key only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        $cloud = rtrim((string) ($this->option('cloud-url') ?: env('EDGE_CLOUD_BASE_URL', '')), '/');
        if ($cloud === '') {
            $this->error('A Cloud base URL is required (--cloud-url or EDGE_CLOUD_BASE_URL).');

            return self::FAILURE;
        }
        $envFile = (string) ($this->option('env-file') ?: EdgeApplianceLayout::envFilePath());
        $forKeyId = (string) ($this->option('for-key-id') ?? '');
        try {
            $result = $provisioner->pull($cloud, $envFile, $forKeyId !== '' ? $forKeyId : null);
        } catch (\Throwable $e) {
            $this->error('Recovery-key provisioning refused: ' . $e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info('Backup recovery material provisioned from the Cloud recovery authority.');
        $this->line('  current key id : ' . $result['key_id']);
        $this->line('  retired keys   : ' . count($result['retired_key_ids']) . ($result['retired_key_ids'] !== [] ? ' (' . implode(', ', $result['retired_key_ids']) . ')' : ''));
        $this->line('  written to     : ' . $result['env_file'] . '  (the ONLY copy on this appliance; ACL-protected)');

        return self::SUCCESS;
    }
}
