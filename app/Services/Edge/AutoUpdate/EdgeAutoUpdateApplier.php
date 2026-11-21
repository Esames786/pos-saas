<?php

namespace App\Services\Edge\AutoUpdate;

use App\Http\Controllers\Edge\EdgeLocalAuthorityControlController;
use App\Services\Edge\EdgeTenantMigrationManifest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * EDGE 0.9 (stream B) — APPLY a verified, staged release on an appliance whose gate is open, with the K6 rollback rule.
 *
 *   0. take the appliance-wide authority-control lock (a "Switch to Offline" click during the update is told to wait) and
 *      RE-CHECK the safety gate inside it (standby, outbox empty, bound) — no TOCTOU between the check and the switch;
 *   1. D7 pre-install verdict (EdgeUpdateInstaller::dryRun): the exact migration plan the NEW runtime would run. A plan with
 *      pending migrations needs the owner-approved plan hash the release advertises (K6 plan_hashes[<installed version>]);
 *      without it the update waits (UPDATE_MIGRATION_PLAN_UNCONFIRMED) — never self-confirmed on an appliance;
 *   2. health report BEFORE (the comparison base);
 *   3. when the plan changes the schema: a FULL encrypted DB snapshot (EdgeDatabaseSnapshot) — no snapshot, no update;
 *   4. the EXISTING installer (verify, pre-update backup, stage, plan-hash check, atomic switch, schema upgrade in the new
 *      runtime), with the services stopped after the backup and restarted on the new runtime after the schema step;
 *   5. health AFTER on the new runtime (child process through the launcher);
 *   6. unhealthy → rollback: services stopped, the snapshot restored when the plan changed the schema, the pointer switched
 *      back, services started on the previous runtime, health re-probed. A rollback that would touch a branch which is no
 *      longer in standby (someone switched Offline meanwhile) is NOT performed — it is reported for a supervised decision.
 *
 * Every outcome is returned for the K4 report + the state file; the installer keeps writing edge_local_updates.
 */
class EdgeAutoUpdateApplier
{
    public const UPDATED = 'updated';
    public const REFUSED = 'refused';
    public const FAILED = 'update_failed';
    public const ROLLED_BACK = 'rolled_back';

    public function __construct(
        private readonly EdgeAutoUpdateInstaller $installer,
        private readonly EdgeAutoUpdateServiceControl $services,
        private readonly EdgeAutoUpdateHealthProbe $health,
        private readonly EdgeAutoUpdateRuntimeProbe $runtime,
        private readonly EdgeDatabaseSnapshot $snapshot,
    ) {
    }

    /**
     * @param  array  $release  the K6 advertisement (plan_hashes / expected_plan_hash are read from it)
     * @return array{result:string,from_version:string,to_version:string,code:?string,message:string,schema_change:bool,rollback?:?string,health?:array}
     */
    public function apply(string $packageRoot, array $signedPackage, array $release, string $performedBy): array
    {
        $from = (string) config('edge.app_version');
        $to = (string) ($signedPackage['payload']['edge_app_version'] ?? $release['version'] ?? '');
        $base = ['from_version' => $from, 'to_version' => $to, 'schema_change' => false];
        $lock = Cache::lock(EdgeLocalAuthorityControlController::LOCK, 3600);
        if (! $lock->get()) {
            return $base + ['result' => self::REFUSED, 'code' => 'AUTO_UPDATE_BUSY', 'message' => 'an authority change is in progress — the update waits for the next run'];
        }
        try {
            // 0. the safety gate, again, inside the lock.
            $facts = $this->runtime->facts();
            if (! EdgeAutoUpdateGate::safe($facts + ['enabled' => true])) {
                return $base + ['result' => self::REFUSED, 'code' => 'AUTO_UPDATE_GATE_CHANGED', 'message' => 'the branch is no longer in a safe state for an update (authority ' . $facts['authority_state'] . ', outbox ' . ($facts['outbox_pending'] + $facts['outbox_in_flight']) . ')'];
            }
            $root = EdgeRuntimePointer::root();
            $previous = EdgeRuntimePointer::current($root);
            $previousDir = $previous !== null ? EdgeRuntimePointer::versionDir($root, $previous) : base_path();
            $artifact = rtrim($packageRoot, "/\\") . DIRECTORY_SEPARATOR . 'app';

            // 1. the pre-install verdict.
            try {
                $verdict = $this->installer->dryRun($signedPackage, $artifact, $performedBy);
            } catch (\Throwable $e) {
                return $base + ['result' => self::REFUSED, 'code' => self::code($e) ?? 'UPDATE_DRY_RUN_REFUSED', 'message' => mb_substr($e->getMessage(), 0, 400)];
            }
            if (($verdict['verdict'] ?? null) !== EdgeTenantMigrationManifest::VERDICT_OK) {
                return $base + ['result' => self::REFUSED, 'code' => 'UPDATE_MIGRATION_PLAN_BLOCKED', 'message' => 'RELEASE BLOCKER: the new runtime carries an unclassified migration'];
            }
            $plan = (array) ($verdict['plan'] ?? []);
            $schemaChange = ! empty($plan['run']) || ! empty($plan['substitute']);
            $base['schema_change'] = $schemaChange;
            $expectedHash = null;
            if (($plan['entries'] ?? []) !== []) {
                $expectedHash = self::approvedPlanHash($release, $from);
                if ($expectedHash === null) {
                    return $base + ['result' => self::REFUSED, 'code' => 'UPDATE_MIGRATION_PLAN_UNCONFIRMED', 'message' => 'the update carries ' . count($plan['entries']) . ' pending migration(s) and the release advertises no owner-approved plan hash for ' . $from . ' (actual ' . (string) $verdict['plan_hash'] . ')'];
                }
                if (! hash_equals($expectedHash, (string) $verdict['plan_hash'])) {
                    return $base + ['result' => self::REFUSED, 'code' => 'UPDATE_MIGRATION_PLAN_MISMATCH', 'message' => 'the actual plan ' . (string) $verdict['plan_hash'] . ' differs from the approved ' . $expectedHash];
                }
            }

            // 2. the comparison base.
            $before = $this->health->probe();

            // 3. the full snapshot when the schema changes.
            $snapFile = null;
            if ($schemaChange) {
                $snapFile = EdgeAutoUpdateSettings::workDir() . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pre-' . EdgeRuntimePointer::safe($to) . '-' . now()->format('YmdHis') . '.snap';
                try {
                    $this->snapshot->snapshot($snapFile);
                } catch (\Throwable $e) {
                    return $base + ['result' => self::REFUSED, 'code' => 'UPDATE_PREUPDATE_SNAPSHOT_FAILED', 'message' => mb_substr($e->getMessage(), 0, 400)];
                }
            }

            // 4. the existing installer, services stopped/started through its seams.
            $newDir = EdgeRuntimePointer::versionDir($root, $to);
            $this->installer->withServiceHooks(fn () => $this->services->stop($previousDir), fn () => $this->services->start($newDir));
            try {
                $this->installer->install($signedPackage, $artifact, $performedBy, $expectedHash);
            } catch (\Throwable $e) {
                $code = self::code($e) ?? 'UPDATE_FAILED';
                $afterSwitch = $code === 'UPDATE_SCHEMA_UPGRADE_FAILED'; // the installer already reverted the pointer
                $notes = [];
                if ($afterSwitch && $snapFile !== null) {
                    try {
                        $this->snapshot->restore($snapFile);
                        $notes[] = 'database restored from the pre-update snapshot';
                    } catch (\Throwable $re) {
                        $notes[] = 'SNAPSHOT RESTORE FAILED: ' . mb_substr($re->getMessage(), 0, 200);
                    }
                }
                if ($this->installer->workersStopped) {
                    $notes[] = $this->restart($previousDir);
                }
                $this->audit($from, $to, $performedBy, $afterSwitch ? 'rolled_back' : 'failed', $code, $afterSwitch ? ($snapFile !== null ? 'restored_snapshot' : 'reverted_runtime') : 'none');

                return $base + [
                    'result' => $afterSwitch ? self::ROLLED_BACK : self::FAILED,
                    'code' => $code,
                    'rollback' => $afterSwitch ? ($snapFile !== null ? 'restored_snapshot' : 'reverted_runtime') : 'none',
                    'message' => mb_substr($e->getMessage(), 0, 400) . ($notes !== [] ? ' — ' . implode('; ', array_filter($notes)) : ''),
                ];
            }

            // 5. health on the new runtime.
            $after = $this->health->waitHealthy($before, $to, (int) config('edge.auto_update.health_wait_seconds', 150));
            if ($after['ok']) {
                if ($snapFile !== null) {
                    @unlink($snapFile);
                }

                return $base + ['result' => self::UPDATED, 'code' => null, 'message' => "updated {$from} → {$to}", 'rollback' => 'none', 'health' => ['ok' => true]];
            }

            // 6. rollback.
            $reasons = implode('; ', array_slice($after['reasons'], 0, 5));
            $facts = $this->runtime->facts();
            if (($facts['authority_state'] ?? '') !== 'standby') {
                // Someone switched the branch Offline while the new runtime was being checked: never roll a SELLING branch back.
                $this->audit($from, $to, $performedBy, 'failed', 'AUTO_UPDATE_HEALTH_FAILED', 'restore_required');

                return $base + ['result' => self::FAILED, 'code' => 'AUTO_UPDATE_HEALTH_FAILED_NOT_ROLLED_BACK', 'rollback' => 'restore_required',
                    'message' => 'the new runtime failed its health check (' . $reasons . ') but the branch is now ' . $facts['authority_state'] . ' — rollback left to a supervised decision'];
            }
            $notes = [];
            try {
                $this->services->stop($newDir);
            } catch (\Throwable $e) {
                $notes[] = 'stop: ' . mb_substr($e->getMessage(), 0, 120);
            }
            $rollback = 'reverted_runtime';
            if ($snapFile !== null) {
                try {
                    $this->snapshot->restore($snapFile);
                    $rollback = 'restored_snapshot';
                } catch (\Throwable $e) {
                    $rollback = 'restore_required';
                    $notes[] = 'SNAPSHOT RESTORE FAILED: ' . mb_substr($e->getMessage(), 0, 200);
                }
            }
            if ($previous !== null) {
                EdgeRuntimePointer::switchTo($root, $previous);
            }
            $notes[] = $this->restart($previousDir);
            $this->audit($from, $to, $performedBy, 'rolled_back', 'AUTO_UPDATE_HEALTH_FAILED', $rollback);
            $back = $this->health->waitHealthy($before, $from, (int) config('edge.auto_update.health_wait_seconds', 150));

            return $base + [
                'result' => $rollback === 'restore_required' ? self::FAILED : self::ROLLED_BACK,
                'code' => 'AUTO_UPDATE_HEALTH_FAILED',
                'rollback' => $rollback,
                'message' => "the new runtime {$to} failed its health check ({$reasons}); rolled back to {$from}" . ($back['ok'] ? ' — healthy again' : ' — STILL UNHEALTHY: ' . implode('; ', array_slice($back['reasons'], 0, 3))) . ($notes !== [] ? ' [' . implode('; ', array_filter($notes)) . ']' : ''),
                'health' => ['ok' => false, 'reasons' => $after['reasons'], 'after_rollback_ok' => $back['ok']],
            ];
        } finally {
            $lock->release();
        }
    }

    /** K6 plan_hashes[<installed version>] (or a single expected_plan_hash) — the owner-approved D7 plan for this transition. */
    public static function approvedPlanHash(array $release, string $fromVersion): ?string
    {
        $map = $release['plan_hashes'] ?? null;
        if (is_array($map) && isset($map[$fromVersion]) && is_string($map[$fromVersion]) && trim($map[$fromVersion]) !== '') {
            return trim($map[$fromVersion]);
        }
        $single = $release['expected_plan_hash'] ?? null;

        return is_string($single) && trim($single) !== '' ? trim($single) : null;
    }

    private function restart(string $runtimeDir): string
    {
        try {
            $this->services->start($runtimeDir);

            return '';
        } catch (\Throwable $e) {
            return 'restart failed: ' . mb_substr($e->getMessage(), 0, 160);
        }
    }

    /** One edge_local_updates row for an outcome the installer itself did not record (written AFTER any snapshot restore). */
    private function audit(string $from, string $to, string $by, string $result, string $code, string $rollback): void
    {
        try {
            DB::connection('tenant')->table('edge_local_updates')->insert([
                'update_uuid' => (string) Str::ulid(),
                'from_version' => $from,
                'to_version' => $to,
                'schema_before' => (string) config('edge.config_schema'),
                'schema_after' => (string) config('edge.config_schema'),
                'result' => mb_substr($result, 0, 24),
                'failure_code' => mb_substr($code, 0, 64),
                'rollback_result' => mb_substr($rollback, 0, 24),
                'performed_by' => mb_substr($by, 0, 191),
                'started_at' => now(),
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[edge-auto-update] could not record the outcome row', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    private static function code(\Throwable $e): ?string
    {
        return preg_match('/^([A-Z_]+)[:( ]/', $e->getMessage(), $m) ? $m[1] : null;
    }
}
