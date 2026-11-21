<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeTenantMigrationManifest;
use App\Services\Edge\EdgeUpdateInstaller;
use App\Services\Edge\EdgeUpdatePackageService;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * OFFLINE EDGE PRODUCTIZATION (O) — apply a signed appliance update.
 *
 *   php artisan edge:local:update {package.json} {artifactDir} [--dry-run] [--json] [--expected-plan-hash=sha256:…]
 *
 * Branch Server only, CLI-allowlisted. Verifies the signed package before any mutation, takes a verified
 * pre-update backup, stages the new artifact, switches the runtime atomically, runs the forward-only schema
 * upgrade, and records the outcome. Refuses (with no mutation) on any signature/tamper/version/schema failure.
 *
 * OWNER DECISION D7 — `--dry-run` is the PRE-INSTALL VERDICT: it verifies the package and prints the EXACT migration
 * plan the new runtime would execute (classification + decision per pending migration) with its `plan_hash`, without
 * staging or switching anything. The real install must be given that hash back (`--expected-plan-hash`); when the
 * actual plan computed before the switch differs, the update STOPS with UPDATE_MIGRATION_PLAN_MISMATCH and the previous
 * version stays active. An unclassified tenant migration in the new runtime is a RELEASE BLOCKER in both modes.
 */
class EdgeLocalUpdateCommand extends Command
{
    protected $signature = 'edge:local:update
        {package : path to the signed update package JSON}
        {artifact : path to the staged artifact directory}
        {--dry-run : Verify the package and print the exact migration plan + plan hash without installing anything}
        {--json : Machine-readable output}
        {--expected-plan-hash= : The plan_hash of the approved pre-install dry-run; the install stops if the actual plan differs}';

    protected $description = 'Verify and apply a signed appliance update (atomic switch, forward schema upgrade, rollback-safe); --dry-run prints the exact migration plan first.';

    public function handle(EdgeUpdateInstaller $installer, EdgeUpdatePackageService $packages): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:update only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        // THE APPLIANCE DB PATH (P4): map the tenant connection to the Edge-local DB — a Branch Server has no Cloud tenant DB.
        EdgeLocalDatabase::useAsTenantConnection();

        $package = json_decode((string) file_get_contents((string) $this->argument('package')), true);
        if (! is_array($package)) {
            $this->error('Could not read the update package JSON.');

            return self::FAILURE;
        }
        $performedBy = 'cli:' . (function_exists('gethostname') ? gethostname() : 'edge');

        if ($this->option('dry-run')) {
            try {
                $verdict = $installer->dryRun($package, (string) $this->argument('artifact'), $performedBy);
            } catch (\Throwable $e) {
                if ($this->option('json')) {
                    $this->line(json_encode(['result' => 'refused', 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));
                }
                $this->error('Pre-install dry-run refused: ' . $e->getMessage());

                return self::FAILURE;
            }
            if ($this->option('json')) {
                $this->line(json_encode($verdict, JSON_UNESCAPED_SLASHES));
            } else {
                $plan = $verdict['plan'];
                $this->info(sprintf('Pre-install verdict for %s -> %s (package VERIFIED; nothing staged or switched)', $verdict['from_version'], $verdict['to_version']));
                $this->line('Migration plan (' . $plan['format'] . ', manifest ' . $plan['manifest_version'] . ')');
                if ($plan['entries'] === []) {
                    $this->line('  up to date — the new runtime has nothing pending on this appliance');
                } else {
                    $this->table(['migration', 'group', 'classification', 'decision', 'tables'], EdgeTenantMigrationManifest::planRows($plan));
                }
                $this->line('  run       : ' . count($plan['run']) . '   skip (CLOUD_ONLY): ' . count($plan['skip']) . '   blockers: ' . count($plan['blockers']));
                $this->line('  verdict   : ' . $verdict['verdict']);
                $this->line('PLAN_HASH=' . $verdict['plan_hash']);
                $this->line('Install with: edge:local:update <package> <artifact> --expected-plan-hash=' . $verdict['plan_hash']);
            }
            if ($verdict['verdict'] !== EdgeTenantMigrationManifest::VERDICT_OK) {
                $this->error('RELEASE BLOCKER — unclassified tenant migration(s) in the new runtime: ' . implode(', ', $verdict['plan']['blockers']));

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        try {
            $result = $installer->install($package, (string) $this->argument('artifact'), $performedBy, $this->option('expected-plan-hash') ?: null);
        } catch (\Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['result' => 'failed', 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));
            }
            $this->error('Update refused/failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        }
        $this->info(sprintf('Updated %s -> %s (schema %s). Active version: %s. Migration plan %s: ran %d, skipped %d.',
            $result['from_version'], $result['to_version'], $result['schema_after'], $result['active_version'],
            $result['plan_hash'], count($result['migrations_run']), count($result['migrations_skipped'])));

        return self::SUCCESS;
    }
}
