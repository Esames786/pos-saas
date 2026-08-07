<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeLocalBootstrapImporter;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Throwable;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section V/11) — import a Cloud bootstrap package into the Branch Server's
 * local database.
 *
 *   php artisan edge:local:bootstrap-import <package.json>
 *
 * Branch Server only, Edge-local DB only. There is NO unauthenticated HTTP import endpoint — the
 * operator/installer runs this command with the package the appliance downloaded from the
 * authenticated Cloud bootstrap API. It NEVER activates Local Mode, opens a shift, enables POS, or
 * marks operational stock ready.
 */
class EdgeLocalBootstrapImportCommand extends Command
{
    protected $signature = 'edge:local:bootstrap-import {package : Path to the bootstrap package JSON}';

    protected $description = 'Import a Cloud bootstrap package into the Branch Server local database (config only).';

    public function handle(EdgeLocalBootstrapImporter $importer): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:bootstrap-import only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        if (($reason = EdgeLocalDatabase::unsafeReason()) !== null) {
            $this->error("Refusing to import: {$reason}.");

            return self::FAILURE;
        }

        $path = (string) $this->argument('package');
        if (! is_file($path)) {
            $this->error("Package file not found: {$path}");

            return self::FAILURE;
        }

        $package = json_decode((string) file_get_contents($path), true);
        if (! is_array($package) || ! isset($package['manifest'], $package['sections'])) {
            $this->error('Invalid package: expected a JSON object with "manifest" and "sections".');

            return self::FAILURE;
        }

        // Bind the tenant connection to the Edge-local DB (lazy).
        EdgeLocalDatabase::useAsTenantConnection();

        try {
            $meta = $importer->import($package);
        } catch (Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Bootstrap imported. Appliance is now bound to:');
        $this->line('  tenant   : ' . $meta->tenant_code . ' (#' . $meta->tenant_id . ')');
        $this->line('  branch   : #' . $meta->branch_id);
        $this->line('  device   : ' . $meta->device_uuid);
        $this->line('  epoch    : ' . $meta->activation_epoch);
        $this->line('  revision : ' . $meta->source_revision);
        $this->line('  state    : ' . $meta->runtime_state . ' (NOT selling — operational stock stays not-ready)');

        return self::SUCCESS;
    }
}
