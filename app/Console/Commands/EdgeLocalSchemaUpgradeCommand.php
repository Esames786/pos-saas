<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeLocalSchemaUpgrader;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Throwable;

/**
 * EDGE-SCHEMA-UPGRADE-1 — upgrade an EXISTING appliance's local schema without rebuilding it.
 *
 *   php artisan edge:local:schema-upgrade [--dry-run]
 *
 * Forward-only, pending-only, fail-closed; never drops or wipes; records the applied Edge schema
 * version on edge_local_meta. A fresh appliance is provisioned by edge:local:db-init instead — and
 * db-init refuses to touch an already-bootstrapped appliance, so the two paths cannot be confused.
 */
class EdgeLocalSchemaUpgradeCommand extends Command
{
    protected $signature = 'edge:local:schema-upgrade {--dry-run : List pending migrations without applying them}';

    protected $description = 'Apply pending forward migrations to an existing Branch Server local database (non-destructive, fail-closed).';

    public function handle(EdgeLocalSchemaUpgrader $upgrader): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:schema-upgrade only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        if (($reason = EdgeLocalDatabase::unsafeReason()) !== null) {
            $this->error("Refusing to upgrade: {$reason}.");

            return self::FAILURE;
        }

        EdgeLocalDatabase::useAsTenantConnection();

        try {
            if ($this->option('dry-run')) {
                foreach ($upgrader->pending() as $group => $names) {
                    $this->line("  {$group}: " . ($names === [] ? 'up to date' : implode(', ', $names)));
                }

                return self::SUCCESS;
            }

            $result = $upgrader->upgrade($this->output);
        } catch (Throwable $e) {
            $this->error('Schema upgrade failed (nothing was rebuilt; the appliance keeps its data): ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Edge-local schema upgraded.');
        $this->line('  applied : ' . ($result['applied'] === [] ? 'nothing pending' : implode(', ', $result['applied'])));
        $this->line('  version : ' . $result['version']);

        return self::SUCCESS;
    }
}
