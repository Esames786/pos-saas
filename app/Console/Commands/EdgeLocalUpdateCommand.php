<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeUpdateInstaller;
use App\Services\Edge\EdgeUpdatePackageService;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * OFFLINE EDGE PRODUCTIZATION (O) — apply a signed appliance update.
 *
 *   php artisan edge:local:update {package.json} {artifactDir}
 *
 * Branch Server only, CLI-allowlisted. Verifies the signed package before any mutation, takes a verified
 * pre-update backup, stages the new artifact, switches the runtime atomically, runs the forward-only schema
 * upgrade, and records the outcome. Refuses (with no mutation) on any signature/tamper/version/schema failure.
 */
class EdgeLocalUpdateCommand extends Command
{
    protected $signature = 'edge:local:update {package : path to the signed update package JSON} {artifact : path to the staged artifact directory}';

    protected $description = 'Verify and apply a signed appliance update (atomic switch, forward schema upgrade, rollback-safe).';

    public function handle(EdgeUpdateInstaller $installer, EdgeUpdatePackageService $packages): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:update only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }

        $package = json_decode((string) file_get_contents((string) $this->argument('package')), true);
        if (! is_array($package)) {
            $this->error('Could not read the update package JSON.');

            return self::FAILURE;
        }

        try {
            $result = $installer->install($package, (string) $this->argument('artifact'), 'cli:' . (function_exists('gethostname') ? gethostname() : 'edge'));
        } catch (\Throwable $e) {
            $this->error('Update refused/failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Updated %s -> %s (schema %s). Active version: %s',
            $result['from_version'], $result['to_version'], $result['schema_after'], $result['active_version']));

        return self::SUCCESS;
    }
}
