<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeLocalSchemaUpgrader;
use App\Services\Edge\EdgeTenantMigrationManifest;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Throwable;

/**
 * EDGE-SCHEMA-UPGRADE-1 — upgrade an EXISTING appliance's local schema without rebuilding it.
 *
 *   php artisan edge:local:schema-upgrade [--dry-run] [--json]
 *
 * Forward-only, pending-only, fail-closed; never drops or wipes; records the applied Edge schema
 * version on edge_local_meta. A fresh appliance is provisioned by edge:local:db-init instead — and
 * db-init refuses to touch an already-bootstrapped appliance, so the two paths cannot be confused.
 *
 * OWNER DECISION D7 — `--dry-run` prints the EXACT migration plan (every pending migration with its manifest
 * classification and the decision RUN / SKIP_CLOUD_ONLY / ABORT_UNCLASSIFIED) as a table and, with `--json`, as the
 * stable machine-readable `edge-migration-plan-v1` document whose `plan_hash` the updater compares against the
 * pre-install report. A plan with a blocker exits non-zero (RELEASE BLOCKER) — nothing is applied either way.
 */
class EdgeLocalSchemaUpgradeCommand extends Command
{
    protected $signature = 'edge:local:schema-upgrade
        {--dry-run : Print the exact migration plan (classification + decision per pending migration) without applying anything}
        {--json : Machine-readable output (the edge-migration-plan-v1 document, or the applied result)}';

    protected $description = 'Apply pending forward migrations to an existing Branch Server local database (non-destructive, fail-closed, manifest-governed).';

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
                return $this->printPlan($upgrader->plan());
            }

            $result = $upgrader->upgrade($this->option('json') ? null : $this->output);
        } catch (Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['result' => 'failed', 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));
            }
            $this->error('Schema upgrade failed (nothing was rebuilt; the appliance keeps its data): ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['result' => 'applied'] + $result, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info('Edge-local schema upgraded.');
        $this->line('  applied   : ' . ($result['applied'] === [] ? 'nothing pending' : implode(', ', $result['applied'])));
        if ($result['skipped'] !== []) {
            $this->line('  skipped   : ' . implode(', ', $result['skipped']) . ' (CLOUD_ONLY — recorded, never executed)');
        }
        $this->line('  plan_hash : ' . $result['plan_hash']);
        $this->line('  version   : ' . $result['version']);

        return self::SUCCESS;
    }

    private function printPlan(array $plan): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($plan, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Edge migration plan (' . $plan['format'] . ', manifest ' . $plan['manifest_version'] . ')');
            if ($plan['entries'] === []) {
                $this->line('  up to date — nothing pending');
            } else {
                $this->table(['migration', 'group', 'classification', 'decision', 'tables'], EdgeTenantMigrationManifest::planRows($plan));
            }
            $this->line('  run       : ' . count($plan['run']) . '   skip (CLOUD_ONLY): ' . count($plan['skip']) . '   blockers: ' . count($plan['blockers']));
            $this->line('  plan_hash : ' . $plan['plan_hash']);
            $this->line('  verdict   : ' . $plan['verdict']);
        }
        if ($plan['verdict'] !== EdgeTenantMigrationManifest::VERDICT_OK) {
            $this->error('RELEASE BLOCKER — unclassified tenant migration(s): ' . implode(', ', $plan['blockers']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
