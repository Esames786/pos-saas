<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * EDGE-SCHEMA-UPGRADE-1 — the NON-DESTRUCTIVE local schema update authority for an EXISTING appliance.
 *
 * `edge:local:db-init` provisions a FRESH appliance (and may rebuild an uninitialised local DB). It is
 * NOT a software-update mechanism: an appliance holding local sales, shifts, held/draft orders, print
 * history, credentials and the sync outbox (pending AND acknowledged rows) must take new schema
 * FORWARD ONLY. This service:
 *
 *   - refuses unless the runtime is a branch_server on a safe Edge-local target AND the appliance is
 *     already initialised + bootstrapped (fresh installs go to db-init — never the other way round);
 *   - computes the PENDING migrations from the repository-approved paths only (real tenant migrations,
 *     then the Edge-only migrations) and applies just those, one migration per batch;
 *   - OWNER DECISION D7: the tenant group runs ONLY from the explicit approved set of the Edge tenant-migration
 *     manifest (EDGE_REQUIRED / EDGE_SCHEMA_SAFE). An UNCLASSIFIED tenant migration present in the runtime aborts
 *     the upgrade BEFORE anything touches the database (SCHEMA_UPGRADE_UNCLASSIFIED_MIGRATION = RELEASE BLOCKER);
 *     a CLOUD_ONLY file (which the artifact builder excludes, so it should never be on an appliance) is SKIPPED
 *     WITH RECORD (plan entry, log, the update audit row) and never executed. The dry-run plan is deterministic and
 *     hashed (plan_hash) so the updater can stop when the actual plan differs from the pre-install report;
 *   - never drops tables, never wipes, never falls back to a rebuild;
 *   - audits protected operational tables before/after — any lost row is a hard failure;
 *   - fails CLOSED: a failing migration stops the run, is recorded on edge_local_meta.last_error, and
 *     the applied schema version does NOT advance; a later run re-applies only what is still pending
 *     (MySQL DDL is not transactional, so "transactional where possible" is per-migration + audit);
 *   - on success records the SHIPPED Edge schema generation (EdgeBuildInfoService::edgeSchemaVersion)
 *     as the APPLIED one, with a timestamp.
 */
class EdgeLocalSchemaUpgrader
{
    public const CONN = 'tenant';

    /** Operational tables whose row counts must never decrease during an upgrade. */
    public const PROTECTED_TABLES = [
        'sales_orders', 'sales_order_lines', 'sale_payments', 'shifts', 'restaurant_table_sessions',
        'kot_batches', 'print_jobs', 'sales_ledgers', 'edge_sync_outbox',
        'edge_operational_stock_movements', 'edge_operational_stock_baselines', 'edge_local_user_credentials',
    ];

    /** @var array<int,string> test-only: extra directories whose files are governed like database/migrations/tenant */
    private array $additionalTenantPaths = [];

    public function __construct(private readonly EdgeBuildInfoService $buildInfo)
    {
    }

    /** Repository-approved migration paths, applied in this order (mirrors db-init). */
    public function approvedPaths(): array
    {
        return [database_path('migrations/tenant'), database_path('migrations/edge')];
    }

    /**
     * TEST SEAM — a clone whose tenant group also reads fixture directories (so a fixture file can prove the manifest
     * gate: unclassified → abort, CLOUD_ONLY → skip). Production callers never use it; fixture dirs must say "test".
     */
    public function withAdditionalTenantPaths(array $paths): static
    {
        foreach ($paths as $p) {
            if (! str_contains(strtolower((string) $p), 'test')) {
                throw new RuntimeException('SCHEMA_UPGRADE_REFUSED: additional tenant paths are a test-only seam.');
            }
        }
        $clone = clone $this;
        $clone->additionalTenantPaths = array_values($paths);

        return $clone;
    }

    /**
     * Pending migration names per approved path group (tenant, edge, extra) without applying anything.
     *
     * @param  array<int,string>  $extraPaths  test-only additional path group (fixture migrations)
     * @return array<string, array<int,string>>
     */
    public function pending(array $extraPaths = []): array
    {
        $this->assertUpgradableTarget();

        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');

        return $migrator->usingConnection(self::CONN, function () use ($migrator, $extraPaths) {
            $this->assertInitialisedAppliance($migrator);

            return $this->pendingByGroup($migrator, $extraPaths);
        });
    }

    /**
     * D7 — the EXACT migration plan (what would run, what is skipped, what blocks), machine-readable and hashable.
     * Nothing is applied. Shape: EdgeTenantMigrationManifest::plan() + edge_schema_version + pending-by-group.
     */
    public function plan(array $extraPaths = []): array
    {
        $pending = $this->pending($extraPaths);
        $plan = $this->manifest()->plan($pending);
        $plan['edge_schema_version'] = $this->buildInfo->edgeSchemaVersion();
        $plan['pending'] = $pending;

        return $plan;
    }

    /**
     * Apply pending forward migrations non-destructively and record the applied schema version.
     *
     * @param  array<int,string>  $extraPaths  test-only additional path group (fixture migrations)
     * @return array{pending: array<string,array<int,string>>, applied: array<int,string>, skipped: array<int,string>, plan_hash: string, version: string}
     */
    public function upgrade(?OutputInterface $output = null, array $extraPaths = []): array
    {
        $this->assertUpgradableTarget();

        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');

        return $migrator->usingConnection(self::CONN, function () use ($migrator, $output, $extraPaths) {
            $meta = $this->assertInitialisedAppliance($migrator);
            $pending = $this->pendingByGroup($migrator, $extraPaths);
            $plan = $this->manifest()->plan($pending);

            // D7 — RELEASE BLOCKER: an unclassified tenant migration aborts BEFORE anything touches the database
            // (no counts, no bookkeeping row, no migration). The operator sees the names; the release is not installable.
            if ($plan['blockers'] !== []) {
                $msg = 'SCHEMA_UPGRADE_UNCLASSIFIED_MIGRATION: RELEASE BLOCKER — tenant migration(s) without a manifest classification: '
                    . implode(', ', $plan['blockers']) . ' (classify them in ' . EdgeTenantMigrationManifest::FILE . '; nothing was applied).';
                Log::error('[edge-schema-upgrade] refused', ['blockers' => $plan['blockers'], 'plan_hash' => $plan['plan_hash']]);
                throw new RuntimeException($msg);
            }

            $before = $this->protectedCounts();
            $applied = [];
            $skipped = $plan['skip'];
            if ($skipped !== []) {
                // Defensive: a CLOUD_ONLY file should never reach an appliance (the artifact excludes it). Record, never run.
                Log::warning('[edge-schema-upgrade] CLOUD_ONLY tenant migration(s) present on the appliance — skipped with record, never executed', ['skipped' => $skipped]);
                if ($output) {
                    foreach ($skipped as $name) {
                        $output->writeln("  <comment>SKIP_CLOUD_ONLY</comment> {$name}");
                    }
                }
            }

            if ($output) {
                $migrator->setOutput($output);
            }

            try {
                foreach ($this->pathGroups($extraPaths) as $group => $paths) {
                    if ($pending[$group] === []) {
                        continue;
                    }
                    // The tenant group runs ONLY the manifest-approved files (RUN decisions); the other groups are
                    // Edge-owned. One migration per batch (step) so a later rollback/inspection has granularity.
                    $target = $group === 'tenant' ? $this->approvedFiles($migrator, $paths, $plan['run']) : $paths;
                    if ($target === []) {
                        continue;
                    }
                    foreach ($migrator->run($target, ['step' => true]) as $file) {
                        $applied[] = $migrator->getMigrationName($file);
                    }
                }
            } catch (Throwable $e) {
                $this->recordFailure($meta, 'SCHEMA_UPGRADE_FAILED: ' . $e->getMessage());
                throw new RuntimeException('SCHEMA_UPGRADE_FAILED: ' . $e->getMessage(), 0, $e);
            }

            // Data-loss audit: a forward migration must never shrink an operational table.
            $after = $this->protectedCounts();
            foreach ($before as $table => $count) {
                if (($after[$table] ?? 0) < $count) {
                    $this->recordFailure($meta, "SCHEMA_UPGRADE_DATA_LOSS: {$table} shrank from {$count} to " . ($after[$table] ?? 0) . ' rows');
                    throw new RuntimeException("SCHEMA_UPGRADE_DATA_LOSS: {$table} shrank from {$count} to " . ($after[$table] ?? 0) . ' rows — refusing to record the upgrade.');
                }
            }

            $version = $this->buildInfo->edgeSchemaVersion();
            // Re-read: the run may have just added the columns this record uses.
            EdgeLocalMeta::query()->where('singleton_guard', EdgeLocalMeta::SINGLETON)->update([
                'edge_schema_version' => $version,
                'last_schema_upgrade_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

            Log::info('[edge-schema-upgrade] applied', ['applied' => $applied, 'skipped' => $skipped, 'plan_hash' => $plan['plan_hash'], 'version' => $version]);

            return ['pending' => $pending, 'applied' => $applied, 'skipped' => $skipped, 'plan_hash' => $plan['plan_hash'], 'version' => $version];
        });
    }

    // ── guards ───────────────────────────────────────────────────────────────

    private function assertUpgradableTarget(): void
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('SCHEMA_UPGRADE_REFUSED: only a Branch Server runtime may upgrade an Edge-local schema.');
        }
        EdgeLocalDatabase::assertSafeTarget();
    }

    /** An upgrade is for an INITIALISED, BOOTSTRAPPED appliance only — a fresh box uses db-init. */
    private function assertInitialisedAppliance($migrator): EdgeLocalMeta
    {
        if (! $migrator->repositoryExists() || ! Schema::connection(self::CONN)->hasTable('edge_local_meta')) {
            throw new RuntimeException('SCHEMA_UPGRADE_REFUSED: this local database is not initialised — use edge:local:db-init for a fresh appliance.');
        }
        $meta = EdgeLocalMeta::query()->where('singleton_guard', EdgeLocalMeta::SINGLETON)->first();
        if (! $meta || $meta->runtime_state !== EdgeLocalMeta::STATE_BOOTSTRAPPED) {
            throw new RuntimeException('SCHEMA_UPGRADE_REFUSED: no bootstrapped appliance binding — use edge:local:db-init + bootstrap-import for a fresh appliance.');
        }

        return $meta;
    }

    private function manifest(): EdgeTenantMigrationManifest
    {
        return EdgeTenantMigrationManifest::load();
    }

    /** @return array<string, array<int,string>> */
    private function pendingByGroup($migrator, array $extraPaths): array
    {
        $ran = $migrator->getRepository()->getRan();
        $out = [];
        foreach ($this->pathGroups($extraPaths) as $group => $paths) {
            $names = array_values(array_diff(array_keys($migrator->getMigrationFiles($paths)), $ran));
            sort($names);
            $out[$group] = $names;
        }

        return $out;
    }

    /** The tenant files the manifest approved (RUN), as individual file paths in name order. */
    private function approvedFiles($migrator, array $paths, array $run): array
    {
        $files = $migrator->getMigrationFiles($paths);   // name => path, sorted by name
        $out = [];
        foreach ($files as $name => $path) {
            if (in_array($name, $run, true)) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /** @return array<string, array<int,string>> */
    private function pathGroups(array $extraPaths): array
    {
        [$tenant, $edge] = $this->approvedPaths();
        $groups = ['tenant' => array_merge([$tenant], $this->additionalTenantPaths), 'edge' => [$edge]];
        if ($extraPaths !== []) {
            $groups['extra'] = array_values($extraPaths);
        }

        return $groups;
    }

    /** @return array<string,int> */
    private function protectedCounts(): array
    {
        $conn = DB::connection(self::CONN);
        $counts = [];
        foreach (self::PROTECTED_TABLES as $table) {
            if (Schema::connection(self::CONN)->hasTable($table)) {
                $counts[$table] = (int) $conn->table($table)->count();
            }
        }

        return $counts;
    }

    private function recordFailure(EdgeLocalMeta $meta, string $message): void
    {
        try {
            EdgeLocalMeta::query()->where('singleton_guard', EdgeLocalMeta::SINGLETON)
                ->update(['last_error' => mb_substr($message, 0, 1900), 'updated_at' => now()]);
        } catch (Throwable $e) {
            // never mask the real failure with a bookkeeping failure
        }
        Log::error('[edge-schema-upgrade] failed', ['error' => mb_substr($message, 0, 500)]);
    }
}
